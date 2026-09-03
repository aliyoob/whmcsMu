<?php
use Wtp\Addon\Admin\View;
/**
 * @var array<int,array<string,mixed>> $products
 * @var array<int,array<string,mixed>> $settings
 * @var array<int,array<string,mixed>> $themes
 * @var string $link
 * @var string $csrf
 */
?>
<p class="muted">
    Enable theme selection on the hosting products that use the
    <code>wpthemehost</code> provisioning module. Customers see the picker on the
    product configuration step of the order form.
</p>

<?php if ($products === []) { ?>
    <div class="alert alert-warning">No hosting products found in WHMCS.</div>
<?php } ?>

<?php foreach ($products as $product) {
    $productId = (int) $product['id'];
    $current = $settings[$productId] ?? null;
    $allowed = [];
    if ($current !== null && !empty($current['allowed_theme_ids'])) {
        $decoded = json_decode((string) $current['allowed_theme_ids'], true);
        $allowed = is_array($decoded) ? array_map('intval', $decoded) : [];
    }
    ?>
    <form method="post" action="<?= View::url($link . '&action=products') ?>">
        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="do" value="save-product">
        <input type="hidden" name="product_id" value="<?= $productId ?>">

        <fieldset>
            <legend>
                <?= View::e($product['name']) ?>
                <span class="muted">· <?= View::e($product['groupname'] ?? 'Ungrouped') ?>
                    · module <?= View::e($product['servertype'] ?: 'none') ?></span>
            </legend>

            <table class="wtp">
                <tr><th style="width:220px;">Theme selection</th>
                    <td>
                        <label><input type="checkbox" name="enabled" value="1"
                            <?= $current !== null && (int) $current['enabled'] === 1 ? 'checked' : '' ?>>
                            Offer theme and demo selection on this product</label>
                    </td></tr>
                <tr><th>Required</th>
                    <td>
                        <label><input type="checkbox" name="selection_required" value="1"
                            <?= $current === null || (int) $current['selection_required'] === 1 ? 'checked' : '' ?>>
                            Refuse checkout until a demo is chosen</label>
                        <p class="muted">With this off, orders without a selection fall back to the
                            defaults below.</p>
                    </td></tr>
                <tr><th>Available themes</th>
                    <td>
                        <select class="form-control" name="allowed_theme_ids[]" multiple size="5">
                            <?php foreach ($themes as $theme) { ?>
                                <option value="<?= (int) $theme['id'] ?>"
                                    <?= in_array((int) $theme['id'], $allowed, true) ? 'selected' : '' ?>>
                                    <?= View::e($theme['name']) ?></option>
                            <?php } ?>
                        </select>
                        <p class="muted">Select none to offer every active theme.</p>
                    </td></tr>
                <tr><th>Default theme ID</th>
                    <td><input class="form-control" type="number" name="default_theme_id"
                               value="<?= $current === null ? '' : View::e($current['default_theme_id']) ?>">
                        <p class="muted">Used for admin-created and API orders, which have no order form.</p></td></tr>
                <tr><th>Default demo ID</th>
                    <td><input class="form-control" type="number" name="default_demo_id"
                               value="<?= $current === null ? '' : View::e($current['default_demo_id']) ?>"></td></tr>
            </table>
            <button class="btn btn-primary btn-sm" type="submit">Save</button>
        </fieldset>
    </form>
<?php } ?>
