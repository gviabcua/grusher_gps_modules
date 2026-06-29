<?php
/**
 * GT06 / JM-LL01 GPS protocol server
 *
 * Packet format (binary TCP):
 *   Login  : 000F + 15-byte ASCII IMEI
 *   GPS    : 7878 + len(1) + 0x22 or 0x12 + datetime(6) + sats_lat_info(1) + lat(4) + lon(4) + speed(1) + course_status(2) + serial(2) + crc(2) + 0D0A
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

$clients         = [];   // (int)$conn => $conn
$connectionIMEIs = [];   // (int)$conn => imei string
$buffers         = [];   // (int)$conn => raw binary buffer

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
                $clients[$id]  = $conn;
                $buffers[$id]  = '';
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
        processBuffer($sock, $id, $buffers, $connectionIMEIs, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
// Buffer processor — extracts complete GT06 packets
// ─────────────────────────────────────────────────────
function processBuffer($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);

        if ($len < 4) return;

        // ── Login packet: 000F + 15 ASCII digits ─
        if (substr($buf, 0, 2) === "\x00\x0F") {
            if ($len < 17) return; // not enough data yet
            $imei = substr($buf, 2, 15);
            $imei = preg_replace('/\D/', '', $imei);
            $connectionIMEIs[$id] = $imei;
            clilogTracker("IMEI: $imei", $protocol_name);
            $buffers[$id] = substr($buf, 17);
            // No ACK required for login on most GT06 variants
            continue;
        }

        // ── Data packet: 7878 + length(1) + ... + 0D0A ─
        if (substr($buf, 0, 2) === "\x78\x78") {
            $pktLen = ord($buf[2]); // body length (after start+len bytes, before 0D0A)
            $total  = 2 + 1 + $pktLen + 2; // start(2) + len(1) + body($pktLen) + 0D0A(2)
            if ($len < $total) return;

            $packet      = substr($buf, 0, $total);
            $buffers[$id] = substr($buf, $total);

            clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);
            parseGT06Packet($conn, $packet, $id, $connectionIMEIs, $protocol_name);
            continue;
        }

        // Unknown data — discard one byte and retry
        clilogTracker('Unknown byte 0x' . bin2hex($buf[0]) . ', skipping', $protocol_name);
        $buffers[$id] = substr($buf, 1);
    }
}

function parseGT06Packet($conn, $packet, $id, &$connectionIMEIs, $protocol_name) {
    $protocolId = ord($packet[3]);

    // 0x01 = login with IMEI in binary BCD (some firmware variants)
    if ($protocolId === 0x01) {
        $imeiHex = bin2hex(substr($packet, 4, 8));
        $imei    = ltrim($imeiHex, '0');
        $connectionIMEIs[$id] = $imei;
        clilogTracker("IMEI (BCD): $imei", $protocol_name);
        sendGT06Ack($conn, $packet);
        return;
    }

    // 0x12 = location data (older firmware)
    // 0x22 = location data (common firmware)
    if ($protocolId === 0x12 || $protocolId === 0x22) {
        if (!isset($connectionIMEIs[$id])) {
            clilogTracker('Got GPS packet but no IMEI yet — ignored', $protocol_name);
            return;
        }
        $imei = $connectionIMEIs[$id];

        // Bytes 4-9: YY MM DD HH MM SS
        $year  = ord($packet[4])  + 2000;
        $month = ord($packet[5]);
        $day   = ord($packet[6]);
        $hour  = ord($packet[7]);
        $min   = ord($packet[8]);
        $sec   = ord($packet[9]);
        $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);

        // Byte 10: GPS info byte — upper 4 bits = sats
        $gpsInfo   = ord($packet[10]);
        $satellites = ($gpsInfo >> 4) & 0x0F;

        // Bytes 11-14: latitude (unsigned 32-bit big-endian, unit = 1/1800000 degree)
        $latRaw = unpack('N', substr($packet, 11, 4))[1];
        // Bytes 15-18: longitude
        $lngRaw = unpack('N', substr($packet, 15, 4))[1];

        $latitude  = $latRaw / 1800000.0;
        $longitude = $lngRaw / 1800000.0;

        // Byte 19: speed in km/h
        $speed = ord($packet[19]);

        // Bytes 20-21: course + status flags
        $courseStatus = unpack('n', substr($packet, 20, 2))[1];
        $course       = $courseStatus & 0x03FF;

        // Status flags
        $gpsRealtime = ($courseStatus >> 13) & 0x01; // 1 = real-time, 0 = differential
        $gpsLocated  = ($courseStatus >> 12) & 0x01; // 1 = located
        $east        = ($courseStatus >> 11) & 0x01; // 1 = East
        $north       = ($courseStatus >> 10) & 0x01; // 1 = North

        if (!$gpsLocated) {
            clilogTracker("IMEI $imei: GPS not located — packet ignored", $protocol_name);
            sendGT06Ack($conn, $packet);
            return;
        }

        if (!$east)  $longitude = -$longitude;
        if (!$north) $latitude  = -$latitude;

        clilogTracker(
            "GT06 IMEI:$imei $datetime Lat:$latitude Lon:$longitude Spd:$speed Crs:$course Sats:$satellites",
            $protocol_name
        );

        sendToGrusher($imei, [
            'protocol_name' => $protocol_name,
            'last_alive'    => $datetime,
            'lat'           => $latitude,
            'lon'           => $longitude,
            'speed'         => $speed,
            'angle'         => $course,
            'sats'          => $satellites,
        ]);

        sendGT06Ack($conn, $packet);
        return;
    }

    clilogTracker('Unknown packet type 0x' . dechex($protocolId), $protocol_name);
}

function sendGT06Ack($conn, $packet) {
    // ACK: 7878 05 <protocolId> <serial2> <crc2> 0D0A
    $protocolId = ord($packet[3]);
    // Serial number is 2 bytes before the final CRC+0D0A
    $serial = substr($packet, -6, 2);

    $body       = chr(0x05) . chr($protocolId) . $serial;
    $crcInput   = $body;
    $crc        = crc16gt06($crcInput);
    $crcHex     = str_pad(dechex($crc), 4, '0', STR_PAD_LEFT); // FIX: zero-pad to 4 chars

    $ack = "\x78\x78" . $body . hex2bin($crcHex) . "\x0D\x0A";
    fwrite($conn, $ack);
}

// CRC-16/IBM (poly 0xA001, init 0xFFFF) — standard for GT06
function crc16gt06(string $buf): int {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($buf); $i++) {
        $crc ^= ord($buf[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x01) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc & 0xFFFF;
}
