<?php
/**
 * GT02A GPS protocol server (binary TCP)
 *
 * Packet format (very similar to GT06):
 *   Start  : 0x7878
 *   Len    : 1 byte (number of bytes that follow, before 0D0A)
 *   Cmd    : 1 byte
 *   Body   : variable
 *   Serial : 2 bytes
 *   CRC    : 2 bytes (CRC-16/IBM over Cmd+Body+Serial)
 *   End    : 0x0D0A
 *
 *   Login  cmd=0x01: 8-byte IMEI (BCD, 15 digits in 8 bytes, last nibble = 0xF pad)
 *   GPS    cmd=0x12/0x22: datetime(6) + sat_info(1) + lat(4) + lon(4) + speed(1) + course_status(2) + serial(2)
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
        processGT02ABuffer($sock, $id, $buffers, $connectionIMEIs, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
function processGT02ABuffer($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);

        if ($len < 5) return;

        // Find 0x7878 start marker
        $start = strpos($buf, "\x78\x78");
        if ($start === false) {
            if ($len > 1) $buffers[$id] = substr($buf, -1);
            return;
        }
        if ($start > 0) {
            $buffers[$id] = substr($buf, $start);
            $buf = $buffers[$id];
            $len = strlen($buf);
        }

        if ($len < 3) return;

        // Total packet = start(2) + len_byte(1) + body(len_byte) + 0D0A(2)
        $bodyLen = ord($buf[2]);
        $total   = 2 + 1 + $bodyLen + 2;

        if ($len < $total) return;

        // Verify end marker
        if (substr($buf, $total - 2, 2) !== "\x0D\x0A") {
            clilogTracker('Bad end marker — skipping 1 byte', $protocol_name);
            $buffers[$id] = substr($buf, 1);
            continue;
        }

        $packet       = substr($buf, 0, $total);
        $buffers[$id] = substr($buf, $total);

        clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);
        parseGT02APacket($conn, $packet, $id, $connectionIMEIs, $protocol_name);
    }
}

function parseGT02APacket($conn, $packet, $id, &$connectionIMEIs, $protocol_name) {
    $cmd = ord($packet[3]);

    // ── Login (cmd=0x01) ─────────────────────────
    if ($cmd === 0x01) {
        // 8 bytes BCD IMEI at position 4
        $imeiRaw = substr($packet, 4, 8);
        $imei    = '';
        for ($i = 0; $i < 8; $i++) {
            $b = ord($imeiRaw[$i]);
            $h = ($b >> 4) & 0x0F;
            $l = $b & 0x0F;
            if ($h <= 9) $imei .= $h;
            if ($l <= 9) $imei .= $l; // skip 0xF padding
        }
        $connectionIMEIs[$id] = $imei;
        clilogTracker("IMEI: $imei", $protocol_name);

        // ACK for login
        $serial = substr($packet, -4, 2);
        sendGT02AAck($conn, $cmd, $serial);
        return;
    }

    // ── GPS position (cmd=0x12 or 0x22) ─────────
    if ($cmd === 0x12 || $cmd === 0x22) {
        if (!isset($connectionIMEIs[$id])) {
            clilogTracker('No IMEI yet — ignored', $protocol_name);
            return;
        }
        $imei = $connectionIMEIs[$id];

        if (strlen($packet) < 25) {
            clilogTracker('Packet too short for GPS', $protocol_name);
            return;
        }

        $year  = ord($packet[4])  + 2000;
        $month = ord($packet[5]);
        $day   = ord($packet[6]);
        $hour  = ord($packet[7]);
        $min   = ord($packet[8]);
        $sec   = ord($packet[9]);
        $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);

        $gpsInfo   = ord($packet[10]);
        $satellites = ($gpsInfo >> 4) & 0x0F;

        $latRaw = unpack('N', substr($packet, 11, 4))[1];
        $lngRaw = unpack('N', substr($packet, 15, 4))[1];

        $latitude  = $latRaw / 1800000.0;
        $longitude = $lngRaw / 1800000.0;

        $speed = ord($packet[19]);

        $courseStatus = unpack('n', substr($packet, 20, 2))[1];
        $course       = $courseStatus & 0x03FF;
        $gpsLocated   = ($courseStatus >> 12) & 0x01;
        $east         = ($courseStatus >> 11) & 0x01;
        $north        = ($courseStatus >> 10) & 0x01;

        if (!$gpsLocated) {
            clilogTracker("GPS not located — ignored", $protocol_name);
            $serial = substr($packet, -4, 2);
            sendGT02AAck($conn, $cmd, $serial);
            return;
        }

        if (!$east)  $longitude = -$longitude;
        if (!$north) $latitude  = -$latitude;

        clilogTracker(
            "GT02A IMEI:$imei $datetime Lat:$latitude Lon:$longitude Spd:$speed Crs:$course Sats:$satellites",
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

        $serial = substr($packet, -4, 2);
        sendGT02AAck($conn, $cmd, $serial);
        return;
    }

    clilogTracker('Unknown GT02A cmd 0x' . dechex($cmd), $protocol_name);
}

function sendGT02AAck($conn, $cmd, $serial) {
    // Body = cmd(1) + serial(2) — CRC covers body
    $body    = chr($cmd) . $serial;
    $crc     = crc16gt02a($body);
    $crcHex  = str_pad(dechex($crc), 4, '0', STR_PAD_LEFT);
    $lenByte = chr(strlen($body) + 2); // body + crc(2)
    $ack     = "\x78\x78" . $lenByte . $body . hex2bin($crcHex) . "\x0D\x0A";
    fwrite($conn, $ack);
}

// CRC-16/IBM (same as GT06)
function crc16gt02a(string $buf): int {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($buf); $i++) {
        $crc ^= ord($buf[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x01) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc & 0xFFFF;
}
