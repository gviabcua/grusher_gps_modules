<?php
/**
 * Autofon SE-9 GPS protocol server
 *
 * WARNING: EXPERIMENTAL — the binary protocol for Autofon SE-9 is not publicly
 * documented. The original implementation was entirely AI-fabricated with no
 * real specification. This version logs raw packets so you can capture and
 * analyse actual device traffic before implementing proper parsing.
 *
 */

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__))); // FIX: was dirname(__FILE__)
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';

clilogTracker("Starting server (STUB - logs raw data only)...", $protocol_name);

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('default_socket_timeout', -1);
ini_set('max_input_time', -1);

$options = getopt('p:');
if (!isset($options['p']) || (int)$options['p'] <= 0 || (int)$options['p'] >= 65536) {
    clilogTracker('Invalid or missing port (-p)', $protocol_name);
    exit(1);
}
$port = (int)$options['p'];
$host = '0.0.0.0';

$server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) {
    clilogTracker("Cannot create socket: $errstr ($errno)", $protocol_name);
    exit(1);
}
stream_set_blocking($server, false);
clilogTracker("Server started on $host:$port (raw logging mode)", $protocol_name);

$clients = [];
$buffers = [];

while (true) {
    $read   = array_merge([$server], array_values($clients));
    $write  = null;
    $except = null;

    if (stream_select($read, $write, $except, 0, 200000) < 1) {
        continue;
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = stream_socket_accept($server);
            if ($conn) {
                stream_set_blocking($conn, false);
                $id = (int)$conn;
                $clients[$id] = $conn;
                $buffers[$id] = '';
                clilogTracker('New connection — logging raw data for protocol analysis', $protocol_name);
            }
            continue;
        }

        $id   = (int)$sock;
        $data = fread($sock, 4096);

        if ($data === false || $data === '') {
            clilogTracker('Connection closed', $protocol_name);
            fclose($sock);
            unset($clients[$id], $buffers[$id]);
            continue;
        }

        // Log every raw byte — this is the data you need to analyse
        clilogTracker('RAW HEX: ' . bin2hex($data), $protocol_name);
        clilogTracker('RAW TXT: ' . addcslashes($data, "\x00..\x1F\x7F..\xFF"), $protocol_name);
    }
}
