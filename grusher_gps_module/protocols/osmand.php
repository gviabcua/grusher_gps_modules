<?php
/**
 * OsmAnd HTTP GPS protocol server
 *
 * OsmAnd (and compatible apps) send HTTP POST/GET with JSON body:
 * {
 *   "device_id": "...",
 *   "location": {
 *     "timestamp": "ISO8601",
 *     "coords": { "latitude": ..., "longitude": ..., "speed": ..., "altitude": ... },
 *     "battery": { "level": 0.0-1.0 }
 *   }
 * }
 *
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';

clilogTracker('Starting GPS server...', $protocol_name);

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('default_socket_timeout', -1);
ini_set('max_input_time', -1);

$options = getopt('p:');
if (!isset($options['p']) || (int)$options['p'] <= 0 || (int)$options['p'] >= 65536) {
    clilogTracker('Invalid or missing port (-p)', $protocol_name);
    exit(1);
}
$port = (int)$options['p'];
$host = '0.0.0.0';

$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) {
    clilogTracker("Failed to create socket: $errstr ($errno)", $protocol_name);
    exit(1);
}
stream_set_blocking($server, false);
clilogTracker("Server started on $host:$port", $protocol_name);

$clients  = [];
$buffers  = [];

while (true) {
    $read   = array_merge([$server], array_values($clients));
    $write  = null;
    $except = null;

    if (stream_select($read, $write, $except, 0, 200000) < 1) {
        continue;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = stream_socket_accept($server);
            if ($conn) {
                stream_set_blocking($conn, false);
                $id = (int)$conn;
                $clients[$id] = $conn;
                $buffers[$id] = '';
                clilogTracker('New connection', $protocol_name);
            }
            continue;
        }

        $id      = (int)$sock;
        $payload = fread($sock, 8192);

        if ($payload === false || $payload === '') {
            clilogTracker('Connection closed', $protocol_name);
            unset($clients[$id], $buffers[$id]);
            fclose($sock);
            continue;
        }

        $buffers[$id] .= $payload;

        // HTTP request ends with \r\n\r\n + body
        if (strpos($buffers[$id], "\r\n\r\n") !== false) {
            processGpsData($sock, $buffers[$id], $protocol_name); // FIX: 3 args (no $connectionIMEIs)
            $buffers[$id] = '';
        }
    }
}

function processGpsData($conn, $raw, $protocol_name) {
    // Split HTTP headers from body
    $parts = explode("\r\n\r\n", $raw, 2);
    if (count($parts) < 2) {
        clilogTracker('Invalid HTTP payload — no header/body split', $protocol_name);
        sendHttpResponse($conn, 400);
        return;
    }
    [, $body] = $parts;

    // Parse JSON body
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        clilogTracker('JSON decode error: ' . json_last_error_msg(), $protocol_name);
        sendHttpResponse($conn, 400);
        return;
    }

    if (!isset($data['device_id'], $data['location']['coords'])) {
        clilogTracker('Missing required JSON fields', $protocol_name);
        sendHttpResponse($conn, 400);
        return;
    }

    $device_id = (string)$data['device_id'];
    $coords    = $data['location']['coords'];

    // Parse timestamp
    $timestamp = '';
    $tsRaw     = $data['location']['timestamp'] ?? '';
    if ($tsRaw !== '') {
        try {
            $dt        = new DateTimeImmutable($tsRaw);
            $timestamp = $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $timestamp = '';
        }
    }

    $lat      = $coords['latitude']  ?? null;
    $lon      = $coords['longitude'] ?? null;
    $speed    = $coords['speed']     ?? null;
    $altitude = $coords['altitude']  ?? null;
    $battery  = $data['location']['battery']['level'] ?? null;

    // Guard against unknown/negative values reported by some clients
    $speed   = ($speed   !== null && $speed   >= 0) ? round($speed   * 3.6, 1) : 0; // m/s → km/h
    $battery = ($battery !== null && $battery >= 0) ? round($battery * 100)    : null;

    clilogTracker(
        "OsmAnd device:$device_id ts:$timestamp lat:$lat lon:$lon spd:$speed alt:$altitude bat:$battery",
        $protocol_name
    );

    $payload = [
        'protocol_name' => $protocol_name,
        'last_alive'    => $timestamp,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed,
        'alt'           => $altitude,
    ];
    if ($battery !== null) {
        $payload['battery'] = $battery;
    }

    sendToGrusher($device_id, $payload);
    sendHttpResponse($conn, 200);
}

function sendHttpResponse($conn, int $code) {
    $text = $code === 200 ? 'OK' : 'Bad Request';
    $body = $code === 200 ? 'OK' : 'Bad Request';
    fwrite($conn, "HTTP/1.1 $code $text\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
}
