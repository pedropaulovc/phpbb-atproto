# Component: Firehose Client

## Overview
- **Purpose**: Maintain WebSocket connection to the AT Protocol relay firehose for real-time event subscription
- **Location**: `sync-service/src/Firehose/`
- **Dependencies**: migrations (for `phpbb_atproto_cursors` table)
- **Dependents**: event-processor, label-subscriber

## Acceptance Criteria
- [ ] AC-1: Establishes WebSocket connection to relay firehose
- [ ] AC-2: Handles CBOR-encoded messages from the firehose
- [ ] AC-3: Persists cursor position after each batch for resumable sync
- [ ] AC-4: Automatically reconnects with exponential backoff on disconnection
- [ ] AC-5: Resumes from persisted cursor on restart
- [ ] AC-6: Handles graceful shutdown (SIGTERM) by persisting cursor before exit
- [ ] AC-7: Updates state file for health check monitoring
- [ ] AC-8: Maximum backoff cap at 60 seconds

## File Structure
```
sync-service/
├── src/
│   └── Firehose/
│       ├── Client.php           # WebSocket connection management
│       ├── CborDecoder.php      # CBOR message decoding
│       ├── CursorManager.php    # Cursor persistence
│       ├── ReconnectStrategy.php # Exponential backoff
│       └── StateWriter.php      # Health check state file
├── bin/
│   └── sync-daemon.php          # Entry point
└── composer.json
```

## Interface Definitions

### ClientInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface ClientInterface
{
    /**
     * Connect to the firehose WebSocket.
     *
     * @param int|null $cursor Resume from this cursor position
     * @throws ConnectionFailedException When connection fails
     */
    public function connect(?int $cursor = null): void;

    /**
     * Disconnect from the firehose.
     */
    public function disconnect(): void;

    /**
     * Register a message handler callback.
     *
     * @param callable $callback Function(array $message): void
     */
    public function onMessage(callable $callback): void;

    /**
     * Register an error handler callback.
     *
     * @param callable $callback Function(\Throwable $error): void
     */
    public function onError(callable $callback): void;

    /**
     * Get the current cursor position.
     *
     * @return int|null Current cursor or null if not connected
     */
    public function getCursor(): ?int;

    /**
     * Check if connected to firehose.
     *
     * @return bool True if connected
     */
    public function isConnected(): bool;

    /**
     * Run the event loop (blocking).
     */
    public function run(): void;
}
```

### CursorManagerInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface CursorManagerInterface
{
    /**
     * Get the last persisted cursor for a service.
     *
     * @param string $service Service name (e.g., 'firehose', 'labels')
     * @return int|null Cursor value or null if none
     */
    public function getCursor(string $service): ?int;

    /**
     * Persist cursor position.
     *
     * @param string $service Service name
     * @param int $cursor Cursor value
     */
    public function setCursor(string $service, int $cursor): void;

    /**
     * Get cursor freshness (seconds since last update).
     *
     * @param string $service Service name
     * @return int|null Seconds since update, or null if no cursor
     */
    public function getStaleness(string $service): ?int;
}
```

### ReconnectStrategyInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface ReconnectStrategyInterface
{
    /**
     * Get delay before next reconnection attempt.
     *
     * @return int Delay in milliseconds
     */
    public function getDelay(): int;

    /**
     * Record a successful connection.
     */
    public function onSuccess(): void;

    /**
     * Record a failed connection attempt.
     */
    public function onFailure(): void;

    /**
     * Check if reconnection should be attempted.
     *
     * @return bool True if should attempt reconnection
     */
    public function shouldReconnect(): bool;

    /**
     * Get consecutive failure count.
     *
     * @return int Number of consecutive failures
     */
    public function getFailureCount(): int;
}
```

### StateWriterInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface StateWriterInterface
{
    /**
     * Update state file with current status.
     *
     * @param bool $connected WebSocket connection status
     * @param int $cursor Current cursor position
     * @param int $messagesProcessed Total messages processed
     */
    public function update(bool $connected, int $cursor, int $messagesProcessed): void;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `onMessage` | Process incoming firehose message | Decoded CBOR message array |
| `onError` | Handle connection/decode errors | `\Throwable` exception |
| `onConnect` | Log successful connection | None |
| `onDisconnect` | Trigger reconnection | None |

## Database Interactions

### Tables Used
- `phpbb_atproto_cursors` - Cursor position persistence

### Key Queries

```php
// Get cursor for firehose
$sql = 'SELECT cursor_value, updated_at
        FROM ' . $this->table_prefix . 'atproto_cursors
        WHERE service = ?';

// Update cursor (upsert)
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_cursors
        (service, cursor_value, updated_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cursor_value = VALUES(cursor_value),
            updated_at = VALUES(updated_at)';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos` | WebSocket | Main firehose subscription |

### WebSocket URL Format
```
wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos?cursor={cursor}
```

### Message Format (CBOR Decoded)

```php
// Commit message
[
    'ops' => [
        [
            'action' => 'create', // or 'update', 'delete'
            'path' => 'net.vza.forum.post/3jui7kd2zoik2',
            'cid' => CID object,
        ],
    ],
    'repo' => 'did:plc:abc123',
    'commit' => CID object,
    'blocks' => CAR file bytes,
    'seq' => 12345678, // This is the cursor
]
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Connection failed | `SYNC_CONNECTION_FAILED` | Exponential backoff reconnect |
| Connection dropped | `SYNC_CONNECTION_LOST` | Resume from cursor with backoff |
| Invalid CBOR | `SYNC_CBOR_DECODE_ERROR` | Log, skip message, continue |
| Invalid cursor | `SYNC_CURSOR_INVALID` | Start from latest (no cursor) |
| Rate limited | `SYNC_RATE_LIMITED` | Respect retry-after header |
| Circuit breaker open | `SYNC_CIRCUIT_OPEN` | Stop reconnecting, alert admin |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `RELAY_URL` | string | `wss://bsky.network/...` | Firehose WebSocket URL |
| `CURSOR_PERSIST_INTERVAL` | int | 100 | Persist cursor every N messages |
| `RECONNECT_BASE_DELAY` | int | 1000 | Initial reconnect delay (ms) |
| `RECONNECT_MAX_DELAY` | int | 60000 | Maximum reconnect delay (ms) |
| `RECONNECT_MULTIPLIER` | float | 2.0 | Backoff multiplier |
| `CIRCUIT_BREAKER_THRESHOLD` | int | 10 | Failures before circuit opens |
| `STATE_FILE_PATH` | string | `/tmp/sync-service-state.json` | Health check state file |
| `STATE_WRITE_INTERVAL` | int | 10 | Seconds between state writes |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Initial connection (no cursor) | Connects, receives messages from latest |
| Resume from cursor | Connects with cursor param, no duplicate messages |
| Connection drop | Reconnects with exponential backoff |
| SIGTERM received | Persists cursor, closes connection, exits 0 |
| Invalid CBOR message | Logs error, continues processing |
| 10+ consecutive failures | Circuit breaker opens, stops reconnecting |
| Successful reconnect | Backoff resets to initial value |
| Cursor persistence | Cursor persisted every 100 messages |

## Implementation Notes

### Reconnection Strategy (Exponential Backoff)

```php
class ExponentialBackoff implements ReconnectStrategyInterface
{
    private int $baseDelay = 1000;
    private int $maxDelay = 60000;
    private float $multiplier = 2.0;
    private int $failures = 0;
    private int $maxFailures = 10;

    public function getDelay(): int
    {
        $delay = (int)($this->baseDelay * pow($this->multiplier, $this->failures));
        return min($delay, $this->maxDelay);
    }

    public function onSuccess(): void
    {
        $this->failures = 0;
    }

    public function onFailure(): void
    {
        $this->failures++;
    }

    public function shouldReconnect(): bool
    {
        return $this->failures < $this->maxFailures;
    }

    public function getFailureCount(): int
    {
        return $this->failures;
    }
}
```

### Graceful Shutdown

```php
// In sync-daemon.php
pcntl_async_signals(true);

$shutdown = false;
pcntl_signal(SIGTERM, function () use (&$shutdown, $client, $cursorManager) {
    echo "SIGTERM received, shutting down gracefully...\n";
    $shutdown = true;

    // Persist current cursor
    $cursor = $client->getCursor();
    if ($cursor !== null) {
        $cursorManager->setCursor('firehose', $cursor);
    }

    $client->disconnect();
});

// Main loop
while (!$shutdown) {
    $client->run();

    if (!$shutdown && $reconnect->shouldReconnect()) {
        $delay = $reconnect->getDelay();
        echo "Reconnecting in {$delay}ms...\n";
        usleep($delay * 1000);
        $client->connect($cursorManager->getCursor('firehose'));
    }
}

exit(0);
```

### State File Writer

```php
class StateWriter implements StateWriterInterface
{
    private string $stateFile;
    private int $lastWrite = 0;
    private int $writeInterval = 10;
    private ?int $connectedAt = null;

    public function update(bool $connected, int $cursor, int $messagesProcessed): void
    {
        if (time() - $this->lastWrite < $this->writeInterval) {
            return;
        }

        if ($connected && $this->connectedAt === null) {
            $this->connectedAt = time();
        } elseif (!$connected) {
            $this->connectedAt = null;
        }

        $state = [
            'websocket_connected' => $connected,
            'connection_established_at' => $this->connectedAt,
            'last_message_at' => time(),
            'cursor' => $cursor,
            'messages_processed' => $messagesProcessed,
            'pid' => getmypid(),
        ];

        file_put_contents($this->stateFile, json_encode($state));
        $this->lastWrite = time();
    }
}
```

### Security Considerations
- Use TLS (wss://) for firehose connection
- Validate message structure before processing
- Log suspicious patterns (unusual volume, malformed data)

### Performance Considerations
- Process messages in batches for better throughput
- Persist cursor periodically, not on every message
- Use connection pooling for database writes
- Single-threaded event loop (amphp) for simplicity

### Dependencies

```json
{
    "require": {
        "php": ">=8.4",
        "amphp/websocket-client": "^2.0",
        "clue/cbor-php": "^0.4",
        "ext-pcntl": "*",
        "ext-pdo": "*"
    }
}
```

## References
- [AT Protocol Firehose](https://atproto.com/specs/sync#firehose)
- [CBOR Specification](https://cbor.io/)
- [Amphp WebSocket Client](https://amphp.org/websocket-client)
- [architecture.md](../../architecture.md) - Firehose Client description
- [risks.md](../../risks.md) - T2: Firehose Connection Drops
