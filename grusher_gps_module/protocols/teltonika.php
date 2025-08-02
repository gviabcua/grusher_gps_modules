<?php
	$protocol_name = explode(".", basename(__FILE__))[0];
	define("WORK_DIR", dirname(dirname(__FILE__)));
	require_once(WORK_DIR . "/config.php");
	require_once(WORK_DIR . "/functions.php");

	clilogTracker("Starting script...", $protocol_name);

	set_time_limit(0);
	ini_set('max_execution_time', 0);
	ini_set('default_socket_timeout', -1);
	ini_set('max_input_time', -1);

	$host = "0.0.0.0";
	$options = getopt("p:");
	if (!isset($options['p']) || (int)$options['p'] <= 0 || (int)$options['p'] >= 65536) {
		clilogTracker("No port, or port not correct", $protocol_name);
		return;
	}
	$port = (int)$options['p'];

	$socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
	if (!$socket) {
		clilogTracker("Unable to create socket: $errstr ($errno)", $protocol_name);
		return;
	}

	clilogTracker("Server started on {$host}:{$port}", $protocol_name);
	$connectionIMEIs = [];

	while (true) {
		try {
			$conn = stream_socket_accept($socket);
			if ($conn) {
				clilogTracker("New connection established", $protocol_name);
				stream_set_blocking($conn, true);

				while (true) {
					$payload = fread($conn, 1500);
					if ($payload === false || $payload === '') {
						clilogTracker("Connection closed by client", $protocol_name);
						break;
					}
					//clilogTracker("RAW: " . bin2hex($payload), $protocol_name);
					processGpsData($conn, bin2hex($payload));
				}

				fclose($conn);
				unset($connectionIMEIs[(int)$conn]);
				clilogTracker("Connection closed", $protocol_name);
			}
		} catch (\Exception $e) {
			clilogTracker("Error: " . $e->getMessage(), $protocol_name);
		}
	}
	fclose($socket);

	function processGpsData($conn, $hex) {
		global $protocol_name, $connectionIMEIs;
		$data = hex2bin($hex);
		$connId = (int)$conn;
		//clilogTracker("Raw data (hex): $hex", $protocol_name);
		clilogTracker("Data length: " . strlen($data), $protocol_name);
		// IMEI пакет
		if (strlen($data) < 20) {
			$len = unpack("n", substr($data, 0, 2))[1];
			$imei = substr($data, 2, $len);
			clilogTracker("IMEI: $imei", $protocol_name);
			$connectionIMEIs[$connId] = $imei;
			fwrite($conn, chr(1)); // ACK for IMEI
			return;
		}
		// AVL пакет
		$offset = 0;
		$preamble = substr($data, $offset, 4); // 0x00000000
		$offset += 4;
		$avlLengthRaw = substr($data, $offset, 4);
		if (strlen($avlLengthRaw) < 4) {
			clilogTracker("No needed bytes for AVL Length", $protocol_name);
			return;
		}
		$avlLength = unpack("N", $avlLengthRaw)[1];
		$offset += 4;
		$codecId = ord($data[$offset++]);
		$recordCount = ord($data[$offset++]);
		clilogTracker("AVL Length from header: $avlLength", $protocol_name);
		clilogTracker("Codec ID: $codecId | Records: $recordCount", $protocol_name);
		if ($codecId === 0 || $recordCount === 0) {
			clilogTracker("Warning: Codec ID or record count is zero, possible malformed packet", $protocol_name);
			return;
		}
		for ($i = 0; $i < $recordCount; $i++) {
			if (strlen($data) < $offset + 30) {
				clilogTracker("❌ Недостатньо даних для запису №$i", $protocol_name);
				break;
			}
			$timestamp = unpack("J", substr($data, $offset, 8))[1];
			$offset += 8;
			$datetime = date("Y-m-d H:i:s", $timestamp / 1000);
			$priority = ord($data[$offset++]);

			$longitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
			$offset += 4;

			$latitude = parseSignedInt32(substr($data, $offset, 4)) / 10000000;
			$offset += 4;

			$altitude = unpack("n", substr($data, $offset, 2))[1];
			$offset += 2;

			$angle = unpack("n", substr($data, $offset, 2))[1];
			$offset += 2;

			$sats = ord($data[$offset++]);

			$speed = unpack("n", substr($data, $offset, 2))[1];
			$offset += 2;

			// IO Element parsing
			$eventId = ord($data[$offset++]);
			$totalIO = ord($data[$offset++]);
			// 1-byte IO
			$n1 = ord($data[$offset++]);
			$offset += $n1 * (1 + 1);
			// 2-byte IO
			$n2 = ord($data[$offset++]);
			$offset += $n2 * (1 + 2);
			// 4-byte IO
			$n4 = ord($data[$offset++]);
			$offset += $n4 * (1 + 4);
			// 8-byte IO
			$n8 = ord($data[$offset++]);
			$offset += $n8 * (1 + 8);
			
			$imei = $connectionIMEIs[$connId] ?? 'unknown';
			clilogTracker("[$i] IMEI: $imei | $datetime | Lat: $latitude, Lon: $longitude, Speed: $speed, Angle: $angle, Alt: $altitude, Sats: $sats", $protocol_name);
			sendToGrusher($imei, [
				"protocol_name" => $protocol_name,
				"last_alive" => $datetime,
				"lat" => $latitude,
				"lon" => $longitude,
				"speed" => $speed,
				"angle" => $angle,
				"alt" => $altitude,
				"sats" => $sats,
			]);
		}
		// Читаємо повторно кількість записів (ACK)
		if (strlen($data) < $offset + 1) {
			clilogTracker("Немає повторного каунтера записів для ACK", $protocol_name);
			return;
		}
		$records2 = ord($data[$offset++]);
		// CRC
		if (strlen($data) < $offset + 4) {
			clilogTracker("No bytes for CRC", $protocol_name);
			return;
		}
		$crc = unpack("N", substr($data, $offset, 4))[1];
		$offset += 4;
		// send ACK
		fwrite($conn, chr($recordCount));
		clilogTracker("ACK sent: $recordCount", $protocol_name);
	}

	function parseSignedInt32($bin) {
		$u = unpack("N", $bin)[1];
		return ($u & 0x80000000) ? -((~$u & 0xFFFFFFFF) + 1) : $u;
	}

?>
