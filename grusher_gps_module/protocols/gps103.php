<?php
$protocol_name = explode(".", basename(__FILE__))[0];
define("WORK_DIR", dirname(dirname(__FILE__)));
require_once(WORK_DIR . "/config.php");
require_once(WORK_DIR . "/functions.php");

clilogTracker("Starting script...", $protocol_name);
clilogTracker("WORK DIR is " . WORK_DIR, $protocol_name);
clilogTracker("PROTOCOLS DIR is " . WORK_DIR . "/protocols", $protocol_name);
clilogTracker("LOGS DIR is " . WORK_DIR . "/logs", $protocol_name);

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('default_socket_timeout', -1);
ini_set('max_input_time', -1);

$host = "0.0.0.0";
clilogTracker("Getting port", $protocol_name);
$options = getopt("p:");
if (isset($options['p']) and ((int)$options['p'] > 0) and ((int)$options['p'] < 65536)) {
	$port = (int)$options['p'];
} else {
	clilogTracker("No port, or port not correct", $protocol_name);
	return;
}

$connectionIMEIs = [];
$buffer = []; // для буферизації даних за з'єднанням

$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$socket) {
	clilogTracker("Unable to create socket: $errstr ($errno)", $protocol_name);
	return;
}
clilogTracker("Server started on {$host}:{$port}", $protocol_name);

while (true) {
	try {
		$conn = stream_socket_accept($socket);
		if ($conn) {
			$connId = intval($conn);
			clilogTracker("New connection established", $protocol_name);
			$buffer[$connId] = '';

			while (true) {
				$data = fread($conn, 1024);
				if ($data === false || $data === '') {
					clilogTracker("Connection closed by client", $protocol_name);
					break;
				}
				$buffer[$connId] .= $data;
				
				// Обробляємо рядки, розділені \r\n
				while (($pos = strpos($buffer[$connId], "\r\n")) !== false) {
					$line = substr($buffer[$connId], 0, $pos);
					$buffer[$connId] = substr($buffer[$connId], $pos + 2);
					clilogTracker("Received line: $line", $protocol_name);
					processGpsDataGPS103($conn, $line, $connId, $connectionIMEIs);
				}
			}
			fclose($conn);
			clilogTracker("Connection closed", $protocol_name);
			unset($connectionIMEIs[$connId]);
			unset($buffer[$connId]);
		}
	} catch (\Exception $e) {
		clilogTracker("Error processing connection: " . $e->getMessage(), $protocol_name);
	}
}

function processGpsDataGPS103($conn, $line, $connId, &$connectionIMEIs) {
	global $protocol_name;
	// IMEI рядок починається з "imei:"
	if (str_starts_with($line, 'imei:')) {
		$parts = explode(',', $line);
		$imei = substr($parts[0], 5); // видаляємо 'imei:'
		$connectionIMEIs[$connId] = $imei;
		clilogTracker("IMEI is: $imei", $protocol_name);
		return;
	}

	// Якщо вже маємо IMEI для цього підключення і це не IMEI рядок
	if (!isset($connectionIMEIs[$connId])) {
		clilogTracker("IMEI not yet received, ignoring data", $protocol_name);
		return;
	}
	$imei = $connectionIMEIs[$connId];
	// Очікуємо формат даних GPS103 з комами
	$parts = explode(',', $line);
	// Перевіримо мінімальну кількість полів і формат
	// Приклад типового GPS103 рядка (після IMEI):
	// tracker,1202231505,,F,22.563191,N,114.058557,E,0.02,;
	if (count($parts) >= 9) {
		$valid = trim($parts[3]) === 'F'; // F означає валідність GPS
		if (!$valid) {
			clilogTracker("GPS data not valid", $protocol_name);
			return;
		}
		// Дата та час - у форматі ddmmyyhhmm (у прикладі 1202231505)
		// GPS103 часто дає час у форматі ddmmyyhhmmss, але може бути і інше — тут обробимо як ddmmyyhhmm
		$dt_str = $parts[1];
		if (strlen($dt_str) < 10) {
			clilogTracker("Invalid date/time string: $dt_str", $protocol_name);
			return;
		}
		$day = substr($dt_str, 0, 2);
		$month = substr($dt_str, 2, 2);
		$year = substr($dt_str, 4, 2);
		$hour = substr($dt_str, 6, 2);
		$min = substr($dt_str, 8, 2);
		$year = 2000 + (int)$year;
		$datetime = sprintf("%04d-%02d-%02d %02d:%02d:00", $year, $month, $day, $hour, $min);
		$lat = floatval($parts[4]);
		if (strtoupper($parts[5]) == 'S') $lat = -$lat;
		$lon = floatval($parts[6]);
		if (strtoupper($parts[7]) == 'W') $lon = -$lon;
		$speed = floatval($parts[8]);
		clilogTracker("GPS103: IMEI: $imei | $datetime | Lat: $lat, Lon: $lon, Speed: $speed", $protocol_name);
		sendToGrusher($imei, [
			"protocol_name" => $protocol_name,
			"last_alive" => $datetime,
			"lat" => $lat,
			"lon" => $lon,
			"speed" => $speed,
		]);

		// Відповідь "ON" (треба для gps103) для підтримки з'єднання
		fwrite($conn, "ON\r\n");
	} else {
		clilogTracker("GPS103: Unexpected data format: $line", $protocol_name);
	}
}
?>
