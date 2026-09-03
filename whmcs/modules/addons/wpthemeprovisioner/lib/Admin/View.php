<?php
namespace Wtp\Addon\Admin;

/**
 * A deliberately tiny PHP-template renderer for the admin screens.
 *
 * WHMCS's admin area is not Smarty-templated for addon modules, so the choice
 * is between building HTML in PHP strings or using plain PHP templates. Plain
 * templates win: they keep escaping visible at the point of output, which is
 * what actually prevents XSS in an interface that renders operator-supplied
 * theme names and customer-supplied domains.
 */
final class View
{
    /** @var string */
    private $directory;
    /** @var array<string,mixed> */
    private $shared = [];

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    /** @param array<string,mixed> $values */
    public function share(array $values): void
    {
        $this->shared = array_merge($this->shared, $values);
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = $this->directory . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $template) . '.php';
        if (!is_file($path)) {
            return '<div class="errorbox">Missing admin template: '
                . self::e($template) . '</div>';
        }

        $view = $this;
        extract(array_merge($this->shared, $data), EXTR_SKIP);
        unset($data);

        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public function renderInLayout(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->render($template, $data);
        return $this->render($layout, array_merge($data, ['content' => $content]));
    }

    /** Escape for HTML text and attribute contexts. */
    public static function e($value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        if (is_array($value)) {
            return '[array]';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape a URL, refusing anything that is not http(s) or relative. */
    public static function url(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $trimmed) || $trimmed[0] === '/' || $trimmed[0] === '?') {
            return self::e($trimmed);
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed)) {
            return ''; // javascript:, data:, and anything else exotic
        }
        return self::e($trimmed);
    }

    /** Human-friendly relative time, e.g. "4 minutes ago". */
    public static function ago(?string $sqlDatetime, ?int $now = null): string
    {
        if ($sqlDatetime === null || $sqlDatetime === '') {
            return '—';
        }
        $timestamp = strtotime($sqlDatetime . ' UTC');
        if ($timestamp === false) {
            return self::e($sqlDatetime);
        }
        $delta = ($now ?? time()) - $timestamp;
        if ($delta < 0) {
            return 'in ' . self::duration(-$delta);
        }
        if ($delta < 5) {
            return 'just now';
        }
        return self::duration($delta) . ' ago';
    }

    public static function duration(int $seconds): string
    {
        $units = [
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
            ['second', 1],
        ];
        foreach ($units as $unit) {
            if ($seconds >= $unit[1]) {
                $value = (int) floor($seconds / $unit[1]);
                return $value . ' ' . $unit[0] . ($value === 1 ? '' : 's');
            }
        }
        return '0 seconds';
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return ($index === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$index];
    }

    /** Bootstrap-ish label class for a job or step status. */
    public static function tone(string $status): string
    {
        switch ($status) {
            case 'completed':
                return 'success';
            case 'failed':
                return 'danger';
            case 'needs_attention':
            case 'skipped':
                return 'warning';
            case 'cancelled':
                return 'default';
            case 'running':
            case 'importing':
            case 'verifying':
            case 'awaiting_agent':
                return 'info';
            default:
                return 'default';
        }
    }
}
