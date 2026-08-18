<?php
// GT02A GPS protocol server (binary TCP)

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$connectionIMEIs = [];

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use (&$connectionIMEIs, $protocol_name) {
        processGT02ABuffer($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

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

        if ($bodyLen < 5) {
            clilogTracker("Implausible frame length $bodyLen — resyncing", $protocol_name);
            $buffers[$id] = substr($buf, 2);
            continue;
        }
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
        // 15-digit IMEIs are padded with a leading zero nibble; drop it so the tracker id matches what GT06 and the other protocols report.
        $imei = ltrim($imei, '0');
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

    // Heartbeat and status frames must be acknowledged too, otherwise the device treats the link as dead and reconnects in a loop.
    clilogTracker('GT02A cmd 0x' . sprintf('%02X', $cmd) . ' — acknowledged', $protocol_name);
    sendGT02AAck($conn, $cmd, substr($packet, -4, 2));
}

function sendGT02AAck($conn, $cmd, $serial) {
    // Frame = 7878 <len> <cmd> <serial:2> <crc:2> 0D0A, CRC over <len>…<serial>
    $lenByte = chr(5);
    $body    = $lenByte . chr($cmd) . $serial;
    $crc     = crc16gt02a($body);
    // pack() instead of hex2bin(dechex()): dechex() drops leading zeroes and
    // produced an odd-length string that hex2bin() rejected.
    $ack     = "\x78\x78" . $body . pack('n', $crc) . "\x0D\x0A";
    safeFwrite($conn, $ack);
}

// CRC-16/X25 ("CRC-ITU") — the checksum GT02A/GT06 devices actually use.
function crc16gt02a(string $buf): int {
    $crc = 0xFFFF;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= ord($buf[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x01) ? (($crc >> 1) ^ 0x8408) : ($crc >> 1);
        }
    }
    return (~$crc) & 0xFFFF;
}
