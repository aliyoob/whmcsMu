#!/usr/bin/env php
<?php
/**
 * Zero-dependency test runner.
 *
 * Usage:
 *   php bin/run-tests.php               run unit + integration
 *   php bin/run-tests.php unit          run one suite
 *   php bin/run-tests.php --filter=Zip  run matching classes only
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/shared/autoload.php';
require $root . '/tests/support/TestCase.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Wtp\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    foreach (['support', 'fakes', 'unit', 'integration', 'e2e'] as $dir) {
        $path = $root . '/tests/' . $dir . '/' . $rel . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

$args = array_slice($argv, 1);
$filter = null;
$suites = [];
foreach ($args as $arg) {
    if (strpos($arg, '--filter=') === 0) {
        $filter = substr($arg, 9);
    } elseif (strpos($arg, '--') !== 0) {
        $suites[] = $arg;
    }
}
if ($suites === []) {
    $suites = ['unit', 'integration'];
}

$files = [];
foreach ($suites as $suite) {
    $dir = $root . '/tests/' . $suite;
    if (!is_dir($dir)) {
        fwrite(STDERR, "No such suite: {$suite}\n");
        exit(2);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && substr($file->getFilename(), -8) === 'Test.php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$passed = 0;
$failed = 0;
$errors = [];
$started = microtime(true);

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $new = array_diff(get_declared_classes(), $before);

    foreach ($new as $class) {
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract() || !$ref->isSubclassOf(\Wtp\Tests\TestCase::class)) {
            continue;
        }
        if ($filter !== null && stripos($class, $filter) === false) {
            continue;
        }
        echo "\n\033[1m" . $ref->getShortName() . "\033[0m\n";
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (strpos($method->getName(), 'test') !== 0) {
                continue;
            }
            $instance = $ref->newInstance();
            try {
                $instance->setUp();
                $method->invoke($instance);
                $instance->tearDown();
                $passed++;
                echo "  \033[32m✓\033[0m " . self_label($method->getName()) . "\n";
            } catch (\Throwable $e) {
                try {
                    $instance->tearDown();
                } catch (\Throwable $ignored) {
                }
                $failed++;
                echo "  \033[31m✗\033[0m " . self_label($method->getName()) . "\n";
                echo "      \033[31m" . $e->getMessage() . "\033[0m\n";
                $errors[] = [
                    'test' => $ref->getShortName() . '::' . $method->getName(),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ];
            }
        }
    }
}

function self_label(string $method): string
{
    $label = preg_replace('/^test/', '', $method);
    $label = preg_replace('/(?<!^)[A-Z]/', ' $0', (string) $label);
    return strtolower(trim((string) $label));
}

$elapsed = round((microtime(true) - $started) * 1000);
echo "\n" . str_repeat('─', 60) . "\n";
if ($failed === 0) {
    echo "\033[32mOK\033[0m — {$passed} tests, " . \Wtp\Tests\TestCase::$assertions . " assertions, {$elapsed}ms\n";
    exit(0);
}
echo "\033[31mFAILURES\033[0m — {$passed} passed, {$failed} failed, {$elapsed}ms\n\n";
foreach ($errors as $e) {
    echo "  • {$e['test']}\n    {$e['message']}\n    {$e['file']}\n\n";
}
exit(1);
