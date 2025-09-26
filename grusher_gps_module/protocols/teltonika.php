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

// Initialize logging
clilogTracker("Starting GPS server...", $protocol_name);

// Set PHP runtime configurations
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('default_socket_timeout', -1);
ini_set('max_input_time', -1);

// Get port from command line arguments
$options = getopt("p:");
if (!isset($options['p']) || (int)$options['p'] <= 0 || (int)$options['p'] >= 65536) {
    clilogTracker("Invalid or missing port number", $protocol_name);
    exit(1);
}
$port = (int)$options['p'];
$host = "0.0.0.0";

// Create TCP server socket
$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    clilogTracker("Failed to create socket: $errstr ($errno)", $protocol_name);
    exit(1);
}
stream_set_blocking($server, false);

clilogTracker("Server started on {$host}:{$port}", $protocol_name);

// Initialize client tracking
$clients = [];          // Active client connections
$connectionIMEIs = []; // Mapping of connection ID to IMEI

// Main server loop
while (true) {
    $read = array_merge([$server], array_values($clients));
    $write = $except = null;

    // Check for socket activity
    if (stream_select($read, $write, $except, 0, 200000) > 0) {
        foreach ($read as $sock) {
            if ($sock === $server) {
                // Handle new connection
                if ($conn = stream_socket_accept($server)) {
                    stream_set_blocking($conn, false);
                    $clients[(int)$conn] = $conn;
                    clilogTracker("New connection established", $protocol_name);
                }
            } else {
                // Handle client data
                $payload = fread($sock, 8192);
                if ($payload === '' || $payload === false) {
                    // Client disconnected
                    clilogTracker("Connection closed", $protocol_name);
                    unset($clients[(int)$sock], $connectionIMEIs[(int)$sock]);
                    fclose($sock);
                } else {
                    processGpsData($sock, bin2hex($payload), $connectionIMEIs);
                }
            }
        }
    }
}

/**
 * Process raw hex payload from GPS device
 * Handles IMEI and AVL packets for all Teltonika codecs
 *
 * @param resource $conn Socket connection
 * @param string $hex Hex-encoded payload
 * @param array $connectionIMEIs Reference to IMEI mapping
 */
function processGpsData($conn, $hex, &$connectionIMEIs) {
    global $protocol_name;
    $data = hex2bin($hex);
    $connId = (int)$conn;
    $totalLen = strlen($data);
    clilogTracker("Received data length: $totalLen", $protocol_name);

    // Handle IMEI packet
    if ($totalLen >= 2) {
        $len = unpack("n", substr($data, 0, 2))[1];
        if ($len > 0 && $len <= 15 && $totalLen >= 2 + $len) {
            $imei = substr($data, 2, $len);
            if (preg_match('/^\d{15}$/', $imei)) {
                clilogTracker("IMEI received: $imei", $protocol_name);
                $connectionIMEIs[$connId] = $imei;
                fwrite($conn, chr(1)); // Send IMEI ACK
                return;
            } else {
                clilogTracker("Invalid IMEI format: $imei", $protocol_name);
                return;
            }
        }
    }

    // Handle AVL packet
    if ($totalLen < 12) {
        clilogTracker("Packet too small for AVL", $protocol_name);
        return;
    }

    $offset = 0;

    // Read preamble (4 bytes, should be 0x00000000 for TCP)
    $preamble = unpack("N", substr($data, $offset, 4))[1];
    if ($preamble !== 0) {
        clilogTracker("Invalid preamble: " . sprintf("%08X", $preamble), $protocol_name);
        return;
    }
    $offset += 4;

    // Read AVL data length (4 bytes)
    $avlLengthRaw = substr($data, $offset, 4);
    if (strlen($avlLengthRaw) < 4) {
        clilogTracker("Insufficient bytes for AVL length", $protocol_name);
        return;
    }
    $avlLength = unpack("N", $avlLengthRaw)[1];
    $offset += 4;

    $startAVL = $offset;
    $endAVL = $startAVL + $avlLength;
    if ($totalLen < $endAVL + 4) {
        clilogTracker("Incomplete AVL packet: expected " . ($endAVL + 4) . " bytes, got $totalLen", $protocol_name);
        return;
    }

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

    // Read second record count and CRC
    if ($offset + 1 > $endAVL) {
        clilogTracker("Missing second record counter for ACK", $protocol_name);
        return;
    }
    $records2 = ord($data[$offset++]);
    if ($records2 !== $recordCount) {
        clilogTracker("Record count mismatch: $recordCount != $records2", $protocol_name);
    }

    if ($offset + 4 > $totalLen) {
        clilogTracker("Missing CRC bytes", $protocol_name);
        return;
    }
    $crc = unpack("N", substr($data, $offset, 4))[1];

    // Basic CRC validation (optional, implement as needed)
    // Note: Teltonika CRC-16-IBM can be added here if required

    // Send ACK with number of accepted records
    fwrite($conn, pack("N", $processed));
    clilogTracker("Sent ACK for $processed records", $protocol_name);
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
        $datetime = date("Y-m-d H:i:s", $timestamp / 1000);
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

        $imei = $connectionIMEIs[$connId] ?? 'unknown';
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
        $datetime = date("Y-m-d H:i:s", $timestamp / 1000);
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

        $imei = $connectionIMEIs[$connId] ?? 'unknown';
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
        $datetime = date("Y-m-d H:i:s", $timestamp / 1000);
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

        $imei = $connectionIMEIs[$connId] ?? 'unknown';
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

    // Parse 1-byte values
    $n1 = ord($data[$offset++]);
    for ($i = 0; $i < $n1 && $offset + 2 <= $endAVL; $i++) {
        $id = ord($data[$offset++]);
        $value = ord($data[$offset++]);
        $io[$id] = $value;
    }

    // Parse 2-byte values
    $n2 = ord($data[$offset++]);
    for ($i = 0; $i < $n2 && $offset + 3 <= $endAVL; $i++) {
        $id = ord($data[$offset++]);
        $value = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
        $io[$id] = $value;
    }

    // Parse 4-byte values
    $n4 = ord($data[$offset++]);
    for ($i = 0; $i < $n4 && $offset + 5 <= $endAVL; $i++) {
        $id = ord($data[$offset++]);
        $value = unpack("N", substr($data, $offset, 4))[1];
        $offset += 4;
        $io[$id] = $value;
    }

    // Parse 8-byte values
    $n8 = ord($data[$offset++]);
    for ($i = 0; $i < $n8 && $offset + 9 <= $endAVL; $i++) {
        $id = ord($data[$offset++]);
        $high = unpack("N", substr($data, $offset, 4))[1];
        $low = unpack("N", substr($data, $offset + 4, 4))[1];
        $offset += 8;
        $io[$id] = combineUInt64($high, $low);
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
        43 => 'OBD_Distance_ormattin_MIL_on',
        48 => 'OBD_Fuel_Level',
        51 => 'OBD_Coolant_Temperature',
        52 => 'OBD_Fuel_Consumption',
        53 => 'OBD_Speed',
        54 => 'OBD_Throttle_Position',
        58 => 'OBD_Engine_Oil_Temperature',
        60 => 'OBD_Fuel_Rate',
        256 => 'OBD_VIN',
        389 => 'Odometer_OEM_Total_Mileage',
        390 => 'Fuel_Level_OEM',
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
                case 66:
                case 67:
                    $result[$mapping[$id]] = round(($value * 0.001), 1);
                    break;
                case 256:
                    $result[$mapping[$id]] = hex2bin($value);
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