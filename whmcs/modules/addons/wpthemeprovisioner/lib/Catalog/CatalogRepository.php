<?php
namespace Wtp\Addon\Catalog;

use Wtp\Addon\Support\Db;
use Wtp\Shared\Support\Clock;
use Wtp\Shared\Support\Json;
use Wtp\Shared\Support\Str;

/**
 * Reads and writes the theme/demo catalogue.
 *
 * Nothing here trusts its caller: every slug, status and JSON blob is validated
 * before it reaches the database, because these rows end up as instructions
 * executed inside a customer's WordPress installation.
 */
final class CatalogRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const SOURCE_STORAGE = 'storage';
    public const SOURCE_URL = 'url';
    public const SOURCE_WPORG = 'wporg';

    public const ASSET_ROLES = ['content', 'widgets', 'customizer', 'elementor_kit', 'plugin', 'media', 'other'];

    /** @var Db */
    private $db;
    /** @var Clock */
    private $clock;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    // ------------------------------------------------------------- themes

    /** @return array<int,array<string,mixed>> */
    public function listThemes(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM mod_wtp_themes';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return $this->db->select($sql);
    }

    /** @return array<string,mixed>|null */
    public function findTheme(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_themes WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findThemeBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_themes WHERE slug = :slug', ['slug' => $slug]);
    }

    /** @param array<string,mixed> $input */
    public function createTheme(array $input): int
    {
        $data = $this->normalizeTheme($input);
        if ($this->findThemeBySlug($data['slug']) !== null) {
            throw new CatalogException('A theme with the slug "' . $data['slug'] . '" already exists');
        }
        $data['created_at'] = $this->clock->nowSql();
        $data['updated_at'] = $this->clock->nowSql();
        return $this->db->insert('mod_wtp_themes', $data);
    }

    /** @param array<string,mixed> $input */
    public function updateTheme(int $id, array $input): void
    {
        if ($this->findTheme($id) === null) {
            throw new CatalogException('Theme not found: ' . $id);
        }
        $data = $this->normalizeTheme($input);
        $clash = $this->findThemeBySlug($data['slug']);
        if ($clash !== null && (int) $clash['id'] !== $id) {
            throw new CatalogException('Another theme already uses the slug "' . $data['slug'] . '"');
        }
        $data['updated_at'] = $this->clock->nowSql();
        $this->db->update('mod_wtp_themes', $data, ['id' => $id]);
    }

    public function setThemeStatus(int $id, string $status): void
    {
        $this->db->update('mod_wtp_themes', [
            'status' => $this->normalizeStatus($status),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $id]);
    }

    /**
     * Deleting a theme cascades to its versions, demos and assets. Services
     * that already selected it keep their selection row (and therefore their
     * provisioning history) — we never rewrite a customer's order.
     */
    public function deleteTheme(int $id): void
    {
        $inUse = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM mod_wtp_service_selection WHERE theme_id = :id',
            ['id' => $id]
        );
        if ($inUse > 0) {
            throw new CatalogException(
                'This theme is referenced by ' . $inUse . ' service(s). Deactivate it instead of deleting it.'
            );
        }
        $this->db->delete('mod_wtp_themes', ['id' => $id]);
    }

    // ----------------------------------------------------------- versions

    /** @return array<int,array<string,mixed>> */
    public function listVersions(int $themeId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM mod_wtp_theme_versions WHERE theme_id = :t';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY is_default DESC, id DESC';
        return $this->db->select($sql, ['t' => $themeId]);
    }

    /** @return array<string,mixed>|null */
    public function findVersion(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_theme_versions WHERE id = :id', ['id' => $id]);
    }

    /** The version a new order should get. @return array<string,mixed>|null */
    public function defaultVersion(int $themeId): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM mod_wtp_theme_versions
             WHERE theme_id = :t AND status = 'active' AND is_default = 1
             ORDER BY id DESC LIMIT 1",
            ['t' => $themeId]
        );
        if ($row !== null) {
            return $row;
        }
        return $this->db->selectOne(
            "SELECT * FROM mod_wtp_theme_versions
             WHERE theme_id = :t AND status = 'active'
             ORDER BY id DESC LIMIT 1",
            ['t' => $themeId]
        );
    }

    /** @param array<string,mixed> $input */
    public function createVersion(int $themeId, array $input): int
    {
        if ($this->findTheme($themeId) === null) {
            throw new CatalogException('Theme not found: ' . $themeId);
        }
        $data = $this->normalizeVersion($input);
        $data['theme_id'] = $themeId;
        $data['created_at'] = $this->clock->nowSql();
        $data['updated_at'] = $this->clock->nowSql();

        return $this->db->transaction(function () use ($data, $themeId): int {
            if (!empty($data['is_default'])) {
                $this->clearDefaultVersion($themeId);
            }
            return $this->db->insert('mod_wtp_theme_versions', $data);
        });
    }

    /** @param array<string,mixed> $input */
    public function updateVersion(int $id, array $input): void
    {
        $existing = $this->findVersion($id);
        if ($existing === null) {
            throw new CatalogException('Theme version not found: ' . $id);
        }
        $data = $this->normalizeVersion($input);
        $data['updated_at'] = $this->clock->nowSql();

        $this->db->transaction(function () use ($data, $id, $existing): void {
            if (!empty($data['is_default'])) {
                $this->clearDefaultVersion((int) $existing['theme_id']);
            }
            $this->db->update('mod_wtp_theme_versions', $data, ['id' => $id]);
        });
    }

    public function deleteVersion(int $id): void
    {
        $inUse = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM mod_wtp_service_selection WHERE theme_version_id = :id',
            ['id' => $id]
        );
        if ($inUse > 0) {
            throw new CatalogException('This version is referenced by ' . $inUse . ' service(s).');
        }
        $this->db->delete('mod_wtp_theme_versions', ['id' => $id]);
    }

    private function clearDefaultVersion(int $themeId): void
    {
        $this->db->execute(
            'UPDATE mod_wtp_theme_versions SET is_default = 0 WHERE theme_id = :t',
            ['t' => $themeId]
        );
    }

    // -------------------------------------------------------------- demos

    /** @return array<int,array<string,mixed>> */
    public function listDemos(int $themeId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM mod_wtp_demos WHERE theme_id = :t';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return $this->db->select($sql, ['t' => $themeId]);
    }

    /** @return array<string,mixed>|null */
    public function findDemo(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_demos WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $input */
    public function createDemo(int $themeId, array $input): int
    {
        if ($this->findTheme($themeId) === null) {
            throw new CatalogException('Theme not found: ' . $themeId);
        }
        $data = $this->normalizeDemo($input);
        $data['theme_id'] = $themeId;

        $clash = $this->db->selectOne(
            'SELECT id FROM mod_wtp_demos WHERE theme_id = :t AND slug = :s',
            ['t' => $themeId, 's' => $data['slug']]
        );
        if ($clash !== null) {
            throw new CatalogException('This theme already has a demo with the slug "' . $data['slug'] . '"');
        }

        $data['created_at'] = $this->clock->nowSql();
        $data['updated_at'] = $this->clock->nowSql();
        return $this->db->insert('mod_wtp_demos', $data);
    }

    /** @param array<string,mixed> $input */
    public function updateDemo(int $id, array $input): void
    {
        $existing = $this->findDemo($id);
        if ($existing === null) {
            throw new CatalogException('Demo not found: ' . $id);
        }
        $data = $this->normalizeDemo($input);

        $clash = $this->db->selectOne(
            'SELECT id FROM mod_wtp_demos WHERE theme_id = :t AND slug = :s AND id <> :id',
            ['t' => (int) $existing['theme_id'], 's' => $data['slug'], 'id' => $id]
        );
        if ($clash !== null) {
            throw new CatalogException('Another demo of this theme already uses that slug');
        }

        $data['updated_at'] = $this->clock->nowSql();
        $this->db->update('mod_wtp_demos', $data, ['id' => $id]);
    }

    public function setDemoStatus(int $id, string $status): void
    {
        $this->db->update('mod_wtp_demos', [
            'status' => $this->normalizeStatus($status),
            'updated_at' => $this->clock->nowSql(),
        ], ['id' => $id]);
    }

    public function deleteDemo(int $id): void
    {
        $inUse = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM mod_wtp_service_selection WHERE demo_id = :id',
            ['id' => $id]
        );
        if ($inUse > 0) {
            throw new CatalogException(
                'This demo is referenced by ' . $inUse . ' service(s). Deactivate it instead of deleting it.'
            );
        }
        $this->db->delete('mod_wtp_demos', ['id' => $id]);
    }

    // ------------------------------------------------------------- assets

    /** @return array<int,array<string,mixed>> */
    public function listAssets(int $demoId): array
    {
        return $this->db->select(
            'SELECT * FROM mod_wtp_demo_assets WHERE demo_id = :d ORDER BY sort_order ASC, id ASC',
            ['d' => $demoId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findAsset(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM mod_wtp_demo_assets WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findAssetByRole(int $demoId, string $role): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM mod_wtp_demo_assets WHERE demo_id = :d AND role = :r ORDER BY sort_order ASC, id ASC LIMIT 1',
            ['d' => $demoId, 'r' => $role]
        );
    }

    /** @param array<string,mixed> $input */
    public function createAsset(int $demoId, array $input): int
    {
        if ($this->findDemo($demoId) === null) {
            throw new CatalogException('Demo not found: ' . $demoId);
        }
        $role = isset($input['role']) ? (string) $input['role'] : 'other';
        if (!in_array($role, self::ASSET_ROLES, true)) {
            throw new CatalogException('Unknown asset role: ' . $role);
        }
        $checksum = strtolower((string) ($input['checksum_sha256'] ?? ''));
        if (!Str::isSha256($checksum)) {
            throw new CatalogException('An asset must carry a sha256 checksum');
        }
        $storagePath = (string) ($input['storage_path'] ?? '');
        if ($storagePath === '' || strpos($storagePath, '..') !== false || $storagePath[0] === '/') {
            throw new CatalogException('storage_path must be a relative path inside the storage root');
        }

        return $this->db->insert('mod_wtp_demo_assets', [
            'demo_id' => $demoId,
            'role' => $role,
            'filename' => substr(basename((string) ($input['filename'] ?? 'asset.bin')), 0, 191),
            'storage_path' => substr($storagePath, 0, 512),
            'mime' => isset($input['mime']) ? substr((string) $input['mime'], 0, 96) : null,
            'size_bytes' => max(0, (int) ($input['size_bytes'] ?? 0)),
            'checksum_sha256' => $checksum,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'created_at' => $this->clock->nowSql(),
        ]);
    }

    public function deleteAsset(int $id): void
    {
        $this->db->delete('mod_wtp_demo_assets', ['id' => $id]);
    }

    // --------------------------------------------------------- validation

    /**
     * Resolve a customer's (theme, demo) pair, rejecting anything inactive,
     * mismatched or non-existent. This is the single gate every code path uses.
     *
     * @return array{theme:array<string,mixed>,demo:array<string,mixed>,version:array<string,mixed>}
     * @throws CatalogException
     */
    public function resolveSelection(int $themeId, int $demoId, ?int $versionId = null): array
    {
        $theme = $this->findTheme($themeId);
        if ($theme === null) {
            throw new CatalogException('theme_not_found');
        }
        if ($theme['status'] !== self::STATUS_ACTIVE) {
            throw new CatalogException('theme_inactive');
        }

        $demo = $this->findDemo($demoId);
        if ($demo === null) {
            throw new CatalogException('demo_not_found');
        }
        if ((int) $demo['theme_id'] !== $themeId) {
            throw new CatalogException('demo_theme_mismatch');
        }
        if ($demo['status'] !== self::STATUS_ACTIVE) {
            throw new CatalogException('demo_inactive');
        }

        $version = $versionId !== null ? $this->findVersion($versionId) : $this->defaultVersion($themeId);
        if ($version === null) {
            throw new CatalogException('theme_version_missing');
        }
        if ((int) $version['theme_id'] !== $themeId || $version['status'] !== self::STATUS_ACTIVE) {
            throw new CatalogException('theme_version_invalid');
        }

        return ['theme' => $theme, 'demo' => $demo, 'version' => $version];
    }

    // -------------------------------------------------------- normalisers

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function normalizeTheme(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new CatalogException('Theme name is required');
        }
        $slug = Str::slug((string) ($in['slug'] ?? $name));
        if ($slug === '') {
            throw new CatalogException('Theme slug is required and must contain letters or digits');
        }

        return [
            'slug' => $slug,
            'name' => substr($name, 0, 191),
            'description' => isset($in['description']) ? substr((string) $in['description'], 0, 65535) : null,
            'preview_image_url' => $this->optionalUrl($in['preview_image_url'] ?? null, 'preview_image_url'),
            'homepage_url' => $this->optionalUrl($in['homepage_url'] ?? null, 'homepage_url'),
            'status' => $this->normalizeStatus((string) ($in['status'] ?? self::STATUS_ACTIVE)),
            'sort_order' => (int) ($in['sort_order'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function normalizeVersion(array $in): array
    {
        $version = trim((string) ($in['version'] ?? ''));
        if (!preg_match('/^[0-9][0-9a-zA-Z.\-+]{0,31}$/', $version)) {
            throw new CatalogException('Version must start with a digit, e.g. "4.6.1"');
        }
        $sourceType = (string) ($in['source_type'] ?? self::SOURCE_STORAGE);
        if (!in_array($sourceType, [self::SOURCE_STORAGE, self::SOURCE_URL, self::SOURCE_WPORG], true)) {
            throw new CatalogException('Unknown source type: ' . $sourceType);
        }
        $sourceRef = trim((string) ($in['source_ref'] ?? ''));
        if ($sourceRef === '') {
            throw new CatalogException('A source reference is required');
        }
        $checksum = isset($in['checksum_sha256']) ? strtolower(trim((string) $in['checksum_sha256'])) : '';

        if ($sourceType === self::SOURCE_STORAGE) {
            if (strpos($sourceRef, '..') !== false || $sourceRef[0] === '/') {
                throw new CatalogException('Storage references must be relative paths inside the storage root');
            }
            if (!Str::isSha256($checksum)) {
                throw new CatalogException('Stored packages must carry a sha256 checksum');
            }
        } elseif ($sourceType === self::SOURCE_URL) {
            if (stripos($sourceRef, 'https://') !== 0) {
                throw new CatalogException('Remote packages must be served over https');
            }
            if (!Str::isSha256($checksum)) {
                throw new CatalogException('Remote packages must carry a sha256 checksum');
            }
        } else { // wporg
            $sourceRef = Str::slug($sourceRef);
            if ($sourceRef === '') {
                throw new CatalogException('A wordpress.org slug is required');
            }
            $checksum = '';
        }

        return [
            'version' => $version,
            'is_default' => !empty($in['is_default']) ? 1 : 0,
            'status' => $this->normalizeStatus((string) ($in['status'] ?? self::STATUS_ACTIVE)),
            'source_type' => $sourceType,
            'source_ref' => substr($sourceRef, 0, 512),
            'checksum_sha256' => $checksum === '' ? null : $checksum,
            'size_bytes' => isset($in['size_bytes']) ? max(0, (int) $in['size_bytes']) : null,
            'wp_min_version' => isset($in['wp_min_version']) ? substr((string) $in['wp_min_version'], 0, 16) : null,
            'php_min_version' => isset($in['php_min_version']) ? substr((string) $in['php_min_version'], 0, 16) : null,
            'notes' => isset($in['notes']) ? substr((string) $in['notes'], 0, 65535) : null,
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private function normalizeDemo(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new CatalogException('Demo name is required');
        }
        $slug = Str::slug((string) ($in['slug'] ?? $name));
        if ($slug === '') {
            throw new CatalogException('Demo slug is required');
        }

        return [
            'slug' => $slug,
            'name' => substr($name, 0, 191),
            'description' => isset($in['description']) ? substr((string) $in['description'], 0, 65535) : null,
            'preview_image_url' => $this->optionalUrl($in['preview_image_url'] ?? null, 'preview_image_url'),
            'preview_site_url' => $this->optionalUrl($in['preview_site_url'] ?? null, 'preview_site_url'),
            'status' => $this->normalizeStatus((string) ($in['status'] ?? self::STATUS_ACTIVE)),
            'sort_order' => (int) ($in['sort_order'] ?? 0),
            'min_theme_version' => isset($in['min_theme_version'])
                ? substr((string) $in['min_theme_version'], 0, 32) : null,
            'import_plan' => $this->normalizeJson($in['import_plan'] ?? null, 'import_plan'),
            'required_plugins' => $this->normalizeJson($in['required_plugins'] ?? null, 'required_plugins'),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return $status === self::STATUS_INACTIVE ? self::STATUS_INACTIVE : self::STATUS_ACTIVE;
    }

    /** @param mixed $value */
    private function optionalUrl($value, string $field): ?string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return null;
        }
        if (!preg_match('#^https?://[^\s<>"\']{3,509}$#i', $s)) {
            throw new CatalogException($field . ' must be an absolute http(s) URL');
        }
        return $s;
    }

    /** @param mixed $value */
    private function normalizeJson($value, string $field): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_array($value)) {
            return Json::encode($value);
        }
        $s = (string) $value;
        try {
            Json::decode($s, 262144);
        } catch (\InvalidArgumentException $e) {
            throw new CatalogException($field . ' must be valid JSON: ' . $e->getMessage());
        }
        return $s;
    }
}
