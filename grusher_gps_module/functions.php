<?php
    // ─────────────────────────────────────────────
    // Logging helpers
    // ─────────────────────────────────────────────

    function clilog($text = '') {
        $log = date('Y-m-d H:i:s') . ' - ' . $text . PHP_EOL;
        echo $log;
        global $write_start_script_log;
        if ($write_start_script_log == 1) {
            file_put_contents(WORK_DIR . '/logs/start_script_' . date('Ymd') . '.log', $log, FILE_APPEND);
        }
        usleep(50000);
    }

    function clilogTracker($text = '', $protocol = 'XXX') {
        $log = date('Y-m-d H:i:s') . ' - ' . $text . PHP_EOL;
        echo $log;
        global $write_gps_log;
        if ($write_gps_log == 1) {
            file_put_contents(WORK_DIR . '/logs/raw_' . $protocol . '_' . date('Ymd') . '.log', $text . "\n", FILE_APPEND);
        }
        usleep(50000);
    }

    // ─────────────────────────────────────────────
    // Network helpers
    // ─────────────────────────────────────────────

    function isPortAvailable($host, $port, $timeout = 1) {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            return 0; // port busy
        }
        return 1; // port free
    }

    /**
     * HTTP GET with configurable SSL verification.
     * Returns response body string or null on failure.
     */
    function fgc($url, $timeout = 5) {
        global $GRUSHER_SSL_VERIFY;
        $verify = isset($GRUSHER_SSL_VERIFY) ? (bool)$GRUSHER_SSL_VERIFY : true;

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeout,
                'header'  => "User-Agent: GrusherGPS/1.0\r\n",
            ],
            'https' => [
                'method'           => 'GET',
                'timeout'          => $timeout,
                'header'           => "User-Agent: GrusherGPS/1.0\r\n",
                'verify_peer'      => $verify,
                'verify_peer_name' => $verify,
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        return $content !== false ? $content : null;
    }

    // ─────────────────────────────────────────────
    // Coordinate validation
    // ─────────────────────────────────────────────

    /**
     * Returns true only when lat/lon are within valid ranges
     * and are not the "null island" (0, 0).
     */
    function isValidCoord($lat, $lon) {
        if ($lat === null || $lon === null) return false;
        $lat = (float)$lat;
        $lon = (float)$lon;
        if ($lat === 0.0 && $lon === 0.0) return false;
        return ($lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0);
    }

    // ─────────────────────────────────────────────
    // Grusher API sender
    // ─────────────────────────────────────────────

    /**
     * Send GPS data to Grusher.
     *
     * Required keys in $data: lat, lon
     * Optional keys: last_alive, speed, angle, alt, battery, sats, protocol_name, io
     * 
     */
    function sendToGrusher($tracker_id = null, $data = []) {
        if ($tracker_id === null) return;
        if (!is_array($data) || empty($data)) return;
        if (!isset($data['lat']) || !isset($data['lon'])) return;
        if (!isValidCoord($data['lat'], $data['lon'])) return;

        global $GRUSHER_URL, $GRUSHER_API_KEY, $GRUSHER_TIMEOUT;

        // Build query — tracker_id first, then all data fields
        $request = '&tracker_id=' . urlencode($tracker_id);
        foreach ($data as $key => $value) {
            $request .= '&' . urlencode($key) . '=' . urlencode((string)$value); 
        }

        $url = $GRUSHER_URL . '/api?key=' . urlencode($GRUSHER_API_KEY) . '&cat=billing&action=set_gps' . $request;

        // Safe log: mask the API key
        $safeUrl = preg_replace('/key=[^&]+/', 'key=***', $url);
        echo 'Sending to Grusher ' . $safeUrl . PHP_EOL;

        $response = fgc($url, $GRUSHER_TIMEOUT);
        if ($response === null) {
            echo 'WARNING: Grusher request failed for tracker ' . $tracker_id . PHP_EOL;
        }
    }
