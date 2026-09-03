<?php
use Wtp\Addon\Admin\View;
/** @var string $message */
?>
<div class="alert alert-danger errorbox">
    <p><strong>This screen could not be rendered.</strong></p>
    <p><?= View::e($message) ?></p>
    <p class="muted">The full details are in the web server's error log.</p>
</div>
