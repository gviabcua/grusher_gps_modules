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

function processGpsData($conn, $payload_hex) {
	global $protocol_name;
	$raw = hex2bin($payload_hex);
	if (!$raw) {
		clilogTracker("Invalid hex payload", $protocol_name);
		return;
	}
	// split HTTP from body
	$parts = explode("\r\n\r\n", $raw, 2);
	if (count($parts) < 2) {
		clilogTracker("Invalid HTTP payload (no headers/body split)", $protocol_name);
		return;
	}
	list($headers_raw, $body) = $parts;
	// show header
	//clilogTracker("HTTP Headers: " . $headers_raw, $protocol_name);
	// parsing as JSON
	$data = json_decode($body, true);
	if (json_last_error() !== JSON_ERROR_NONE) {
		clilogTracker("JSON decode error: " . json_last_error_msg(), $protocol_name);
		return;
	}
	// check for needed fields
	if (!isset($data['device_id']) || !isset($data['location']['coords'])) {
		clilogTracker("Missing required fields in JSON", $protocol_name);
		return;
	}
	// get fields
	$device_id = $data['device_id'];
	$timestamp = $data['location']['timestamp'] ?? '';
	$lat = $data['location']['coords']['latitude'] ?? null;
	$lon = $data['location']['coords']['longitude'] ?? null;
	$speed = $data['location']['coords']['speed'] ?? null;
	$altitude = $data['location']['coords']['altitude'] ?? null;
	$battery = $data['location']['battery']['level'] ?? null;
	// loging and sending to Grusher
	$msg = "Device: $device_id | Time: $timestamp | Lat: $lat | Lon: $lon | Speed: $speed | Alt: $altitude | Battery: $battery";
	clilogTracker($msg, $protocol_name);
		sendToGrusher($device_id = null, $data = [
		"protocol_name" => $protocol_name,
		"last_alive" => $timestamp,
		"lat" => $lat,
		"lon" => $lon,
		"speed" => $speed,
		"alt" => $altitude,
		"battery" => $battery,
	]);
	
	// Answer for client
	$response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
	fwrite($conn, $response);
}

?>
