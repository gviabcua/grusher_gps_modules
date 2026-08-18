<?php
// GT06 / JM-LL01 GPS protocol server

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';
require_once WORK_DIR . '/gps_server.php';

$connectionIMEIs = [];   // (int)$conn => imei string

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use (&$connectionIMEIs, $protocol_name) {
        processBuffer($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

// ─────────────────────────────────────────────────────
// Buffer processor — extracts complete GT06 packets
// ─────────────────────────────────────────────────────
function processBuffer($conn, $id, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);

        if ($len < 5) return;

        // ── ASCII login: 000F + 15 digits ─────────
        if (substr($buf, 0, 2) === "\x00\x0F") {
            if ($len < 17) return; // not enough data yet
            $imei = preg_replace('/\D/', '', substr($buf, 2, 15));
            $connectionIMEIs[$id] = $imei;
            clilogTracker("IMEI: $imei", $protocol_name);
            $buffers[$id] = substr($buf, 17);
            continue;
        }

        $start2 = substr($buf, 0, 2);

        // ── Standard frame 7878, 1-byte length ────
        if ($start2 === "\x78\x78") {
            $bodyLen = ord($buf[2]);
            $total   = 2 + 1 + $bodyLen + 2; // start + len + body + 0D0A
            $hdr     = 3;
        // ── Extended frame 7979, 2-byte length ────
        } elseif ($start2 === "\x79\x79") {
            if ($len < 4) return;
            $bodyLen = unpack('n', substr($buf, 2, 2))[1];
            $total   = 2 + 2 + $bodyLen + 2;
            $hdr     = 4;
        } else {
            // Unknown data — discard one byte and retry
            clilogTracker('Unknown byte 0x' . bin2hex($buf[0]) . ', skipping', $protocol_name);
            $buffers[$id] = substr($buf, 1);
            continue;
        }

        if ($bodyLen < 5 || $total > 2048) {
            clilogTracker("Implausible frame length $bodyLen — resyncing", $protocol_name);
            $buffers[$id] = substr($buf, 2);
            continue;
        }
        if ($len < $total) return; // wait for more data

        // Validate the terminator before consuming the frame; without this a
        // corrupt length byte silently desynchronises the whole stream.
        if (substr($buf, $total - 2, 2) !== "\x0D\x0A") {
            clilogTracker('Bad end marker — resyncing', $protocol_name);
            $buffers[$id] = substr($buf, 2);
            continue;
        }

        $packet       = substr($buf, 0, $total);
        $buffers[$id] = substr($buf, $total);

        clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);

        // CRC-16/X25 over <len> … <serial> (everything except start and 0D0A/CRC)
        $crcPacket = unpack('n', substr($packet, $total - 4, 2))[1];
        $crcCalc   = crc16gt06(substr($packet, 2, $total - 6));
        // Logged, not enforced: firmware variants differ, and silently dropping
        // real positions would be worse than accepting a rare corrupt one.
        if ($crcPacket !== $crcCalc) {
            clilogTracker(
                sprintf('CRC mismatch: packet=%04X calculated=%04X', $crcPacket, $crcCalc),
                $protocol_name
            );
        }

        parseGT06Packet($conn, $packet, $hdr, $id, $connectionIMEIs, $protocol_name);
    }
}

function parseGT06Packet($conn, $packet, $hdr, $id, &$connectionIMEIs, $protocol_name) {
    $protocolId = ord($packet[$hdr]);

    // 0x01 = login with IMEI in binary BCD
    if ($protocolId === 0x01) {
        $imeiHex = bin2hex(substr($packet, $hdr + 1, 8));
        $imei    = ltrim($imeiHex, '0');
        $connectionIMEIs[$id] = $imei;
        clilogTracker("IMEI (BCD): $imei", $protocol_name);
        sendGT06Ack($conn, $packet, $hdr);
        return;
    }

    // 0x12 = location data (older firmware)
    // 0x22 = location data (common firmware)
    if ($protocolId === 0x12 || $protocolId === 0x22) {
        if (!isset($connectionIMEIs[$id])) {
            clilogTracker('Got GPS packet but no IMEI yet — ignored', $protocol_name);
            sendGT06Ack($conn, $packet, $hdr);
            return;
        }
        $imei = $connectionIMEIs[$id];

        // Location payload starts right after the protocol byte and needs
        // 6 (datetime) + 1 (sats) + 4 (lat) + 4 (lon) + 1 (speed) + 2 (course).
        $p = $hdr + 1;
        if (strlen($packet) < $p + 18) {
            clilogTracker('Location packet truncated — ignored', $protocol_name);
            sendGT06Ack($conn, $packet, $hdr);
            return;
        }

        $datetime = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            ord($packet[$p])     + 2000,
            ord($packet[$p + 1]),
            ord($packet[$p + 2]),
            ord($packet[$p + 3]),
            ord($packet[$p + 4]),
            ord($packet[$p + 5])
        );

        // GPS info byte — upper 4 bits = satellite count
        $satellites = (ord($packet[$p + 6]) >> 4) & 0x0F;

        // Latitude / longitude, unit = 1/1800000 degree
        $latitude  = unpack('N', substr($packet, $p + 7,  4))[1] / 1800000.0;
        $longitude = unpack('N', substr($packet, $p + 11, 4))[1] / 1800000.0;

        $speed = ord($packet[$p + 15]);

        $courseStatus = unpack('n', substr($packet, $p + 16, 2))[1];
        $course       = $courseStatus & 0x03FF;
        $gpsLocated   = ($courseStatus >> 12) & 0x01; // 1 = located
        $east         = ($courseStatus >> 11) & 0x01; // 1 = East
        $north        = ($courseStatus >> 10) & 0x01; // 1 = North

        if (!$gpsLocated) {
            clilogTracker("IMEI $imei: GPS not located — packet ignored", $protocol_name);
            sendGT06Ack($conn, $packet, $hdr);
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

        sendGT06Ack($conn, $packet, $hdr);
        return;
    }

    // Heartbeat / status (0x13, 0x23), LBS (0x11, 0x28), alarm (0x16, 0x26),
    // information (0x94) and time sync (0x8A) all expect an acknowledgement.
    // Without one the device assumes the link is dead and reconnects in a loop.
    clilogTracker('Packet type 0x' . sprintf('%02X', $protocolId) . ' — acknowledged', $protocol_name);
    sendGT06Ack($conn, $packet, $hdr);
}

function sendGT06Ack($conn, $packet, $hdr) {
    $protocolId = ord($packet[$hdr]);
    // Serial number sits just before the CRC and the 0D0A terminator.
    $serial = substr($packet, -6, 2);

    $body = chr(0x05) . chr($protocolId) . $serial;   // len + protocol + serial
    $crc  = crc16gt06($body);

    $ack = "\x78\x78" . $body . pack('n', $crc) . "\x0D\x0A";
    safeFwrite($conn, $ack);
}

/**
 * CRC-16/X25 (poly 0x8408 reflected, init 0xFFFF, final XOR 0xFFFF)
 * — the checksum GT06 devices actually use.
 */
function crc16gt06(string $buf): int {
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
