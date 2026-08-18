<?php
//Autofon SE-5 GPS protocol server
$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__))); // FIX: was dirname(__FILE__) — wrong dir
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';
$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use ($protocol_name) {
        while (($pos = strpos($buffers[$id], "\n")) !== false) {
            $line         = rtrim(substr($buffers[$id], 0, $pos), "\r\n");
            $buffers[$id] = substr($buffers[$id], $pos + 1);
            if ($line === '') continue;
            clilogTracker("Line: $line", $protocol_name);
            parseAutofon5Line($conn, $line, $protocol_name);
        }
    }
);

function parseAutofon5Line($conn, $line, $protocol_name) {
    $parts = explode(',', $line);

    if (count($parts) < 9) {
        clilogTracker("Too few fields: $line", $protocol_name);
        return;
    }

    $imei    = preg_replace('/\D/', '', $parts[0]);
    $dateRaw = trim($parts[1]); // DDMMYY
    $timeRaw = trim($parts[2]); // HHMMSS

    $datetime = DateTime::createFromFormat('dmyHis', $dateRaw . $timeRaw);
    if (!$datetime) {
        clilogTracker("Invalid datetime: '$dateRaw $timeRaw'", $protocol_name);
        return;
    }
    $datetimeStr = $datetime->format('Y-m-d H:i:s');

    $validity = strtoupper(trim($parts[3] ?? ''));
    if ($validity !== 'A' && $validity !== '1') {
        clilogTracker("GPS invalid (validity=$validity)", $protocol_name);
        return;
    }

    $lat = autofon5NmeaToDecimal((float)$parts[4], strtoupper($parts[5]));
    $lon = autofon5NmeaToDecimal((float)$parts[6], strtoupper($parts[7]));
    $speed_kmh = round((float)$parts[8] * 1.852, 1);

    clilogTracker(
        "Autofon5 IMEI:$imei $datetimeStr Lat:$lat Lon:$lon Spd:{$speed_kmh}km/h",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetimeStr,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed_kmh,
    ]);
}

function autofon5NmeaToDecimal(float $nmea, string $dir): float {
    $degrees = floor($nmea / 100.0);
    $minutes = $nmea - $degrees * 100.0;
    $decimal = $degrees + $minutes / 60.0;
    return ($dir === 'S' || $dir === 'W') ? -$decimal : $decimal;
}
