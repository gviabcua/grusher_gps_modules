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

        while (true) {
            $data = fread($conn, 2048);
            if ($data === false || $data === '') {
                clilogTracker("Connection closed by client", $protocol_name);
                break;
            }
            clilogTracker("Received binary data: " . bin2hex($data), $protocol_name);

            // Розбір даних Autofon 9 (приклад, структура залежить від протоколу)
            // Припустимо, що IMEI у перших 15 байтах ASCII
            $imeiRaw = substr($data, 0, 15);
            $imei = preg_replace('/\D/', '', $imeiRaw);
            $connectionIMEIs[$connId] = $imei;

            // Тут додаємо парсинг GPS і інших даних згідно специфікації Autofon 9
            // Для прикладу беремо довільні байти для координат (треба замінити на реальну логіку)
            if (strlen($data) >= 25) {
                $latRaw = unpack('N', substr($data, 15, 4))[1];
                $lonRaw = unpack('N', substr($data, 19, 4))[1];
                $latitude = $latRaw / 1000000;  // Приклад
                $longitude = $lonRaw / 1000000;

                clilogTracker("IMEI: $imei | Lat: $latitude, Lon: $longitude", $protocol_name);

                sendToGrusher($imei, [
                    "protocol_name" => $protocol_name,
                    "last_alive" => date('Y-m-d H:i:s'),
                    "lat" => $latitude,
                    "lon" => $longitude,
                ]);
            } else {
                clilogTracker("Incomplete data packet", $protocol_name);
            }
        }
        fclose($conn);
        unset($connectionIMEIs[$connId]);
        clilogTracker("Connection closed", $protocol_name);
    }
}
fclose($socket);
?>
