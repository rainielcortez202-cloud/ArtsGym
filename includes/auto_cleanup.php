<?php
/**
 * auto_cleanup.php
 * 
 * Automatically purges stale records from the database.
 * Runs at most once per day to avoid slow requests.
 * 
 * Rules:
 *  - activity_log   : delete records older than 90 days (on day 91+)
 *  - attendance     : delete records older than 90 days (on day 91+)
 *  - walk_ins       : delete records older than 30 days (on day 31+)
 *  - ip_login_attempts : delete rows where lockout has expired
 */

function runAutoCleanup(PDO $pdo): void {

    try {
        // Throttle: only run once per day using the settings table
        $last_run_stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'last_auto_cleanup'");
        $last_run_stmt->execute();
        $last_run = $last_run_stmt->fetchColumn();

        if ($last_run && strtotime($last_run) >= strtotime('today')) {
            return; // Already ran today
        }
    } catch (Exception $e) {
        // settings table may not exist yet; proceed anyway
    }

    try {
        $getSettingDays = function(string $key, int $default) use ($pdo): int {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $val = $stmt->fetchColumn();
                $days = is_numeric($val) ? (int)$val : $default;
                return $days > 0 ? $days : $default;
            } catch (Exception $e) {
                return $default;
            }
        };

        $activityDays = $getSettingDays('retention_activity_log_days', 90);
        $attendanceDays = $getSettingDays('retention_attendance_days', 90);
        $walkinsDays = $getSettingDays('retention_walkins_days', 30);
        $unverifiedDays = $getSettingDays('retention_unverified_member_days', 7);

        // 1. Delete activity_log entries older than 90 days
        //    "on its 91st day" = created more than 90 full days ago
        $pdo->exec("
            DELETE FROM activity_log
            WHERE created_at < CURRENT_TIMESTAMP - ($activityDays || ' days')::interval
        ");

        // 2. Delete attendance records older than 90 days
        //    Uses attendance_date column (DATE type)
        $pdo->exec("
            DELETE FROM attendance
            WHERE attendance_date < (CURRENT_DATE - ($attendanceDays || ' days')::interval)
        ");

        // 3. Delete walk_ins records older than 30 days
        //    Uses visit_date column (TIMESTAMP type)
        $pdo->exec("
            DELETE FROM walk_ins
            WHERE visit_date < CURRENT_TIMESTAMP - ($walkinsDays || ' days')::interval
        ");

        // 4. Delete expired ip_login_attempts rows
        //    Safe to delete any IP where lockout_until has already passed
        $pdo->exec("
            DELETE FROM ip_login_attempts
            WHERE lockout_until IS NOT NULL
              AND lockout_until < CURRENT_TIMESTAMP
        ");

        // 5. Delete unverified member registrations older than 7 days
        //    Only targets role='member' with is_verified = FALSE — never touches
        //    admin/staff accounts (they are created with is_verified = TRUE anyway)
        $pdo->exec("
            DELETE FROM users
            WHERE role = 'member'
              AND (is_verified = FALSE OR is_verified IS NULL)
              AND created_at < CURRENT_TIMESTAMP - ($unverifiedDays || ' days')::interval
        ");

        // Update last run timestamp in settings
        $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES ('last_auto_cleanup', NOW()::text, NOW())
            ON CONFLICT (setting_key) DO UPDATE
                SET setting_value = NOW()::text,
                    updated_at    = NOW()
        ")->execute();

    } catch (Exception $e) {
        // Silently fail — cleanup is best-effort, must not break the app
        error_log('[auto_cleanup] Error: ' . $e->getMessage());
    }
}
