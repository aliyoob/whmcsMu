<?php
namespace Wtp\Addon\Provision;

/**
 * One unit of WHMCS-side provisioning work.
 *
 * A handler must be safe to call repeatedly: the worker will re-run it after a
 * timeout, after a deploy, or when an operator presses Retry.
 */
interface StepHandler
{
    /** @return string[] step keys this handler is responsible for */
    public function handles(): array;

    public function execute(StepContext $context): StepOutcome;
}
