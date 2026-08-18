<?php
/**
 * GPS Tracking Server for Teltonika Protocols
 * Supports Codec 8, Codec 8 Extended, and Codec 16
 * Handles TCP connections from GPS devices, processes IMEI and AVL packets
 */

$protocol_name = explode(".", basename(__FILE__))[0];
define("WORK_DIR", dirname(dirname(__FILE__)));
require_once WORK_DIR . "/config.php";
require_once WORK_DIR . "/functions.php";
require_once WORK_DIR . "/gps_server.php";

$connectionIMEIs = []; // Mapping of connection ID to IMEI

$server = gpsBootstrap($protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use (&$connectionIMEIs, $protocol_name) {
        processTeltonikaBuffer($conn, $id, $buffers, $connectionIMEIs, $protocol_name);
    },
    function ($id) use (&$connectionIMEIs) {
        unset($connectionIMEIs[$id]);
    }
);

/**
 * Pull complete Teltonika frames out of the receive buffer.
 *
 * TCP is a byte stream: a device may split one AVL packet across several
 * segments, or pack the IMEI handshake and the first AVL packet into a single
 * one. 
 */
function processTeltonikaBuffer($conn, $connId, &$buffers, &$connectionIMEIs, $protocol_name) {
    while (true) {
        $buf      = $buffers[$connId];
        $totalLen = strlen($buf);

        if ($totalLen < 2) return;

        // ── IMEI handshake: length(2) + ASCII digits ──
        $len = unpack("n", substr($buf, 0, 2))[1];
        if ($len > 0 && $len <= 15) {
            if ($totalLen < 2 + $len) return; // wait for the rest
            $imei = substr($buf, 2, $len);
            $buffers[$connId] = substr($buf, 2 + $len);

            if (preg_match('/^\d{15}$/', $imei)) {
                clilogTracker("IMEI received: $imei", $protocol_name);
                $connectionIMEIs[$connId] = $imei;
                safeFwrite($conn, chr(1)); // accept
            } else {
                clilogTracker("Invalid IMEI format: " . bin2hex($imei), $protocol_name);
                safeFwrite($conn, chr(0)); // reject
            }
            continue;
        }

        // ── AVL packet: preamble(4) + length(4) + data + CRC(4) ──
        if ($totalLen < 12) return;

        $preamble = unpack("N", substr($buf, 0, 4))[1];
        if ($preamble !== 0) {
            clilogTracker(
                "Invalid preamble: " . sprintf("%08X", $preamble) . " — dropping connection",
                $protocol_name
            );
            $buffers[$connId] = '';
            throw new RuntimeException('protocol desync');
        }

        $avlLength = unpack("N", substr($buf, 4, 4))[1];
        if ($avlLength < 3 || $avlLength > 65535) {
            clilogTracker("Implausible AVL length $avlLength — dropping connection", $protocol_name);
            $buffers[$connId] = '';
            throw new RuntimeException('protocol desync');
        }

        $frameLen = 8 + $avlLength + 4;
        if ($totalLen < $frameLen) return; // wait for the rest of the frame

        $frame            = substr($buf, 0, $frameLen);
        $buffers[$connId] = substr($buf, $frameLen);

        processGpsData($conn, $frame, $connId, $connectionIMEIs);
    }
}

/**
 * Process one complete AVL frame.
 *
 * @param resource $conn   Socket connection
 * @param string   $data   Complete frame (preamble … CRC)
 * @param int      $connId Connection id
 * @param array    $connectionIMEIs Reference to IMEI mapping
 */
function processGpsData($conn, $data, $connId, &$connectionIMEIs) {
    global $protocol_name;
    $totalLen = strlen($data);
    clilogTracker("Received AVL frame, length: $totalLen", $protocol_name);

    $offset    = 8;
    $avlLength = unpack("N", substr($data, 4, 4))[1];
    $startAVL  = $offset;
    $endAVL    = $startAVL + $avlLength;

    // Read codec ID and record count
    $codecId = ord($data[$offset++]);
    $recordCount = ord($data[$offset++]);

    // Validate codec ID
    if (!in_array($codecId, [8, 0x8E, 16])) {
        clilogTracker("Unsupported codec ID: $codecId", $protocol_name);
        return;
    }

    clilogTracker("AVL Length: $avlLength | Codec ID: $codecId | Records: $recordCount", $protocol_name);

    if ($recordCount === 0) {
        clilogTracker("Invalid record count: 0", $protocol_name);
        return;
    }

    // Process records based on codec
    $processed = 0;
    if ($codecId == 8) {
        $processed = processCodec8($data, $offset, $endAVL, $recordCount, $connId, $connectionIMEIs);
    } elseif ($codecId == 0x8E) {
        $processed = processCodec8Extended($data, $offset, $endAVL, $recordCount, $connId, $connectionIMEIs);
    } elseif ($codecId == 16) {
        $processed = processCodec16($data, $offset, $endAVL, $recordCount, $connId, $connectionIMEIs);
    }

    // Second record counter is the last byte of the AVL block
    $records2 = ord($data[$endAVL - 1]);
    if ($records2 !== $recordCount) {
        clilogTracker("Record count mismatch: $recordCount != $records2", $protocol_name);
    }

    // CRC-16/IBM over the AVL block (codec id … second record counter)
    $crcExpected = unpack("N", substr($data, $endAVL, 4))[1] & 0xFFFF;
    $crcActual   = teltonikaCrc16(substr($data, $startAVL, $avlLength));
    if ($crcExpected !== $crcActual) {
        clilogTracker(
            sprintf("CRC mismatch: packet=%04X calculated=%04X", $crcExpected, $crcActual),
            $protocol_name
        );
    }

    // ACK with the number of accepted records. This must always be sent —
    // without it the device replays the same packet forever.
    safeFwrite($conn, pack("N", $recordCount));
    clilogTracker("Sent ACK for $recordCount records ($processed parsed)", $protocol_name);
}

/**
 * Convert a Teltonika millisecond timestamp to "Y-m-d H:i:s".
 *
 * intdiv() instead of "/ 1000": passing a float to date() is deprecated in
 * PHP 8.1+ and every record emitted a deprecation notice. Implausible values
 * (a corrupt record, or a device with no RTC yet) fall back to server time.
 */
function teltonikaDatetime($timestampMs) {
    $seconds = is_int($timestampMs) ? intdiv($timestampMs, 1000) : (int)((float)$timestampMs / 1000);
    if ($seconds < 946684800 || $seconds > time() + 86400) { // < 2000-01-01 or > +1 day
        return date("Y-m-d H:i:s");
    }
    return date("Y-m-d H:i:s", $seconds);
}

/** CRC-16/IBM (poly 0xA001, init 0x0000) as used by Teltonika. */
function teltonikaCrc16(string $buf): int {
    $crc = 0;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= ord($buf[$i]);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x01) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc & 0xFFFF;
}

/**
 * Process Codec 8 records (1-byte IO IDs and counts)
 *
 * @return int Number of processed records
 */
function processCodec8($data, &$offset, $endAVL, $recordCount, $connId, &$connectionIMEIs) {
    global $protocol_name;
    $processed = 0;

    for ($i = 0; $i < $recordCount; $i++) {
        if ($offset + 24 > $endAVL) {
            clilogTracker("Insufficient data for record #$i (Codec8)", $protocol_name);
            break;
        }

        $timestamp = unpack64be(substr($data, $offset, 8));
        $offset += 8;
        $datetime = teltonikaDatetime($timestamp);
        $priority = ord($data[$offset++]);
        $longitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $latitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $altitude = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $angle = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $sats = ord($data[$offset++]);
        $speed = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        $ioData = parseIOElements($data, $offset, $endAVL);

        $imei = $connectionIMEIs[$connId] ?? null;
        if ($imei === null) {
            clilogTracker("Record #$i received before the IMEI handshake — skipped", $protocol_name);
            continue;
        }
        if(is_array($ioData) and !empty($ioData)){
            ksort($ioData);
        }
        clilogTracker("[$i] IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Angle: $angle, Alt: $altitude, Sats: $sats | IO: " . print_r($ioData, true), $protocol_name);

        sendToGrusher($imei, [
            "protocol_name" => $protocol_name,
            "last_alive" => $datetime,
            "lat" => $latitude,
            "lon" => $longitude,
            "speed" => $speed,
            "angle" => $angle,
            "alt" => $altitude,
            "sats" => $sats,
            "io" => json_encode($ioData),
        ]);

        $processed++;
    }

    return $processed;
}

/**
 * Process Codec 8 Extended records (2-byte IO IDs and counts)
 *
 * @return int Number of processed records
 */
function processCodec8Extended($data, &$offset, $endAVL, $recordCount, $connId, &$connectionIMEIs) {
    global $protocol_name;
    $processed = 0;

    for ($i = 0; $i < $recordCount; $i++) {
        if ($offset + 24 > $endAVL) {
            clilogTracker("Insufficient data for record #$i (Codec8E)", $protocol_name);
            break;
        }

        $timestamp = unpack64be(substr($data, $offset, 8));
        $offset += 8;
        $datetime = teltonikaDatetime($timestamp);
        $priority = ord($data[$offset++]);
        $longitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $latitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $altitude = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $angle = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $sats = ord($data[$offset++]);
        $speed = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        if ($offset + 4 > $endAVL) {
            clilogTracker("Insufficient bytes for IO headers (record $i)", $protocol_name);
            break;
        }
        $eventId = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $totalIO = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        $ioData = parseIOElements8E($data, $offset, $endAVL, $totalIO);

        $imei = $connectionIMEIs[$connId] ?? null;
        if ($imei === null) {
            clilogTracker("Record #$i received before the IMEI handshake — skipped", $protocol_name);
            continue;
        }
        if(is_array($ioData) and !empty($ioData)){
            ksort($ioData);
        }
        clilogTracker("[$i] IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Angle: $angle, Alt: $altitude, Sats: $sats | IO: " . print_r($ioData, true), $protocol_name);

        sendToGrusher($imei, [
            "protocol_name" => $protocol_name,
            "last_alive" => $datetime,
            "lat" => $latitude,
            "lon" => $longitude,
            "speed" => $speed,
            "angle" => $angle,
            "alt" => $altitude,
            "sats" => $sats,
            "io" => json_encode($ioData),
        ]);

        $processed++;
    }

    return $processed;
}

/**
 * Process Codec 16 records (2-byte IO IDs, extended data formats)
 *
 * @return int Number of processed records
 */
function processCodec16($data, &$offset, $endAVL, $recordCount, $connId, &$connectionIMEIs) {
    global $protocol_name;
    $processed = 0;

    for ($i = 0; $i < $recordCount; $i++) {
        if ($offset + 25 > $endAVL) {
            clilogTracker("Insufficient data for record #$i (Codec16)", $protocol_name);
            break;
        }

        $timestamp = unpack64be(substr($data, $offset, 8));
        $offset += 8;
        $datetime = teltonikaDatetime($timestamp);
        $priority = ord($data[$offset++]);
        $longitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $latitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
        $offset += 4;
        $altitude = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $angle = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $sats = ord($data[$offset++]);
        $speed = unpack("N", substr($data, $offset, 4))[1]; // Codec 16 uses 4 bytes for speed
        $offset += 4;

        if ($offset + 4 > $endAVL) {
            clilogTracker("Insufficient bytes for IO headers (record $i)", $protocol_name);
            break;
        }
        $eventId = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $totalIO = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        $ioData = parseIOElements8E($data, $offset, $endAVL, $totalIO); // Codec 16 uses same IO structure as 8E

        $imei = $connectionIMEIs[$connId] ?? null;
        if ($imei === null) {
            clilogTracker("Record #$i received before the IMEI handshake — skipped", $protocol_name);
            continue;
        }
        if(is_array($ioData) and !empty($ioData)){
            ksort($ioData);
        }
        clilogTracker("[$i] IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Angle: $angle, Alt: $altitude, Sats: $sats | IO: " . print_r($ioData, true), $protocol_name);

        sendToGrusher($imei, [
            "protocol_name" => $protocol_name,
            "last_alive" => $datetime,
            "lat" => $latitude,
            "lon" => $longitude,
            "speed" => $speed,
            "angle" => $angle,
            "alt" => $altitude,
            "sats" => $sats,
            "io" => json_encode($ioData),
        ]);

        $processed++;
    }

    return $processed;
}

/**
 * Parse IO elements for Codec 8 (1-byte IDs and counts)
 *
 * @return array Parsed IO data
 */
function parseIOElements($data, &$offset, $endAVL) {
    global $protocol_name;
    $io = [];

    if ($offset + 2 > $endAVL) {
        clilogTracker("Insufficient bytes for IO headers", $protocol_name);
        return [];
    }

    $eventId = ord($data[$offset++]);
    $totalIO = ord($data[$offset++]);

    // ID(1) + value(N) for each block. The block counters themselves were read
    // without a bounds check before, so a truncated record walked $offset past
    // the end of the string ("Uninitialized string offset" warnings, and
    // garbage IO values).
    $blocks = [1, 2, 4, 8];
    foreach ($blocks as $size) {
        if ($offset + 1 > $endAVL) {
            clilogTracker("Insufficient bytes for N$size counter", $protocol_name);
            return mapFmbIo($io);
        }
        $count = ord($data[$offset++]);

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 1 + $size > $endAVL) {
                clilogTracker("Insufficient bytes parsing N$size IO", $protocol_name);
                return mapFmbIo($io);
            }
            $id = ord($data[$offset++]);
            switch ($size) {
                case 1:
                    $value = ord($data[$offset]);
                    break;
                case 2:
                    $value = unpack("n", substr($data, $offset, 2))[1];
                    break;
                case 4:
                    $value = unpack("N", substr($data, $offset, 4))[1];
                    break;
                default:
                    $high  = unpack("N", substr($data, $offset, 4))[1];
                    $low   = unpack("N", substr($data, $offset + 4, 4))[1];
                    $value = combineUInt64($high, $low);
                    break;
            }
            $offset += $size;
            $io[$id] = $value;
        }
    }

    return mapFmbIo($io);
}

/**
 * Parse IO elements for Codec 8 Extended and Codec 16 (2-byte IDs and counts)
 *
 * @return array Parsed IO data
 */
function parseIOElements8E($data, &$offset, $endAVL, $totalIOdecl = null) {
    global $protocol_name;
    $io = [];
    $parsedCount = 0;

    $blocks = [
        'N1' => 3,  // ID(2)+Value(1)
        'N2' => 4,  // ID(2)+Value(2)
        'N4' => 6,  // ID(2)+Value(4)
        'N8' => 10, // ID(2)+Value(8)
    ];

    foreach ($blocks as $block => $size) {
        if ($offset + 2 > $endAVL) {
            clilogTracker("Insufficient bytes for $block count", $protocol_name);
            return mapFmbIo($io);
        }
        $count = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        for ($i = 0; $i < $count; $i++) {
            if ($offset + $size > $endAVL) {
                clilogTracker("Insufficient bytes parsing $block IO (need $size)", $protocol_name);
                return mapFmbIo($io);
            }
            $id = unpack("n", substr($data, $offset, 2))[1];
            $offset += 2;

            switch ($block) {
                case 'N1':
                    $value = ord($data[$offset++]);
                    break;
                case 'N2':
                    $value = unpack("n", substr($data, $offset, 2))[1];
                    $offset += 2;
                    break;
                case 'N4':
                    $value = unpack("N", substr($data, $offset, 4))[1];
                    $offset += 4;
                    break;
                case 'N8':
                    $high = unpack("N", substr($data, $offset, 4))[1];
                    $low = unpack("N", substr($data, $offset + 4, 4))[1];
                    $offset += 8;
                    $value = combineUInt64($high, $low);
                    break;
            }

            $io[$id] = $value;
            $parsedCount++;
        }
    }

    // Parse variable-length (NX) block
    if ($offset + 2 <= $endAVL) {
        $nx = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;

        for ($i = 0; $i < $nx; $i++) {
            if ($offset + 4 > $endAVL) {
                clilogTracker("Insufficient bytes for NX header", $protocol_name);
                return mapFmbIo($io);
            }
            $id = unpack("n", substr($data, $offset, 2))[1];
            $offset += 2;
            $len = unpack("n", substr($data, $offset, 2))[1];
            $offset += 2;

            if ($offset + $len > $endAVL) {
                clilogTracker("Insufficient bytes for NX value (need $len)", $protocol_name);
                return mapFmbIo($io);
            }

            $valueRaw = substr($data, $offset, $len);
            $offset += $len;
            $io[$id] = bin2hex($valueRaw);
            $parsedCount++;
        }
    }

    // Verify parsed IO count
    if ($totalIOdecl !== null && $parsedCount !== $totalIOdecl) {
        clilogTracker("Parsed IO count ($parsedCount) does not match declared ($totalIOdecl)", $protocol_name);
    }

    return mapFmbIo($io);
}

/**
 * Map raw IO IDs to human-readable keys
 *
 * @param array $io Raw IO data
 * @return array Mapped IO data
 */
function mapFmbIo($io) {
    $mapping = [
        239 => 'Ignition',
        240 => 'Movement',
        80 => 'Data_Mode',
        21 => 'GSM_Signal',
        200 => 'Sleep_Mode',
        69 => 'GNSS_Status',
        181 => 'GNSS_PDOP',
        182 => 'GNSS_HDOP',
        66 => 'External_Voltage',
        67 => 'Battery_Voltage',
        68 => 'Battery_Current',
        24 => 'Speed',
        16 => 'Odometer',
        199 => 'Trip_Odometer',
        1 => 'Digital_Input_1',
        2 => 'Digital_Input_2',
        3 => 'Digital_Input_3',
        4 => 'Digital_Input_4',
        179 => 'Digital_Output_1',
        180 => 'Digital_Output_2',
        9 => 'Analog_Input_1',
        6 => 'Analog_Input_2',
        72 => 'Dallas_Temperature_1',
        73 => 'Dallas_Temperature_2',
        74 => 'Dallas_Temperature_3',
        75 => 'Dallas_Temperature_4',
        76 => 'Dallas_Sensor_ID_1',
        77 => 'Dallas_Sensor_ID_2',
        78 => 'Dallas_Sensor_ID_3',
        79 => 'Dallas_Sensor_ID_4',
        241 => 'Active_GSM_Operator',
        238 => 'User_ID',
        237 => 'Network_Type',
        10 => 'SD_Status',
        11 => 'ICCID1',

        30 => 'OBD_Number_of_DTC',
        31 => 'OBD_Engine_Load',
        32 => 'OBD_Coolant_Temperature',
        36 => 'OBD_Engine_RPM',
        37 => 'OBD_Vehicle_Speed',
        39 => 'OBD_Intake_Air_Temperature',
        42 => 'CAN_Engine_RPM',
        43 => 'OBD_Distance_Traveled_MIL_On',
        44 => 'OBD_Fuel_Level ',
        48 => 'OBD_Fuel_Level',
        51 => 'OBD_Control_Module_Voltage',
        52 => 'OBD_Fuel_Consumption',
        53 => 'OBD_Speed',
        54 => 'OBD_Throttle_Position',
        58 => 'OBD_Engine_Oil_Temperature',
        60 => 'OBD_Fuel_Rate',

        113 => 'Battery_Level',


        256 => 'OBD_VIN',
        389 => 'Odometer_OEM_Total_Mileage',
        390 => 'OBD_Fuel_Level',
        2001 => 'CAN_RPM',
        2002 => 'CAN_Speed',
        2003 => 'CAN_Fuel_Consumption',
        2004 => 'CAN_Fuel_Level',
        2005 => 'CAN_Engine_Temperature',
        2006 => 'CAN_Coolant_Temperature',
        3001 => 'BLE_Temperature_1',
        3002 => 'BLE_Temperature_2',
        3003 => 'BLE_Humidity_1',
        3004 => 'BLE_Humidity_2',
        3005 => 'BLE_Acceleration_X',
        3006 => 'BLE_Acceleration_Y',
        3007 => 'BLE_Acceleration_Z',
        202 => 'Trip_Fuel',
        203 => 'Trip_Distance',
        204 => 'Trip_Time',
        205 => 'GSM_Cell_ID',
        206 => 'GSM_Area_Code',
        207 => 'Battery_Level',
        208 => 'Fuel_Consumption_Total',
        209 => 'Engine_Hours',
    ];

    $result = [];
    foreach ($io as $id => $value) {
        if (isset($mapping[$id])) {
            switch ((int)$id) {
                case 16:
                    $result[$mapping[$id]] = round(($value * 0.001), 0);
                    break;
                case 60:
                    $result[$mapping[$id]] = round(($value * 0.01), 0);
                    break;
                case 51:
                case 66:
                case 67:
                    $result[$mapping[$id]] = round(($value * 0.001), 1);
                    break;
                case 256:
                    // VIN 
                    $result[$mapping[$id]] = (is_string($value) && strlen($value) % 2 === 0
                        && ctype_xdigit($value))
                        ? hex2bin($value)
                        : $value;
                    break;
                case 390:
                    $result[$mapping[$id]] = round(($value * 0.1), 1);
                    break;
                default:
                    $result[$mapping[$id]] = $value;
                    break;
            }
        } else {
            $result[$id] = $value;
        }
    }
    return $result;
}

/**
 * Parse signed 32-bit integer from big-endian binary
 *
 * @param string $bin Binary data
 * @return int Signed integer
 */
function parseSignedInt32($bin) {
    $u = unpack("N", $bin)[1];
    return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u;
}

/**
 * Combine high and low 32-bit values into 64-bit integer
 *
 * @param int $high High 32 bits
 * @param int $low Low 32 bits
 * @return int|string Combined value
 */
function combineUInt64($high, $low) {
    if (PHP_INT_SIZE >= 8) {
        return ($high << 32) | $low;
    } elseif (function_exists('bcadd') && function_exists('bcmul')) {
        return bcadd(bcmul((string)$high, "4294967296"), (string)$low);
    }
    return sprintf("%08X%08X", $high, $low);
}

/**
 * Unpack 64-bit big-endian value
 *
 * @param string $bin 8-byte binary data
 * @return int|string Unpacked value
 * @throws InvalidArgumentException
 */
function unpack64be($bin) {
    if (strlen($bin) !== 8) {
        throw new InvalidArgumentException("Expected 8 bytes for 64-bit value");
    }

    $parts = unpack("Nhi/Nlo", $bin);
    $hi = $parts['hi'];
    $lo = $parts['lo'];

    if (PHP_INT_SIZE >= 8) {
        return ($hi << 32) + $lo;
    } elseif (function_exists('bcadd') && function_exists('bcmul')) {
        return bcadd(bcmul((string)$hi, "4294967296"), (string)$lo);
    }

    return $lo;
}
?>