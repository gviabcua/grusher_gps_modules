<?php
$protocol_name = explode(".", basename(__FILE__))[0];
define("WORK_DIR", dirname(__FILE__));
require_once(WORK_DIR . "/config.php");
require_once(WORK_DIR . "/functions.php");

clilogTracker("Starting script for $protocol_name...", $protocol_name);

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('default_socket_timeout', -1);
ini_set('max_input_time', -1);

$host = "0.0.0.0";
$options = getopt("p:");
if (isset($options['p']) && (int)$options['p'] > 0 && (int)$options['p'] < 65536) {
    $port = (int)$options['p'];
} else {
    clilogTracker("No port or invalid port specified", $protocol_name);
    return;
}

$connectionIMEIs = [];
$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$socket) {
    clilogTracker("Unable to create socket: $errstr ($errno)", $protocol_name);
    return;
}
clilogTracker("Server started on $host:$port", $protocol_name);

while (true) {
    $conn = stream_socket_accept($socket);
    if ($conn) {
        $connId = intval($conn);
        clilogTracker("New connection accepted", $protocol_name);
        while (($line = fgets($conn)) !== false) {
            $line = trim($line);
            clilogTracker("Received: $line", $protocol_name);

            // Розбір повідомлення Autofon 5
            $parts = explode(',', $line);
            if (count($parts) >= 9) {
                $imei = $parts[0];
                $connectionIMEIs[$connId] = $imei;

                $dateRaw = $parts[1];  // ddmmyy
                $timeRaw = $parts[2];  // hhmmss
                $latRaw = $parts[4];
                $latDir = $parts[5];
                $lonRaw = $parts[6];
                $lonDir = $parts[7];
                $speed = $parts[8];

                $datetime = DateTime::createFromFormat('dmyHis', $dateRaw . $timeRaw);
                if (!$datetime) {
                    clilogTracker("Invalid datetime format", $protocol_name);
                    continue;
                }
                $latitude = convertToDecimalDegrees($latRaw, $latDir);
                $longitude = convertToDecimalDegrees($lonRaw, $lonDir);

                clilogTracker("IMEI: $imei | $datetime->format('Y-m-d H:i:s') | Lat: $latitude, Lon: $longitude, Speed: $speed", $protocol_name);

                sendToGrusher($imei, [
                    "protocol_name" => $protocol_name,
                    "last_alive" => $datetime->format('Y-m-d H:i:s'),
                    "lat" => $latitude,
                    "lon" => $longitude,
                    "speed" => $speed,
                ]);
            } else {
                clilogTracker("Invalid message format", $protocol_name);
            }
        }
        fclose($conn);
        unset($connectionIMEIs[$connId]);
        clilogTracker("Connection closed", $protocol_name);
    }
}
fclose($socket);

function convertToDecimalDegrees($coord, $direction) {
    $deg = intval(substr($coord, 0, 2));
    $min = floatval(substr($coord, 2));
    $dec = $deg + $min / 60;
    if ($direction == 'S' || $direction == 'W') $dec = -$dec;
    return $dec;
}
?>
