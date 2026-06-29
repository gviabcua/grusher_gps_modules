<?php
/**
 * Autofon SE-7 GPS protocol server
 *
 * NOTE: Experimental — not officially documented.
 *
 * Format (ASCII, comma-separated, terminated by \r\n):
 *   IMEI,DDMMYY,HHMMSS,validity,DDMM.MMMM,N,DDDMM.MMMM,E,speed_knots,course,battery_mV,gsm_signal,...
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__))); // FIX: was dirname(__FILE__)
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';

clilogTracker("Starting server (EXPERIMENTAL - not fully tested)...", $protocol_name);

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

        $id   = (int)$sock;
        $data = fread($sock, 2048);

        if ($data === false || $data === '') {
            clilogTracker('Connection closed', $protocol_name);
            fclose($sock);
            unset($clients[$id], $buffers[$id]);
            continue;
        }

        $buffers[$id] .= $data;

        while (($pos = strpos($buffers[$id], "\n")) !== false) {
            $line           = rtrim(substr($buffers[$id], 0, $pos), "\r\n");
            $buffers[$id]   = substr($buffers[$id], $pos + 1);
            if ($line === '') continue;
            clilogTracker("Line: $line", $protocol_name);
            parseAutofon7Line($sock, $line, $protocol_name);
        }
    }
}

function parseAutofon7Line($conn, $line, $protocol_name) {
    $parts = explode(',', $line);

    if (count($parts) < 12) {
        clilogTracker("Too few fields: $line", $protocol_name);
        return;
    }

    $imei    = preg_replace('/\D/', '', $parts[0]);
    $dateRaw = trim($parts[1]);
    $timeRaw = trim($parts[2]);

    $datetime = DateTime::createFromFormat('dmyHis', $dateRaw . $timeRaw);
    if (!$datetime) {
        clilogTracker("Invalid datetime: '$dateRaw $timeRaw'", $protocol_name);
        return;
    }
    $datetimeStr = $datetime->format('Y-m-d H:i:s'); // FIX: method call, not string interpolation

    $validity = strtoupper(trim($parts[3] ?? ''));
    if ($validity !== 'A' && $validity !== '1') {
        clilogTracker("GPS invalid (validity=$validity)", $protocol_name);
        return;
    }

    $lat = autofon7NmeaToDecimal((float)$parts[4], strtoupper($parts[5]));
    $lon = autofon7NmeaToDecimal((float)$parts[6], strtoupper($parts[7]));
    $speed_kmh = round((float)$parts[8] * 1.852, 1);
    $battery   = (float)($parts[10] ?? 0);   // mV
    $gsm       = (int)($parts[11] ?? 0);     // 0-31 (ASU)

    clilogTracker(
        "Autofon7 IMEI:$imei $datetimeStr Lat:$lat Lon:$lon Spd:{$speed_kmh}km/h Bat:{$battery}mV GSM:{$gsm}",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetimeStr,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed_kmh,
        'battery'       => $battery,
    ]);
}

function autofon7NmeaToDecimal(float $nmea, string $dir): float {
    $degrees = floor($nmea / 100.0);
    $minutes = $nmea - $degrees * 100.0;
    $decimal = $degrees + $minutes / 60.0;
    return ($dir === 'S' || $dir === 'W') ? -$decimal : $decimal;
}
