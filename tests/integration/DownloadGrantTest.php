<?php
namespace Wtp\Tests;

/**
 * Signed, single-use, job-bound package delivery.
 */
final class DownloadGrantTest extends TestCase
{
    /** @var Harness */
    private $h;
    /** @var FakeAgent */
    private $agent;
    /** @var array<string,mixed> */
    private $job;

    public function setUp(): void
    {
        $this->h = new Harness();
        $this->h->seedCatalogue();
        $this->h->seedSelection();
        $this->job = $this->h->createJob();

        $token = $this->h->container->jobs()->issueBootstrapToken((int) $this->job['id'], 1800, 'x.php');
        $this->job = $this->h->container->jobs()->require((int) $this->job['id']);

        $this->agent = new FakeAgent($this->h->container, (string) $this->job['uuid'], $token);
        $this->agent->handshake();
        $this->agent->fetchJob();
    }

    public function tearDown(): void
    {
        $this->h->cleanup();
    }

    private function themeHandle(): string
    {
        return 'tv:' . $this->h->ids['version'];
    }

    public function testAGrantYieldsTheRealPackage(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle());
        $this->assertSame(200, $grant->status());

        $body = $grant->decoded();
        $this->assertSame($this->themeHandle(), $body['asset']['handle']);
        $this->assertTrue(strlen((string) $body['asset']['sha256']) === 64);

        $download = $this->agent->download((string) $body['grant']['url']);
        $this->assertSame(200, $download->status());
        $this->assertSame((string) $body['asset']['sha256'], $download->headers()['X-WTP-Sha256']);
    }

    public function testTheStreamedBytesMatchTheAdvertisedChecksum(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();

        // The response is a stream, so the bytes are pulled through the same
        // Storage::stream() the controller uses rather than captured from the
        // output buffer it deliberately flushes past.
        $version = $this->h->container->catalog()->findVersion($this->h->ids['version']);
        $sink = fopen('php://temp', 'w+b');
        $written = $this->h->container->storage()->stream((string) $version['source_ref'], $sink);

        rewind($sink);
        $bytes = (string) stream_get_contents($sink);
        fclose($sink);

        $this->assertSame($grant['asset']['sha256'], hash('sha256', $bytes));
        $this->assertSame((int) $grant['asset']['size_bytes'], strlen($bytes));
        $this->assertSame(strlen($bytes), $written);

        // And the response itself advertises exactly those bytes.
        $download = $this->agent->download((string) $grant['grant']['url']);
        $this->assertSame((string) strlen($bytes), $download->headers()['Content-Length']);
        $this->assertTrue($download->isStream());
    }

    public function testAnAssetOutsideThePlanIsNotGrantable(): void
    {
        // A second demo's asset: real, and none of this job's business.
        $otherDemo = $this->h->container->catalog()->createDemo($this->h->ids['theme'], [
            'name' => 'Other',
            'slug' => 'other',
        ]);
        $stored = $this->h->storeFixture('demos/other-content.xml', '<rss></rss>');
        $otherAsset = $this->h->container->catalog()->createAsset($otherDemo, [
            'role' => 'content',
            'filename' => 'other-content.xml',
            'storage_path' => $stored['path'],
            'size_bytes' => $stored['size'],
            'checksum_sha256' => $stored['sha256'],
        ]);

        $response = $this->agent->requestGrant('da:' . $otherAsset);

        // 404, not 403: an agent has no business learning the asset exists.
        $this->assertSame(404, $response->status());
    }

    public function testAMalformedHandleIsRejected(): void
    {
        foreach (['../../etc/passwd', 'xx:1', 'da:abc', 'da:'] as $handle) {
            $response = $this->agent->requestGrant($handle);
            $this->assertSame(404, $response->status(), 'handle: ' . $handle);
        }
    }

    public function testAGrantExpires(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $this->h->clock->advance(601);

        $this->assertSame(403, $this->agent->download((string) $grant['grant']['url'])->status());
    }

    public function testAGrantIsUseLimited(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $url = (string) $grant['grant']['url'];

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->agent->download($url)->status(), 'use ' . ($i + 1));
        }

        $exhausted = $this->agent->download($url);
        $this->assertSame(410, $exhausted->status());
        $this->assertSame('grant_exhausted', $exhausted->decoded()['error']);
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $url = (string) $grant['grant']['url'];

        $tampered = preg_replace('/sig=[a-f0-9]{64}/', 'sig=' . str_repeat('0', 64), $url);
        $this->assertSame(403, $this->agent->download((string) $tampered)->status());
    }

    public function testTheExpiryCannotBeExtended(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $url = (string) $grant['grant']['url'];

        $extended = preg_replace('/exp=\d+/', 'exp=' . ($this->h->clock->now() + 999999), $url);
        $this->assertSame(403, $this->agent->download((string) $extended)->status());
    }

    public function testAGrantCannotBeUsedByAnotherJob(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $url = (string) $grant['grant']['url'];

        // A second service, with its own job and its own signing key.
        $this->h->db->insert('tblhosting', [
            'id' => 999,
            'userid' => Harness::CLIENT_ID,
            'orderid' => Harness::ORDER_ID,
            'packageid' => Harness::PRODUCT_ID,
            'server' => Harness::SERVER_ID,
            'domain' => 'other.test',
        ]);
        $other = $this->h->container->jobs()->openFor([
            'service_id' => 999,
            'domain' => 'other.test',
            'server_id' => Harness::SERVER_ID,
        ]);

        $swapped = preg_replace(
            '/job=[0-9a-f\-]{36}/',
            'job=' . (string) $other['job']['uuid'],
            $url
        );

        $this->assertSame(403, $this->agent->download((string) $swapped)->status());
    }

    public function testGrantsAreRevokedWhenTheJobEnds(): void
    {
        $grant = $this->agent->requestGrant($this->themeHandle())->decoded();
        $url = (string) $grant['grant']['url'];

        $this->h->container->grants()->revokeForJob((int) $this->job['id']);

        $response = $this->agent->download($url);
        $this->assertSame(410, $response->status());
        $this->assertSame('grant_revoked', $response->decoded()['error']);
    }

    public function testACorruptedStoredPackageIsRefusedRatherThanServed(): void
    {
        // Somebody replaced the file on disk without updating the catalogue.
        $version = $this->h->container->catalog()->findVersion($this->h->ids['version']);
        file_put_contents(
            $this->h->storagePath . '/' . $version['source_ref'],
            'this is no longer the package that was catalogued'
        );

        $response = $this->agent->requestGrant($this->themeHandle());

        $this->assertSame(503, $response->status());
        $this->assertSame('asset_unavailable', $response->decoded()['error']);

        $events = $this->h->container->eventLog()->forJob((int) $this->job['id'], 50);
        $this->assertContains('checksum', strtolower(implode(' ', array_column($events, 'code'))));
    }

    public function testAMissingStoredPackageIsReportedNotServed(): void
    {
        $version = $this->h->container->catalog()->findVersion($this->h->ids['version']);
        unlink($this->h->storagePath . '/' . $version['source_ref']);

        $response = $this->agent->requestGrant($this->themeHandle());
        $this->assertSame(503, $response->status());
    }
}
