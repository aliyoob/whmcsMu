<?php
namespace Wtp\Tests;

use Wtp\Shared\Job\Backoff;

final class BackoffTest extends TestCase
{
    private function deterministic(float $r = 0.5): Backoff
    {
        return new Backoff(15, 600, 0.2, static function () use ($r): float {
            return $r;
        });
    }

    public function testGrowsExponentially(): void
    {
        $b = new Backoff(15, 600, 0.0);
        $this->assertSame(15, $b->delayFor(1));
        $this->assertSame(30, $b->delayFor(2));
        $this->assertSame(60, $b->delayFor(3));
        $this->assertSame(120, $b->delayFor(4));
        $this->assertSame(240, $b->delayFor(5));
        $this->assertSame(480, $b->delayFor(6));
    }

    public function testIsCapped(): void
    {
        $b = new Backoff(15, 600, 0.0);
        $this->assertSame(600, $b->delayFor(10));
        $this->assertSame(600, $b->delayFor(50));
    }

    public function testDoesNotOverflowOnAbsurdAttemptCounts(): void
    {
        $b = new Backoff(15, 600, 0.0);
        $this->assertSame(600, $b->delayFor(PHP_INT_MAX));
        $this->assertGreaterThan(0, $b->delayFor(PHP_INT_MAX));
    }

    public function testAttemptZeroAndNegativeAreTreatedAsFirstAttempt(): void
    {
        $b = new Backoff(15, 600, 0.0);
        $this->assertSame(15, $b->delayFor(0));
        $this->assertSame(15, $b->delayFor(-7));
    }

    public function testJitterStaysWithinBounds(): void
    {
        $low = $this->deterministic(0.0)->delayFor(3);   // 60 * 0.8
        $high = $this->deterministic(1.0)->delayFor(3);  // 60 * 1.2
        $mid = $this->deterministic(0.5)->delayFor(3);   // 60 * 1.0

        $this->assertSame(48, $low);
        $this->assertSame(72, $high);
        $this->assertSame(60, $mid);
    }

    public function testJitterNeverProducesZeroOrNegativeDelay(): void
    {
        $b = new Backoff(1, 10, 1.0, static function (): float {
            return 0.0;
        });
        $this->assertGreaterThan(0, $b->delayFor(1));
    }

    public function testNextAttemptAtAddsToNow(): void
    {
        $b = new Backoff(15, 600, 0.0);
        $this->assertSame(1700000015, $b->nextAttemptAt(1700000000, 1));
    }

    public function testRandomJitterIsBounded(): void
    {
        $b = new Backoff(15, 600, 0.2);
        for ($i = 0; $i < 200; $i++) {
            $d = $b->delayFor(4); // base 120
            $this->assertGreaterThan(95, $d);
            $this->assertLessThanOrEqual(145, $d);
        }
    }
}
