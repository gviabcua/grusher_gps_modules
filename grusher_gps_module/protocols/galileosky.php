<?php
/**
 * GalileoSky GPS protocol server (binary TCP)
 *
 * GalileoSky devices use a binary TLV (Tag-Length-Value) protocol,
 * NOT an ASCII "$GS..." format as the original AI-fabricated file assumed.
 *
 * Real packet structure:
 *   Header : 0x01
 *   Len    : 2 bytes LE (total packet length including header/len/crc)
 *   Tags   : sequence of Tag(1) + [optional Len(1)] + Value bytes
 *   CRC    : 2 bytes CRC-16/IBM
 *
 * Key tags (most common):
 *   0x03 = IMEI (15 bytes ASCII)
 *   0x20 = datetime (4 bytes Unix timestamp LE)
 *   0x30 = lat (4 bytes signed int32 LE, unit = 1e-7 degrees)
 *   0x31 = lon (4 bytes signed int32 LE, unit = 1e-7 degrees)
 *   0x33 = speed (2 bytes LE, km/h)
 *   0x34 = course (2 bytes LE, degrees)
 *   0x35 = altitude (2 bytes LE, meters)
 *   0x2B = satellites (1 byte)
 *   0xC4 = input/output status (variable)
 *
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__))); // FIX: was dirname(__FILE__)
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
        $data = fread($sock, 4096);

        if ($data === false || $data === '') {
            clilogTracker('Connection closed', $protocol_name);
            fclose($sock);
            unset($clients[$id], $connectionIMEIs[$id], $buffers[$id]);
            continue;
        }

        $buffers[$id] .= $data;
        processGalileoBuffer($sock, $id, $buffers, $connectionIMEIs, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
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
            $buf = $buffers[$id];
            $len = strlen($buf);
        }

        if ($len < 3) return;

        // Packet length: 2 bytes LE at offset 1 (includes header + len field + crc)
        $pktLen = unpack('v', substr($buf, 1, 2))[1];

        if ($pktLen < 5 || $pktLen > 4096) {
            clilogTracker('Invalid packet length ' . $pktLen . ', skipping byte', $protocol_name);
            $buffers[$id] = substr($buf, 1);
            continue;
        }

        if ($len < $pktLen) return; // wait for more data

        $packet       = substr($buf, 0, $pktLen);
        $buffers[$id] = substr($buf, $pktLen);

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

        // ACK: echo back first 3 bytes (header + len) with CRC
        $ack     = substr($packet, 0, 3);
        $ackCrc  = galileoCrc16($ack);
        fwrite($conn, $ack . pack('v', $ackCrc));
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
        if ($offset >= $end) break;
        $tag = ord($packet[$offset++]);

        switch ($tag) {
            case 0x03: // IMEI (15 bytes ASCII)
                if ($offset + 15 <= $end) {
                    $imei = rtrim(substr($packet, $offset, 15));
                    $connectionIMEIs[$id] = $imei;
                    clilogTracker("IMEI: $imei", $protocol_name);
                }
                $offset += 15;
                break;

            case 0x20: // Unix timestamp (4 bytes LE)
                if ($offset + 4 <= $end) {
                    $ts        = unpack('V', substr($packet, $offset, 4))[1];
                    $timestamp = date('Y-m-d H:i:s', $ts);
                }
                $offset += 4;
                break;

            case 0x30: // Latitude (4 bytes signed int32 LE, 1e-7 deg)
                if ($offset + 4 <= $end) {
                    $raw = unpack('V', substr($packet, $offset, 4))[1];
                    // convert unsigned to signed
                    if ($raw >= 0x80000000) $raw -= 0x100000000;
                    $lat = $raw / 10000000.0;
                }
                $offset += 4;
                break;

            case 0x31: // Longitude (4 bytes signed int32 LE, 1e-7 deg)
                if ($offset + 4 <= $end) {
                    $raw = unpack('V', substr($packet, $offset, 4))[1];
                    if ($raw >= 0x80000000) $raw -= 0x100000000;
                    $lon = $raw / 10000000.0;
                }
                $offset += 4;
                break;

            case 0x33: // Speed (2 bytes LE, km/h)
                if ($offset + 2 <= $end) {
                    $speed = unpack('v', substr($packet, $offset, 2))[1];
                }
                $offset += 2;
                break;

            case 0x34: // Course (2 bytes LE, degrees)
                if ($offset + 2 <= $end) {
                    $course = unpack('v', substr($packet, $offset, 2))[1];
                }
                $offset += 2;
                break;

            case 0x35: // Altitude (2 bytes LE, meters)
                if ($offset + 2 <= $end) {
                    $altitude = unpack('v', substr($packet, $offset, 2))[1];
                }
                $offset += 2;
                break;

            case 0x2B: // Satellites (1 byte)
                if ($offset + 1 <= $end) {
                    $sats = ord($packet[$offset]);
                }
                $offset += 1;
                break;

            default:
                // Unknown tag — skip 1 byte and continue scanning
                // (some tags have variable length; this is a best-effort skip)
                $offset++;
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
