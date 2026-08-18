<?php
/**
 * OsmAnd HTTP GPS protocol server (the protocol Traccar exposes on 5055).
 *
 * Two request shapes are accepted, because clients in the wild use both:
 *
 * 1) Query parameters (OsmAnd itself, and most "Traccar Client" apps):
 *      GET /?id=353816...&lat=50.45&lon=30.52&timestamp=1717171717&speed=12.3
 *          &bearing=180&altitude=180&batt=87&hdop=0.9
 *
 * 2) JSON body (background-geolocation style clients):
 *      POST / {"device_id":"…","location":{"timestamp":"ISO8601",
 *              "coords":{"latitude":…,"longitude":…,"speed":…,"altitude":…},
 *              "battery":{"level":0.0-1.0}}}
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use ($protocol_name) {
        processHttpBuffer($conn, $id, $buffers, $srv, $protocol_name);
    }
);

/**
 * Extract complete HTTP requests from the receive buffer.
 *
 * The previous version fired as soon as it saw the header terminator, so any
 * POST whose body landed in a second TCP segment was parsed with an empty
 * body and answered with "400 Bad Request".
 */
function processHttpBuffer($conn, $id, &$buffers, GpsServer $srv, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];

        $headerEnd = strpos($buf, "\r\n\r\n");
        $sep       = 4;
        if ($headerEnd === false) {
            $headerEnd = strpos($buf, "\n\n"); // tolerate LF-only clients
            $sep       = 2;
        }
        if ($headerEnd === false) {
            if (strlen($buf) > 16384) {
                clilogTracker('Header too large — dropping connection', $protocol_name);
                $buffers[$id] = '';
                $srv->close($id);
            }
            return;
        }

        $headers    = substr($buf, 0, $headerEnd);
        $bodyOffset = $headerEnd + $sep;

        $contentLength = 0;
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $m)) {
            $contentLength = (int)$m[1];
        }

        if (strlen($buf) < $bodyOffset + $contentLength) {
            return; // body still on the wire
        }

        $body         = substr($buf, $bodyOffset, $contentLength);
        $buffers[$id] = substr($buf, $bodyOffset + $contentLength);

        handleHttpRequest($conn, $headers, $body, $protocol_name);

        // We answer with "Connection: close", so honour it.
        $srv->close($id);
        return;
    }
}

function handleHttpRequest($conn, $headers, $body, $protocol_name) {
    $requestLine = strtok($headers, "\r\n");
    $parts       = explode(' ', (string)$requestLine);
    $target      = $parts[1] ?? '/';

    clilogTracker('Request: ' . $requestLine, $protocol_name);

    // ── 1) Query parameters ─────────────────────────
    $query = [];
    $qpos  = strpos($target, '?');
    if ($qpos !== false) {
        parse_str(substr($target, $qpos + 1), $query);
    }
    // A form-encoded body carries the same field names.
    if ($body !== '' && stripos($headers, 'application/x-www-form-urlencoded') !== false) {
        $form = [];
        parse_str($body, $form);
        $query = array_merge($query, $form);
    }

    if (isset($query['lat'], $query['lon'])) {
        $deviceId = (string)($query['id'] ?? $query['deviceid'] ?? $query['device_id'] ?? '');
        if ($deviceId === '') {
            clilogTracker('Missing device id in query', $protocol_name);
            sendHttpResponse($conn, 400);
            return;
        }

        $timestamp = '';
        if (isset($query['timestamp']) && is_numeric($query['timestamp'])) {
            $timestamp = date('Y-m-d H:i:s', (int)$query['timestamp']);
        }

        $payload = [
            'protocol_name' => $protocol_name,
            'last_alive'    => $timestamp,
            'lat'           => $query['lat'],
            'lon'           => $query['lon'],
        ];
        // OsmAnd reports speed in m/s.
        if (isset($query['speed']))    $payload['speed']   = round((float)$query['speed'] * 3.6, 1);
        if (isset($query['bearing']))  $payload['angle']   = (int)round((float)$query['bearing']);
        if (isset($query['heading']))  $payload['angle']   = (int)round((float)$query['heading']);
        if (isset($query['altitude'])) $payload['alt']     = round((float)$query['altitude'], 1);
        if (isset($query['batt']))     $payload['battery'] = round((float)$query['batt']);

        clilogTracker(
            "OsmAnd device:$deviceId ts:$timestamp lat:{$query['lat']} lon:{$query['lon']}",
            $protocol_name
        );

        sendToGrusher($deviceId, $payload);
        sendHttpResponse($conn, 200);
        return;
    }

    // ── 2) JSON body ────────────────────────────────
    if ($body === '') {
        clilogTracker('Empty request — no query parameters and no body', $protocol_name);
        sendHttpResponse($conn, 400);
        return;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
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

    $timestamp = '';
    $tsRaw     = $data['location']['timestamp'] ?? '';
    if ($tsRaw !== '') {
        try {
            $timestamp = (new DateTimeImmutable($tsRaw))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $timestamp = '';
        }
    }

    $lat      = $coords['latitude']  ?? null;
    $lon      = $coords['longitude'] ?? null;
    $speed    = $coords['speed']     ?? null;
    $altitude = $coords['altitude']  ?? null;
    $heading  = $coords['heading']   ?? null;
    $battery  = $data['location']['battery']['level'] ?? null;

    // Guard against the "unknown" sentinel (-1) some clients report
    $speed   = ($speed   !== null && $speed   >= 0) ? round($speed * 3.6, 1) : 0; // m/s → km/h
    $battery = ($battery !== null && $battery >= 0) ? round($battery * 100)  : null;

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
    if ($battery !== null)                  $payload['battery'] = $battery;
    if ($heading !== null && $heading >= 0) $payload['angle']   = (int)round((float)$heading);

    sendToGrusher($device_id, $payload);
    sendHttpResponse($conn, 200);
}

function sendHttpResponse($conn, int $code) {
    $text = $code === 200 ? 'OK' : 'Bad Request';
    safeFwrite(
        $conn,
        "HTTP/1.1 $code $text\r\n" .
        "Content-Type: text/plain\r\n" .
        'Content-Length: ' . strlen($text) . "\r\n" .
        "Connection: close\r\n\r\n" . $text
    );
}
