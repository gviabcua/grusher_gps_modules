<?php
/**
 * GPS Tracking Server for OSMAND Protocols
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
                $payload = fread($sock, 1500);
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
	if($timestamp != ''){
		try{
			$timestamp = new DateTimeImmutable($timestamp);  // Or use DateTime if you need mutable
			$timestamp = $timestamp->format('Y-m-d H:i:s');
		} catch (\Throwable $e) { // For PHP 7
        	$timestamp = '';
        } catch (\Exception $e) { // For PHP 5
        	$timestamp = '';
        }
	}
	$lat = $data['location']['coords']['latitude'] ?? null;
	$lon = $data['location']['coords']['longitude'] ?? null;
	$speed = $data['location']['coords']['speed'] ?? null;
	$altitude = $data['location']['coords']['altitude'] ?? null;
	$battery = $data['location']['battery']['level'] ?? null;
	// loging and sending to Grusher
	$msg = "Device: $device_id | Time: $timestamp | Lat: $lat | Lon: $lon | Speed: $speed | Alt: $altitude | Battery: $battery";
	clilogTracker($msg, $protocol_name);
	$data = [
		"protocol_name" => $protocol_name,
		"last_alive" => $timestamp,
		"lat" => $lat,
		"lon" => $lon,
		"speed" => ($speed >= 0) ? $speed : 0,
		"alt" => $altitude,
		"battery" => ($battery >= 0) ? $battery * 100 : 0,
	];
	sendToGrusher($device_id, $data);
	// Answer for client
	$response = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
	fwrite($conn, $response);
}

?>
