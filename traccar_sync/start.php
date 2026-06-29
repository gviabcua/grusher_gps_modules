<?php
/**
 * Grusher - Traccar sync
 * Pulls device list + last positions from Traccar REST API,
 * forwards each to Grusher set_gps endpoint.
 * @gviabcua
 */

define('WORK_DIR', dirname(__FILE__));
require_once WORK_DIR . '/config.php';

// ── Pull devices ──────────────────────────────────────
$devices_raw = getTraccarApi($TRACCAR_URL . 'devices', $TRACCAR_USERNAME, $TRACCAR_PASSWORD);
$get_devices = @json_decode($devices_raw, true);

// ── Pull last positions ───────────────────────────────
$positions_raw = getTraccarApi($TRACCAR_URL . 'positions', $TRACCAR_USERNAME, $TRACCAR_PASSWORD);
$get_positions = @json_decode($positions_raw, true);

$returner = [];

if (is_array($get_devices) && !empty($get_devices)) {
    foreach ($get_devices as $rec) {
        if (!isset($rec['id'])) continue;

        $id  = $rec['id'];
        $returner[$id]['id']       = $id;
        $returner[$id]['uniqueId'] = $rec['uniqueId'] ?? null;

        $lastUpdate = $rec['lastUpdate'] ?? null;
        if ($lastUpdate !== null) {
            try {
                // Traccar returns ISO 8601 UTC — convert to server timezone
                $dt         = new DateTime($lastUpdate, new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone($TIMEZONE ?? 'UTC'));
                $lastUpdate = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $lastUpdate = null;
            }
        }
        $returner[$id]['lastUpdate'] = $lastUpdate;
    }
}

if (is_array($get_positions) && !empty($get_positions)) {
    foreach ($get_positions as $recp) {
        $did = $recp['deviceId'] ?? null;
        if ($did === null) continue;
        $returner[$did]['lat']   = $recp['latitude']  ?? null;
        $returner[$did]['lon']   = $recp['longitude'] ?? null;
        $returner[$did]['speed'] = $recp['speed']     ?? null;
    }
}

// ── Forward to Grusher ────────────────────────────────
$sent = 0;
foreach ($returner as $rec) {
    $uid = $rec['uniqueId'] ?? null;
    $lat = $rec['lat']      ?? null;
    $lon = $rec['lon']      ?? null;

    if ($uid === null || $lat === null || $lon === null) continue;
    if (!isValidCoord($lat, $lon)) continue;

    $query  = '&tracker_id=' . urlencode($uid);
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

function isValidCoord($lat, $lon): bool {
    $lat = (float)$lat;
    $lon = (float)$lon;
    if ($lat === 0.0 && $lon === 0.0) return false;
    return ($lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0);
}

function getTraccarApi(string $url, string $username, string $password): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPGET        => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $username . ':' . $password,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true, 
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    global $TRACCAR_SSL_VERIFY;
    if (isset($TRACCAR_SSL_VERIFY) && !$TRACCAR_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error) {
        echo 'Traccar API error: ' . $error . PHP_EOL;
        return null;
    }
    return $response !== false ? $response : null;
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
