<?php
/**
 * H02 GPS protocol server
 *
 * H02 devices speak one of two dialects on the same port:
 *
 * 1) Text (by far the most common — "HQ" firmwares):
 *      *HQ,<imei>,V1,HHMMSS,A,DDMM.MMMM,N,DDDMM.MMMM,E,speed,course,DDMMYY,status#
 *      *HQ,<imei>,XT,...#     (heartbeat / config — answered, not stored)
 *
 * 2) Binary:
 *      Start   : 0x2424 ("$$")
 *      Len     : 1 byte  (total packet length, including the "$$" and length byte)
 *      Cmd     : 1 byte  (0x10 = location, 0x80 = alarm location)
 *      [4..9]  : YY MM DD HH MM SS  (BCD)
 *      [10..13]: latitude  DD MM.MMMM (BCD)      [14] : 'N'/'S'
 *      [15..18]: longitude DDD MM.MMMM (BCD)     [19] : 'E'/'W'
 *      [20]    : speed (BCD)                     [21..22]: course (BCD)
 *      [23..29]: IMEI (BCD)
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use ($protocol_name) {
        processH02Buffer($conn, $id, $buffers, $srv, $protocol_name);
    }
);

function processH02Buffer($conn, $id, &$buffers, GpsServer $srv, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);
        if ($len < 5) return;

        $textStart = strpos($buf, '*');
        $binStart  = strpos($buf, "\x24\x24");

        // Nothing recognisable at all — keep only a short tail so the buffer cannot grow without bound while we resynchronise.
        if ($textStart === false && $binStart === false) {
            $buffers[$id] = substr($buf, -1);
            return;
        }

        $useText = ($textStart !== false) && ($binStart === false || $textStart < $binStart);

        if ($useText) {
            if ($textStart > 0) {
                $buffers[$id] = substr($buf, $textStart);
                continue;
            }
            $end = strpos($buf, '#');
            if ($end === false) {
                // Guard against a peer that sends '*' and never a '#'.
                if ($len > 1024) $buffers[$id] = '';
                return;
            }
            $packet       = substr($buf, 0, $end + 1);
            $buffers[$id] = substr($buf, $end + 1);
            clilogTracker('RAW: ' . $packet, $protocol_name);
            parseH02Text($conn, $packet, $protocol_name);
            continue;
        }

        // ── Binary frame ────────────────────────────
        if ($binStart > 0) {
            $buffers[$id] = substr($buf, $binStart);
            continue;
        }

        $total = ord($buf[2]); // declared *total* packet length
        if ($total < 5 || $total > 255) {
            clilogTracker('Invalid binary length ' . $total . ' — resyncing', $protocol_name);
            $buffers[$id] = substr($buf, 2); // skip past this "$$"
            continue;
        }
        if ($len < $total) return; // wait for the rest

        $packet       = substr($buf, 0, $total);
        $buffers[$id] = substr($buf, $total);

        clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);
        parseH02Binary($conn, $packet, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
// Text dialect: *HQ,imei,V1,HHMMSS,A,lat,N,lon,E,speed,course,DDMMYY,status#
// ─────────────────────────────────────────────────────
function parseH02Text($conn, $packet, $protocol_name) {
    $body  = trim($packet, "*#\r\n");
    $parts = explode(',', $body);

    if (count($parts) < 3) {
        clilogTracker('Malformed text packet: ' . $body, $protocol_name);
        return;
    }

    $imei = preg_replace('/\D/', '', $parts[1]);
    $type = strtoupper(trim($parts[2]));

    // Location frames. V1/V4 carry a fix; heartbeats (HTBT/XT/…) do not.
    if ($type !== 'V1' && $type !== 'V4' && $type !== 'V19') {
        clilogTracker("IMEI $imei: non-location frame '$type'", $protocol_name);
        safeFwrite($conn, "*HQ,$imei,$type,OK#");
        return;
    }

    if (count($parts) < 12) {
        clilogTracker('Too few fields in text packet: ' . $body, $protocol_name);
        return;
    }

    $timeRaw  = trim($parts[3]);              // HHMMSS
    $validity = strtoupper(trim($parts[4]));  // A = valid, V = invalid
    $dateRaw  = trim($parts[11]);             // DDMMYY

    if ($validity !== 'A') {
        clilogTracker("IMEI $imei: GPS not fixed (validity=$validity)", $protocol_name);
        safeFwrite($conn, "*HQ,$imei,V1,OK#");
        return;
    }

    $datetime = '';
    if (preg_match('/^\d{6}$/', $dateRaw) && preg_match('/^\d{6}$/', $timeRaw)) {
        $datetime = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            2000 + (int)substr($dateRaw, 4, 2),
            (int)substr($dateRaw, 2, 2),
            (int)substr($dateRaw, 0, 2),
            (int)substr($timeRaw, 0, 2),
            (int)substr($timeRaw, 2, 2),
            (int)substr($timeRaw, 4, 2)
        );
    }

    $lat   = h02NmeaToDecimal((float)$parts[5], strtoupper(trim($parts[6])));
    $lon   = h02NmeaToDecimal((float)$parts[7], strtoupper(trim($parts[8])));
    $speed = round((float)$parts[9] * 1.852, 1); // knots → km/h
    $angle = (int)round((float)$parts[10]);

    clilogTracker(
        "H02 IMEI:$imei $datetime Lat:$lat Lon:$lon Spd:{$speed}km/h Crs:$angle",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed,
        'angle'         => $angle,
    ]);

    safeFwrite($conn, "*HQ,$imei,V1,OK#");
}

// ─────────────────────────────────────────────────────
// Binary dialect
// ─────────────────────────────────────────────────────
function parseH02Binary($conn, $packet, $protocol_name) {
    if (strlen($packet) < 4) return;

    $cmd = ord($packet[3]);
    if ($cmd !== 0x10 && $cmd !== 0x80) {
        clilogTracker('Unknown H02 command 0x' . sprintf('%02X', $cmd), $protocol_name);
        return;
    }
    if (strlen($packet) < 30) {
        clilogTracker('Packet too short for location data', $protocol_name);
        return;
    }

    $datetime = sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        2000 + bcdByte($packet[4]),  // YY
        bcdByte($packet[5]),         // MM
        bcdByte($packet[6]),         // DD
        bcdByte($packet[7]),
        bcdByte($packet[8]),
        bcdByte($packet[9])
    );

    // Latitude: 4 BCD bytes → 8 digits "DDMMMMMM" = DD° MM.MMMM'
    $latDigits = bcdDigits(substr($packet, 10, 4));
    $latitude  = (int)substr($latDigits, 0, 2)
               + ((float)(substr($latDigits, 2, 2) . '.' . substr($latDigits, 4, 4))) / 60.0;
    if (strtoupper($packet[14]) === 'S') $latitude = -$latitude;

    // Longitude: 4 BCD bytes → 8 digits "DDDMMMMM" = DDD° MM.MMM'
    $lonDigits = bcdDigits(substr($packet, 15, 4));
    $longitude = (int)substr($lonDigits, 0, 3)
               + ((float)(substr($lonDigits, 3, 2) . '.' . substr($lonDigits, 5, 3))) / 60.0;
    if (strtoupper($packet[19]) === 'W') $longitude = -$longitude;

    $speed = bcdByte($packet[20]);
    $angle = (int)bcdDigits(substr($packet, 21, 2));

    // Device id: 7 BCD bytes → 14 digits (as in the original implementation).
    $imei = bcdDigits(substr($packet, 23, 7));

    clilogTracker(
        "H02 IMEI:$imei $datetime Lat:$latitude Lon:$longitude Spd:$speed Crs:$angle",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $latitude,
        'lon'           => $longitude,
        'speed'         => $speed,
        'angle'         => $angle,
    ]);

    // ACK — sprintf with a fixed width so hex2bin() can never be handed an
    // odd-length string. dechex($cmd) produced one character for $cmd < 0x10.
    $ack = @hex2bin(sprintf('242405%02X00010D0A', $cmd));
    if ($ack !== false) safeFwrite($conn, $ack);
}

/** Decode one BCD byte to a decimal integer. */
function bcdByte(string $byte): int {
    $b = ord($byte);
    return (($b >> 4) & 0x0F) * 10 + ($b & 0x0F);
}

/**
 * Decode a run of BCD bytes into its digit string ("\x12\x34" → "1234").
 *
 * Uses %02X so that every byte contributes exactly two characters — a stray
 * A–F nibble from a corrupt packet keeps the field alignment intact instead of
 * shifting every following digit.
 */
function bcdDigits(string $bytes): string {
    $out = '';
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $out .= sprintf('%02X', ord($bytes[$i]));
    }
    return $out;
}

/** NMEA DDMM.MMMM → decimal degrees. */
function h02NmeaToDecimal(float $nmea, string $dir): float {
    $degrees = floor($nmea / 100.0);
    $minutes = $nmea - $degrees * 100.0;
    $decimal = $degrees + $minutes / 60.0;
    return ($dir === 'S' || $dir === 'W') ? -$decimal : $decimal;
}
