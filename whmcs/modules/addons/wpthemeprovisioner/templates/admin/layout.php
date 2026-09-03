<?php
/**
 * @var \Wtp\Addon\Admin\View $view
 * @var string $link
 * @var string $csrf
 * @var string $action
 * @var string[] $notices
 * @var string[] $errors
 * @var string $content
 */
use Wtp\Addon\Admin\View;

$tabs = [
    'dashboard' => 'Dashboard',
    'themes' => 'Themes &amp; demos',
    'products' => 'Products',
    'jobs' => 'Provisioning jobs',
    'settings' => 'Settings',
];
?>
<div class="wtp-admin">
    <style>
        .wtp-admin .wtp-tabs { margin-bottom: 18px; border-bottom: 1px solid #ddd; padding-left: 0; }
        .wtp-admin .wtp-tabs li { display: inline-block; list-style: none; }
        .wtp-admin .wtp-tabs a { display: inline-block; padding: 8px 16px; text-decoration: none;
            border: 1px solid transparent; border-bottom: 0; color: #337ab7; }
        .wtp-admin .wtp-tabs a.active { background: #fff; border-color: #ddd; border-radius: 4px 4px 0 0;
            color: #333; font-weight: 600; margin-bottom: -1px; }
        .wtp-admin .wtp-cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .wtp-admin .wtp-card { flex: 1 1 150px; border: 1px solid #e3e3e3; border-radius: 4px;
            padding: 12px 14px; background: #fff; }
        .wtp-admin .wtp-card .n { font-size: 26px; font-weight: 600; line-height: 1.1; }
        .wtp-admin .wtp-card .l { color: #777; font-size: 12px; text-transform: uppercase;
            letter-spacing: .04em; }
        .wtp-admin table.wtp { width: 100%; border-collapse: collapse; background: #fff; }
        .wtp-admin table.wtp th, .wtp-admin table.wtp td { padding: 8px 10px; border-bottom: 1px solid #eee;
            text-align: left; vertical-align: top; font-size: 13px; }
        .wtp-admin table.wtp th { background: #f7f7f7; font-weight: 600; }
        .wtp-admin .wtp-timeline { list-style: none; padding-left: 0; }
        .wtp-admin .wtp-timeline li { padding: 7px 0 7px 26px; position: relative; border-bottom: 1px solid #f2f2f2; }
        .wtp-admin .wtp-timeline .icon { position: absolute; left: 0; top: 7px; width: 20px; text-align: center; }
        .wtp-admin .wtp-log { max-height: 420px; overflow: auto; border: 1px solid #eee; border-radius: 4px;
            background: #fbfbfb; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
        .wtp-admin .wtp-log div { padding: 4px 8px; border-bottom: 1px solid #f0f0f0; white-space: pre-wrap;
            word-break: break-word; }
        .wtp-admin .wtp-log .lvl-error, .wtp-admin .wtp-log .lvl-critical { color: #a94442; }
        .wtp-admin .wtp-log .lvl-warning { color: #8a6d3b; }
        .wtp-admin .muted { color: #888; }
        .wtp-admin .wtp-bar { height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
        .wtp-admin .wtp-bar span { display: block; height: 100%; background: #5cb85c; }
        .wtp-admin fieldset { border: 1px solid #e3e3e3; border-radius: 4px; padding: 14px; margin-bottom: 18px; }
        .wtp-admin legend { width: auto; padding: 0 8px; font-size: 14px; font-weight: 600; border: 0; margin: 0; }
    </style>

    <ul class="wtp-tabs">
        <?php foreach ($tabs as $key => $label) { ?>
            <li><a class="<?= $action === $key ? 'active' : '' ?>"
                   href="<?= View::url($link . '&action=' . $key) ?>"><?= $label ?></a></li>
        <?php } ?>
    </ul>

    <?php foreach ($errors as $message) { ?>
        <div class="alert alert-danger errorbox"><strong>Problem:</strong> <?= View::e($message) ?></div>
    <?php } ?>
    <?php foreach ($notices as $message) { ?>
        <div class="alert alert-success successbox"><?= View::e($message) ?></div>
    <?php } ?>

    <?= $content ?>
</div>
