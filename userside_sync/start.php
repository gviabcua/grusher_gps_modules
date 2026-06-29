<?php
/**
 * Grusher - UserSide GPS sync
 * Fetches tracker list, then individual tracker data from UserSide API,
 * forwards each to Grusher set_gps endpoint.
 * @gviabcua
 */

define('WORK_DIR', dirname(__FILE__));
require_once WORK_DIR . '/config.php';

// ── Get tracker list ──────────────────────────────────
$list_raw = fgc(
    $US_URL . '/api.php?key=' . urlencode($US_API_KEY) . '&cat=gps&action=get_list',
    $US_TIMEOUT
);

if ($list_raw === null) {
    echo 'ERROR: Cannot reach UserSide API' . PHP_EOL;
    exit(1);
}

$tracker_list = @json_decode($list_raw);

if (!isset($tracker_list->Data) || empty($tracker_list->Data)) {
    echo 'No trackers in UserSide GPS list' . PHP_EOL;
    exit(0);
}

// ── Fetch each tracker and forward ───────────────────
$sent = 0;
foreach ($tracker_list->Data as $item) {
    $tracker_id = $item->id ?? null;
    if ($tracker_id === null) continue;

    echo 'Tracker ' . $tracker_id . PHP_EOL;

    $info_raw = fgc(
        $US_URL . '/api.php?key=' . urlencode($US_API_KEY) . '&cat=gps&action=get_info&id=' . (int)$tracker_id,
        $US_TIMEOUT
    );

    if ($info_raw === null) {
        echo 'WARNING: Cannot get info for tracker ' . $tracker_id . PHP_EOL;
        continue;
    }

    $info = @json_decode($info_raw);
    if (!isset($info->Data->id)) continue;

    $d   = $info->Data;
    $lat = $d->lat ?? null;
    $lon = $d->lon ?? null;

    if (!isValidCoordUS($lat, $lon)) {
        echo 'Skipping tracker ' . $tracker_id . ' — invalid coordinates' . PHP_EOL;
        continue;
    }

    $query  = '&tracker_id=' . urlencode((string)$d->id);
    $query .= '&last_alive=' . urlencode((string)($d->last_alive ?? ''));
    $query .= '&lat='        . urlencode((string)$lat);
    $query .= '&lon='        . urlencode((string)$lon);
    $query .= '&speed='      . urlencode((string)($d->speed ?? ''));

    $url     = $GRUSHER_URL . '/api?key=' . urlencode($GRUSHER_API_KEY) . '&cat=billing&action=set_gps' . $query;
    $safeUrl = preg_replace('/key=[^&]+/', 'key=***', $url);
    echo 'Sending: ' . $safeUrl . PHP_EOL;

    $response = fgc($url, $GRUSHER_TIMEOUT);
    if ($response === null) {
        echo 'WARNING: Grusher request failed for tracker ' . $tracker_id . PHP_EOL;
    } else {
        $sent++;
    }
}

echo 'Done. Sent ' . $sent . ' tracker(s).' . PHP_EOL;

// ── Helpers ───────────────────────────────────────────

function isValidCoordUS($lat, $lon): bool {
    if ($lat === null || $lon === null) return false;
    $lat = (float)$lat;
    $lon = (float)$lon;
    if ($lat === 0.0 && $lon === 0.0) return false;
    return ($lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0);
}

function fgc(string $url, int $timeout = 5): ?string {
    global $GRUSHER_SSL_VERIFY, $US_SSL_VERIFY;
    // Determine verify flag based on which host we're calling
    $isGrusher = isset($GRUSHER_SSL_VERIFY);
    $verify    = $isGrusher ? (bool)($GRUSHER_SSL_VERIFY ?? true) : (bool)($US_SSL_VERIFY ?? true);
    $ctx = stream_context_create([
        'http'  => ['method' => 'GET', 'timeout' => $timeout, 'header' => "User-Agent: GrusherGPS/1.0\r\n"],
        'https' => ['method' => 'GET', 'timeout' => $timeout, 'header' => "User-Agent: GrusherGPS/1.0\r\n",
                    'verify_peer' => $verify, 'verify_peer_name' => $verify],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}
