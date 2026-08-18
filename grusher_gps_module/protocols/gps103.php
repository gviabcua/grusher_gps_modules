<?php
/**
 * GPS103 / TK103-clone GPS protocol server (text-based)
 *
 * Real on-the-wire formats (ASCII, terminated by ';' and usually \r\n):
 *
 *   Login     : ##,imei:359586015829802,A;          → reply "LOAD"
 *   Heartbeat : 359586015829802;                    → reply "ON"
 *   Position  : imei:359586015829802,tracker,1809231929,,F,112909.397,A,
 *               2234.4669,N,11354.3287,E,0.11,0;
 *
 * Field layout of a position line:
 *   [0] imei:<digits>   [1] alarm/type      [2] YYMMDDHHMM   [3] phone number
 *   [4] F|L             [5] HHMMSS.sss      [6] A|V          [7] DDMM.MMMM
 *   [8] N|S             [9] DDDMM.MMMM     [10] E|W         [11] speed (knots)
 *  [12] course
 *
 * NOTE: position lines start with "imei:" as well. The previous version
 * treated every such line as a login handshake and returned immediately, so no
 * position was ever forwarded; the field indexes it used were shifted by one
 * on top of that.
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
        gps103ReadLines($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

/**
 * Split the buffer into messages. Devices terminate with ';' and most, but not
 * all, firmwares add \r\n — splitting on the newline alone leaves the last
 * message stuck in the buffer forever on the ones that do not.
 */
function gps103ReadLines($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $pos = strpos($buf, ';');
        if ($pos === false) {
            $nl = strpos($buf, "\n");
            if ($nl === false) return;
            $pos = $nl;
        }

        $line         = trim(substr($buf, 0, $pos + 1), " \t\r\n;");
        $buffers[$id] = ltrim(substr($buf, $pos + 1), "\r\n");

        if ($line === '') continue;
        clilogTracker("Line: $line", $protocol_name);
        parseGPS103Line($conn, $line, $id, $connectionIMEIs, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
function parseGPS103Line($conn, $line, $id, &$connectionIMEIs, $protocol_name) {
    $parts = explode(',', $line);

    // ── Login: ##,imei:<digits>,A ───────────────────
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

    // ── Heartbeat: bare IMEI digits ─────────────────
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

    // Short frames are keep-alives / command replies, not positions.
    if (count($parts) < 12) {
        clilogTracker("IMEI $imei: non-position message (" . count($parts) . ' fields)', $protocol_name);
        safeFwrite($conn, "ON\r\n");
        return;
    }

    $alarm    = trim($parts[1]);
    $gpsFlag  = strtoupper(trim($parts[4]));   // F = GPS fix, L = LBS only
    $validity = strtoupper(trim($parts[6]));   // A = valid, V = invalid

    if ($gpsFlag !== 'F' || ($validity !== '' && $validity !== 'A')) {
        clilogTracker("IMEI $imei: no GPS fix (flag=$gpsFlag validity=$validity)", $protocol_name);
        safeFwrite($conn, "ON\r\n"); 
        return;
    }

    $datetime = gps103Datetime(trim($parts[2]), trim($parts[5]));

    // Coordinates: NMEA DDMM.MMMM → decimal degrees
    $lat = nmeaToDecimal((float)$parts[7], strtoupper(trim($parts[8])));
    $lon = nmeaToDecimal((float)$parts[9], strtoupper(trim($parts[10])));

    // Speed: knots → km/h
    $speed_kmh = round((float)$parts[11] * 1.852, 1);
    $course    = isset($parts[12]) && is_numeric(trim($parts[12]))
        ? (int)round((float)$parts[12])
        : null;

    clilogTracker(
        "GPS103 IMEI:$imei $datetime Lat:$lat Lon:$lon Spd:{$speed_kmh}km/h Crs:$course Alarm:$alarm",
        $protocol_name
    );

    $payload = [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed_kmh,
    ];
    if ($course !== null) $payload['angle'] = $course;

    sendToGrusher($imei, $payload);

    safeFwrite($conn, "ON\r\n");
}

function gps103Datetime(string $date, string $time): string {
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

/**
 * Convert NMEA DDMM.MMMM (or DDDMM.MMMM) to decimal degrees.
 * Accepts the direction indicator (N/S/E/W) and applies the sign.
 */
function nmeaToDecimal(float $nmea, string $dir): float {
    $degrees = floor($nmea / 100.0);
    $minutes = $nmea - $degrees * 100.0;
    $decimal = $degrees + $minutes / 60.0;
    return ($dir === 'S' || $dir === 'W') ? -$decimal : $decimal;
}
