<?php
namespace Wtp\Tests;

use Wtp\Shared\Http\CanonicalRequest;
use Wtp\Shared\Http\HmacSigner;
use Wtp\Shared\Support\FrozenClock;

final class HmacSignerTest extends TestCase
{
    /** @var FrozenClock */
    private $clock;
    /** @var HmacSigner */
    private $signer;

    private const SECRET = 'a-32-byte-secret-for-testing-1234';
    private const JOB = '11111111-2222-4333-8444-555555555555';

    public function setUp(): void
    {
        $this->clock = new FrozenClock(1700000000);
        $this->signer = new HmacSigner($this->clock);
    }

    public function testSignedRequestVerifies(): void
    {
        $body = '{"step":"theme.install","status":"completed"}';
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'POST', '/provisioning/v1/steps/theme.install', $body);

        $this->assertSame(self::JOB, $headers[HmacSigner::HEADER_JOB]);
        $this->assertTrue($this->signer->verify(
            self::SECRET,
            'POST',
            '/provisioning/v1/steps/theme.install',
            $body,
            $headers
        ));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $body = '{"amount":1}';
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'POST', '/v1/steps/x', $body);

        $this->assertFalse($this->signer->verify(self::SECRET, 'POST', '/v1/steps/x', '{"amount":2}', $headers));
        $this->assertSame('signature_mismatch', $this->signer->getLastFailureCode());
    }

    public function testTamperedPathIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'POST', '/v1/steps/a', '{}');
        $this->assertFalse($this->signer->verify(self::SECRET, 'POST', '/v1/steps/b', '{}', $headers));
    }

    public function testTamperedMethodIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'POST', '/v1/job', '');
        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
    }

    public function testWrongSecretIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');
        $this->assertFalse($this->signer->verify('another-32-byte-secret-abcdefghij', 'GET', '/v1/job', '', $headers));
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');
        $this->clock->advance(HmacSigner::DEFAULT_SKEW + 5);

        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
        $this->assertSame('timestamp_out_of_window', $this->signer->getLastFailureCode());
    }

    public function testFutureTimestampBeyondSkewIsRejected(): void
    {
        $future = new HmacSigner(new FrozenClock(1700000000 + 3600));
        $headers = $future->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');

        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
    }

    public function testTimestampInsideSkewIsAccepted(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');
        $this->clock->advance(HmacSigner::DEFAULT_SKEW - 1);
        $this->assertTrue($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
    }

    public function testMissingHeadersAreRejected(): void
    {
        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', []));
        $this->assertSame('missing_headers', $this->signer->getLastFailureCode());
    }

    public function testMalformedNonceIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');
        $headers[HmacSigner::HEADER_NONCE] = 'not-hex!!';
        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
        $this->assertSame('bad_nonce', $this->signer->getLastFailureCode());
    }

    public function testUnknownSignatureSchemeIsRejected(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job', '');
        $headers[HmacSigner::HEADER_SIGNATURE] = 'v9=' . str_repeat('a', 64);
        $this->assertFalse($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
        $this->assertSame('bad_scheme', $this->signer->getLastFailureCode());
    }

    public function testHeadersAreCaseInsensitive(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'POST', '/v1/events', '[]');
        $lowered = [];
        foreach ($headers as $k => $v) {
            $lowered[strtolower($k)] = $v;
        }
        $this->assertTrue($this->signer->verify(self::SECRET, 'POST', '/v1/events', '[]', $lowered));
    }

    public function testQueryStringIsNotPartOfTheSignature(): void
    {
        $headers = $this->signer->sign(self::SECRET, self::JOB, 'GET', '/v1/job?x=1', '');
        $this->assertTrue($this->signer->verify(self::SECRET, 'GET', '/v1/job', '', $headers));
    }

    public function testCanonicalStringShape(): void
    {
        $canonical = CanonicalRequest::build('post', 'v1/job', 123, 'abc', 'hello');
        $expected = "WTP1\nPOST\n/v1/job\n123\nabc\n" . hash('sha256', 'hello');
        $this->assertSame($expected, $canonical);
    }
}
