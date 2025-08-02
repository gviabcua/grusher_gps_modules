<?php
	$protocol_name =  explode(".", basename(__FILE__))[0];
	define("WORK_DIR", dirname(dirname(__FILE__)));
	require_once (WORK_DIR."/config.php");
	require_once (WORK_DIR."/functions.php");
	clilogTracker("Starting script...", $protocol_name);
	clilogTracker("WORK DIR is ".WORK_DIR, $protocol_name);
	clilogTracker("PROTOCOLS DIR is ".WORK_DIR."/protocols", $protocol_name);
	clilogTracker("LOGS DIR is ".WORK_DIR."/logs", $protocol_name);

	set_time_limit(0); // allow unlimited script loop
	ini_set('max_execution_time', 0);
	ini_set('default_socket_timeout', -1);
	ini_set('max_input_time', -1);

	$host = "0.0.0.0"; // listen all IP
	clilogTracker("Getting port", $protocol_name);   
	$options = getopt("p:");
	if (isset($options['p']) and ((int)$options['p'] > 0) and ((int)$options['p'] < 65536)){
		$port = (int)$options['p']; // port for tracker
	}else{
		clilogTracker("No port, or port not correct", $protocol_name); 
		return;
	}
	// Creating socket
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
				clilogTracker("New connection established", $protocol_name);
				// Keep the connection open for reading
				while (true) {
					clilogTracker("Getting data from tracker", $protocol_name);
					$payload = fread($conn, 1500);  // Read up to 1500 bytes
					if ($payload === false || $payload === '') {
						clilogTracker("Connection closed by client", $protocol_name);
						break;
					}
					clilogTracker("RAW: ".bin2hex($payload), $protocol_name);
					// Process the data
					processGpsData($conn, bin2hex($payload));
				}
				fclose($conn);
				clilogTracker("Connection closed", $protocol_name);
			}
		} catch (\Exception $e) {
			clilogTracker("Error processing connection: " . $e->getMessage(), $protocol_name);
		}
	}
	fclose($socket);

	function processGpsData($conn, $hex) {
		global $protocol_name;
		// === H02 (префікс 4040) ===
		if (str_starts_with($hex, '4040')) {
			clilogTracker("H02 protocol founded", $protocol_name);
			$data = hex2bin($hex);
			$protocolType = ord($data[3]);
			if ($protocolType == 0x10 || $protocolType == 0x80) {
				$datetime = sprintf(
					"20%02d-%02d-%02d %02d:%02d:%02d",
					ord($data[4]),
					ord($data[5]),
					ord($data[6]),
					ord($data[11]),
					ord($data[12]),
					ord($data[13])
				);
				// Широта
				$lat_deg = ord($data[7]);
				$lat_min = ord($data[8]) + ord($data[9]) / 100;
				$latitude = $lat_deg + $lat_min / 60;
				// N/S
				$ns = chr($data[10]);
				if ($ns == 'S') {
					$latitude = -$latitude;
				}
				// Довгота
				$lon_deg = ord($data[14]);
				$lon_min = ord($data[15]) + ord($data[16]) / 100;
				$longitude = $lon_deg + $lon_min / 60;
				// E/W
				$ew = chr($data[17]);
				if ($ew == 'W') {
					$longitude = -$longitude;
				}
				$speed = ord($data[18]);

				clilogTracker("$datetime | Lat: $latitude, Lon: $longitude, Speed: $speed", $protocol_name);
				sendToGrusher($device_id = null, $data = [
					"protocol_name" => $protocol_name,
					"last_alive" => $datetime,
					"lat" => $latitude,
					"lon" => $longitude,
					"speed" => $speed,
				]);
				// ACK — не обов'язково, але можна:
				$ack = "4040050135300D0A"; // стандартна відповідь
				fwrite($conn, hex2bin($ack));
				clilogTracker("H02 ACK done", $protocol_name);
			} else {
				clilogTracker("H02: Unknown packet type (0x" . dechex($protocolType) . ")", $protocol_name);
			}
			return;
		}
	}
?>
