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
            if ($line === '') continue;
            clilogTracker("Received: $line", $protocol_name);

            // GalileoSky передає дані у форматі ASCII рядка, наприклад:
            // $GS123456789012345,130815,071230,37.7749,N,122.4194,W,12.5,0.0,123456789;
            // Приклад розбору:

            if (str_starts_with($line, '$GS')) {
                $line = rtrim($line, ';');
                $parts = explode(',', $line);

                if (count($parts) >= 10) {
                    // Припустимо, IMEI йде після $GS
                    $imei = substr($parts[0], 3); // наприклад: GS123456789012345 -> 123456789012345
                    $connectionIMEIs[$connId] = $imei;

                    $dateRaw = $parts[1];  // ddmmyy
                    $timeRaw = $parts[2];  // hhmmss
                    $lat = floatval($parts[3]);
                    $latDir = $parts[4];
                    $lon = floatval($parts[5]);
                    $lonDir = $parts[6];
                    $speed = floatval($parts[7]);
                    $course = floatval($parts[8]);
                    $additional = $parts[9]; // наприклад, odometer або щось ще

                    $datetime = DateTime::createFromFormat('dmyHis', $dateRaw . $timeRaw);
                    if (!$datetime) {
                        clilogTracker("Invalid datetime format", $protocol_name);
                        continue;
                    }

                    $latitude = adjustCoordinate($lat, $latDir);
                    $longitude = adjustCoordinate($lon, $lonDir);

                    clilogTracker("IMEI: $imei | {$datetime->format('Y-m-d H:i:s')} | Lat: $latitude, Lon: $longitude, Speed: $speed, Course: $course", $protocol_name);

                    sendToGrusher($imei, [
                        "protocol_name" => $protocol_name,
                        "last_alive" => $datetime->format('Y-m-d H:i:s'),
                        "lat" => $latitude,
                        "lon" => $longitude,
                        "speed" => $speed,
                        "angle" => $course,
                        "additional" => $additional,
                    ]);
                } else {
                    clilogTracker("Invalid GalileoSky message format", $protocol_name);
                }
            } else {
                clilogTracker("Unknown message prefix", $protocol_name);
            }
        }
        fclose($conn);
        unset($connectionIMEIs[$connId]);
        clilogTracker("Connection closed", $protocol_name);
    }
}
fclose($socket);

function adjustCoordinate($value, $direction) {
    if ($direction == 'S' || $direction == 'W') {
        return -abs($value);
    }
    return abs($value);
}
?>
