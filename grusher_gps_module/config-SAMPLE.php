<?php
    /************************
     * Grusher GPS Module
     * RENAME THIS FILE TO config.php
     * @gviabcua
     ************************/

    $localHostIp = '127.0.0.1'; // do not change

    // ── Grusher ──────────────────────────────────
    $GRUSHER_URL     = 'http://192.168.1.1';
    $GRUSHER_API_KEY = 'key';
    $GRUSHER_TIMEOUT = 2;   // total time budget for one request, seconds

    // Hard cap on the TCP/TLS handshake. Without it an unreachable Grusher
    // blocks the listener for the OS connect timeout (~2 minutes).
    $GRUSHER_CONNECT_TIMEOUT = 3;

    // Set to false only for self-signed certs / internal HTTP deployments
    $GRUSHER_SSL_VERIFY = false;

    // ── Grusher failure handling ─────────────────
    // Requests are sent asynchronously; these bound the damage when Grusher
    // is down so the listeners keep accepting packets from the trackers.
    $GRUSHER_MAX_INFLIGHT      = 64; // queued requests before new ones are dropped
    $GRUSHER_FAIL_THRESHOLD    = 3;  // consecutive failures that trip the breaker
    $GRUSHER_BREAKER_COOLDOWN  = 30; // seconds to stop sending after tripping

    // ── Listener limits ──────────────────────────
    $max_clients        = 400;      // select() cannot watch more than ~1024 handles
    $client_idle_timeout = 900;     // drop a connection after N seconds of silence
    $max_client_buffer  = 262144;   // per-connection receive buffer cap, bytes

    // ── Logging ──────────────────────────────────
    $write_start_script_log = 1;
    $write_gps_log          = 1;
    $log_to_stdout          = 1;    // set to 0 when running under cron/systemd
    $log_grusher_requests   = 1;    // log every outgoing API call (verbose)
    $log_max_bytes          = 33554432; // rotate a log file once it exceeds this

    // ── Protocol ports ───────────────────────────
    // Comment out protocols you do not use.
    $protocols_ports = [
        // ── Tested & working ─────────────────────
        'osmand'   => 5055,   // OsmAnd HTTP/JSON — also used by many mobile apps
        'teltonika' => 5027,  // Teltonika FMB/FMT series (Codec 8 / 8E / 16)

        // ── Tested / community-verified ──────────
        'gps103'   => 5001,  // GPS103 / TK103 clone — common cheap Chinese trackers
        'gt06'     => 5023,  // GT06 / JM-LL01 — popular mid-range Chinese trackers
        'gt02a'    => 5022,  // GT02A — budget Chinese trackers
        'h02'      => 5013,  // H02 — simple cheap trackers
        'tk103'    => 5002,  // TK103 text-based variant

        // ── Not tested / disabled by default ─────
        // Москалі - згенеровано ШІ. Кому потрібно - доробляйте. Я хз чи воно працює
        // Uncomment only if you have actual devices to test against.
        // 'autofon5'   => 5077,
        // 'autofon7'   => 5099,
        // 'autofon9'   => 9109,
        // 'galileosky' => 5034,

        // NOT DONE - NOT PRESENT
		//"meiligao" => 5009,
		//"mikrotik" => 5200,
		//"ntcb_flex" => 9000,
		//"okonavi" => 5098,
		//"wialon" => 5039,
    ];
