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
    $GRUSHER_TIMEOUT = 2;

    // Set to false only for self-signed certs / internal HTTP deployments
    $GRUSHER_SSL_VERIFY = false;

    // ── Logging ──────────────────────────────────
    $write_start_script_log = 1;
    $write_gps_log          = 1;

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
