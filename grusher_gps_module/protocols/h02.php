<?php
/**
 * H02 GPS protocol server (binary TCP)
 *
 * Packet format:
 *   Start   : 0x2424 ("$$")
 *   Len     : 1 byte
 *   Cmd     : 1 byte  (0x10 = GPS location, 0x80 = alarm location)
 *   Datetime: 7 bytes (YY MM DD HH MM SS — BCD packed)
 *   Lat     : 4 bytes (degrees BCD, minutes BCD, decimal minutes BCD×100)
 *   N/S     : 1 byte  ASCII 'N' or 'S'
 *   Lon     : 4 bytes
 *   E/W     : 1 byte  ASCII 'E' or 'W'
 *   Speed   : 1 byte
 *   ... course, status, IMEI ...
 *   End     : 0x0D0A
 *
 * H02 embeds the IMEI inside the packet body (not a separate login),
 * so we extract it from every packet.
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
        processH02Buffer($sock, $id, $buffers, $protocol_name);
    }
}

// ─────────────────────────────────────────────────────
function processH02Buffer($conn, $id, &$buffers, $protocol_name) {
    while (true) {
        $buf = $buffers[$id];
        $len = strlen($buf);

        // Find "$$" start marker
        $start = strpos($buf, "\x24\x24");
        if ($start === false) {
            // No marker — discard all but last byte (could be partial "$$")
            if ($len > 1) $buffers[$id] = substr($buf, -1);
            return;
        }
        if ($start > 0) {
            $buffers[$id] = substr($buf, $start);
            $buf = $buffers[$id];
            $len = strlen($buf);
        }

        if ($len < 3) return;
        $pktLen = ord($buf[2]); // declared body length
        $total  = 2 + 1 + $pktLen; // "$$" + len byte + body

        if ($len < $total) return;

        $packet       = substr($buf, 0, $total);
        $buffers[$id] = substr($buf, $total);

        clilogTracker('RAW: ' . bin2hex($packet), $protocol_name);
        parseH02Packet($conn, $packet, $protocol_name);
    }
}

function parseH02Packet($conn, $packet, $protocol_name) {
    if (strlen($packet) < 4) return;

    $cmd = ord($packet[3]);

    // Only handle location packets (0x10 standard, 0x80 alarm)
    if ($cmd !== 0x10 && $cmd !== 0x80) {
        clilogTracker('Unknown H02 command 0x' . dechex($cmd), $protocol_name);
        return;
    }

    if (strlen($packet) < 32) {
        clilogTracker('Packet too short for location data', $protocol_name);
        return;
    }

    // Bytes 4-6: YY MM DD (BCD)
    // Bytes 7-9: HH MM SS (BCD) — NOTE: time comes AFTER the N/S in some variants
    // The layout below matches the most common H02 variant (0x2424 len 0x10 ...):
    //   [0-1]=$$  [2]=len  [3]=cmd
    //   [4]=YY  [5]=MM  [6]=DD  [7]=HH  [8]=MM  [9]=SS
    //   [10-13]=lat(BCD deg+min)  [14]=N/S
    //   [15-18]=lon(BCD deg+min)  [19]=E/W
    //   [20]=speed  [21-22]=course  [23-29]=IMEI(BCD 7bytes=14digits+parity)
    //   ... [last-2,last-1]=0D0A

    $year  = bcdByte($packet[4])  + 2000;
    $month = bcdByte($packet[5]);
    $day   = bcdByte($packet[6]);
    $hour  = bcdByte($packet[7]);
    $min   = bcdByte($packet[8]);
    $sec   = bcdByte($packet[9]);
    $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec);

    // Latitude: bytes 10-13 BCD → DD + MM.mm
    $latDeg   = bcdByte($packet[10]);
    $latMin   = bcdByte($packet[11]);
    $latMinD  = bcdByte($packet[12]) * 0.01 + bcdByte($packet[13]) * 0.0001;
    $latitude = $latDeg + ($latMin + $latMinD) / 60.0;
    $ns       = chr(ord($packet[14]));
    if ($ns === 'S') $latitude = -$latitude;

    // Longitude: bytes 15-18 BCD → DDD + MM.mm
    $lonDeg   = bcdByte($packet[15]) * 10 + (($packet[16] >> 4) & 0x0F); // 3 BCD digits for degrees
    // Simpler approach: treat as 2-byte degree + 2-byte minute
    $lonDeg   = bcdByte($packet[15]);
    // Handle 3-digit degrees (>= 100): high nibble of byte15 carries the hundreds
    $hi = (ord($packet[15]) >> 4) & 0x0F;
    $lo = ord($packet[15]) & 0x0F;
    // When degrees ≥ 100, byte15 high nibble = hundreds digit
    // We read it as: degrees = BCD(byte15)×10 if >9 else standard
    // Most robust: read as 4 BCD digits across bytes 15-16 for degrees+min start
    $lonDeg   = $hi * 100 + $lo * 10 + ((ord($packet[16]) >> 4) & 0x0F);
    $lonMin   = (ord($packet[16]) & 0x0F) * 10 + ((ord($packet[17]) >> 4) & 0x0F);
    $lonMinD  = (ord($packet[17]) & 0x0F) * 0.1 + ((ord($packet[18]) >> 4) & 0x0F) * 0.01
                + (ord($packet[18]) & 0x0F) * 0.001;
    $longitude = $lonDeg + ($lonMin + $lonMinD) / 60.0;
    $ew        = chr(ord($packet[19]));
    if ($ew === 'W') $longitude = -$longitude;

    $speed = bcdByte($packet[20]);

    // IMEI: bytes 23-29, 7 bytes BCD = 14 hex digits (last nibble = checksum)
    $imeiHex = '';
    for ($i = 23; $i <= 29 && $i < strlen($packet); $i++) {
        $imeiHex .= sprintf('%02X', ord($packet[$i]));
    }
    $imei = substr($imeiHex, 0, 15); // take 15 digits

    clilogTracker(
        "H02 IMEI:$imei $datetime Lat:$latitude Lon:$longitude Spd:$speed",
        $protocol_name
    );

    sendToGrusher($imei, [
        'protocol_name' => $protocol_name,
        'last_alive'    => $datetime,
        'lat'           => $latitude,
        'lon'           => $longitude,
        'speed'         => $speed,
    ]);

    // ACK
    fwrite($conn, hex2bin('2424' . '05' . dechex($cmd) . '00' . '010D0A'));
}

/** Decode one BCD byte to decimal integer */
function bcdByte(string $byte): int {
    $b = ord($byte);
    return (($b >> 4) & 0x0F) * 10 + ($b & 0x0F);
}
