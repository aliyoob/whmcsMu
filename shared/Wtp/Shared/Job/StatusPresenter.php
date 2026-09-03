<?php
namespace Wtp\Shared\Job;

/**
 * Turns (status, phase, steps) into the human-readable checklist shown in the
 * admin services tab and the client area. Storage keeps machine states; only
 * this class decides what people read, so wording changes never need a migration.
 */
final class StatusPresenter
{
    /** @return array{label:string,tone:string} */
    public static function job(string $status, ?string $phase = null): array
    {
        switch ($status) {
            case JobStatus::PENDING:
                return ['label' => 'Queued', 'tone' => 'muted'];
            case JobStatus::RUNNING:
                return ['label' => self::phaseLabel($phase, 'Provisioning'), 'tone' => 'info'];
            case JobStatus::AWAITING_AGENT:
                return ['label' => 'Waiting for the site to check in', 'tone' => 'info'];
            case JobStatus::IMPORTING:
                return ['label' => self::phaseLabel($phase, 'Importing demo'), 'tone' => 'info'];
            case JobStatus::VERIFYING:
                return ['label' => 'Verifying site', 'tone' => 'info'];
            case JobStatus::COMPLETED:
                return ['label' => 'Completed', 'tone' => 'success'];
            case JobStatus::FAILED:
                return ['label' => 'Failed', 'tone' => 'danger'];
            case JobStatus::CANCELLED:
                return ['label' => 'Cancelled', 'tone' => 'muted'];
            case JobStatus::NEEDS_ATTENTION:
                return ['label' => 'Needs attention', 'tone' => 'warning'];
            default:
                return ['label' => ucfirst(str_replace('_', ' ', $status)), 'tone' => 'muted'];
        }
    }

    /**
     * @param array<int,array{step_key:string,status:string,error_message?:?string,progress?:int}> $steps
     * @return array<int,array{key:string,label:string,status:string,icon:string,progress:int,error:?string}>
     */
    public static function checklist(array $steps): array
    {
        $byKey = [];
        foreach ($steps as $s) {
            $byKey[$s['step_key']] = $s;
        }

        $out = [];
        foreach (StepCatalog::all() as $def) {
            $row = isset($byKey[$def['key']]) ? $byKey[$def['key']] : null;
            $status = $row === null ? StepStatus::PENDING : (string) $row['status'];
            $out[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'status' => $status,
                'icon' => self::icon($status),
                'progress' => $row === null ? 0 : (int) ($row['progress'] ?? 0),
                'error' => $row === null ? null : ($row['error_message'] ?? null),
            ];
        }
        return $out;
    }

    public static function icon(string $stepStatus): string
    {
        switch ($stepStatus) {
            case StepStatus::COMPLETED:
                return '✓';
            case StepStatus::RUNNING:
                return '⏳';
            case StepStatus::FAILED:
                return '✗';
            case StepStatus::SKIPPED:
                return '–';
            default:
                return '○';
        }
    }

    /**
     * Overall percentage, weighted by step count. Optional steps that were
     * skipped still count as done so the bar never goes backwards.
     *
     * @param array<int,array{step_key:string,status:string}> $steps
     */
    public static function percentage(array $steps): int
    {
        $total = count(StepCatalog::all());
        if ($total === 0) {
            return 0;
        }
        $done = 0;
        foreach ($steps as $s) {
            if (StepStatus::isTerminal((string) $s['status'])) {
                $done++;
            }
        }
        return (int) round(min(100, ($done / $total) * 100));
    }

    private static function phaseLabel(?string $phase, string $fallback): string
    {
        if ($phase === null) {
            return $fallback;
        }
        $def = StepCatalog::get($phase);
        return $def === null ? $fallback : $def['label'];
    }
}
