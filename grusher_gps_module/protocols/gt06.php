<?php
	$protocol_name = explode(".", basename(__FILE__))[0];
	define("WORK_DIR", dirname(dirname(__FILE__)));
	require_once(WORK_DIR . "/config.php");
	require_once(WORK_DIR . "/functions.php");

	clilogTracker("Starting script...", $protocol_name);
	clilogTracker("WORK DIR is " . WORK_DIR, $protocol_name);
	clilogTracker("PROTOCOLS DIR is " . WORK_DIR . "/protocols", $protocol_name);
	clilogTracker("LOGS DIR is " . WORK_DIR . "/logs", $protocol_name);

	set_time_limit(0); // allow unlimited script loop
	ini_set('max_execution_time', 0);
	ini_set('default_socket_timeout', -1);
	ini_set('max_input_time', -1);

	$host = "0.0.0.0"; // listen all IP
	clilogTracker("Getting port", $protocol_name);
	$options = getopt("p:");
	if (isset($options['p']) and ((int)$options['p'] > 0) and ((int)$options['p'] < 65536)) {
		$port = (int)$options['p']; // port for tracker
	} else {
		clilogTracker("No port, or port not correct", $protocol_name);
		return;
	}
	// Save IMEI for every connections
	$connectionIMEIs = [];
	$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
	if (!$socket) {
		clilogTracker("Unable to create socket: $errstr ($errno)", $protocol_name);
		return;
	}
	$log = "Server is started on {$host}:{$port}...";
	clilogTracker($log, $protocol_name);

	while (true) {
		try {
			$conn = stream_socket_accept($socket);
			if ($conn) {
				$connId = intval($conn); 
				clilogTracker("New connection established", $protocol_name);
				while (true) {
					clilogTracker("Getting data from tracker", $protocol_name);
					$payload = fread($conn, 1500);  // Read up to 1500 bytes
					if ($payload === false || $payload === '') {
						clilogTracker("Connection closed by client", $protocol_name);
						break;
					}
					clilogTracker("RAW: " . bin2hex($payload), $protocol_name);
					processGpsData($conn, bin2hex($payload), $connId, $connectionIMEIs);
				}
				fclose($conn);
				clilogTracker("Connection closed", $protocol_name);
				unset($connectionIMEIs[$connId]);
			}
		} catch (\Exception $e) {
			clilogTracker("Error processing connection: " . $e->getMessage(), $protocol_name);
		}
	}
	fclose($socket);

	function processGpsData($conn, $hex, $connId, &$connectionIMEIs){
		global $protocol_name;
		// if start with 000f ... this is IMEI pachet GT06 (write IMEI)
		if (strlen($hex) == 34 && substr($hex, 0, 4) === "000f") {
			// IMEI in ASCII from 5 bytes (10 symbols) after prreffix 000f
			$imei_hex = substr($hex, 4); // all after 000f
			$imei = '';
			for ($i = 0; $i < strlen($imei_hex); $i += 2) {
				$imei .= chr(hexdec(substr($imei_hex, $i, 2)));
			}
			$imei = preg_replace('/\D/', '', $imei);
			$connectionIMEIs[$connId] = $imei;
			clilogTracker("IMEI is: $imei", $protocol_name);
			return;
		}

		// if start with 7878 — this GT06
		if (str_starts_with($hex, '7878')) {
			clilogTracker("GT06 protocol founded", $protocol_name);
			$data = hex2bin($hex);
			$len = ord($data[1]);
			$protocol = ord($data[3]);
			if ($protocol == 0x22 || $protocol == 0x12) {
				$datetimeRaw = substr($data, 4, 6);
				$year = ord($datetimeRaw[0]) + 2000;
				$month = ord($datetimeRaw[1]);
				$day = ord($datetimeRaw[2]);
				$hour = ord($datetimeRaw[3]);
				$min = ord($datetimeRaw[4]);
				$sec = ord($datetimeRaw[5]);
				$datetime = sprintf("%04d-%02d-%02d %02d:%02d:%02d", $year, $month, $day, $hour, $min, $sec);
				$satellites = ord($data[11]) & 0x0F;
				$latRaw = unpack("N", substr($data, 12, 4))[1];
				$lngRaw = unpack("N", substr($data, 16, 4))[1];
				$latitude = $latRaw / 1800000;
				$longitude = $lngRaw / 1800000;
				$speed = ord($data[20]);
				$courseStatus = unpack("n", substr($data, 21, 2))[1];
				$course = $courseStatus & 0x03FF;
				$imei = $connectionIMEIs[$connId] ?? 'unknown';
				clilogTracker("GT06: IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Course: $course, Sats: $satellites", $protocol_name);
				sendToGrusher($imei, [
					"protocol_name" => $protocol_name,
					"last_alive" => $datetime,
					"lat" => $latitude,
					"lon" => $longitude,
					"speed" => $speed,
					"angle" => $course,
					"sats" => $satellites,
				]);
				// ACK answer
				$serial = substr($data, -6, 2);
				$response = "78780501" . bin2hex($serial);
				$crc = strtoupper(dechex(crc16(hex2bin($response))));
				$ack = $response . $crc . "0D0A";
				fwrite($conn, hex2bin($ack));
				clilogTracker("GT06 ACK done", $protocol_name);
			} else {
				clilogTracker("Unknown packet type (0x" . dechex($protocol) . ")", $protocol_name);
			}
			return;
		}
	}

	// CRC16-IBM це тільки для GT06 в інших може буть інше
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
