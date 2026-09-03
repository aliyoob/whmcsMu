<?php
namespace Wtp\Addon\Job;

use Wtp\Addon\Catalog\PlanBuilder;
use Wtp\Addon\Catalog\ProductSettingsRepository;
use Wtp\Shared\Plan\ImportPlan;
use Wtp\Shared\Support\Json;

/**
 * Resolves a job into the plan the agent should execute.
 *
 * Extracted from the controllers so that "what may this job fetch?" and "what
 * shall this job do?" are answered by exactly the same code — an asset is
 * downloadable if and only if it appears in the plan this resolver produces.
 */
final class PlanResolver
{
    /** @var SelectionRepository */
    private $selections;
    /** @var ProductSettingsRepository */
    private $productSettings;
    /** @var PlanBuilder */
    private $builder;
    /** @var int */
    private $httpTimeout;

    public function __construct(
        SelectionRepository $selections,
        ProductSettingsRepository $productSettings,
        PlanBuilder $builder,
        int $httpTimeout = 30
    ) {
        $this->selections = $selections;
        $this->productSettings = $productSettings;
        $this->builder = $builder;
        $this->httpTimeout = $httpTimeout;
    }

    /** @param array<string,mixed> $job */
    public function forJob(array $job): ImportPlan
    {
        $productId = isset($job['product_id']) && $job['product_id'] !== null ? (int) $job['product_id'] : 0;

        $resolved = $this->selections->resolveOrDefault(
            [
                'service_id' => (int) $job['service_id'],
                'order_id' => $job['order_id'] ?? null,
                'client_id' => $job['client_id'] ?? null,
                'product_id' => $productId,
                'domain' => $job['domain'] ?? '',
            ],
            $productId > 0 ? $this->productSettings->forProduct($productId) : null
        );

        $payload = Json::tryDecode($job['payload'] ?? null, []);
        $site = isset($payload['site']) && is_array($payload['site']) ? $payload['site'] : [];
        if (!isset($site['title']) || $site['title'] === '') {
            $site['title'] = (string) ($job['domain'] ?? '');
        }

        return $this->builder->build(
            $resolved['theme'],
            $resolved['version'],
            $resolved['demo'],
            $site,
            ['http_timeout' => $this->httpTimeout]
        );
    }

    /**
     * Every asset handle the given job is allowed to fetch.
     *
     * @param array<string,mixed> $job
     * @return string[]
     */
    public function allowedAssetHandles(array $job): array
    {
        try {
            $plan = $this->forJob($job);
        } catch (\Throwable $e) {
            return [];
        }

        $handles = [];

        $themePackage = $plan->theme()['package'];
        if (($themePackage['kind'] ?? '') === 'asset') {
            $handles[] = (string) $themePackage['asset_id'];
        }
        foreach ($plan->plugins() as $plugin) {
            if (($plugin['kind'] ?? '') === 'asset') {
                $handles[] = (string) $plugin['asset_id'];
            }
        }
        foreach ($plan->steps() as $step) {
            if (isset($step['asset']['asset_id'])) {
                $handles[] = (string) $step['asset']['asset_id'];
            }
        }

        return array_values(array_unique($handles));
    }
}
