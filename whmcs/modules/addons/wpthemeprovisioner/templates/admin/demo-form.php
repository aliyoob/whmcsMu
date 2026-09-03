<?php
use Wtp\Addon\Admin\View;
use Wtp\Addon\Catalog\CatalogRepository;
/**
 * @var array<string,mixed>|null $demo
 * @var array<string,mixed>|null $theme
 * @var array<int,array<string,mixed>> $assets
 * @var string $link
 * @var string $csrf
 */
$id = $demo === null ? 0 : (int) $demo['id'];
$themeId = $theme === null ? 0 : (int) $theme['id'];
$value = static function (string $key, $default = '') use ($demo) {
    return $demo === null ? $default : ($demo[$key] ?? $default);
};
?>
<p>
    <a href="<?= View::url($link . '&action=theme&id=' . $themeId) ?>">←
        <?= $theme === null ? 'Themes' : View::e($theme['name']) ?></a>
</p>

<form method="post" action="<?= View::url($link . '&action=demo') ?>">
    <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="do" value="save-demo">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="theme_id" value="<?= $themeId ?>">

    <fieldset>
        <legend><?= $id > 0 ? 'Edit demo' : 'New demo' ?></legend>
        <table class="wtp">
            <tr><th style="width:200px;">Name</th>
                <td><input class="form-control" name="name" required maxlength="191"
                           value="<?= View::e($value('name')) ?>"></td></tr>
            <tr><th>Slug</th>
                <td><input class="form-control" name="slug" maxlength="96"
                           value="<?= View::e($value('slug')) ?>"></td></tr>
            <tr><th>Description</th>
                <td><textarea class="form-control" name="description" rows="3"><?= View::e($value('description')) ?></textarea></td></tr>
            <tr><th>Preview image URL</th>
                <td><input class="form-control" name="preview_image_url" maxlength="512"
                           value="<?= View::e($value('preview_image_url')) ?>"></td></tr>
            <tr><th>Live preview URL</th>
                <td><input class="form-control" name="preview_site_url" maxlength="512"
                           value="<?= View::e($value('preview_site_url')) ?>">
                    <p class="muted">Shown to customers as a "Preview" link on the order form.</p></td></tr>
            <tr><th>Status</th>
                <td><select class="form-control" name="status">
                    <?php foreach (['active', 'inactive'] as $status) { ?>
                        <option value="<?= $status ?>" <?= $value('status', 'active') === $status ? 'selected' : '' ?>>
                            <?= ucfirst($status) ?></option>
                    <?php } ?>
                </select></td></tr>
            <tr><th>Sort order</th>
                <td><input class="form-control" name="sort_order" type="number"
                           value="<?= (int) $value('sort_order', 0) ?>"></td></tr>
            <tr><th>Required plugins</th>
                <td><textarea class="form-control" name="required_plugins" rows="5"
                              placeholder='[{"source":"wporg","slug":"elementor","activate":true}]'><?= View::e($value('required_plugins')) ?></textarea>
                    <p class="muted">JSON array. Each entry is either
                        <code>{"source":"wporg","slug":"…","version":"…","activate":true}</code> or
                        <code>{"source":"asset","asset_id":123,"activate":true}</code> referring to an
                        asset of <em>this</em> demo.</p></td></tr>
            <tr><th>Import plan overrides</th>
                <td><textarea class="form-control" name="import_plan" rows="8"
                              placeholder='{"site":{"front_page":"Home","menus":{"primary":"Main Menu"}},"options":{"source_site_url":"https://demo.example.com"}}'><?= View::e($value('import_plan')) ?></textarea>
                    <p class="muted">Optional JSON. Steps are derived automatically from the assets below;
                        this only overrides site settings, URL rewriting and per-importer options.</p></td></tr>
        </table>
        <button class="btn btn-primary" type="submit">Save demo</button>
    </fieldset>
</form>

<?php if ($id > 0) { ?>
    <fieldset>
        <legend>Import assets</legend>
        <?php if ($assets === []) { ?>
            <p class="muted">No assets yet. A demo with no content file will install and activate the
                theme but import nothing.</p>
        <?php } else { ?>
            <table class="wtp">
                <tr><th>Role</th><th>File</th><th>Size</th><th>SHA-256</th><th></th></tr>
                <?php foreach ($assets as $asset) { ?>
                    <tr>
                        <td><code><?= View::e($asset['role']) ?></code></td>
                        <td><?= View::e($asset['filename']) ?><br>
                            <span class="muted"><?= View::e($asset['storage_path']) ?></span></td>
                        <td class="muted"><?= View::e(View::bytes((int) $asset['size_bytes'])) ?></td>
                        <td class="muted"><?= View::e(substr((string) $asset['checksum_sha256'], 0, 16)) ?>…</td>
                        <td>
                            <form method="post" action="<?= View::url($link . '&action=demo&id=' . $id) ?>"
                                  onsubmit="return confirm('Remove this asset from the demo?');">
                                <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="do" value="delete-asset">
                                <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                                <button class="btn btn-xs btn-danger" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        <?php } ?>

        <form method="post" enctype="multipart/form-data" style="margin-top:14px;"
              action="<?= View::url($link . '&action=demo&id=' . $id) ?>">
            <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="do" value="upload-asset">
            <input type="hidden" name="demo_id" value="<?= $id ?>">
            <table class="wtp">
                <tr>
                    <th style="width:200px;">Role</th>
                    <td>
                        <select class="form-control" name="role">
                            <?php foreach (CatalogRepository::ASSET_ROLES as $role) { ?>
                                <option value="<?= View::e($role) ?>"><?= View::e($role) ?></option>
                            <?php } ?>
                        </select>
                        <p class="muted">
                            <code>content</code> a WordPress WXR export ·
                            <code>widgets</code> widget JSON ·
                            <code>customizer</code> a <code>.dat</code> export ·
                            <code>elementor_kit</code> an Elementor kit ·
                            <code>plugin</code> a plugin zip referenced from "required plugins".
                        </p>
                    </td>
                </tr>
                <tr><th>File</th>
                    <td><input type="file" name="asset" required>
                        <p class="muted">zip, xml, json, dat, wie, txt or csv · up to 512 MB ·
                            zip archives are inspected for traversal, symlinks and executable content
                            before they are accepted.</p></td></tr>
            </table>
            <button class="btn btn-default" type="submit">Upload asset</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>Danger zone</legend>
        <form method="post" action="<?= View::url($link . '&action=theme&id=' . $themeId) ?>"
              onsubmit="return confirm('Delete this demo?');">
            <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="do" value="delete-demo">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="theme_id" value="<?= $themeId ?>">
            <button class="btn btn-danger" type="submit">Delete demo</button>
        </form>
    </fieldset>
<?php } ?>
