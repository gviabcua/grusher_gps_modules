<?php
// Shared non-blocking TCP event loop for all protocol listeners.
class GpsServer
{
    /** @var resource */
    private $server;
    private string $protocol;

    /** @var array<int,resource> */
    private array $clients = [];
    /** @var array<int,string> */
    private array $buffers = [];
    /** @var array<int,float> */
    private array $lastSeen = [];
    /** @var array<int,string> */
    private array $peers = [];

    private bool  $running   = true;
    private float $lastReap  = 0.0;

    /** Drop a connection after this many seconds without data. */
    public int $idleTimeout = 900;
    /** Hard cap — select() cannot watch more than FD_SETSIZE (1024) handles. */
    public int $maxClients = 400;
    /** Per-connection receive buffer cap; protects against memory exhaustion. */
    public int $maxBuffer = 262144;
    public int $readSize = 8192;
    /** select() timeout in microseconds. */
    public int $selectUsec = 200000;

    public function __construct(string $protocol, int $port, string $host = '0.0.0.0')
    {
        $this->protocol = $protocol;

        $server = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if (!$server) {
            clilogTracker("Failed to create socket: $errstr ($errno)", $protocol);
            exit(1);
        }

        stream_set_blocking($server, false);
        $this->server = $server;
        clilogTracker("Server started on {$host}:{$port} (pid " . getmypid() . ')', $protocol);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () { $this->running = false; });
            pcntl_signal(SIGINT,  function () { $this->running = false; });
            if (defined('SIGPIPE')) pcntl_signal(SIGPIPE, SIG_IGN);
        }
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function peer(int $id): string
    {
        return $this->peers[$id] ?? '?';
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    /**
     * @param callable $onData  function($conn, int $id, array &$buffers, GpsServer $srv): void
     *                          The handler consumes complete frames from $buffers[$id]
     *                          and leaves any partial tail in place.
     * @param callable|null $onClose function(int $id): void — purge protocol-side state.
     */
    public function run(callable $onData, ?callable $onClose = null): void
    {
        $this->lastReap = microtime(true);

        while ($this->running) {
            $read   = array_merge([$this->server], array_values($this->clients));
            $write  = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, $this->selectUsec);
            if ($ready === false) {
                // Interrupted syscall or descriptor-table overflow. Sleeping here is what keeps this from becoming a busy loop.
                usleep(50000);
                grusherPump();
                $this->reapIdle($onClose);
                continue;
            }

            if ($ready > 0) {
                foreach ($read as $sock) {
                    if ($sock === $this->server) {
                        $this->acceptAll($onClose);
                        continue;
                    }
                    $this->readFrom($sock, $onData, $onClose);
                }
            }
            // Advance queued Grusher requests without blocking the packet path.
            grusherPump();
            $this->reapIdle($onClose);
        }

        $this->shutdown($onClose);
    }

    private function acceptAll(?callable $onClose): void
    {
        // Drain the accept backlog; the socket is non-blocking so a timeout of 0 returns immediately once the queue is empty.
        for ($i = 0; $i < 32; $i++) {
            $conn = @stream_socket_accept($this->server, 0, $peer);
            if (!$conn) return;
            if (count($this->clients) >= $this->maxClients) {
                clilogTracker(
                    'Connection limit (' . $this->maxClients . ') reached — rejecting ' . $peer,
                    $this->protocol
                );
                @fclose($conn);
                continue;
            }
            stream_set_blocking($conn, false);
            $id = (int)$conn;
            // PHP reuses stream ids: wipe anything left over from a previous connection that happened to get the same id.
            if ($onClose !== null) {
                $onClose($id);
            }

            $this->clients[$id]  = $conn;
            $this->buffers[$id]  = '';
            $this->lastSeen[$id] = microtime(true);
            $this->peers[$id]    = (string)$peer;

            clilogTracker('New connection from ' . $peer, $this->protocol);
        }
    }

    private function readFrom($sock, callable $onData, ?callable $onClose): void
    {
        $id = (int)$sock;
        if (!isset($this->clients[$id])) return;

        $chunk = @fread($sock, $this->readSize);

        if ($chunk === false || ($chunk === '' && feof($sock))) {
            clilogTracker('Connection closed (' . $this->peer($id) . ')', $this->protocol);
            $this->close($id, $onClose);
            return;
        }
        if ($chunk === '') {
            // Spurious readability notification — not a disconnect.
            return;
        }
        $this->lastSeen[$id] = microtime(true);
        $this->buffers[$id] .= $chunk;
        if (strlen($this->buffers[$id]) > $this->maxBuffer) {
            clilogTracker(
                'Buffer overflow (' . strlen($this->buffers[$id]) . ' bytes) from ' .
                $this->peer($id) . ' — dropping connection',
                $this->protocol
            );
            $this->close($id, $onClose);
            return;
        }
        try {
            $onData($sock, $id, $this->buffers, $this);
        } catch (\Throwable $e) {
            // A malformed packet must never take the listener down.
            clilogTracker(
                'Parser error (' . get_class($e) . '): ' . $e->getMessage() .
                ' in ' . basename($e->getFile()) . ':' . $e->getLine() .
                ' — dropping connection ' . $this->peer($id),
                $this->protocol
            );
            $this->close($id, $onClose);
        }
    }

    private function reapIdle(?callable $onClose): void
    {
        $now = microtime(true);
        if ($now - $this->lastReap < 5.0) return;
        $this->lastReap = $now;
        if ($this->idleTimeout <= 0) return;
        foreach ($this->lastSeen as $id => $seen) {
            if ($now - $seen > $this->idleTimeout) {
                clilogTracker(
                    'Idle timeout (' . $this->idleTimeout . 's) — closing ' . $this->peer($id),
                    $this->protocol
                );
                $this->close($id, $onClose);
            }
        }
    }

    public function close(int $id, ?callable $onClose = null): void
    {
        if (isset($this->clients[$id]) && is_resource($this->clients[$id])) {
            @fclose($this->clients[$id]);
        }
        unset($this->clients[$id], $this->buffers[$id], $this->lastSeen[$id], $this->peers[$id]);
        if ($onClose !== null) {
            $onClose($id);
        }
    }

    private function shutdown(?callable $onClose): void
    {
        clilogTracker('Shutting down…', $this->protocol);
        foreach (array_keys($this->clients) as $id) {
            $this->close($id, $onClose);
        }
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        grusherFlush(3);
    }
}

/**
 * Common bootstrap for every protocol file: parse -p, harden the runtime,
 * install error handlers and return a ready GpsServer.
 */
function gpsBootstrap(string $protocol): GpsServer
{
    set_time_limit(0);
    ini_set('max_execution_time', '0');
    ini_set('max_input_time', '-1');
    ini_set('default_socket_timeout', (string)max(1, (int)cfg('GRUSHER_TIMEOUT', 2)));
    installErrorHandlers($protocol);
    $options = getopt('p:');
    if (!isset($options['p']) || (int)$options['p'] <= 0 || (int)$options['p'] >= 65536) {
        clilogTracker('Invalid or missing port (-p)', $protocol);
        exit(1);
    }
    $server = new GpsServer($protocol, (int)$options['p']);
    $server->idleTimeout = (int)cfg('client_idle_timeout', 900);
    $server->maxClients  = (int)cfg('max_clients', 400);
    $server->maxBuffer   = (int)cfg('max_client_buffer', 262144);
    return $server;
}
