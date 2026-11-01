<?php
declare(strict_types=1);

/**
 * Requests-per-day dari access log Nginx sebuah domain, dipakai bersama oleh
 * halaman Traffic Analysis (Website & Node.js) dan sparkline "Traffic" di
 * tabel daftar. Pembacaan log (termasuk yang sudah dirotasi) lumayan mahal
 * untuk log besar, jadi hasilnya di-cache singkat di disk supaya membuka
 * daftar berisi banyak situs tidak menjalankan banyak proses sekaligus.
 */
final class TrafficService
{
    private const CACHE_TTL = 300;

    /**
     * Oldest first, zero-filled untuk hari tanpa traffic.
     * @return array<int,array{date:string,count:int}>
     */
    public static function daily(string $domain, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $counts = self::loadCounts($domain);

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['date' => $date, 'count' => $counts[$date] ?? 0];
        }
        return $out;
    }

    /** @return array<int,array{date:string,count:int}> deretan nol (mis. app tanpa domain) */
    public static function zeros(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $out[] = ['date' => date('Y-m-d', strtotime("-{$i} days")), 'count' => 0];
        }
        return $out;
    }

    /** @return array<string,int> */
    private static function loadCounts(string $domain): array
    {
        $cacheFile = sys_get_temp_dir() . '/yuuka-traffic-' . md5($domain) . '.json';
        if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_map('intval', $cached);
            }
        }

        $counts = [];
        $result = Executor::run('log-traffic-daily', [$domain], null, 30);
        if ($result['ok']) {
            foreach (explode("\n", trim($result['output'])) as $line) {
                if ($line === '' || !str_contains($line, "\t")) {
                    continue;
                }
                [$date, $count] = explode("\t", $line, 2);
                $counts[$date] = (int) $count;
            }
        }
        @file_put_contents($cacheFile, json_encode($counts), LOCK_EX);
        return $counts;
    }

    /** Sparkline SVG kecil (viewBox, melebar mengikuti sel tabel) + total periode di tooltip. */
    public static function sparkline(array $series, int $w = 120, int $h = 32): string
    {
        $n = count($series);
        $total = array_sum(array_column($series, 'count'));
        $max = max(1, ...array_column($series, 'count'));
        $pts = [];
        foreach ($series as $i => $p) {
            $x = $n > 1 ? ($i / ($n - 1)) * $w : $w / 2;
            $y = $h - 2 - ($p['count'] / $max) * ($h - 4);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $line = implode(' ', $pts);
        $area = "0,{$h} {$line} {$w},{$h}";
        $title = number_format($total) . ' request / ' . $n . ' hari terakhir';
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" preserveAspectRatio="none" role="img" aria-label="Traffic">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<polygon points="' . $area . '" fill="var(--brand)" fill-opacity="0.15"/>'
            . '<polyline points="' . $line . '" fill="none" stroke="var(--brand)" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>'
            . '</svg>';
    }
}
