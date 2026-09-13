<?php
use Wtp\Addon\Admin\View;
/**
 * @var \Wtp\Addon\Support\Config $config
 * @var array<string,bool> $tables
 * @var string[] $migrations
 * @var array<int,array{label:string,ok:bool,detail:string}> $diagnostics
 * @var string $link
 * @var string $csrf
 */
?>
<fieldset>
    <legend>Diagnostics</legend>
    <table class="wtp">
        <?php foreach ($diagnostics as $check) { ?>
            <tr>
                <th style="width:220px;"><?= View::e($check['label']) ?></th>
                <td>
                    <span class="label label-<?= $check['ok'] ? 'success' : 'danger' ?>">
                        <?= $check['ok'] ? 'OK' : 'Problem' ?></span>
                    <span class="muted"> <?= View::e($check['detail']) ?></span>
                </td>
            </tr>
        <?php } ?>
    </table>
</fieldset>

<fieldset>
    <legend>Configuration</legend>
    <p class="muted">
        Settings are read from <code><?= View::e($config->envPath()) ?></code> (or <code>WTP_*</code>
        environment variables) and fall back to the defaults below. Secrets are never stored in the
        database or in this interface.
    </p>
    <table class="wtp">
        <tr><th>Setting</th><th>Effective value</th><th>Environment variable</th></tr>
        <?php
        $visible = [
            'api_base_url', 'api_path', 'storage_path', 'agent_package', 'wp_core_source',
            'wp_version', 'bootstrap_ttl', 'secret_ttl', 'download_ttl', 'download_max_uses',
            'nonce_ttl', 'clock_skew', 'worker_batch', 'max_job_attempts', 'http_timeout',
            'rate_limit_api', 'rate_limit_window', 'rate_limit_auth_failures', 'enforce_https',
            'log_level', 'log_retention_days',
        ];
        foreach ($visible as $key) {
            $value = $config->get($key);
            ?>
            <tr>
                <td><code><?= View::e($key) ?></code></td>
                <td><?= $value === '' || $value === null ? '<span class="muted">(derived)</span>' : View::e($value) ?></td>
                <td class="muted"><code>WTP_<?= View::e(strtoupper($key)) ?></code></td>
            </tr>
        <?php } ?>
    </table>
</fieldset>

<fieldset>
    <legend>Database schema</legend>
    <table class="wtp">
        <tr><th>Table</th><th>Present</th></tr>
        <?php foreach ($tables as $table => $exists) { ?>
            <tr>
                <td><code><?= View::e($table) ?></code></td>
                <td><span class="label label-<?= $exists ? 'success' : 'danger' ?>">
                    <?= $exists ? 'yes' : 'missing' ?></span></td>
            </tr>
        <?php } ?>
    </table>
    <p class="muted">Applied migrations: <?= $migrations === []
        ? 'none recorded' : View::e(implode(', ', $migrations)) ?></p>
    <form method="post" action="<?= View::url($link . '&action=settings') ?>">
        <input type="hidden" name="token" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="do" value="run-migrations">
        <button class="btn btn-default" type="submit">Run pending migrations</button>
    </form>
</fieldset>

<fieldset>
    <legend>Setup</legend>
    <p class="muted">
        Activation created everything below that PHP is able to create. Anything
        marked missing has to be dealt with by hand before the first order.
    </p>
    <table class="wtp">
        <tbody>
            <tr>
                <th>Encryption key</th>
                <td><code><?= View::e($setup['env_path']) ?></code></td>
                <td><?= $setup['has_master_key']
                    ? '<span class="label label-success">present</span>'
                    : '<span class="label label-danger">missing</span>' ?></td>
            </tr>
            <tr>
                <th>Package storage</th>
                <td><code><?= View::e($setup['storage_path']) ?></code></td>
                <td><?= $setup['storage_exists']
                    ? '<span class="label label-success">present</span>'
                    : '<span class="label label-danger">missing</span>' ?></td>
            </tr>
            <tr>
                <th>Agent package</th>
                <td><code><?= View::e($setup['agent_package']) ?></code></td>
                <td><?= $setup['agent_installed']
                    ? '<span class="label label-success">installed</span>'
                    : '<span class="label label-danger">missing</span>' ?></td>
            </tr>
<?php if ($setup['pointer_needed']): ?>
            <tr>
                <th>Key location file</th>
                <td><code><?= View::e($setup['pointer_path']) ?></code></td>
                <td><?= $setup['pointer_exists']
                    ? '<span class="label label-success">present</span>'
                    : '<span class="label label-danger">missing</span>' ?></td>
            </tr>
<?php endif; ?>
        </tbody>
    </table>
    <p class="muted">
        Back up the encryption key. Losing it makes every stored credential
        unreadable, permanently.
    </p>
</fieldset>

<fieldset>
    <legend>Cron</legend>
<?php if ($setup['cpanel']): ?>
    <p>In cPanel &rarr; Advanced &rarr; Cron Jobs, add a job running
       <strong>Once Per Minute</strong> (<code>* * * * *</code>) with this command:</p>
<?php else: ?>
    <p>Add this to the WHMCS server's crontab, running as the web user:</p>
<?php endif; ?>
    <pre><?php if (!$setup['cpanel']): ?>* * * * * <?php endif; ?><?= View::e($setup['cron_command']) ?></pre>
    <p class="muted">
        Use that exact PHP path rather than plain <code>php</code>: the one on
        <code>PATH</code> is frequently a different version from the one this
        site runs, with different extensions.
    </p>
    <p class="muted">
        Without the cron entry, jobs are created but never advance. Verify with
        <code>php cron/worker.php --selftest</code>.
    </p>
</fieldset>
