<?php
    // Grusher GPS Module — shared helpers

    // Config defaults (so a stale config.php cannot produce "undefined variable" warnings)
    function cfg($name, $default = null) {
        return array_key_exists($name, $GLOBALS) && $GLOBALS[$name] !== null ? $GLOBALS[$name] : $default;
    }

    // Logging helpers
    function logWrite($file, $line) {
        $maxBytes = (int)cfg('log_max_bytes', 32 * 1024 * 1024);
        if ($maxBytes > 0 && @filesize($file) > $maxBytes) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    function clilog($text = '') {
        $log = date('Y-m-d H:i:s') . ' - ' . $text . PHP_EOL;
        echo $log;
        if ((int)cfg('write_start_script_log', 0) === 1) {
            logWrite(WORK_DIR . '/logs/start_script_' . date('Ymd') . '.log', $log);
        }
    }

    // Per-protocol logger.
    function clilogTracker($text = '', $protocol = 'XXX') {
        if ((int)cfg('log_to_stdout', 1) === 1) {
            echo date('Y-m-d H:i:s') . ' - ' . $text . PHP_EOL;
        }
        if ((int)cfg('write_gps_log', 0) === 1) {
            logWrite(WORK_DIR . '/logs/raw_' . $protocol . '_' . date('Ymd') . '.log', date('Y-m-d H:i:s') . ' - ' . $text . "\n");
        }
    }

    // Crash safety
    function installErrorHandlers($protocol) {
        set_error_handler(function ($no, $str, $file, $line) use ($protocol) {
            if (!(error_reporting() & $no)) return true;
            clilogTracker("PHP warning: $str in " . basename($file) . ":$line", $protocol);
            return true; // do not fall through to the default handler
        });

        register_shutdown_function(function () use ($protocol) {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                clilogTracker('FATAL: ' . $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line'], $protocol);
            }
            grusherFlush(2);
        });
    }

    // Network helpers
    function isPortAvailable($host, $port, $timeout = 1) {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection);
            return 0; // port busy
        }
        return 1; // port free
    }

    // Write to a client socket without ever blocking the event loop. Returns false if the peer is gone (caller may drop the connection).
    function safeFwrite($conn, $data) {
        if (!is_resource($conn) || $data === '' || $data === null) return true;
        $len     = strlen($data);
        $written = 0;
        $guard   = 0;
        while ($written < $len) {
            $n = @fwrite($conn, substr($data, $written));
            if ($n === false || $n === 0) {
                // Non-blocking socket buffer is full, or the peer went away.
                if (++$guard > 50) return false;
                usleep(1000);
                continue;
            }
            $written += $n;
            $guard = 0;
        }
        return true;
    }

    //Blocking HTTP GET with a hard connect timeout. Returns the response body, or null on failure.
    //Kept for compatibility / one-off calls. The protocol listeners use the non-blocking grusherSend() path below instead.
    function fgc($url, $timeout = 5) {
        $verify        = (bool)cfg('GRUSHER_SSL_VERIFY', true);
        $connectTimeout = max(1, min((int)$timeout, (int)cfg('GRUSHER_CONNECT_TIMEOUT', 3)));
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => max(1, (int)$timeout),
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_USERAGENT      => 'GrusherGPS/1.0',
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $err  = curl_errno($ch);
            curl_close($ch);
            return $err === 0 ? $body : null;
        }

        // Fallback: stream wrapper.
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'header'        => "User-Agent: GrusherGPS/1.0\r\nConnection: close\r\n",
            ],
            'ssl' => [
                'verify_peer'      => $verify,
                'verify_peer_name' => $verify,
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        return $content !== false ? $content : null;
    }

    // Coordinate validation
    // Returns true only when lat/lon are within valid ranges and are not the "null island" (0, 0).
    function isValidCoord($lat, $lon) {
        if ($lat === null || $lon === null) return false;
        if (!is_numeric($lat) || !is_numeric($lon)) return false;
        $lat = (float)$lat;
        $lon = (float)$lon;
        if (is_nan($lat) || is_nan($lon)) return false;
        if ($lat === 0.0 && $lon === 0.0) return false;
        return ($lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0);
    }

    // Grusher API sender — non-blocking
    // Mutable process-wide sender state (ArrayObject so callers share one copy).
    function grusherStateRef() {
        static $ref = null;
        if ($ref === null) {
            $ref = new ArrayObject([
                'multi'      => null,
                'handles'    => [],   // spl_object_id($ch) => ['ch'=>..,'tracker'=>..,'protocol'=>..]
                'failures'   => 0,
                'openUntil'  => 0,    // circuit breaker: skip sending until this ts
                'lastReport' => 0,
                'dropped'    => 0,
            ]);
        }
        return $ref;
    }

    // Queue a GET request to Grusher. Never blocks for more than a few microseconds when cURL is available.
    function grusherSend($url, $tracker_id, $protocol = 'XXX') {
        $st  = grusherStateRef();
        $now = time();
        // ── Circuit breaker ──────────────────────────────
        if ($st['openUntil'] > $now) {
            $st['dropped']++;
            if ($now - $st['lastReport'] >= 30) {
                clilogTracker('Grusher unreachable — skipping sends for ' . ($st['openUntil'] - $now) . 's (' . $st['dropped'] . ' dropped)', $protocol);
                $st['lastReport'] = $now;
            }
            return;
        }
        if (!function_exists('curl_multi_init')) {
            // No cURL: fall back to a short blocking call.
            $ok = fgc($url, (int)cfg('GRUSHER_TIMEOUT', 2)) !== null;
            grusherRecordResult($ok, $tracker_id, $protocol, 'no-curl');
            return;
        }
        if ($st['multi'] === null) {
            $st['multi'] = curl_multi_init();
        }
        // Bound the in-flight queue so a slow Grusher cannot exhaust memory.
        $maxInflight = (int)cfg('GRUSHER_MAX_INFLIGHT', 64);
        if (count($st['handles']) >= $maxInflight) {
            grusherPump();
            if (count($st['handles']) >= $maxInflight) {
                $st['dropped']++;
                clilogTracker('Grusher queue full — dropping update for ' . $tracker_id, $protocol);
                return;
            }
        }
        $verify = (bool)cfg('GRUSHER_SSL_VERIFY', true);
        $ch     = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)cfg('GRUSHER_CONNECT_TIMEOUT', 3)),
            CURLOPT_TIMEOUT        => max(1, (int)cfg('GRUSHER_TIMEOUT', 2)),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_USERAGENT      => 'GrusherGPS/1.0',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL       => true,
        ]);
        curl_multi_add_handle($st['multi'], $ch);
        $handles                      = $st['handles'];
        $handles[spl_object_id($ch)]  = ['ch' => $ch, 'tracker' => $tracker_id, 'protocol' => $protocol];
        $st['handles']                = $handles;
        grusherPump();
    }

    // Advance queued transfers and reap finished ones. Called from the event loop on every iteration — must never block.
    function grusherPump() {
        $st = grusherStateRef();
        if ($st['multi'] === null || empty($st['handles'])) return;

        $active = null;
        do {
            $rc = curl_multi_exec($st['multi'], $active);
        } while ($rc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($st['multi'])) {
            $ch      = $info['handle'];
            $key     = is_object($ch) ? spl_object_id($ch) : (int)$ch;
            $handles = $st['handles'];
            $meta    = $handles[$key] ?? ['tracker' => '?', 'protocol' => 'XXX'];

            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ok   = ($info['result'] === CURLE_OK && $code >= 200 && $code < 400);
            $why  = $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : ('HTTP ' . $code);

            grusherRecordResult($ok, $meta['tracker'], $meta['protocol'], $why);

            curl_multi_remove_handle($st['multi'], $ch);
            curl_close($ch);
            unset($handles[$key]);
            $st['handles'] = $handles;
        }
    }

    // Update circuit-breaker state after a completed request.
    function grusherRecordResult($ok, $tracker_id, $protocol, $why = '') {
        $st = grusherStateRef();
        if ($ok) {
            $st['failures']  = 0;
            $st['openUntil'] = 0;
            $st['dropped']   = 0;
            return;
        }
        $st['failures'] = $st['failures'] + 1;
        clilogTracker('WARNING: Grusher request failed for tracker ' . $tracker_id . ' (' . $why . ')', $protocol);
        $threshold = max(1, (int)cfg('GRUSHER_FAIL_THRESHOLD', 3));
        if ($st['failures'] >= $threshold) {
            $cooldown        = max(5, (int)cfg('GRUSHER_BREAKER_COOLDOWN', 30));
            $st['openUntil'] = time() + $cooldown;
            $st['failures']  = 0;
            clilogTracker('Grusher marked DOWN — pausing sends for ' . $cooldown . 's', $protocol);
        }
    }
    // Drain the queue for at most $seconds (used on shutdown).
    function grusherFlush($seconds = 2) {
        $st = grusherStateRef();
        if ($st['multi'] === null) return;
        $deadline = microtime(true) + $seconds;
        while (!empty($st['handles']) && microtime(true) < $deadline) {
            curl_multi_select($st['multi'], 0.1);
            grusherPump();
        }
    }

    // Send GPS data to Grusher.
    // Required keys in $data: lat, lon
    // Optional keys: last_alive, speed, angle, alt, battery, sats, protocol_name, io
    function sendToGrusher($tracker_id = null, $data = []) {
        if ($tracker_id === null || $tracker_id === '') return;
        if (!is_array($data) || empty($data)) return;
        if (!isset($data['lat']) || !isset($data['lon'])) return;
        if (!isValidCoord($data['lat'], $data['lon'])) return;
        $base    = (string)cfg('GRUSHER_URL', '');
        $apiKey  = (string)cfg('GRUSHER_API_KEY', '');
        $protocol = isset($data['protocol_name']) ? (string)$data['protocol_name'] : 'XXX';
        if ($base === '') {
            clilogTracker('ERROR: GRUSHER_URL is not configured', $protocol);
            return;
        }
        // Build query — tracker_id first, then all data fields
        $request = '&tracker_id=' . urlencode((string)$tracker_id);
        foreach ($data as $key => $value) {
            if ($value === null) continue;
            $request .= '&' . urlencode((string)$key) . '=' . urlencode((string)$value);
        }
        $url = rtrim($base, '/') . '/api?key=' . urlencode($apiKey) . '&cat=billing&action=set_gps' . $request;

        if ((int)cfg('log_grusher_requests', 1) === 1) {
            clilogTracker('Sending to Grusher ' . preg_replace('/key=[^&]*/', 'key=***', $url),$protocol);
        }
        grusherSend($url, $tracker_id, $protocol);
    }
