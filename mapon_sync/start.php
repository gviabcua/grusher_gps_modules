<?php
/**
 * Grusher - MapOn sync
 * Pulls unit list (with last GPS position) from MapOn API v1,
 * forwards each to Grusher set_gps endpoint.
 * @gviabcua
 */

define('WORK_DIR', dirname(__FILE__));
require_once WORK_DIR . '/config.php';

// ── Pull unit list ────────────────────────────────────
$raw         = fgc($MAPON_URL . 'unit/list.json?key=' . urlencode($MAPON_API_KEY), $GRUSHER_TIMEOUT);
$get_devices = $raw !== null ? @json_decode($raw, true) : null;

$returner = [];

if (is_array($get_devices) && isset($get_devices['data']['units']) && is_array($get_devices['data']['units'])) {
    foreach ($get_devices['data']['units'] as $rec) {
        $uid = $rec['unit_id'] ?? null;
        if ($uid === null) continue;

        $lastUpdate = $rec['last_update'] ?? null;
        if ($lastUpdate !== null) {
            try {
                $dt         = new DateTime($lastUpdate);
                $lastUpdate = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $lastUpdate = null;
            }
        }

        $returner[$uid] = [
            'unit_id'    => $uid,
            'lastUpdate' => $lastUpdate,
            'lat'        => $rec['lat']   ?? null,
            'lon'        => $rec['lng']   ?? null,
            'speed'      => $rec['speed'] ?? null,
        ];
    }
}

// ── Forward to Grusher ────────────────────────────────
$sent = 0;
foreach ($returner as $rec) {
    $uid = $rec['unit_id'];
    $lat = $rec['lat'];
    $lon = $rec['lon'];

    if (!isValidCoordMapon($lat, $lon)) continue;

    $query  = '&tracker_id=' . urlencode((string)$uid);
    $query .= '&last_alive=' . urlencode((string)($rec['lastUpdate'] ?? ''));
    $query .= '&lat='        . urlencode((string)$lat);
    $query .= '&lon='        . urlencode((string)$lon);
    $query .= '&speed='      . urlencode((string)($rec['speed'] ?? ''));

    $url     = $GRUSHER_URL . '/api?key=' . urlencode($GRUSHER_API_KEY) . '&cat=billing&action=set_gps' . $query;
    $safeUrl = preg_replace('/key=[^&]+/', 'key=***', $url);
    echo 'Sending: ' . $safeUrl . PHP_EOL;

    $response = fgc($url, $GRUSHER_TIMEOUT);
    if ($response === null) {
        echo 'WARNING: failed for tracker ' . $uid . PHP_EOL;
    } else {
        $sent++;
    }
}

echo 'Done. Sent ' . $sent . ' tracker(s).' . PHP_EOL;

// ── Helpers ───────────────────────────────────────────

function isValidCoordMapon($lat, $lon): bool {
    if ($lat === null || $lon === null) return false;
    $lat = (float)$lat;
    $lon = (float)$lon;
    if ($lat === 0.0 && $lon === 0.0) return false;
    return ($lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0);
}

function fgc(string $url, int $timeout = 5): ?string {
    global $GRUSHER_SSL_VERIFY;
    $verify = isset($GRUSHER_SSL_VERIFY) ? (bool)$GRUSHER_SSL_VERIFY : true;
    $ctx = stream_context_create([
        'http'  => ['method' => 'GET', 'timeout' => $timeout, 'header' => "User-Agent: GrusherGPS/1.0\r\n"],
        'https' => ['method' => 'GET', 'timeout' => $timeout, 'header' => "User-Agent: GrusherGPS/1.0\r\n",
                    'verify_peer' => $verify, 'verify_peer_name' => $verify],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}
