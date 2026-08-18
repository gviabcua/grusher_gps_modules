<?php
// GalileoSky GPS protocol server (binary TCP)

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$connectionIMEIs = [];

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use (&$connectionIMEIs, $protocol_name) {
        processGalileoBuffer($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

function processGalileoBuffer($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);

        if ($len < 3) return;

        // Find header byte 0x01
        $start = strpos($buf, "\x01");
        if ($start === false) {
            $buffers[$id] = '';
            return;
        }
        if ($start > 0) {
            $buffers[$id] = substr($buf, $start);
            continue;
        }
        $lenField  = unpack('v', substr($buf, 1, 2))[1];
        $dataLen   = $lenField & 0x7FFF;
        $frameLen  = 1 + 2 + $dataLen + 2; // header + len + tags + crc

        if ($dataLen < 1 || $frameLen > 8192) {
            clilogTracker('Invalid packet length ' . $dataLen . ', skipping byte', $protocol_name);
            $buffers[$id] = substr($buf, 1);
            continue;
        }

        if ($len < $frameLen) return; // wait for more data

        $pktLen       = $frameLen;
        $packet       = substr($buf, 0, $frameLen);
        $buffers[$id] = substr($buf, $frameLen);

        clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);

        // Verify CRC (last 2 bytes, CRC-16/IBM over everything before them)
        $crcCalc    = galileoCrc16(substr($packet, 0, $pktLen - 2));
        $crcPacket  = unpack('v', substr($packet, $pktLen - 2, 2))[1];
        if ($crcCalc !== $crcPacket) {
            clilogTracker(
                'CRC mismatch: calc=' . dechex($crcCalc) . ' pkt=' . dechex($crcPacket),
                $protocol_name
            );
            continue;
        }

        // Parse TLV tags (offset 3 = after header(1) + len(2), until CRC)
        parseTLVPacket($conn, $packet, $id, $connectionIMEIs, $protocol_name);

        // ACK: 0x02 followed by the CRC of the packet we just accepted
        safeFwrite($conn, "\x02" . substr($packet, $pktLen - 2, 2));
    }
}

function parseTLVPacket($conn, $packet, $id, &$connectionIMEIs, $protocol_name) {
    $offset  = 3; // skip header(1) + len(2)
    $end     = strlen($packet) - 2; // stop before CRC

    $imei      = $connectionIMEIs[$id] ?? null;
    $timestamp = null;
    $lat       = null;
    $lon       = null;
    $speed     = null;
    $course    = null;
    $altitude  = null;
    $sats      = null;

    while ($offset < $end) {
        $tag = ord($packet[$offset++]);

        $size = galileoTagLength($tag);
        if ($size === null) {
            clilogTracker(
                sprintf('Unknown tag 0x%02X at offset %d — rest of packet skipped', $tag, $offset - 1),
                $protocol_name
            );
            break;
        }
        if ($offset + $size > $end) {
            clilogTracker(sprintf('Truncated value for tag 0x%02X', $tag), $protocol_name);
            break;
        }

        $value   = substr($packet, $offset, $size);
        $offset += $size;

        switch ($tag) {
            case 0x03: // IMEI (15 bytes ASCII)
                $imei = trim($value);
                $connectionIMEIs[$id] = $imei;
                clilogTracker("IMEI: $imei", $protocol_name);
                break;

            case 0x20: // Unix timestamp (4 bytes LE)
                $ts = unpack('V', $value)[1];
                if ($ts > 946684800) $timestamp = date('Y-m-d H:i:s', $ts);
                break;

            case 0x30: // Coordinates: flags(1) + lat(4 LE) + lon(4 LE), 1e-6 deg
                $flags = ord($value[0]);
                $sats  = $flags & 0x0F;
                $valid = (($flags >> 4) & 0x0F) === 0;
                $rawLat = galileoInt32(substr($value, 1, 4));
                $rawLon = galileoInt32(substr($value, 5, 4));
                if ($valid) {
                    $lat = $rawLat / 1000000.0;
                    $lon = $rawLon / 1000000.0;
                }
                break;

            case 0x33: // Speed (2 bytes LE, 0.1 km/h) + course (2 bytes LE, 0.1°)
                $speed  = round(unpack('v', substr($value, 0, 2))[1] * 0.1, 1);
                $course = (int)round(unpack('v', substr($value, 2, 2))[1] * 0.1);
                break;

            case 0x34: // Height (2 bytes LE signed, metres)
                $altitude = unpack('v', $value)[1];
                if ($altitude >= 0x8000) $altitude -= 0x10000;
                break;

            default:
                // Known length, value not needed.
                break;
        }
    }

    if ($imei === null) {
        clilogTracker('No IMEI in packet', $protocol_name);
        return;
    }
    if ($lat === null || $lon === null) {
        clilogTracker("IMEI $imei: no coordinates in packet", $protocol_name);
        return;
    }

    clilogTracker(
        "GalileoSky IMEI:$imei ts:$timestamp Lat:$lat Lon:$lon Spd:$speed Crs:$course Alt:$altitude Sats:$sats",
        $protocol_name
    );

    $payload = [
        'protocol_name' => $protocol_name,
        'last_alive'    => $timestamp,
        'lat'           => $lat,
        'lon'           => $lon,
    ];
    if ($speed    !== null) $payload['speed']  = $speed;
    if ($course   !== null) $payload['angle']  = $course;
    if ($altitude !== null) $payload['alt']    = $altitude;
    if ($sats     !== null) $payload['sats']   = $sats;

    sendToGrusher($imei, $payload);
}


function galileoTagLength(int $tag): ?int {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ([0x01, 0x02, 0x35, 0x43, 0xC4, 0xC5, 0xC6, 0xC7, 0xC8, 0xC9,
                  0xCA, 0xCB, 0xCC, 0xCD, 0xCE, 0xCF, 0xD0, 0xD1, 0xD2, 0xD3,
                  0xD4, 0xD6, 0xD7, 0xD8, 0xD9, 0xDA] as $t) {
            $map[$t] = 1;
        }
        foreach ([0x04, 0x10, 0x34, 0x40, 0x41, 0x42, 0x45, 0x46, 0x48, 0x49,
                  0x50, 0x51, 0x52, 0x53, 0x54, 0x55, 0x56, 0x57, 0x58, 0x59,
                  0x5A, 0x5B, 0x5C, 0x5D, 0x5E, 0x5F, 0x60, 0x61, 0x62, 0x63,
                  0x64, 0x65, 0x66, 0x67, 0x68, 0x69, 0x6A, 0x6B, 0x6C, 0x6D,
                  0x6E, 0x6F, 0xB0, 0xB1, 0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7,
                  0xB8, 0xB9, 0xD5] as $t) {
            $map[$t] = 2;
        }
        foreach ([0x20, 0x33, 0x44, 0x47, 0x90, 0xC0, 0xC1, 0xC2, 0xC3, 0xDB,
                  0xDC, 0xDD, 0xDE, 0xDF, 0xE0, 0xE1, 0xE2] as $t) {
            $map[$t] = 4;
        }
        $map[0x30] = 9;  // coordinates
        $map[0x03] = 15; // IMEI
        $map[0x04] = 2;
    }
    return $map[$tag] ?? null;
}

/** Signed 32-bit little-endian. */
function galileoInt32(string $bin): int {
    $u = unpack('V', $bin)[1];
    return ($u >= 0x80000000) ? $u - 0x100000000 : $u;
}

// CRC-16/IBM (poly 0xA001, init 0xFFFF)
function galileoCrc16(string $buf): int {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($buf); $i++) {
        $crc ^= ord($buf[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x01) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc & 0xFFFF;
}
