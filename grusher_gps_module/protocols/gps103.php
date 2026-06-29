<?php
/**
 * GPS103 / TK103-clone GPS protocol server (text-based)
 *
 * Packet format (ASCII, terminated by \r\n):
 *   Login : imei:XXXXXXXXXXXXXXX,tracker;
 *   GPS   : imei:...,tracker,DDMMYYHHMMSS,,F,DDMM.MMMM,N,DDDMM.MMMM,E,speed,course;
 *
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';

clilogTracker('Starting server...', $protocol_name);

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
    clilogTracker("Cannot create socket: $errstr ($errno)", $protocol_name);
    exit(1);
}
stream_set_blocking($server, false);
clilogTracker("Server started on $host:$port", $protocol_name);

$clients         = [];
$connectionIMEIs = [];
$buffers         = [];

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

        $id   = (int)$sock;
        $data = fread($sock, 2048);

        if ($data === false || $data === '') {
            clilogTracker('Connection closed', $protocol_name);
            fclose($sock);
            unset($clients[$id], $connectionIMEIs[$id], $buffers[$id]);
            continue;
        }

        $buffers[$id] .= $data;

        // Process complete lines (terminated by \r\n or \n)
        while (($pos = strpos($buffers[$id], "\n")) !== false) {
            $line           = rtrim(substr($buffers[$id], 0, $pos), "\r\n");
            $buffers[$id]   = substr($buffers[$id], $pos + 1);
            if ($line === '') continue;
            clilogTracker("Line: $line", $protocol_name);
            parseGPS103Line($sock, $line, $id, $connectionIMEIs, $protocol_name);
        }
    }
}

// ─────────────────────────────────────────────────────
function parseGPS103Line($conn, $line, $id, &$connectionIMEIs, $protocol_name) {
    // Strip trailing semicolon if present
    $line = rtrim($line, ';');

    $parts = explode(',', $line);

    // ── Login packet: imei:XXXXXXXXXXXXXXX,tracker ──
    if (str_starts_with($parts[0], 'imei:')) {
        $imei = substr($parts[0], 5);
        $imei = preg_replace('/\D/', '', $imei);
        $connectionIMEIs[$id] = $imei;
        clilogTracker("IMEI: $imei", $protocol_name);
        // ACK: device expects "LOAD" or just no reply — no ACK needed for login
        return;
    }

    // ── Data packet ─────────────────────────────────
    if (!isset($connectionIMEIs[$id])) {
        clilogTracker('No IMEI yet — ignored', $protocol_name);
        return;
    }
    $imei = $connectionIMEIs[$id];

    // Minimum fields: [type, datetime, alarm, validity, lat, NS, lon, EW, speed, ...]
    if (count($parts) < 9) {
        clilogTracker("Too few fields ($line)", $protocol_name);
        return;
    }

    $validity = strtoupper(trim($parts[3]));
    if ($validity !== 'F') {
        clilogTracker("GPS invalid (validity=$validity)", $protocol_name);
        fwrite($conn, "ON\r\n"); // keep alive even on invalid fix
        return;
    }

    // Date/time: DDMMYYHHMMSS (12 chars) or DDMMYYHHMM (10 chars)
    $dt = $parts[1];
    if (strlen($dt) < 10) {
        clilogTracker("Bad datetime: $dt", $protocol_name);
        return;
    }
    $day   = substr($dt, 0, 2);
    $mon   = substr($dt, 2, 2);
    $yr    = 2000 + (int)substr($dt, 4, 2);
    $hr    = substr($dt, 6, 2);
    $min   = substr($dt, 8, 2);
    $sec   = strlen($dt) >= 12 ? substr($dt, 10, 2) : '00';
    $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $yr, $mon, $day, $hr, $min, $sec);

    // Coordinates: NMEA DDMM.MMMM → decimal degrees
    $lat = nmeaToDecimal((float)$parts[4], strtoupper($parts[5]));
    $lon = nmeaToDecimal((float)$parts[6], strtoupper($parts[7]));

    // Speed: knots → km/h
    $speed_kmh = round((float)$parts[8] * 1.852, 1);

    clilogTracker(
        "GPS103 IMEI:$imei $datetime Lat:$lat Lon:$lon Spd:{$speed_kmh}km/h",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed_kmh,
    ]);

    fwrite($conn, "ON\r\n");
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
