<?php
// Run every hour by cron while any schedule exists (see sl_sync_cron in lib.php).
// Starts the scheduled scans due this hour, then sends growth alerts for scans that have finished.
// Usage: php scheduled.php [unix time to treat as now, for testing]

require_once __DIR__ . '/../php/lib.php';

$now = getdate(isset($argv[1]) ? (int)$argv[1] : time());

foreach (sl_schedules() as $schedule) {
    try {
        if (sl_schedule_due($schedule, $now) && is_dir($schedule['path'])) {
            if (sl_scan_status($schedule['path'])['state'] === 'idle') {
                sl_start_scan($schedule['path']);
                echo "started {$schedule['path']}\n";
            }
        }
        if ($subject = sl_check_alert($schedule)) {
            echo "alert: $subject\n";
        }
    } catch (Throwable $e) {
        error_log("spacelens: scheduled scan of {$schedule['path']}: " . $e->getMessage());
    }
}
