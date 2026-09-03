<?php
namespace Wtp\Tests;

use Wtp\Shared\Http\SignedUrl;
use Wtp\Shared\Support\FrozenClock;

final class SignedUrlTest extends TestCase
{
    private const SECRET = 'download-secret-32-bytes-abcdefgh';
    private const JOB = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    /** @var FrozenClock */
    private $clock;
    /** @var SignedUrl */
    private $urls;

    public function setUp(): void
    {
        $this->clock = new FrozenClock(1700000000);
        $this->urls = new SignedUrl($this->clock);
    }

    /** @return array<string,string> */
    private function queryOf(string $url): array
    {
        $q = parse_url($url, PHP_URL_QUERY);
        parse_str((string) $q, $out);
        return array_map('strval', $out);
    }

    public function testRoundTrip(): void
    {
        $url = $this->urls->make('https://billing.example/provisioning/v1/download', self::JOB, '42', self::SECRET, 600);
        $this->assertContains('job=', $url);
        $this->assertTrue($this->urls->verify($this->queryOf($url), self::SECRET));
    }

    public function testExpiredUrlIsRejected(): void
    {
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 60);
        $this->clock->advance(61);
        $this->assertFalse($this->urls->verify($this->queryOf($url), self::SECRET));
    }

    public function testMinimumTtlIsEnforced(): void
    {
        // A caller asking for 1 second still gets a usable 30-second window.
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 1);
        $this->clock->advance(20);
        $this->assertTrue($this->urls->verify($this->queryOf($url), self::SECRET));
    }

    public function testGrantIdCannotBeSwapped(): void
    {
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 600);
        $q = $this->queryOf($url);
        $q['grant'] = '43';
        $this->assertFalse($this->urls->verify($q, self::SECRET));
    }

    public function testJobCannotBeSwapped(): void
    {
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 600);
        $q = $this->queryOf($url);
        $q['job'] = 'ffffffff-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $this->assertFalse($this->urls->verify($q, self::SECRET));
    }

    public function testExpiryCannotBeExtended(): void
    {
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 60);
        $q = $this->queryOf($url);
        $q['exp'] = (string) ($this->clock->now() + 86400);
        $this->assertFalse($this->urls->verify($q, self::SECRET));
    }

    public function testAnotherJobsSecretCannotVerify(): void
    {
        $url = $this->urls->make('https://billing.example/dl', self::JOB, '42', self::SECRET, 600);
        $this->assertFalse($this->urls->verify($this->queryOf($url), 'a-completely-different-secret-999'));
    }

    public function testMissingParametersAreRejected(): void
    {
        $this->assertFalse($this->urls->verify(['job' => self::JOB], self::SECRET));
        $this->assertFalse($this->urls->verify([], self::SECRET));
    }

    public function testBaseUrlWithExistingQueryIsHandled(): void
    {
        $url = $this->urls->make('https://billing.example/index.php?m=wtp', self::JOB, '7', self::SECRET, 300);
        $this->assertContains('m=wtp&job=', $url);
        $this->assertTrue($this->urls->verify($this->queryOf($url), self::SECRET));
    }
}
