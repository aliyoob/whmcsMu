<?php
namespace Wtp\Tests;

use Wtp\Shared\Job\ErrorClassifier;

final class ErrorClassifierTest extends TestCase
{
    /** @var ErrorClassifier */
    private $c;

    public function setUp(): void
    {
        $this->c = new ErrorClassifier();
    }

    public function testNetworkFailuresAreTransient(): void
    {
        foreach (['network_timeout', 'connection_refused', 'http_503', 'cpanel_busy'] as $code) {
            $this->assertSame(ErrorClassifier::RETRY, $this->c->classify($code), $code);
        }
    }

    public function testIntegrityFailuresArePermanent(): void
    {
        foreach (['checksum_mismatch', 'zip_slip', 'theme_not_found', 'demo_not_found', 'invalid_token'] as $code) {
            $this->assertSame(ErrorClassifier::FAIL, $this->c->classify($code), $code);
        }
    }

    public function testQuotaAndCredentialFailuresNeedAHuman(): void
    {
        foreach (['disk_quota_exceeded', 'cpanel_auth_failed', 'license_invalid', 'master_key_missing'] as $code) {
            $this->assertSame(ErrorClassifier::MANUAL, $this->c->classify($code), $code);
        }
    }

    public function testHttpStatusShorthand(): void
    {
        $this->assertSame(ErrorClassifier::RETRY, $this->c->classify('http_429'));
        $this->assertSame(ErrorClassifier::RETRY, $this->c->classify('http_408'));
        $this->assertSame(ErrorClassifier::RETRY, $this->c->classify('http_599'));
        $this->assertSame(ErrorClassifier::FAIL, $this->c->classify('http_404'));
        $this->assertSame(ErrorClassifier::FAIL, $this->c->classify('http_401'));
    }

    public function testMessageHeuristicsCatchUnlabelledTimeouts(): void
    {
        $this->assertSame(
            ErrorClassifier::RETRY,
            $this->c->classify('weird_code', 'cURL error 28: Operation timed out after 30000 ms')
        );
    }

    public function testUnknownCodesAreRetriedBoundedly(): void
    {
        $this->assertSame(ErrorClassifier::RETRY, $this->c->classify('something_new'));
        $this->assertTrue($this->c->shouldRetry('something_new', 1, 5));
        $this->assertFalse($this->c->shouldRetry('something_new', 5, 5), 'attempt cap must stop it');
    }

    public function testPermanentErrorsAreNeverRetriedEvenOnFirstAttempt(): void
    {
        $this->assertFalse($this->c->shouldRetry('checksum_mismatch', 1, 5));
    }

    public function testManualErrorsAreNotAutoRetried(): void
    {
        $this->assertFalse($this->c->shouldRetry('disk_quota_exceeded', 1, 5));
    }

    public function testClassificationIsCaseInsensitive(): void
    {
        $this->assertSame(ErrorClassifier::FAIL, $this->c->classify('CHECKSUM_MISMATCH'));
    }
}
