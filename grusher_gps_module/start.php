<?php
    /**
     * Grusher GPS Module
     * @gviabcua
     */
    define('WORK_DIR', dirname(__FILE__));
    require_once WORK_DIR . '/config.php';
    require_once WORK_DIR . '/functions.php';

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

        clilog('Checking port ' . $port . ' for ' . $protocol . '...');
        if (isPortAvailable($localHostIp, $port, 2) !== 1) {
            clilog('SKIP: port ' . $port . ' already in use (is ' . $protocol . ' already running?)');
            continue;
        }

        clilog('Launching ' . $protocol . ' on port ' . $port . '...');
        shell_exec($php_path . ' ' . escapeshellarg($filepath) . ' -p ' . (int)$port . ' > /dev/null 2>&1 &');
    }

    clilog('');
    clilog('All protocols launched. This process will now exit.');
    clilog('Use "ps aux | grep php" to verify running listeners.');
