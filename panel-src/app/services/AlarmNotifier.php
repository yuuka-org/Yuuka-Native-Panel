<?php
declare(strict_types=1);

/**
 * Sends a generic webhook (POST JSON, curl_init() - same client pattern as
 * HealthCheckService::runCheck()) when Dashboard's Alarm thresholds
 * (Settings > Alarm) are breached. There's no email/SMTP here - this
 * project is intentionally zero-dependency (no Composer), and PHP's
 * built-in mail() needs a locally configured MTA this codebase never sets
 * up, so a webhook (Discord/Telegram-via-relay/generic HTTP endpoint) is
 * the only notification channel that needs nothing extra installed.
 */
final class AlarmNotifier
{
    private const COOLDOWN_SECONDS = 900; // 15 minutes - avoids re-notifying on every Dashboard load/refresh while a condition stays breached.

    /**
     * Mirrors the same threshold comparisons dashboard.php's Alarm banner
     * already renders (CPU/RAM against SettingsService thresholds, PM2
     * restart_count per app) - called once per Dashboard page load,
     * server-side only (never from the 5s AJAX refresh, which would spam
     * a webhook every few seconds while a condition persists).
     */
    public static function checkAndNotify(array $summary, array $nodejsStatus): void
    {
        $webhookUrl = SettingsService::get('alarm_webhook_url');
        if ($webhookUrl === '' || !Validator::healthCheckUrl($webhookUrl)) {
            return;
        }

        $cpuThreshold = (float) SettingsService::get('cpu_alert_threshold', '85');
        $memThreshold = (float) SettingsService::get('mem_alert_threshold', '85');
        $restartThreshold = (int) SettingsService::get('restart_alert_threshold', '10');

        $cpuPercent = (float) ($summary['cpu_percent'] ?? 0);
        $ramPercent = (float) ($summary['ram']['percent'] ?? 0);

        $messages = [];
        if ($cpuPercent > $cpuThreshold) {
            $messages[] = sprintf('CPU %.1f%% melebihi ambang %s%%', $cpuPercent, $cpuThreshold);
        }
        if ($ramPercent > $memThreshold) {
            $messages[] = sprintf('RAM %.1f%% melebihi ambang %s%%', $ramPercent, $memThreshold);
        }
        foreach ($nodejsStatus['managed'] ?? [] as $item) {
            $rt = $item['runtime'] ?? null;
            if ($rt !== null && (int) $rt['restart_count'] > $restartThreshold) {
                $messages[] = sprintf(
                    'Restart count %s (%s) melebihi ambang %d',
                    $item['meta']['app_name'] ?? '?',
                    $rt['restart_count'],
                    $restartThreshold
                );
            }
        }

        if (empty($messages)) {
            return;
        }

        $lastNotifiedAt = (int) SettingsService::get('alarm_last_notified_at', '0');
        if ((time() - $lastNotifiedAt) < self::COOLDOWN_SECONDS) {
            return;
        }

        self::send($webhookUrl, $messages);
        SettingsService::set('alarm_last_notified_at', (string) time());
    }

    /** A failed/unreachable webhook must never break Dashboard rendering. */
    private static function send(string $webhookUrl, array $messages): void
    {
        try {
            $payload = json_encode([
                'event' => 'yuuka_panel_alarm',
                'message' => implode(' | ', $messages),
                'timestamp' => date('c'),
            ], JSON_UNESCAPED_SLASHES);

            $ch = curl_init();
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_URL => $webhookUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($errno !== 0) {
                ActivityLog::record(null, 'alarm.webhook_failed', "curl errno {$errno} saat mengirim ke {$webhookUrl}");
            }
        } catch (Throwable $e) {
            ActivityLog::record(null, 'alarm.webhook_failed', $e->getMessage());
        }
    }
}
