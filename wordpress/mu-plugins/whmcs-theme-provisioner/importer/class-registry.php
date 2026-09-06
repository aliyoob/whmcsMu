<?php
/**
 * Importer lookup.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Holds the built-in importers and anything a site or a theme adds through the
 * `wtp_importers` filter.
 */
final class Wtp_Import_Registry
{
    /** @var Wtp_Importer[]|null */
    private $importers;

    /** @return Wtp_Importer[] */
    public function all(): array
    {
        if ($this->importers !== null) {
            return $this->importers;
        }

        $builtIn = [
            new Wtp_Import_Theme_Installer(),
            new Wtp_Import_Plugin_Installer(),
            new Wtp_Import_Content_Importer(),
            new Wtp_Import_Widget_Importer(),
            new Wtp_Import_Customizer_Importer(),
            new Wtp_Import_Elementor_Importer(),
            new Wtp_Import_Options_Importer(),
            new Wtp_Import_Finalizer(),
        ];

        /**
         * Filter the importers available to the provisioning agent.
         *
         * @param Wtp_Importer[] $builtIn
         */
        $filtered = apply_filters('wtp_importers', $builtIn);

        $valid = [];
        foreach (is_array($filtered) ? $filtered : $builtIn as $importer) {
            if ($importer instanceof Wtp_Importer) {
                $valid[] = $importer;
            }
        }

        $this->importers = $valid;
        return $this->importers;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $step
     */
    public function find(array $plan, array $step): ?Wtp_Importer
    {
        $wanted = (string) ($step['importer'] ?? '');
        if ($wanted === '') {
            return null;
        }

        foreach ($this->all() as $importer) {
            if ($importer->key() === $wanted && $importer->supports($plan, $step)) {
                return $importer;
            }
        }
        return null;
    }
}
