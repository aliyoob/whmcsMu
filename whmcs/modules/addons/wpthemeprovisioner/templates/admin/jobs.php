<?php
use Wtp\Addon\Admin\View;
/**
 * @var array<int,array<string,mixed>> $jobs
 * @var array<string,mixed> $filters
 * @var int $page
 * @var string[] $statuses
 * @var string $link
 */
?>
<form method="get" action="" style="margin-bottom:14px;">
    <?php
    // Preserve WHMCS's own module routing parameters.
    $parts = parse_url($link);
    parse_str($parts['query'] ?? '', $carry);
    foreach ($carry as $key => $value) {
        if ($key === 'status' || $key === 'q' || $key === 'page') {
            continue;
        }
        echo '<input type="hidden" name="' . View::e($key) . '" value="' . View::e($value) . '">';
    }
    ?>
    <input type="hidden" name="action" value="jobs">
    <input class="form-control" style="display:inline-block;width:260px;" name="q"
           placeholder="domain or job id" value="<?= View::e($filters['q'] ?? '') ?>">
    <select class="form-control" style="display:inline-block;width:200px;" name="status">
        <option value="">Any status</option>
        <?php foreach ($statuses as $status) { ?>
            <option value="<?= View::e($status) ?>"
                <?= (isset($filters['status'][0]) && $filters['status'][0] === $status) ? 'selected' : '' ?>>
                <?= View::e($status) ?></option>
        <?php } ?>
    </select>
    <button class="btn btn-default" type="submit">Filter</button>
</form>

<?php if ($jobs === []) { ?>
    <p class="muted">No jobs match.</p>
<?php } else { ?>
    <table class="wtp">
        <tr><th>#</th><th>Domain</th><th>Service</th><th>Status</th><th>Phase</th>
            <th>Attempt</th><th>Updated</th><th></th></tr>
        <?php foreach ($jobs as $job) { ?>
            <tr>
                <td><?= (int) $job['id'] ?></td>
                <td><?= View::e($job['domain']) ?></td>
                <td class="muted">#<?= (int) $job['service_id'] ?></td>
                <td><span class="label label-<?= View::e(View::tone((string) $job['status'])) ?>">
                    <?= View::e($job['status']) ?></span></td>
                <td class="muted"><?= View::e($job['phase']) ?></td>
                <td class="muted"><?= (int) $job['attempt'] ?>/<?= (int) $job['max_attempts'] ?></td>
                <td class="muted"><?= View::e(View::ago($job['updated_at'] ?? null)) ?></td>
                <td><a class="btn btn-xs btn-default"
                       href="<?= View::url($link . '&action=job&job=' . (int) $job['id']) ?>">Open</a></td>
            </tr>
        <?php } ?>
    </table>

    <p style="margin-top:12px;">
        <?php if ($page > 1) { ?>
            <a class="btn btn-xs btn-default"
               href="<?= View::url($link . '&action=jobs&page=' . ($page - 1)) ?>">← Previous</a>
        <?php } ?>
        <?php if (count($jobs) === 50) { ?>
            <a class="btn btn-xs btn-default"
               href="<?= View::url($link . '&action=jobs&page=' . ($page + 1)) ?>">Next →</a>
        <?php } ?>
    </p>
<?php } ?>
