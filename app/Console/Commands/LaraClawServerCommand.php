<?php

namespace App\Console\Commands;

use App\DTOs\CommandResponseDTO;
use App\Logging\MultiLogger;
use App\Services\CommandProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Safe\getmypid;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\pcntl_signal;
use function Safe\pcntl_signal_dispatch;
use function Safe\posix_kill;
use function Safe\preg_match;
use function Safe\socket_accept;
use function Safe\socket_bind;
use function Safe\socket_create;
use function Safe\socket_listen;
use function Safe\socket_read;
use function Safe\socket_set_nonblock;
use function Safe\socket_set_option;
use function Safe\socket_write;
use function Safe\unpack;

class LaraClawServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:server
                            {action : Action to perform (start|stop|status)}
                            {--port= : Port to listen on (overrides config)}';

    /**
     * The console command description.
     */
    protected $description = 'Manage the LaraClaw WebSocket server';

    protected CommandProcessingService $commandService;

    /**
     * Server state
     */
    protected string $pidFile;

    protected string $host = '0.0.0.0';

    protected int $port;

    protected ?\Socket $server = null;

    /** @var \Socket[] */
    protected array $clients = [];

    /** @var int[] */
    protected array $clientAuth = []; // Track authenticated clients by socket object ID

    protected bool $running = false;

    protected int $startTime = 0;

    protected ?string $apiKey = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->pidFile = storage_path('app/laraclaw/server.pid');

        $action = $this->argument('action');

        if (! in_array($action, ['start', 'stop', 'status'])) {
            $this->error("Invalid action: {$action}");
            $this->info('Valid actions: start, stop, status');

            return Command::FAILURE;
        }

        try {
            $rc = match ($action) {
                'start' => $this->startServer(),
                'stop' => $this->stopServer(),
                'status' => $this->showStatus(),
            };
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            if (app()->environment('local')) {
                $this->error($exception->getTraceAsString());
            }
        }

        return Command::FAILURE;
    }

    /**
     * Start the WebSocket server.
     */
    protected function startServer(): int
    {
        // Check if already running
        if ($this->isRunning()) {
            $pid = $this->getPid();
            $this->error("Server is already running (PID: {$pid})");
            $this->info("Use 'php artisan laraclaw:server stop' to stop it first.");

            return Command::FAILURE;
        }

        $this->port = $this->option('port') ?? config('laraclaw.server_port', 19123);

        // Validate port
        if (! is_numeric($this->port) || $this->port < 1 || $this->port > 65535) {
            $this->error("Invalid port: {$this->port}");

            return Command::FAILURE;
        }

        $this->info('Starting LaraClaw WebSocket server...');
        $this->line("  Host: <fg=cyan>{$this->host}</>");
        $this->line("  Port: <fg=cyan>{$this->port}</>");
        $this->newLine();

        // Initialize the command processing service with memory service
        $this->commandService = app(CommandProcessingService::class);
        $this->commandService->setMemoryService(app(\App\Services\MemoryEngineService::class));

        // Create server socket
        $this->server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        // Set socket options
        socket_set_option($this->server, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($this->server);

        // Bind and listen
        socket_bind($this->server, $this->host, $this->port);
        socket_listen($this->server, 10);

        // Save PID file and start time
        $this->savePid();
        $this->startTime = time();

        $this->line('<fg=green>✓</> Server started successfully');
        $this->newLine();
        $this->line('<fg=gray>Press Ctrl+C to stop the server</>');
        $this->newLine();

        // Start the event loop
        $this->runEventLoop();

        return Command::SUCCESS;
    }

    /**
     * Stop the WebSocket server.
     */
    protected function stopServer(): int
    {
        if (! $this->isRunning()) {
            $this->warn('Server is not running.');

            return Command::SUCCESS;
        }

        $pid = $this->getPid();

        $this->info("Stopping LaraClaw server (PID: {$pid})...");

        // Send SIGTERM to the process
        posix_kill($pid, SIGTERM);
        $this->line('<fg=green>✓</> Server stopped');

        // Clean up PID file
        if (File::exists($this->pidFile)) {
            File::delete($this->pidFile);
        }

        return Command::SUCCESS;
    }

    /**
     * Show server status.
     */
    protected function showStatus(): int
    {
        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->line('<fg=white;options=bold>  LaraClaw Server Status</>');
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        if ($this->isRunning()) {
            $pid = $this->getPid();
            $port = $this->option('port') ?? config('laraclaw.server_port', 19123);

            $this->line('  Status:    <fg=green>Running</>');
            $this->line("  PID:       <fg=white>{$pid}</>");
            $this->line("  Host:      <fg=white>{$this->host}</>");
            $this->line("  Port:      <fg=white>{$port}</>");
            $this->line("  WebSocket: <fg=cyan>ws://{$this->host}:{$port}</>");
        } else {
            $this->line('  Status:    <fg=red>Stopped</>');
            $this->line('  Port:      <fg=gray>'.config('laraclaw.server_port', 19123).' (configured)</>');
        }

        $this->newLine();
        $this->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Run the main event loop.
     */
    protected function runEventLoop(): void
    {
        $this->running = true;

        // Set up signal handlers
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);

        while ($this->running) {
            $read = array_merge([$this->server], $this->clients);
            $write = null;
            $except = null;

            // Use select with timeout for non-blocking
            $changed = @socket_select($read, $write, $except, 0, 200000);

            if ($changed === false) {
                // Error occurred
                continue;
            }

            if ($changed === 0) {
                // No changes, continue loop
                continue;
            }

            // Check for new connections
            if (in_array($this->server, $read)) {
                $this->acceptConnection();
                unset($read[array_search($this->server, $read)]);
            }

            // Handle client messages
            foreach ($read as $client) {
                $this->handleClientMessage($client);
            }

            // Allow signal handlers to run
            pcntl_signal_dispatch();
        }

        $this->cleanup();
    }

    /**
     * Accept a new connection.
     */
    protected function acceptConnection(): void
    {
        $clientSocket = socket_accept($this->server);
        $request = socket_read($clientSocket, 5000); // Perform WebSocket handshake

        try {
            $this->performHandshake($clientSocket, $request);
        } catch (\Throwable $exception) {
            socket_close($clientSocket);
            return;
        }

        // Get client ID for tracking
        $clientId = spl_object_id($clientSocket);

        // Store client as unauthenticated initially
        $this->clients[] = $clientSocket;
        $this->clientAuth[$clientId] = false;

        MultiLogger::info("Client connected: {$clientId}");

        // Send auth_required message
        $authResponse = new CommandResponseDTO(
            type: 'auth_required',
            success: true,
            message: 'Authentication required',
        );
        $this->sendToClient($clientSocket, $authResponse);
    }

    /**
     * Perform WebSocket handshake.
     */
    protected function performHandshake(\Socket $client, string $request): bool
    {
        // Parse the WebSocket key from the request
        if (! preg_match('/Sec-WebSocket-Key:\s*(.+?)\r\n/i', $request, $matches)) {
            return false;
        }

        $key = trim($matches[1]);
        $acceptKey = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n";
        $response .= "\r\n";

        socket_write($client, $response);

        return true;
    }

    /**
     * Handle a message from a client.
     */
    protected function handleClientMessage(\Socket $client): void
    {
        $data = $this->readWebSocketFrame($client);

        if ($data === null) {
            // Client disconnected
            $this->disconnectClient($client);

            return;
        }

        // Process the message using the command service
        $this->processMessage($client, $data);
    }

    /**
     * Process a message from a client.
     * Delegates to CommandProcessingService for all command handling.
     */
    protected function processMessage(\Socket $client, string $message): void
    {
        $clientId = spl_object_id($client);

        // Try to parse the message as JSON
        $data = json_decode($message, true);

        // Handle authentication
        if ($data && isset($data['type']) && $data['type'] === 'auth') {
            $this->handleAuthentication($client, $data['token'] ?? '');

            return;
        }

        // Check if client is authenticated
        if (!($this->clientAuth[$clientId] ?? false)) {
            // Send auth required message
            $authResponse = new CommandResponseDTO(
                type   : 'auth_required',
                message: 'Authentication required. Please send an "auth" message with your token.',
                success: false,
            );
            $this->sendToClient($client, $authResponse);

            return;
        }

        // Build context for the command service
        $context = [
            'port' => $this->port,
            'clients' => count($this->clients),
            'status' => 'running',
            'uptime' => $this->startTime > 0 ? time() - $this->startTime : 0,
        ];

        // Use the command processing service
        $response = $this->commandService->process($message, $context);

        // Log the response at DEBUG level
        MultiLogger::debug('LLM Response', [
            'type' => $response->type,
            'success' => $response->success,
            'message_length' => strlen($response->message),
            'message_preview' => substr($response->message, 0, 200).(strlen($response->message) > 200 ? '...' : ''),
            'data' => $response->data,
        ]);

        // Send the response
        $this->sendToClient($client, $response);
    }

    /**
     * Handle authentication request from client.
     */
    protected function handleAuthentication(\Socket $client, string $token): void
    {
        $clientId = spl_object_id($client);
        $validToken = $this->getApiKey();

        if ($validToken && hash_equals($validToken, $token)) {
            // Authentication successful
            $this->clientAuth[$clientId] = true;
            MultiLogger::info("Client authenticated: {$clientId}");

            $response = new CommandResponseDTO(
                type: 'auth_success',
                success: true,
                message: 'Authentication successful',
            );
            $this->sendToClient($client, $response);

            // Send welcome message after successful auth
            $welcomeResponse = $this->commandService->getWelcome('1.0.0');
            $this->sendToClient($client, $welcomeResponse);
        } else {
            // Authentication failed
            MultiLogger::warning("Authentication failed for client: {$clientId}");

            $response = new CommandResponseDTO(
                type: 'auth_failed',
                success: false,
                message: 'Invalid API token',
            );
            $this->sendToClient($client, $response);
        }
    }

    /**
     * Get the API key from configuration.
     */
    protected function getApiKey(): ?string
    {
        if ($this->apiKey === null) {
            $this->apiKey = config('laraclaw.server_api_key');
        }

        return $this->apiKey;
    }

    /**
     * Send a CommandResponseDTO to a client.
     */
    protected function sendToClient(\Socket $client, CommandResponseDTO $response): void
    {
        $json = json_encode($response->toArray());
        $frame = $this->encodeWebSocketFrame($json);
        @socket_write($client, $frame);
    }

    /**
     * Read a WebSocket frame.
     */
    protected function readWebSocketFrame(\Socket $client): ?string
    {
        $data = socket_read($client, 8192);

        if (!$data) {
            return null;
        }

        return $this->decodeWebSocketFrame($data);
    }

    /**
     * Decode a WebSocket frame.
     */
    protected function decodeWebSocketFrame(string $data): string
    {
        if (strlen($data) < 2) {
            return '';
        }

        $opcode = ord($data[0]) & 0x0F;
        $masked = (ord($data[1]) & 0x80) !== 0;
        $payloadLen = ord($data[1]) & 0x7F;

        // Handle connection close
        if ($opcode === 0x08) {
            return '';
        }

        // Handle ping
        if ($opcode === 0x09) {
            return json_encode(['type' => 'ping']);
        }

        $offset = 2;

        if ($payloadLen === 126) {
            $payloadLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            $payloadLen = unpack('J', substr($data, $offset, 8))[1];
            $offset += 8;
        }

        if ($masked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }

        $payload = substr($data, $offset, $payloadLen);

        if ($masked) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return $payload;
    }

    /**
     * Encode a WebSocket frame.
     */
    protected function encodeWebSocketFrame(string $data): string
    {
        $frame = chr(0x81); // Text frame
        $len = strlen($data);

        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126).pack('n', $len);
        } else {
            $frame .= chr(127).pack('J', $len);
        }

        $frame .= $data;

        return $frame;
    }

    /**
     * Disconnect a client.
     */
    protected function disconnectClient(\Socket $client): void
    {
        $clientId = spl_object_id($client);
        $key = array_search($client, $this->clients, true);

        if ($key !== false) {
            unset($this->clients[$key]);
            $this->clients = array_values($this->clients);
        }

        // Clean up auth state
        unset($this->clientAuth[$clientId]);

        socket_close($client);
        MultiLogger::info("Client disconnected: {$clientId}");
    }

    /**
     * Handle shutdown signal.
     */
    public function handleShutdown(): void
    {
        $this->info("\nShutting down server...");
        $this->running = false;
    }

    /**
     * Clean up resources.
     */
    protected function cleanup(): void
    {
        // Close all clients
        foreach ($this->clients as $client) {
            @socket_close($client);
        }

        // Close server socket
        if ($this->server) {
            @socket_close($this->server);
        }

        // Remove PID file
        if (File::exists($this->pidFile)) {
            File::delete($this->pidFile);
        }

        $this->line('<fg=green>✓</> Server stopped');
    }

    /**
     * Check if the server is running.
     */
    protected function isRunning(): bool
    {
        if (! File::exists($this->pidFile)) {
            return false;
        }

        $pid = $this->getPid();

        if (! $pid) {
            return false;
        }

        // Check if process is running
        posix_kill($pid, 0);

        return true;
    }

    /**
     * Get the server PID.
     */
    protected function getPid(): ?int
    {
        if (! File::exists($this->pidFile)) {
            return null;
        }

        $pid = (int) trim(File::get($this->pidFile));

        return $pid > 0 ? $pid : null;
    }

    /**
     * Save the current PID to file.
     */
    protected function savePid(): void
    {
        $pid = getmypid();
        File::ensureDirectoryExists(dirname($this->pidFile));
        File::put($this->pidFile, (string) $pid);
    }
}
