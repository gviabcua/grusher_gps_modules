<?php
/**
 * TK103 GPS protocol server (text-based)
 *
 * TK103 firmwares ship in two flavours and both are handled here:
 *
 * 1) The parenthesised "BP/BR" dialect (TK103-B and most clones):
 *      (027044702021BP00HSO)                       login    → (…AP01HSO)
 *      (027044702021BP05000027044702021110705A2233.5150N
 *       11400.9971E000.0034749000.0000000000L000)  login+fix → (…AP05)
 *      (027044702021BR00110705A2233.5150N11400.9971E000.0
 *       034749000.0000000000L000)                  position
 *
 * 2) The GPS103 "imei:" dialect — identical to protocols/gps103.php:
 *      ##,imei:359586015829802,A;                  login    → LOAD
 *      359586015829802;                            heartbeat → ON
 *      imei:…,tracker,YYMMDDHHMM,,F,HHMMSS.sss,A,DDMM.MMMM,N,
 *      DDDMM.MMMM,E,speed,course;
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$connectionIMEIs = [];

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use (&$connectionIMEIs, $protocol_name) {
        tk103ReadMessages($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

/** Split the stream into messages: "(…)" frames or ';'/newline-terminated lines. */
function tk103ReadMessages($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = ltrim($buffers[$id], "\r\n \t");
        if ($buf === '') { $buffers[$id] = ''; return; }

        if ($buf[0] === '(') {
            $end = strpos($buf, ')');
            if ($end === false) { $buffers[$id] = $buf; return; }
            $message      = substr($buf, 1, $end - 1);
            $buffers[$id] = substr($buf, $end + 1);
            clilogTracker("Frame: ($message)", $protocol_name);
            parseTK103Frame($conn, $message, $id, $connectionIMEIs, $protocol_name);
            continue;
        }

        $pos = strpos($buf, ';');
        if ($pos === false) {
            $nl = strpos($buf, "\n");
            if ($nl === false) { $buffers[$id] = $buf; return; }
            $pos = $nl;
        }
        $line         = trim(substr($buf, 0, $pos + 1), " \t\r\n;");
        $buffers[$id] = substr($buf, $pos + 1);

        if ($line === '') continue;
        clilogTracker("Line: $line", $protocol_name);
        parseTK103Line($conn, $line, $id, $connectionIMEIs, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
// Dialect 1 — "(<id><type><payload>)"
// ─────────────────────────────────────────────────────
function parseTK103Frame($conn, $message, $id, &$connectionIMEIs, $protocol_name) {
    if (!preg_match('/^(\d{10,15})([A-Z]{2}\d{2})(.*)$/s', $message, $m)) {
        clilogTracker("Unrecognised frame: $message", $protocol_name);
        return;
    }

    [, $deviceId, $type, $payload] = $m;
    $connectionIMEIs[$id] = $deviceId;

    // Handshake / keep-alive frames
    if ($type === 'BP00') {
        clilogTracker("Login: $deviceId", $protocol_name);
        safeFwrite($conn, '(' . $deviceId . 'AP01HSO)');
        return;
    }
    if ($type === 'BP03' || $type === 'BP02') {
        safeFwrite($conn, '(' . $deviceId . 'AP03)');
        return;
    }

    // BP05 carries the 15-digit IMEI before the position block
    if ($type === 'BP05' && preg_match('/^(\d{15})(.*)$/s', $payload, $mm)) {
        $connectionIMEIs[$id] = $mm[1];
        $deviceId             = $mm[1];
        $payload              = $mm[2];
    }

    $position = tk103ParsePositionBlock($payload);
    if ($position === null) {
        clilogTracker("$deviceId: frame type $type carries no position", $protocol_name);
        if ($type === 'BP05') safeFwrite($conn, '(' . $deviceId . 'AP05)');
        return;
    }

    clilogTracker(
        "TK103 ID:$deviceId {$position['datetime']} Lat:{$position['lat']} Lon:{$position['lon']} " .
        "Spd:{$position['speed']}km/h Crs:{$position['angle']}",
        $protocol_name
    );

    sendToGrusher($deviceId, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $position['datetime'],
        'lat'           => $position['lat'],
        'lon'           => $position['lon'],
        'speed'         => $position['speed'],
        'angle'         => $position['angle'],
    ]);

    if ($type === 'BP05') safeFwrite($conn, '(' . $deviceId . 'AP05)');
}

/**
 * Parse the shared position block:
 *   YYMMDD A|V DDMM.MMMM N|S DDDMM.MMMM E|W SSS.S HHMMSS CCC.CC
 */
function tk103ParsePositionBlock(string $payload): ?array {
    $re = '/^(\d{2})(\d{2})(\d{2})'          //  1-3  date YY MM DD
        . '([AV])'                           //  4    validity
        . '(\d{2})(\d{2}\.\d+)([NS])'        //  5-7  latitude  DD MM.MMMM N/S
        . '(\d{3})(\d{2}\.\d+)([EW])'        //  8-10 longitude DDD MM.MMMM E/W
        . '(\d{3}\.\d)'                      // 11    speed, knots
        . '(\d{2})(\d{2})(\d{2})'            // 12-14 time HH MM SS
        . '(\d{3}\.\d{2})?/';                // 15    course

    if (!preg_match($re, $payload, $m)) return null;
    if ($m[4] !== 'A') return null; // no fix

    $year  = 2000 + (int)$m[1];
    $month = (int)$m[2];
    $day   = (int)$m[3];

    $datetime = checkdate($month, $day, $year)
        ? sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $year, $month, $day, (int)$m[12], (int)$m[13], (int)$m[14]
        )
        : '';

    $lat = (int)$m[5] + ((float)$m[6]) / 60.0;
    if ($m[7] === 'S') $lat = -$lat;
    $lon = (int)$m[8] + ((float)$m[9]) / 60.0;
    if ($m[10] === 'W') $lon = -$lon;

    return [
        'datetime' => $datetime,
        'lat'      => $lat,
        'lon'      => $lon,
        'speed'    => round((float)$m[11] * 1.852, 1), // knots → km/h
        'angle'    => isset($m[15]) && $m[15] !== '' ? (int)round((float)$m[15]) : 0,
    ];
}

// ─────────────────────────────────────────────────────
// Dialect 2 — GPS103 "imei:" text lines
// ─────────────────────────────────────────────────────
function parseTK103Line($conn, $line, $id, &$connectionIMEIs, $protocol_name) {
    $parts = explode(',', $line);

    if ($parts[0] === '##') {
        foreach ($parts as $p) {
            if (str_starts_with($p, 'imei:')) {
                $connectionIMEIs[$id] = preg_replace('/\D/', '', substr($p, 5));
            }
        }
        clilogTracker('Login IMEI: ' . ($connectionIMEIs[$id] ?? '?'), $protocol_name);
        safeFwrite($conn, "LOAD\r\n");
        return;
    }

    if (count($parts) === 1 && preg_match('/^\d{10,17}$/', $parts[0])) {
        $connectionIMEIs[$id] = $parts[0];
        clilogTracker('Heartbeat from ' . $parts[0], $protocol_name);
        safeFwrite($conn, "ON\r\n");
        return;
    }

    if (!str_starts_with($parts[0], 'imei:')) {
        clilogTracker("Unrecognised message: $line", $protocol_name);
        return;
    }

    $imei = preg_replace('/\D/', '', substr($parts[0], 5));
    if ($imei !== '') {
        $connectionIMEIs[$id] = $imei;
    } else {
        $imei = $connectionIMEIs[$id] ?? '';
    }
    if ($imei === '') {
        clilogTracker('No IMEI — ignored', $protocol_name);
        return;
    }

    if (count($parts) < 12) {
        clilogTracker("IMEI $imei: non-position message (" . count($parts) . ' fields)', $protocol_name);
        safeFwrite($conn, "ON\r\n");
        return;
    }

    $gpsFlag  = strtoupper(trim($parts[4]));
    $validity = strtoupper(trim($parts[6]));
    if ($gpsFlag !== 'F' || ($validity !== '' && $validity !== 'A')) {
        clilogTracker("IMEI $imei: no GPS fix (flag=$gpsFlag validity=$validity)", $protocol_name);
        safeFwrite($conn, "ON\r\n");
        return;
    }

    $datetime = tk103Datetime(trim($parts[2]), trim($parts[5]));
    $lat      = nmeaToDecimalTK((float)$parts[7], strtoupper(trim($parts[8])));
    $lon      = nmeaToDecimalTK((float)$parts[9], strtoupper(trim($parts[10])));
    $speed    = round((float)$parts[11] * 1.852, 1); // knots → km/h
    $course   = isset($parts[12]) && is_numeric(trim($parts[12]))
        ? (int)round((float)$parts[12])
        : null;

    clilogTracker(
        "TK103 IMEI:$imei $datetime Lat:$lat Lon:$lon Spd:{$speed}km/h",
        $protocol_name
    );

    $payload = [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed,
    ];
    if ($course !== null) $payload['angle'] = $course;

    sendToGrusher($imei, $payload);

    safeFwrite($conn, "ON\r\n");
}

function tk103Datetime(string $date, string $time): string {
    if (!preg_match('/^\d{10,}$/', $date) || (int)$date === 0) return '';

    $year  = 2000 + (int)substr($date, 0, 2);
    $month = (int)substr($date, 2, 2);
    $day   = (int)substr($date, 4, 2);
    $hour  = (int)substr($date, 6, 2);
    $min   = (int)substr($date, 8, 2);
    $sec   = 0;

    if (preg_match('/^(\d{2})(\d{2})(\d{2})/', $time, $m)) {
        $hour = (int)$m[1];
        $min  = (int)$m[2];
        $sec  = (int)$m[3];
    }

    if (!checkdate($month, $day, $year)) return '';

    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);
}

function nmeaToDecimalTK(float $nmea, string $dir): float {
    $degrees = floor($nmea / 100.0);
    $minutes = $nmea - $degrees * 100.0;
    $decimal = $degrees + $minutes / 60.0;
    return ($dir === 'S' || $dir === 'W') ? -$decimal : $decimal;
}
