<?php
    /**
     * Grusher GPS Module
     * @gviabcua
     */
    define('WORK_DIR', dirname(__FILE__));
    require_once WORK_DIR . '/config.php';
    require_once WORK_DIR . '/functions.php';

    // Logging writes here on the very first call below.
    if (!is_dir(WORK_DIR . '/logs')) {
        @mkdir(WORK_DIR . '/logs', 0775, true);
    }

    clilog('Starting script...');
    clilog('PROTOCOLS DIR: ' . WORK_DIR . '/protocols');
    clilog('LOGS DIR: '      . WORK_DIR . '/logs');

    // ── Resolve PHP binary ────────────────────────
    $php_path = trim((string)shell_exec('which php'));
    if (strlen($php_path) < 4) {
        die('ERROR: PHP binary not found in PATH' . PHP_EOL);
    }
    clilog('PHP path: ' . $php_path);

    $version_check = trim((string)shell_exec($php_path . ' --version'));
    if (strpos($version_check, 'Copyright') === false) {
        die("ERROR: PHP binary '$php_path' does not work" . PHP_EOL);
    }
    clilog('PHP binary verified');

    // ── Validate config ───────────────────────────
    if (!isset($protocols_ports)) {
        die('ERROR: $protocols_ports not defined in config.php' . PHP_EOL);
    }
    if (!is_array($protocols_ports) || empty($protocols_ports)) {
        die('ERROR: $protocols_ports is empty — uncomment at least one protocol' . PHP_EOL);
    }

    clilog('Configured protocols: ' . http_build_query($protocols_ports, '', ', '));
    clilog('');
    clilog('Starting protocol listeners...');

    // ── Launch each protocol handler ─────────────
    foreach ($protocols_ports as $protocol => $port) {
        $filepath = WORK_DIR . '/protocols/' . $protocol . '.php';

        if (!file_exists($filepath)) {
            clilog('ERROR: protocol file not found — ' . $filepath);
            continue;
        }

        $port = (int)$port;
        if ($port <= 0 || $port >= 65536) {
            clilog('ERROR: invalid port "' . $port . '" for ' . $protocol);
            continue;
        }

        clilog('Checking port ' . $port . ' for ' . $protocol . '...');
        // Listeners bind 0.0.0.0, so a successful connect to 127.0.0.1 means one is already up. A short timeout keeps this loop well under the one-minute cron interval even with every protocol enabled.
        if (isPortAvailable($localHostIp, $port, 1) !== 1) {
            clilog('SKIP: port ' . $port . ' already in use (is ' . $protocol . ' already running?)');
            continue;
        }

        clilog('Launching ' . $protocol . ' on port ' . $port . '...');
        // setsid detaches the listener from this script's session, so it is not killed when cron reaps the process group of a finished job.
        $cmd = 'setsid ' . escapeshellarg($php_path) . ' ' . escapeshellarg($filepath)
             . ' -p ' . $port . ' < /dev/null > /dev/null 2>&1 &';
        shell_exec($cmd);
    }

    clilog('');
    clilog('All protocols launched. This process will now exit.');
    clilog('Use "ps aux | grep php" to verify running listeners.');
