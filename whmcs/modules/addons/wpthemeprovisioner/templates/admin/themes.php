<?php
use Wtp\Addon\Admin\View;
/**
 * @var array<int,array<string,mixed>> $themes
 * @var array<int,int> $demoCounts
 * @var string $link
 * @var string $csrf
 */
?>
<p>
    <a class="btn btn-primary" href="<?= View::url($link . '&action=theme') ?>">Add a theme</a>
</p>

<?php if ($themes === []) { ?>
    <p class="muted">No themes yet. A theme needs at least one active version and one active demo
        before customers can choose it.</p>
<?php } else { ?>
    <table class="wtp">
        <tr><th>Theme</th><th>Slug</th><th>Demos</th><th>Status</th><th>Order</th><th></th></tr>
        <?php foreach ($themes as $theme) {
            $id = (int) $theme['id'];
            ?>
            <tr>
                <td>
                    <?php if (!empty($theme['preview_image_url'])) { ?>
                        <img src="<?= View::url((string) $theme['preview_image_url']) ?>" alt=""
                             style="width:48px;height:32px;object-fit:cover;border-radius:3px;
                                    vertical-align:middle;margin-right:8px;">
                    <?php } ?>
                    <strong><?= View::e($theme['name']) ?></strong>
                </td>
                <td><code><?= View::e($theme['slug']) ?></code></td>
                <td><?= (int) ($demoCounts[$id] ?? 0) ?></td>
                <td>
                    <span class="label label-<?= $theme['status'] === 'active' ? 'success' : 'default' ?>">
                        <?= View::e($theme['status']) ?>
                    </span>
                </td>
                <td class="muted"><?= (int) $theme['sort_order'] ?></td>
                <td style="white-space:nowrap;">
                    <a class="btn btn-xs btn-default"
                       href="<?= View::url($link . '&action=theme&id=' . $id) ?>">Edit</a>
                    <form method="post" style="display:inline;"
                          action="<?= View::url($link . '&action=themes') ?>">
                        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="do" value="toggle-theme">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-xs btn-default" type="submit">
                            <?= $theme['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>
