<?php
namespace Wtp\Addon\Api;

use Wtp\Addon\Container;
use Wtp\Shared\Support\Str;

/**
 * POST /assets/{handle}/grant — mint a signed, single-use download URL.
 *
 * Authorisation is by *reachability*: the handle must appear in the plan this
 * job would be served right now. An agent therefore cannot fetch a package
 * belonging to a theme its customer did not buy, even though it holds a valid
 * signing secret.
 */
final class AssetController
{
    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function grant(Request $request, string $handle): Response
    {
        $auth = $this->container->authenticator()->authenticateAgent($request);
        $job = $auth['job'];
        $jobId = (int) $job['id'];

        $handle = urldecode($handle);
        if (!preg_match('/^(tv|da):([0-9]{1,10})$/', $handle, $m)) {
            throw ApiException::notFound('Malformed asset handle');
        }
        $kind = $m[1];
        $id = (int) $m[2];

        $descriptor = $this->authorise($job, $handle, $kind, $id);

        $config = $this->container->config();
        $grantId = $this->container->grants()->issue(
            $jobId,
            $descriptor,
            $config->getInt('download_ttl', 600),
            $config->getInt('download_max_uses', 3)
        );

        $url = $this->container->signedUrls()->make(
            $this->container->apiBaseUrl() . '/download',
            (string) $job['uuid'],
            (string) $grantId,
            $this->container->jobs()->downloadKey($job),
            $config->getInt('download_ttl', 600)
        );

        $this->container->eventLog()->info($jobId, 'Issued a download grant', [
            'handle' => $handle,
            'grant' => $grantId,
            'filename' => $descriptor['filename'],
            'sha256' => $descriptor['sha256'],
            'size_bytes' => $descriptor['size'],
        ]);

        return Response::json([
            'success' => true,
            'grant' => [
                'id' => (string) $grantId,
                'url' => $url,
                'expires_in' => $config->getInt('download_ttl', 600),
                'max_uses' => $config->getInt('download_max_uses', 3),
            ],
            'asset' => [
                'handle' => $handle,
                'filename' => $descriptor['filename'],
                'sha256' => $descriptor['sha256'],
                'size_bytes' => $descriptor['size'],
            ],
        ], 200, ['X-WTP-Api' => Router::VERSION]);
    }

    /**
     * @param array<string,mixed> $job
     * @return array{kind:string,ref:string,sha256:string,size:int,filename:string}
     */
    private function authorise(array $job, string $handle, string $kind, int $id): array
    {
        if (!$this->handleIsInPlan($job, $handle)) {
            // Deliberately a 404, not a 403: an agent has no business learning
            // that an asset it may not have exists at all.
            throw ApiException::notFound('No such asset for this job');
        }

        $catalog = $this->container->catalog();
        $storage = $this->container->storage();

        if ($kind === 'tv') {
            $version = $catalog->findVersion($id);
            if ($version === null || $version['source_type'] !== 'storage') {
                throw ApiException::notFound('No such asset for this job');
            }
            $path = (string) $version['source_ref'];
            $checksum = (string) $version['checksum_sha256'];
            $filename = basename($path);
            $assetKind = 'theme_package';
        } else {
            $asset = $catalog->findAsset($id);
            if ($asset === null) {
                throw ApiException::notFound('No such asset for this job');
            }
            $path = (string) $asset['storage_path'];
            $checksum = (string) $asset['checksum_sha256'];
            $filename = (string) $asset['filename'];
            $assetKind = 'demo_asset';
        }

        if (!$storage->exists($path)) {
            $this->container->eventLog()->error((int) $job['id'], 'Package is missing from storage', [
                'handle' => $handle,
                'path' => $path,
            ], null, 'storage_unreadable');
            throw new ApiException(503, 'asset_unavailable', 'The package is temporarily unavailable');
        }

        // The catalogue's checksum is authoritative, but a mismatch against
        // what is actually on disk means the file was swapped — refuse rather
        // than hand the agent something we cannot vouch for.
        $actual = $storage->checksum($path);
        if (!Str::isSha256($checksum) || !hash_equals($checksum, $actual)) {
            $this->container->eventLog()->critical((int) $job['id'], 'Stored package does not match its catalogue checksum', [
                'handle' => $handle,
                'expected' => $checksum,
                'actual' => $actual,
            ], null, 'checksum_mismatch');
            throw new ApiException(503, 'asset_unavailable', 'The package failed its integrity check');
        }

        return [
            'kind' => $assetKind,
            'ref' => $path,
            'sha256' => $actual,
            'size' => $storage->size($path),
            'filename' => $filename,
        ];
    }

    /** @param array<string,mixed> $job */
    private function handleIsInPlan(array $job, string $handle): bool
    {
        return in_array($handle, $this->container->planResolver()->allowedAssetHandles($job), true);
    }
}
