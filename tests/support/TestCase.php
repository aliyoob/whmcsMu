<?php
namespace Wtp\Tests;

/**
 * Minimal, dependency-free test base class.
 *
 * A WHMCS module repository cannot assume composer is available on the target
 * machine, and pulling PHPUnit in just to assert equality would make the test
 * suite unrunnable exactly where it is most useful (on the production box,
 * after a deploy). The assertion surface below is intentionally PHPUnit-shaped
 * so the suite can be ported later without rewriting the tests.
 */
abstract class TestCase
{
    /** @var int */
    public static $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    // -- assertions ---------------------------------------------------------

    /** @param mixed $expected @param mixed $actual */
    protected function assertSame($expected, $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed($message ?: sprintf(
                "Failed asserting that %s is identical to %s",
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    /** @param mixed $expected @param mixed $actual */
    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected != $actual) {
            throw new AssertionFailed($message ?: sprintf(
                "Failed asserting that %s equals %s",
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    protected function assertTrue($value, string $message = ''): void
    {
        self::$assertions++;
        if ($value !== true) {
            throw new AssertionFailed($message ?: 'Failed asserting that ' . self::export($value) . ' is true');
        }
    }

    protected function assertFalse($value, string $message = ''): void
    {
        self::$assertions++;
        if ($value !== false) {
            throw new AssertionFailed($message ?: 'Failed asserting that ' . self::export($value) . ' is false');
        }
    }

    protected function assertNull($value, string $message = ''): void
    {
        self::$assertions++;
        if ($value !== null) {
            throw new AssertionFailed($message ?: 'Failed asserting that ' . self::export($value) . ' is null');
        }
    }

    protected function assertNotNull($value, string $message = ''): void
    {
        self::$assertions++;
        if ($value === null) {
            throw new AssertionFailed($message ?: 'Failed asserting that value is not null');
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailed($message ?: sprintf(
                "Failed asserting that %s contains %s",
                self::export($haystack),
                self::export($needle)
            ));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        self::$assertions++;
        if (strpos($haystack, $needle) !== false) {
            throw new AssertionFailed($message ?: sprintf(
                "Failed asserting that %s does not contain %s",
                self::export($haystack),
                self::export($needle)
            ));
        }
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        self::$assertions++;
        if (count($actual) !== $expected) {
            throw new AssertionFailed($message ?: sprintf(
                'Failed asserting that array has %d elements (found %d)',
                $expected,
                count($actual)
            ));
        }
    }

    protected function assertGreaterThan($bound, $actual, string $message = ''): void
    {
        self::$assertions++;
        if (!($actual > $bound)) {
            throw new AssertionFailed($message ?: sprintf(
                'Failed asserting that %s is greater than %s',
                self::export($actual),
                self::export($bound)
            ));
        }
    }

    protected function assertLessThanOrEqual($bound, $actual, string $message = ''): void
    {
        self::$assertions++;
        if (!($actual <= $bound)) {
            throw new AssertionFailed($message ?: sprintf(
                'Failed asserting that %s is <= %s',
                self::export($actual),
                self::export($bound)
            ));
        }
    }

    /**
     * @param class-string<\Throwable> $expectedClass
     */
    protected function assertThrows(string $expectedClass, callable $fn, ?string $messageContains = null): \Throwable
    {
        self::$assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $expectedClass)) {
                throw new AssertionFailed(sprintf(
                    'Expected %s but got %s: %s',
                    $expectedClass,
                    get_class($e),
                    $e->getMessage()
                ));
            }
            if ($messageContains !== null && strpos($e->getMessage(), $messageContains) === false) {
                throw new AssertionFailed(sprintf(
                    'Exception message %s does not contain %s',
                    self::export($e->getMessage()),
                    self::export($messageContains)
                ));
            }
            return $e;
        }
        throw new AssertionFailed('Expected ' . $expectedClass . ' but nothing was thrown');
    }

    protected function assertDoesNotThrow(callable $fn, string $message = ''): void
    {
        self::$assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            throw new AssertionFailed(($message ? $message . ': ' : '')
                . 'Unexpected ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** @param mixed $value */
    private static function export($value): string
    {
        if (is_string($value)) {
            return "'" . (strlen($value) > 120 ? substr($value, 0, 120) . '…' : $value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')' . json_encode(array_slice($value, 0, 8));
        }
        if (is_object($value)) {
            return get_class($value);
        }
        return (string) $value;
    }
}

class AssertionFailed extends \RuntimeException
{
}
