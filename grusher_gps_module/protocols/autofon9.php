<?php
// Autofon SE-9 GPS protocol server

$protocol_name = explode('.', basename(__FILE__))[0];
define('WORK_DIR', dirname(dirname(__FILE__)));
require_once WORK_DIR . '/config.php';
require_once WORK_DIR . '/functions.php';

require_once WORK_DIR . '/gps_server.php';

$server = gpsBootstrap($protocol_name);
clilogTracker('Raw logging mode — no packets are forwarded to Grusher', $protocol_name);

$server->run(
    function ($conn, $id, &$buffers, GpsServer $srv) use ($protocol_name) {
        $data = $buffers[$id];
        $buffers[$id] = '';
        clilogTracker('RAW HEX: ' . bin2hex($data), $protocol_name);
        clilogTracker('RAW TXT: ' . addcslashes($data, "\x00..\x1F\x7F..\xFF"), $protocol_name);
    }
);
