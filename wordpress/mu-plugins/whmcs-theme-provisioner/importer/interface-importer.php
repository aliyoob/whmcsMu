<?php
/**
 * The extension point for demo importers.
 *
 * @package WTP\Agent
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One kind of import work.
 *
 * Implementations must be **resumable**: `run()` is called with a time budget
 * and may return `progress()` with an opaque cursor as many times as it needs.
 * They must also be **idempotent**: the same step may be run again after a
 * crash, a retry, or a site restore, and must not duplicate content.
 *
 * Registering a new importer is one filter call:
 *
 *     add_filter('wtp_importers', function (array $importers): array {
 *         $importers[] = new My_Importer();
 *         return $importers;
 *     });
 */
interface Wtp_Importer
{
    /** The plan's `importer` value this class handles, e.g. "content". */
    public function key(): string;

    /**
     * @param array<string,mixed> $plan the whole provisioning plan
     * @param array<string,mixed> $step the step being considered
     */
    public function supports(array $plan, array $step): bool;

    public function run(Wtp_Import_Context $context): Wtp_Import_Result;
}
