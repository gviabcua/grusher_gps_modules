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
$buffer = [];

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
				
				while (($pos = strpos($buffer[$connId], "\r\n")) !== false) {
					$line = substr($buffer[$connId], 0, $pos);
					$buffer[$connId] = substr($buffer[$connId], $pos + 2);
					clilogTracker("Received line: $line", $protocol_name);
					processGpsDataTK103($conn, $line, $connId, $connectionIMEIs);
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

function processGpsDataTK103($conn, $line, $connId, &$connectionIMEIs) {
	global $protocol_name;
	// Зберігаємо IMEI з рядка, що починається на imei:
	if (str_starts_with($line, 'imei:')) {
		$parts = explode(',', $line);
		$imei = substr($parts[0], 5);
		$connectionIMEIs[$connId] = $imei;
		clilogTracker("IMEI is: $imei", $protocol_name);
		return;
	}
	if (!isset($connectionIMEIs[$connId])) {
		clilogTracker("IMEI not yet received, ignoring data", $protocol_name);
		return;
	}
	$imei = $connectionIMEIs[$connId];
	$parts = explode(',', $line);
	// Приклад мінімальних даних TK103: [tracker, datetime, , F, lat, N/S, lon, E/W, speed, ...]
	if (count($parts) >= 9) {
		$valid = trim($parts[3]) === 'F';
		if (!$valid) {
			clilogTracker("GPS data not valid", $protocol_name);
			return;
		}
		$dt_str = $parts[1]; // дата і час у форматі ддммррггггччммсс або ддммррггггччмм
		if (strlen($dt_str) < 10) {
			clilogTracker("Invalid date/time string: $dt_str", $protocol_name);
			return;
		}
		// Визначаємо дату і час (ддммррггггччммсс)
		$day = substr($dt_str, 0, 2);
		$month = substr($dt_str, 2, 2);
		$year = substr($dt_str, 4, 2);
		$hour = substr($dt_str, 6, 2);
		$min = substr($dt_str, 8, 2);
		$sec = strlen($dt_str) >= 12 ? substr($dt_str, 10, 2) : "00";
		$year = 2000 + (int)$year;
		$datetime = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $min, $sec);
		// Конвертація координат з формату DDMM.MMMM у десяткові градуси
		$lat_raw = $parts[4];
		$lat_dir = strtoupper($parts[5]);
		$lon_raw = $parts[6];
		$lon_dir = strtoupper($parts[7]);
		$latitude = tk103CoordToDecimal($lat_raw, $lat_dir);
		$longitude = tk103CoordToDecimal($lon_raw, $lon_dir);
		$speed_knots = floatval($parts[8]);
		$speed_kmh = $speed_knots * 1.852; // конвертація вузлів у км/год

		clilogTracker("TK103: IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed_kmh km/h", $protocol_name);
		sendToGrusher($imei, [
			"protocol_name" => $protocol_name,
			"last_alive" => $datetime,
			"lat" => $latitude,
			"lon" => $longitude,
			"speed" => $speed_kmh,
		]);
		// Відповідь OK, щоб пристрій не закривав з'єднання
		fwrite($conn, "ON\r\n");
	} else {
		clilogTracker("TK103: Unexpected data format: $line", $protocol_name);
	}
}

function tk103CoordToDecimal($coord, $direction) {
	// Конвертація DDMM.MMMM -> десятинні градуси
	// Приклад: 2232.1234 -> 22 градуси, 32.1234 хвилин
	if (strpos($coord, '.') === false) return 0;
	$parts = explode('.', $coord);
	if (strlen($parts[0]) <= 2) return 0;
	$degrees_len = (strlen($parts[0]) == 4) ? 2 : 3; // широта має 2 градуси, довгота 3
	$degrees = (int)substr($coord, 0, $degrees_len);
	$minutes = floatval(substr($coord, $degrees_len));
	$decimal = $degrees + ($minutes / 60);
	if ($direction == 'S' || $direction == 'W') {
		$decimal = -$decimal;
	}
	return $decimal;
}
?>
