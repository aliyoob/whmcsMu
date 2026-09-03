<?php
namespace Wtp\Addon\Catalog;

use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;

/** Which WHMCS products offer theme/demo selection, and with what constraints. */
final class ProductSettingsRepository
{
    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /** @return array<string,mixed>|null */
    public function forProduct(int $productId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM mod_wtp_product_settings WHERE product_id = :p',
            ['p' => $productId]
        );
        if ($row === null) {
            return null;
        }
        $row['allowed_theme_ids'] = $row['allowed_theme_ids'] === null || $row['allowed_theme_ids'] === ''
            ? null
            : array_values(array_filter(array_map('intval', Json::tryDecode((string) $row['allowed_theme_ids'], []))));
        return $row;
    }

    public function isEnabled(int $productId): bool
    {
        $row = $this->forProduct($productId);
        return $row !== null && (int) $row['enabled'] === 1;
    }

    public function isRequired(int $productId): bool
    {
        $row = $this->forProduct($productId);
        return $row !== null && (int) $row['enabled'] === 1 && (int) $row['selection_required'] === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM mod_wtp_product_settings ORDER BY product_id ASC');
    }

    /** @return int[] product ids with selection enabled */
    public function enabledProductIds(): array
    {
        $rows = $this->db->select('SELECT product_id FROM mod_wtp_product_settings WHERE enabled = 1');
        return array_map(static function (array $r): int {
            return (int) $r['product_id'];
        }, $rows);
    }

    /** @param array<string,mixed> $input */
    public function save(int $productId, array $input): void
    {
        $allowed = $input['allowed_theme_ids'] ?? null;
        if (is_array($allowed)) {
            $allowed = array_values(array_unique(array_filter(array_map('intval', $allowed))));
            $allowed = $allowed === [] ? null : Json::encode($allowed);
        } elseif (is_string($allowed) && trim($allowed) !== '') {
            $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $allowed)))));
            $allowed = $ids === [] ? null : Json::encode($ids);
        } else {
            $allowed = null;
        }

        $data = [
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'selection_required' => !empty($input['selection_required']) ? 1 : 0,
            'allowed_theme_ids' => $allowed,
            'default_theme_id' => !empty($input['default_theme_id']) ? (int) $input['default_theme_id'] : null,
            'default_demo_id' => !empty($input['default_demo_id']) ? (int) $input['default_demo_id'] : null,
            'updated_at' => $this->clock->nowSql(),
        ];

        $existing = $this->db->selectOne(
            'SELECT id FROM mod_wtp_product_settings WHERE product_id = :p',
            ['p' => $productId]
        );

        if ($existing === null) {
            $data['product_id'] = $productId;
            $data['created_at'] = $this->clock->nowSql();
            $this->db->insert('mod_wtp_product_settings', $data);
            return;
        }
        $this->db->update('mod_wtp_product_settings', $data, ['product_id' => $productId]);
    }

    /**
     * The themes a customer may pick for this product: the product's allow-list
     * intersected with the globally active catalogue.
     *
     * @return array<int,array<string,mixed>>
     */
    public function availableThemes(int $productId, CatalogRepository $catalog): array
    {
        $settings = $this->forProduct($productId);
        $themes = $catalog->listThemes(true);
        if ($settings === null || $settings['allowed_theme_ids'] === null) {
            return $themes;
        }
        $allowed = $settings['allowed_theme_ids'];
        return array_values(array_filter($themes, static function (array $t) use ($allowed): bool {
            return in_array((int) $t['id'], $allowed, true);
        }));
    }

    public function isThemeAllowed(int $productId, int $themeId): bool
    {
        $settings = $this->forProduct($productId);
        if ($settings === null) {
            return false;
        }
        if ($settings['allowed_theme_ids'] === null) {
            return true;
        }
        return in_array($themeId, $settings['allowed_theme_ids'], true);
    }
}
