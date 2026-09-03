<?php
namespace Wtp\Addon\Job;

use Wtp\Addon\Catalog\CatalogException;
use Wtp\Addon\Catalog\CatalogRepository;
use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;

/**
 * The binding between a WHMCS service and the theme/demo the customer chose.
 *
 * One row per service, created at checkout (or from the product defaults for
 * admin/API orders). It is locked as soon as provisioning starts so a later
 * catalogue edit can never change what a running import is doing.
 */
final class SelectionRepository
{
    public const SOURCE_CLIENT = 'client';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_API = 'api';

    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;
    /** @var CatalogRepository */
    private $catalog;

    public function __construct(Db $db, Clock $clock, CatalogRepository $catalog)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->catalog = $catalog;
    }

    /** @return array<string,mixed>|null */
    public function forService(int $serviceId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM mod_wtp_service_selection WHERE service_id = :s',
            ['s' => $serviceId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM mod_wtp_service_selection WHERE order_id = :o ORDER BY id ASC',
            ['o' => $orderId]
        );
    }

    /**
     * Store (or replace) a selection. Validates against the live catalogue, so
     * a forged cart POST cannot bind a service to an inactive or foreign demo.
     *
     * @param array<string,mixed> $context service_id, order_id, client_id, product_id, domain
     * @throws CatalogException with a machine-readable reason code
     */
    public function record(array $context, int $themeId, int $demoId, string $source = self::SOURCE_CLIENT): int
    {
        $serviceId = (int) ($context['service_id'] ?? 0);
        if ($serviceId <= 0) {
            throw new CatalogException('selection_missing_service');
        }

        $resolved = $this->catalog->resolveSelection($themeId, $demoId);

        $existing = $this->forService($serviceId);
        if ($existing !== null && (int) $existing['locked'] === 1) {
            throw new CatalogException('selection_locked');
        }

        $data = [
            'order_id' => isset($context['order_id']) ? (int) $context['order_id'] : null,
            'client_id' => isset($context['client_id']) ? (int) $context['client_id'] : null,
            'product_id' => isset($context['product_id']) ? (int) $context['product_id'] : null,
            'domain' => isset($context['domain']) ? substr((string) $context['domain'], 0, 191) : null,
            'theme_id' => (int) $resolved['theme']['id'],
            'theme_version_id' => (int) $resolved['version']['id'],
            'demo_id' => (int) $resolved['demo']['id'],
            'source' => in_array($source, [self::SOURCE_CLIENT, self::SOURCE_ADMIN, self::SOURCE_DEFAULT, self::SOURCE_API], true)
                ? $source : self::SOURCE_CLIENT,
            'updated_at' => $this->clock->nowSql(),
        ];

        if ($existing !== null) {
            $this->db->update('mod_wtp_service_selection', $data, ['service_id' => $serviceId]);
            return (int) $existing['id'];
        }

        $data['service_id'] = $serviceId;
        $data['selected_at'] = $this->clock->nowSql();
        return $this->db->insert('mod_wtp_service_selection', $data);
    }

    /** Freeze the selection once a provisioning job starts consuming it. */
    public function lock(int $serviceId): void
    {
        $this->db->update('mod_wtp_service_selection', [
            'locked' => 1,
            'updated_at' => $this->clock->nowSql(),
        ], ['service_id' => $serviceId]);
    }

    public function unlock(int $serviceId): void
    {
        $this->db->update('mod_wtp_service_selection', [
            'locked' => 0,
            'updated_at' => $this->clock->nowSql(),
        ], ['service_id' => $serviceId]);
    }

    public function delete(int $serviceId): void
    {
        $this->db->delete('mod_wtp_service_selection', ['service_id' => $serviceId]);
    }

    /**
     * Resolve the selection for a service, falling back to the product's
     * configured defaults when there is none (admin orders, API orders,
     * imported services).
     *
     * @param array<string,mixed> $context
     * @return array{theme:array<string,mixed>,demo:array<string,mixed>,version:array<string,mixed>,source:string}
     * @throws CatalogException
     */
    public function resolveOrDefault(array $context, ?array $productDefaults): array
    {
        $serviceId = (int) ($context['service_id'] ?? 0);
        $row = $serviceId > 0 ? $this->forService($serviceId) : null;

        if ($row !== null) {
            $resolved = $this->catalog->resolveSelection(
                (int) $row['theme_id'],
                (int) $row['demo_id'],
                $row['theme_version_id'] !== null ? (int) $row['theme_version_id'] : null
            );
            $resolved['source'] = (string) $row['source'];
            return $resolved;
        }

        $themeId = (int) ($productDefaults['default_theme_id'] ?? 0);
        $demoId = (int) ($productDefaults['default_demo_id'] ?? 0);
        if ($themeId <= 0 || $demoId <= 0) {
            throw new CatalogException('selection_missing');
        }

        $resolved = $this->catalog->resolveSelection($themeId, $demoId);
        $this->record($context, $themeId, $demoId, self::SOURCE_DEFAULT);
        $resolved['source'] = self::SOURCE_DEFAULT;
        return $resolved;
    }
}
