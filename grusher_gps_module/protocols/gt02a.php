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
				// Обробляємо пакети, які починаються з 0x7878 та закінчуються 0x0D0A
				while (($startPos = strpos($buffer[$connId], "\x78\x78")) !== false) {
					if (strlen($buffer[$connId]) < $startPos + 5) break; // чекати поки буде мінімум 5 байт
					$length = ord($buffer[$connId][$startPos + 2]);
					$packetLen = $length + 5; // 2 (start) +1 (length) + length + 2 (crc) + 2 (end)
					if (strlen($buffer[$connId]) < $startPos + $packetLen) break;
					$packet = substr($buffer[$connId], $startPos, $packetLen);
					$buffer[$connId] = substr($buffer[$connId], $startPos + $packetLen);
					clilogTracker("Packet: " . bin2hex($packet), $protocol_name);
					processGpsDataGT02A($conn, $packet, $connId, $connectionIMEIs);
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

function processGpsDataGT02A($conn, $packet, $connId, &$connectionIMEIs) {
	global $protocol_name;
	if (strlen($packet) < 12) {
		clilogTracker("Packet too short", $protocol_name);
		return;
	}
	// Пакет починається з 0x7878
	if (substr($packet, 0, 2) !== "\x78\x78") {
		clilogTracker("Invalid packet header", $protocol_name);
		return;
	}
	$length = ord($packet[2]);
	$protocol = ord($packet[3]);
	// Пакет IMEI (тип 0x01) - збереження IMEI
	if ($protocol == 0x01) {
		// IMEI 8 байт з позиції 4 по 11, ASCII
		$imei_raw = substr($packet, 4, 8);
		$imei = '';
		for ($i = 0; $i < 8; $i++) {
			$imei .= sprintf("%02X", ord($imei_raw[$i]));
		}
		// Зберігаємо IMEI (іноді IMEI 15 цифр, тут беремо 8 байт, може бути трохи коротше)
		$connectionIMEIs[$connId] = $imei;
		clilogTracker("IMEI received: $imei", $protocol_name);
		// Відповідь ACK 0x01
		$response = "\x78\x78\x05\x01\x00\x01\xD9\xDC\x0D\x0A";
		fwrite($conn, $response);
		clilogTracker("IMEI ACK sent", $protocol_name);
		return;
	}
	// Якщо це пакет позиції (наприклад, 0x12 або 0x22, залежить від пристрою)
	if ($protocol == 0x12 || $protocol == 0x22) {
		if (!isset($connectionIMEIs[$connId])) {
			clilogTracker("No IMEI yet, ignoring position packet", $protocol_name);
			return;
		}
		$imei = $connectionIMEIs[$connId];
		// Дата і час з байтів 4-9
		$datetimeRaw = substr($packet, 4, 6);
		$year = ord($datetimeRaw[0]) + 2000;
		$month = ord($datetimeRaw[1]);
		$day = ord($datetimeRaw[2]);
		$hour = ord($datetimeRaw[3]);
		$min = ord($datetimeRaw[4]);
		$sec = ord($datetimeRaw[5]);
		$datetime = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $min, $sec);
		// Координати
		$latRaw = unpack("N", substr($packet, 10, 4))[1];
		$lngRaw = unpack("N", substr($packet, 14, 4))[1];
		$latitude = $latRaw / 1800000;
		$longitude = $lngRaw / 1800000;
		$speed = ord($packet[18]);
		// Курс і статус
		$courseStatus = unpack("n", substr($packet, 19, 2))[1];
		$course = $courseStatus & 0x03FF;
		$satellites = ord($packet[11]) & 0x0F; // приблизно як GT06, залежить від прошивки
		clilogTracker("GT02A: IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Course: $course, Sats: $satellites", $protocol_name);
		sendToGrusher($imei, [
			"protocol_name" => $protocol_name,
			"last_alive" => $datetime,
			"lat" => $latitude,
			"lon" => $longitude,
			"speed" => $speed,
			"angle" => $course,
			"sats" => $satellites,
		]);
		// Відповідь ACK — беремо серійний номер (2 байти) наприкінці пакету перед CRC
		$serial = substr($packet, $length + 1 - 4, 2);
		$response = "\x78\x78\x05\x01" . $serial;
		$crc = crc16(substr($response, 2));
		$response .= pack("n", $crc) . "\x0D\x0A";
		fwrite($conn, $response);
		clilogTracker("GT02A ACK sent", $protocol_name);
		return;
	}
	clilogTracker("Unknown GT02A protocol: 0x" . dechex($protocol), $protocol_name);
}

// CRC16-IBM для GT02A
function crc16($buffer) {
	$crc = 0xFFFF;
	for ($i = 0; $i < strlen($buffer); $i++) {
		$crc ^= ord($buffer[$i]);
		for ($j = 0; $j < 8; $j++) {
			if ($crc & 0x01) {
				$crc = ($crc >> 1) ^ 0xA001;
			} else {
				$crc = $crc >> 1;
			}
		}
	}
	return $crc & 0xFFFF;
}
?>
