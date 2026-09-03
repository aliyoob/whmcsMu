{*
    Customer-facing provisioning status for a WordPress hosting service.

    Rendered by wpthemehost_ClientArea(). Everything here is escaped by Smarty's
    default escaping; the credentials block is only populated once the job has
    completed and only when the product enables it.
*}
<div class="wtp-service-status" style="margin-bottom:20px;">
    <style>
        .wtp-service-status .wtp-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            margin-bottom:12px; }
        .wtp-service-status .wtp-progress { height:8px; background:#eceff2; border-radius:4px;
            overflow:hidden; margin:10px 0 14px; }
        .wtp-service-status .wtp-progress span { display:block; height:100%; background:#2e9e5b;
            transition:width .4s ease; }
        .wtp-service-status ul.wtp-steps { list-style:none; padding:0; margin:0; }
        .wtp-service-status ul.wtp-steps li { padding:4px 0; font-size:13px; color:#5c6670; }
        .wtp-service-status ul.wtp-steps li.done { color:#2e7d46; }
        .wtp-service-status ul.wtp-steps li.failed { color:#a94442; }
        .wtp-service-status ul.wtp-steps li.active { color:#1f2933; font-weight:600; }
        .wtp-service-status .wtp-creds { margin-top:14px; padding:12px 14px; border:1px solid #cfe3fb;
            border-radius:6px; background:#f4f9ff; font-size:13px; }
        .wtp-service-status .wtp-creds code { background:#fff; padding:1px 5px; border-radius:3px; }
    </style>

    <div class="wtp-head">
        <h3 style="margin:0;font-size:16px;">Your WordPress site</h3>
        <span class="label label-{if $statusTone == 'muted'}default{else}{$statusTone}{/if}">{$statusLabel}</span>
        {if $siteUrl}
            <a href="{$siteUrl}" target="_blank" rel="noopener">Visit site</a>
        {/if}
        {if $adminUrl && $status == 'completed'}
            <a href="{$adminUrl}" target="_blank" rel="noopener">WordPress admin</a>
        {/if}
    </div>

    <div class="wtp-progress"><span style="width:{$percentage}%"></span></div>

    <ul class="wtp-steps">
        {foreach $checklist as $step}
            <li class="{if $step.status == 'completed' || $step.status == 'skipped'}done
                {elseif $step.status == 'failed'}failed
                {elseif $step.status == 'running'}active{/if}">
                {$step.icon} {$step.label}
            </li>
        {/foreach}
    </ul>

    {if $failed}
        <div class="alert alert-warning" style="margin-top:14px;">
            Something went wrong while setting up your site and our team has been notified.
            Your hosting account is ready to use in the meantime — no action is needed from you.
        </div>
    {elseif $status != 'completed'}
        <p class="text-muted" style="margin-top:12px;font-size:13px;">
            Setting up your site takes a few minutes. This page updates each time you reload it.
        </p>
    {/if}

    {if $credentials.username && $credentials.password}
        <div class="wtp-creds">
            <strong>WordPress administrator</strong><br>
            Username: <code>{$credentials.username}</code><br>
            Password: <code>{$credentials.password}</code>
            <p style="margin:8px 0 0;color:#6b7480;">
                Please sign in and change this password. It is shown here only because your site
                was created for you automatically.
            </p>
        </div>
    {/if}
</div>
