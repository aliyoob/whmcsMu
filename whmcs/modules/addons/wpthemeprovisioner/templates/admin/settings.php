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
    <legend>Cron</legend>
    <p>Add this to the WHMCS server's crontab, running as the web user:</p>
    <pre>* * * * * /usr/bin/php -q <?= View::e(defined('WTP_ADDON_DIR') ? WTP_ADDON_DIR : '') ?>/cron/worker.php &gt;/dev/null 2&gt;&amp;1</pre>
    <p class="muted">
        Without it, jobs are created but never advance. Verify with
        <code>php cron/worker.php --selftest</code>.
    </p>
</fieldset>
