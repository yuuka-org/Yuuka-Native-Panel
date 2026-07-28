<?php
declare(strict_types=1);

/**
 * Shared helpers for Server-Sent Events streaming endpoints (real-time
 * Node.js logs/stats, traffic analysis - see wiki for the full list).
 * SSE over plain fetch-less EventSource was picked over WebSockets for
 * these because it needs nothing beyond what's already running (plain
 * PHP-FPM + Nginx, no separate daemon like ttyd's terminal has) and the
 * browser's native EventSource already handles reconnect on its own.
 *
 * Any endpoint using this MUST live at a path matching `*_stream.php` -
 * the panel's Nginx vhost (modules/panel.sh) matches that filename
 * pattern to a dedicated location with `fastcgi_buffering off`, without
 * which Nginx would buffer the entire response before the browser sees
 * any of it, defeating the whole point.
 */

/**
 * Call ONLY after any session reads a stream endpoint needs (e.g.
 * Auth::requireLogin()/Rbac::require()) are already done. Releases the
 * PHP session file lock immediately - the default session handler holds
 * an exclusive lock for the entire request, and a stream is deliberately
 * long-lived, so without this every OTHER request from the same browser
 * (another tab, another AJAX call) would hang until this stream ends.
 */
function sse_release_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function sse_start(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // belt-and-suspenders alongside the Nginx location's fastcgi_buffering off
    header('Connection: keep-alive');
    ob_implicit_flush(true);
    set_time_limit(0);
    ignore_user_abort(false);
}

/** @param mixed $data JSON-encoded as the event payload */
function sse_send(string $event, $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

/** Keep-alive ping so idle browsers/intermediate proxies don't time the connection out. */
function sse_ping(): void
{
    echo ": ping\n\n";
    flush();
}

function sse_client_gone(): bool
{
    return connection_aborted() !== 0;
}

/**
 * Runs $tick every $intervalUs microseconds, sending a keep-alive ping
 * every ~15s of inactivity, until the client disconnects or $maxSeconds
 * elapses. Endpoints intentionally self-terminate rather than stream
 * forever - EventSource reconnects on its own a few seconds later, which
 * keeps any single PHP-FPM worker from being tied up indefinitely.
 *
 * @param callable(): void $tick called on each interval; call sse_send() inside it
 */
function sse_loop(callable $tick, int $intervalUs = 2000000, int $maxSeconds = 55): void
{
    $deadline = time() + $maxSeconds;
    $lastPing = time();
    while (time() < $deadline) {
        if (sse_client_gone()) {
            return;
        }
        $tick();
        if (time() - $lastPing >= 15) {
            sse_ping();
            $lastPing = time();
        }
        usleep($intervalUs);
    }
}
