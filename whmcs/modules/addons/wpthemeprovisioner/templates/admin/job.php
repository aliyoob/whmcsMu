<?php
use Wtp\Addon\Admin\View;
use Wtp\Shared\Job\StatusPresenter;
/**
 * @var array<string,mixed> $job
 * @var array<int,array<string,mixed>> $steps
 * @var array<int,array<string,mixed>> $events
 * @var array<int,array<string,mixed>> $checklist
 * @var int $percentage
 * @var array<string,mixed>|null $selection
 * @var array<string,mixed> $result
 * @var string $link
 * @var string $csrf
 */
$jobId = (int) $job['id'];
$self = $link . '&action=job&job=' . $jobId;
$presented = StatusPresenter::job((string) $job['status'], (string) $job['phase']);
$stepsByKey = [];
foreach ($steps as $row) {
    $stepsByKey[(string) $row['step_key']] = $row;
}
?>
<p><a href="<?= View::url($link . '&action=jobs') ?>">← All jobs</a></p>

<div class="wtp-cards">
    <div class="wtp-card" style="flex:2 1 320px;">
        <div class="l">Job <?= $jobId ?> · <?= View::e($job['uuid']) ?></div>
        <div class="n" style="font-size:20px;"><?= View::e($job['domain']) ?></div>
        <p style="margin:8px 0 4px;">
            <span class="label label-<?= View::e($presented['tone'] === 'muted' ? 'default' : $presented['tone']) ?>">
                <?= View::e($presented['label']) ?></span>
            <span class="muted"> · service #<?= (int) $job['service_id'] ?>
                <?php if (!empty($job['order_id'])) { ?> · order #<?= (int) $job['order_id'] ?><?php } ?>
                <?php if (!empty($job['client_id'])) { ?> · client #<?= (int) $job['client_id'] ?><?php } ?>
            </span>
        </p>
        <div class="wtp-bar"><span style="width:<?= (int) $percentage ?>%"></span></div>
        <p class="muted" style="margin-top:6px;"><?= (int) $percentage ?>% complete ·
            created <?= View::e(View::ago($job['created_at'] ?? null)) ?>
            <?php if (!empty($job['completed_at'])) { ?>
                · finished <?= View::e(View::ago($job['completed_at'])) ?>
            <?php } ?>
        </p>
    </div>

    <div class="wtp-card" style="flex:1 1 240px;">
        <div class="l">Selection</div>
        <?php if ($selection === null) { ?>
            <p class="muted">No selection recorded.</p>
        <?php } else { ?>
            <p style="margin:6px 0 0;">
                Theme #<?= (int) $selection['theme_id'] ?> ·
                Demo #<?= (int) $selection['demo_id'] ?><br>
                <span class="muted">chosen by <?= View::e($selection['source']) ?>
                    <?= (int) $selection['locked'] === 1 ? '· locked' : '' ?></span>
            </p>
        <?php } ?>
        <?php if (!empty($result['site_url'])) { ?>
            <p style="margin-top:8px;">
                <a href="<?= View::url((string) $result['site_url']) ?>" target="_blank" rel="noopener">Open site ↗</a>
                <?php if (!empty($result['admin_url'])) { ?>
                    · <a href="<?= View::url((string) $result['admin_url']) ?>" target="_blank" rel="noopener">wp-admin ↗</a>
                <?php } ?>
            </p>
        <?php } ?>
    </div>
</div>

<?php if (!empty($job['last_error'])) { ?>
    <div class="alert alert-danger">
        <strong>Last error<?php if (!empty($job['last_error_code'])) { ?>
            (<code><?= View::e($job['last_error_code']) ?></code>)<?php } ?>:</strong>
        <?= View::e($job['last_error']) ?>
    </div>
<?php } ?>

<fieldset>
    <legend>Provisioning status</legend>
    <ul class="wtp-timeline">
        <?php foreach ($checklist as $item) {
            $row = $stepsByKey[$item['key']] ?? null;
            ?>
            <li>
                <span class="icon"><?= View::e($item['icon']) ?></span>
                <strong><?= View::e($item['label']) ?></strong>
                <span class="muted"> · <?= View::e($item['status']) ?>
                    <?php if ($row !== null && (int) $row['attempt'] > 1) { ?>
                        · attempt <?= (int) $row['attempt'] ?>/<?= (int) $row['max_attempts'] ?>
                    <?php } ?>
                    <?php if ($row !== null && !empty($row['completed_at'])) { ?>
                        · <?= View::e(View::ago($row['completed_at'])) ?>
                    <?php } elseif ($row !== null && !empty($row['heartbeat_at'])) { ?>
                        · last heard <?= View::e(View::ago($row['heartbeat_at'])) ?>
                    <?php } ?>
                </span>
                <?php if (!empty($item['error'])) { ?>
                    <div style="color:#a94442;margin-top:3px;"><?= View::e($item['error']) ?></div>
                <?php } ?>
                <?php if ($row !== null && $item['status'] !== 'pending') { ?>
                    <form method="post" action="<?= View::url($self) ?>" style="display:inline;margin-top:4px;">
                        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
                        <input type="hidden" name="do" value="retry-step">
                        <input type="hidden" name="job" value="<?= $jobId ?>">
                        <input type="hidden" name="step" value="<?= View::e($item['key']) ?>">
                        <button class="btn btn-xs btn-default" type="submit">Retry this step</button>
                        <button class="btn btn-xs btn-default" type="submit" name="from_here" value="1">
                            Retry from here</button>
                    </form>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
</fieldset>

<fieldset>
    <legend>Actions</legend>
    <form method="post" action="<?= View::url($self) ?>" style="display:inline;">
        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="do" value="retry-job">
        <input type="hidden" name="job" value="<?= $jobId ?>">
        <button class="btn btn-primary" type="submit">Retry from the first failure</button>
    </form>
    <form method="post" action="<?= View::url($self) ?>" style="display:inline;"
          onsubmit="return confirm('Rotate the agent secret? The site will need re-bootstrapping.');">
        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="do" value="rotate-secret">
        <input type="hidden" name="job" value="<?= $jobId ?>">
        <button class="btn btn-default" type="submit">Rotate agent secret</button>
    </form>
    <form method="post" action="<?= View::url($self) ?>" style="display:inline;"
          onsubmit="return confirm('Cancel this job? It cannot be resumed afterwards.');">
        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="do" value="cancel-job">
        <input type="hidden" name="job" value="<?= $jobId ?>">
        <button class="btn btn-danger" type="submit">Cancel job</button>
    </form>
</fieldset>

<fieldset>
    <legend>Event log</legend>
    <div class="wtp-log">
        <?php if ($events === []) { ?>
            <div class="muted">No events recorded.</div>
        <?php } ?>
        <?php foreach ($events as $event) { ?>
            <div class="lvl-<?= View::e($event['level']) ?>">
                <span class="muted"><?= View::e($event['created_at']) ?></span>
                [<?= View::e($event['source']) ?>/<?= View::e($event['level']) ?>]
                <?php if (!empty($event['step_key'])) { ?><?= View::e($event['step_key']) ?> · <?php } ?>
                <?= View::e($event['message']) ?>
                <?php if (!empty($event['context'])) { ?>
                    <span class="muted"><?= View::e($event['context']) ?></span>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
    <p class="muted" style="margin-top:6px;">
        Tokens, passwords and signatures are redacted before anything is written here.
    </p>
</fieldset>
