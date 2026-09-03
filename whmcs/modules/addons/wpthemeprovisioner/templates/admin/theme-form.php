<?php
use Wtp\Addon\Admin\View;
/**
 * @var array<string,mixed>|null $theme
 * @var array<int,array<string,mixed>> $versions
 * @var array<int,array<string,mixed>> $demos
 * @var string $link
 * @var string $csrf
 */
$id = $theme === null ? 0 : (int) $theme['id'];
$value = static function (string $key, $default = '') use ($theme) {
    return $theme === null ? $default : ($theme[$key] ?? $default);
};
?>
<p><a href="<?= View::url($link . '&action=themes') ?>">← All themes</a></p>

<form method="post" action="<?= View::url($link . '&action=theme') ?>">
    <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="do" value="save-theme">
    <input type="hidden" name="id" value="<?= $id ?>">

    <fieldset>
        <legend><?= $id > 0 ? 'Edit theme' : 'New theme' ?></legend>
        <table class="wtp">
            <tr><th style="width:200px;">Name</th>
                <td><input class="form-control" name="name" required maxlength="191"
                           value="<?= View::e($value('name')) ?>"></td></tr>
            <tr><th>Slug</th>
                <td><input class="form-control" name="slug" maxlength="96"
                           value="<?= View::e($value('slug')) ?>"
                           placeholder="derived from the name if left empty">
                    <p class="muted">Must match the theme's WordPress directory name — the agent
                        activates the theme by this slug.</p></td></tr>
            <tr><th>Description</th>
                <td><textarea class="form-control" name="description" rows="3"><?= View::e($value('description')) ?></textarea></td></tr>
            <tr><th>Preview image URL</th>
                <td><input class="form-control" name="preview_image_url" maxlength="512"
                           value="<?= View::e($value('preview_image_url')) ?>"></td></tr>
            <tr><th>Homepage URL</th>
                <td><input class="form-control" name="homepage_url" maxlength="512"
                           value="<?= View::e($value('homepage_url')) ?>"></td></tr>
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
        </table>
        <button class="btn btn-primary" type="submit">Save theme</button>
    </fieldset>
</form>

<?php if ($id > 0) { ?>
    <fieldset>
        <legend>Versions</legend>
        <?php if ($versions === []) { ?>
            <p class="muted">This theme has no versions yet, so it cannot be provisioned.</p>
        <?php } else { ?>
            <table class="wtp">
                <tr><th>Version</th><th>Source</th><th>Checksum</th><th>Default</th><th>Status</th><th></th></tr>
                <?php foreach ($versions as $version) { ?>
                    <tr>
                        <td><strong><?= View::e($version['version']) ?></strong></td>
                        <td><code><?= View::e($version['source_type']) ?></code><br>
                            <span class="muted"><?= View::e($version['source_ref']) ?></span></td>
                        <td class="muted"><?= View::e(substr((string) $version['checksum_sha256'], 0, 16)) ?><?= $version['checksum_sha256'] ? '…' : '—' ?></td>
                        <td><?= (int) $version['is_default'] === 1 ? '✓' : '' ?></td>
                        <td><span class="label label-<?= $version['status'] === 'active' ? 'success' : 'default' ?>">
                            <?= View::e($version['status']) ?></span></td>
                        <td>
                            <form method="post" action="<?= View::url($link . '&action=theme&id=' . $id) ?>"
                                  onsubmit="return confirm('Delete this version?');">
                                <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="do" value="delete-version">
                                <input type="hidden" name="theme_id" value="<?= $id ?>">
                                <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
                                <button class="btn btn-xs btn-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        <?php } ?>

        <form method="post" action="<?= View::url($link . '&action=theme&id=' . $id) ?>" style="margin-top:14px;">
            <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="do" value="save-version">
            <input type="hidden" name="theme_id" value="<?= $id ?>">
            <table class="wtp">
                <tr>
                    <th style="width:200px;">Version</th>
                    <td><input class="form-control" name="version" placeholder="4.6.1" required></td>
                </tr>
                <tr>
                    <th>Source</th>
                    <td>
                        <select class="form-control" name="source_type">
                            <option value="storage">Uploaded package (storage)</option>
                            <option value="url">Remote https URL</option>
                            <option value="wporg">wordpress.org repository</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Reference</th>
                    <td><input class="form-control" name="source_ref" required
                               placeholder="themes/astra-4.6.1.zip, https://…/astra.zip, or the wp.org slug">
                        <p class="muted">Storage references are relative to the package storage root.</p></td>
                </tr>
                <tr>
                    <th>SHA-256</th>
                    <td><input class="form-control" name="checksum_sha256" maxlength="64"
                               placeholder="required for storage and URL sources">
                        <p class="muted">Run <code>sha256sum theme.zip</code>. The agent refuses any package
                            whose bytes do not match.</p></td>
                </tr>
                <tr>
                    <th>Default</th>
                    <td><label><input type="checkbox" name="is_default" value="1"> Use this version for new orders</label></td>
                </tr>
            </table>
            <button class="btn btn-default" type="submit">Add version</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>Demos</legend>
        <p><a class="btn btn-primary btn-sm"
              href="<?= View::url($link . '&action=demo&theme_id=' . $id) ?>">Add a demo</a></p>
        <?php if ($demos === []) { ?>
            <p class="muted">No demos yet.</p>
        <?php } else { ?>
            <table class="wtp">
                <tr><th>Demo</th><th>Slug</th><th>Status</th><th></th></tr>
                <?php foreach ($demos as $demo) { ?>
                    <tr>
                        <td><?= View::e($demo['name']) ?></td>
                        <td><code><?= View::e($demo['slug']) ?></code></td>
                        <td><span class="label label-<?= $demo['status'] === 'active' ? 'success' : 'default' ?>">
                            <?= View::e($demo['status']) ?></span></td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-xs btn-default"
                               href="<?= View::url($link . '&action=demo&id=' . (int) $demo['id']) ?>">Edit</a>
                            <form method="post" style="display:inline;"
                                  action="<?= View::url($link . '&action=theme&id=' . $id) ?>">
                                <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
                                <input type="hidden" name="do" value="toggle-demo">
                                <input type="hidden" name="id" value="<?= (int) $demo['id'] ?>">
                                <button class="btn btn-xs btn-default" type="submit">
                                    <?= $demo['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </table>
        <?php } ?>
    </fieldset>

    <fieldset>
        <legend>Danger zone</legend>
        <form method="post" action="<?= View::url($link . '&action=theme&id=' . $id) ?>"
              onsubmit="return confirm('Delete this theme, its versions and its demos? Services that already selected it will block the deletion.');">
            <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
            <input type="hidden" name="do" value="delete-theme">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-danger" type="submit">Delete theme</button>
            <span class="muted">Deactivating is almost always the right choice — it keeps history intact.</span>
        </form>
    </fieldset>
<?php } ?>
