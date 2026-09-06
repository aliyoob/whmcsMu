<?php
/**
 * Widget import.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Wtp\Shared\Support\Json;

/**
 * Imports a widget export in the format the "Widget Importer & Exporter"
 * plugin produces, which is the de facto standard demo authors ship.
 *
 *   { "sidebar-1": { "text-2": { "title": "...", "text": "..." } } }
 *
 * A sidebar the current theme does not register is imported into
 * `wp_inactive_widgets` rather than dropped, so nothing the customer paid for
 * silently disappears.
 */
final class Wtp_Import_Widget_Importer implements Wtp_Importer
{
    public function key(): string
    {
        return 'widgets';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function supports(array $plan, array $step): bool
    {
        return isset($step['asset']) && is_array($step['asset']);
    }

    public function run(Wtp_Import_Context $context): Wtp_Import_Result
    {
        $asset = $context->asset();
        if ($asset === null) {
            return Wtp_Import_Result::skipped('This demo ships no widget export');
        }
        if ($context->alreadyDone()) {
            return Wtp_Import_Result::done(['repeated' => true]);
        }

        try {
            $file = $context->downloader()->fetch($asset);
        } catch (Wtp_Security_Exception $e) {
            return Wtp_Import_Result::failed($e->errorCode(), $e->getMessage());
        }

        $raw = (string) @file_get_contents($file);
        if ($raw === '') {
            return Wtp_Import_Result::failed('widgets_unreadable', 'The widget export is empty');
        }

        $data = Json::tryDecode($raw, []);
        if ($data === []) {
            return Wtp_Import_Result::failed('widgets_invalid', 'The widget export is not valid JSON');
        }

        $available = $this->availableWidgets();
        $sidebars = get_option('sidebars_widgets', []);
        if (!is_array($sidebars)) {
            $sidebars = [];
        }

        $imported = 0;
        $movedToInactive = 0;

        foreach ($data as $sidebarId => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            $sidebarId = (string) $sidebarId;

            $target = $sidebarId;
            if (!$this->sidebarExists($sidebarId)) {
                $target = 'wp_inactive_widgets';
                $movedToInactive++;
            }

            foreach ($widgets as $instanceId => $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                $baseId = $this->baseId((string) $instanceId);
                if ($baseId === '' || !isset($available[$baseId])) {
                    continue;
                }

                $newInstanceId = $this->nextInstanceId($baseId);
                $optionName = 'widget_' . $baseId;

                $settings = get_option($optionName, []);
                if (!is_array($settings)) {
                    $settings = [];
                }
                $settings[$newInstanceId] = $instance;
                $settings['_multiwidget'] = 1;
                update_option($optionName, $settings);

                if (!isset($sidebars[$target]) || !is_array($sidebars[$target])) {
                    $sidebars[$target] = [];
                }
                $sidebars[$target][] = $baseId . '-' . $newInstanceId;
                $imported++;
            }
        }

        update_option('sidebars_widgets', $sidebars);
        $context->markDone('', ['widgets' => $imported]);

        $context->logger()->info('Imported widgets', [
            'widgets' => $imported,
            'sidebars_missing' => $movedToInactive,
        ]);

        return Wtp_Import_Result::done([
            'widgets' => $imported,
            'sidebars_missing' => $movedToInactive,
        ]);
    }

    /** @return array<string,bool> registered widget base ids */
    private function availableWidgets(): array
    {
        global $wp_registered_widget_controls;

        $available = [];
        foreach ((array) $wp_registered_widget_controls as $widget) {
            if (!empty($widget['id_base'])) {
                $available[(string) $widget['id_base']] = true;
            }
        }
        return $available;
    }

    private function sidebarExists(string $sidebarId): bool
    {
        global $wp_registered_sidebars;
        return isset($wp_registered_sidebars[$sidebarId]);
    }

    /** "text-2" -> "text" */
    private function baseId(string $instanceId): string
    {
        $position = strrpos($instanceId, '-');
        return $position === false ? $instanceId : substr($instanceId, 0, $position);
    }

    /** The next free numeric index for a widget type. */
    private function nextInstanceId(string $baseId): int
    {
        $settings = get_option('widget_' . $baseId, []);
        if (!is_array($settings) || $settings === []) {
            return 2; // WordPress itself starts multi-widgets at 2
        }
        $numeric = array_filter(array_keys($settings), 'is_numeric');
        return $numeric === [] ? 2 : ((int) max($numeric) + 1);
    }
}
