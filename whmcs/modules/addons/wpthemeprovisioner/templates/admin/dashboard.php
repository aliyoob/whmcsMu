<?php
use Wtp\Addon\Admin\View;
/**
 * @var array<string,int> $counts
 * @var array<int,array<string,mixed>> $recent
 * @var array<int,array<string,mixed>> $attention
 * @var int $themes
 * @var array<int,array{label:string,ok:bool,detail:string}> $diagnostics
 * @var string $link
 */
$headline = [
    'running' => 'In progress',
    'awaiting_agent' => 'Awaiting site',
    'importing' => 'Importing',
    'completed' => 'Completed',
    'failed' => 'Failed',
    'needs_attention' => 'Needs attention',
];
?>
<div class="wtp-cards">
    <?php foreach ($headline as $status => $label) { ?>
        <div class="wtp-card">
            <div class="n"><?= (int) ($counts[$status] ?? 0) ?></div>
            <div class="l"><?= View::e($label) ?></div>
        </div>
    <?php } ?>
    <div class="wtp-card">
        <div class="n"><?= (int) $themes ?></div>
        <div class="l">Active themes</div>
    </div>
</div>

<?php
$broken = array_filter($diagnostics, static function (array $d): bool {
    return !$d['ok'];
});
if ($broken !== []) { ?>
    <div class="alert alert-warning">
        <strong>The provisioner is not fully configured.</strong>
        <ul style="margin:8px 0 0 18px;">
            <?php foreach ($broken as $check) { ?>
                <li><?= View::e($check['label']) ?>: <?= View::e($check['detail']) ?></li>
            <?php } ?>
        </ul>
        <p style="margin-top:8px;">
            <a href="<?= View::url($link . '&action=settings') ?>">Open settings and diagnostics →</a>
        </p>
    </div>
<?php } ?>

<?php if ($attention !== []) { ?>
    <fieldset>
        <legend>Needs your attention</legend>
        <table class="wtp">
            <tr><th>Job</th><th>Domain</th><th>Status</th><th>Last error</th><th>Updated</th><th></th></tr>
            <?php foreach ($attention as $job) { ?>
                <tr>
                    <td>#<?= (int) $job['id'] ?></td>
                    <td><?= View::e($job['domain']) ?></td>
                    <td><span class="label label-<?= View::e(View::tone((string) $job['status'])) ?>">
                        <?= View::e($job['status']) ?></span></td>
                    <td class="muted"><?= View::e($job['last_error'] ?? '') ?></td>
                    <td class="muted"><?= View::e(View::ago($job['updated_at'] ?? null)) ?></td>
                    <td><a class="btn btn-xs btn-default"
                           href="<?= View::url($link . '&action=job&job=' . (int) $job['id']) ?>">Open</a></td>
                </tr>
            <?php } ?>
        </table>
    </fieldset>
<?php } ?>

<fieldset>
    <legend>Recent jobs</legend>
    <?php if ($recent === []) { ?>
        <p class="muted">No provisioning jobs yet. They appear here as soon as a customer orders a
            product with theme selection enabled.</p>
    <?php } else { ?>
        <table class="wtp">
            <tr><th>Job</th><th>Domain</th><th>Status</th><th>Phase</th><th>Created</th><th></th></tr>
            <?php foreach ($recent as $job) { ?>
                <tr>
                    <td>#<?= (int) $job['id'] ?></td>
                    <td><?= View::e($job['domain']) ?></td>
                    <td><span class="label label-<?= View::e(View::tone((string) $job['status'])) ?>">
                        <?= View::e($job['status']) ?></span></td>
                    <td class="muted"><?= View::e($job['phase']) ?></td>
                    <td class="muted"><?= View::e(View::ago($job['created_at'] ?? null)) ?></td>
                    <td><a class="btn btn-xs btn-default"
                           href="<?= View::url($link . '&action=job&job=' . (int) $job['id']) ?>">Open</a></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</fieldset>
