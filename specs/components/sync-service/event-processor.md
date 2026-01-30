# Component: Event Processor

## Overview
- **Purpose**: Filter and route firehose events to appropriate handlers based on collection type
- **Location**: `sync-service/src/Firehose/`
- **Dependencies**: firehose-client
- **Dependents**: database-writer, forum-config-sync
- **Task**: phpbb-rfm

## Acceptance Criteria
- [ ] AC-1: Filters events to only process `net.vza.forum.*` collections
- [ ] AC-2: Routes `net.vza.forum.post` events to PostWriter
- [ ] AC-3: Routes `net.vza.forum.board` events to ConfigSync
- [ ] AC-4: Routes `net.vza.forum.acl` events to ConfigSync
- [ ] AC-5: Handles create, update, and delete operations correctly
- [ ] AC-6: Validates record structure before processing
- [ ] AC-7: Logs and skips malformed records without crashing
- [ ] AC-8: Extracts record data from CAR file blocks

## File Structure
```
sync-service/
└── src/
    └── Firehose/
        ├── Processor.php         # Main event processor
        ├── Filter.php            # Collection filtering
        ├── CarReader.php         # CAR file block extraction
        ├── RecordValidator.php   # Lexicon validation
        └── EventRouter.php       # Route to handlers
```

## Interface Definitions

### ProcessorInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface ProcessorInterface
{
    /**
     * Process a repository commit event.
     *
     * @param array $commit Decoded commit message from firehose
     */
    public function processCommit(array $commit): void;

    /**
     * Handle a create operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param array $record Record data
     * @param string $cid Content identifier
     */
    public function handleCreate(
        string $repo,
        string $collection,
        string $rkey,
        array $record,
        string $cid
    ): void;

    /**
     * Handle an update operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param array $record Updated record data
     * @param string $cid New content identifier
     */
    public function handleUpdate(
        string $repo,
        string $collection,
        string $rkey,
        array $record,
        string $cid
    ): void;

    /**
     * Handle a delete operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     */
    public function handleDelete(
        string $repo,
        string $collection,
        string $rkey
    ): void;

    /**
     * Check if a collection should be processed.
     *
     * @param string $collection Lexicon collection name
     * @return bool True if collection is relevant (net.vza.forum.*)
     */
    public function shouldProcess(string $collection): bool;
}
```

### FilterInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface FilterInterface
{
    /**
     * Check if a collection matches the filter.
     *
     * @param string $collection Collection name
     * @return bool True if should process
     */
    public function matches(string $collection): bool;

    /**
     * Get the collection namespace prefix.
     *
     * @return string Namespace prefix (e.g., 'net.vza.forum.')
     */
    public function getNamespace(): string;
}
```

### CarReaderInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface CarReaderInterface
{
    /**
     * Extract a record from CAR file blocks by CID.
     *
     * @param string $blocks Raw CAR file bytes
     * @param string $cid CID of the record to extract
     * @return array Decoded record data
     * @throws RecordNotFoundException If CID not found in blocks
     * @throws CarParseException If CAR format is invalid
     */
    public function extractRecord(string $blocks, string $cid): array;

    /**
     * Parse CAR file into a map of CID -> block data.
     *
     * @param string $blocks Raw CAR file bytes
     * @return array<string, array> Map of CID to decoded block
     */
    public function parseBlocks(string $blocks): array;
}
```

### EventRouterInterface

```php
<?php

namespace phpbb\atproto\sync\Firehose;

use phpbb\atproto\sync\Database\PostWriterInterface;
use phpbb\atproto\sync\Config\ForumSyncInterface;

interface EventRouterInterface
{
    /**
     * Register handler for a collection.
     *
     * @param string $collection Collection name
     * @param callable $handler Handler function
     */
    public function register(string $collection, callable $handler): void;

    /**
     * Route an event to the appropriate handler.
     *
     * @param string $collection Collection name
     * @param string $operation 'create', 'update', or 'delete'
     * @param array $params Event parameters
     * @return bool True if handled, false if no handler found
     */
    public function route(string $collection, string $operation, array $params): bool;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `onCommit` | Receive raw commit from firehose | CBOR-decoded commit |
| `onRecord` | Process a single record operation | Record data, metadata |
| `onError` | Handle processing errors | Exception, context |

## Processing Flow

```
Firehose Message
    │
    ▼
┌─────────────────────────────────────┐
│ Decode CBOR commit                   │
│ - repo: author DID                   │
│ - ops: list of operations            │
│ - blocks: CAR file with record data  │
│ - seq: cursor position               │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ For each operation in ops:           │
│ - action: create/update/delete       │
│ - path: collection/rkey              │
│ - cid: record CID (for create/update)│
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Filter: collection starts with       │
│         'net.vza.forum.'?            │
│                                      │
│ No  ────────────────────────► Skip   │
│ Yes │                                │
└─────┼───────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────┐
│ Extract record from CAR blocks       │
│ using CID (for create/update)        │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Validate record structure            │
│ against lexicon schema               │
│                                      │
│ Invalid ────────────────► Log, skip  │
│ Valid   │                            │
└─────────┼───────────────────────────┘
          │
          ▼
┌─────────────────────────────────────┐
│ Route to handler:                    │
│                                      │
│ net.vza.forum.post → PostWriter      │
│ net.vza.forum.board → ConfigSync     │
│ net.vza.forum.acl → ConfigSync       │
│ net.vza.forum.* → (logged, ignored)  │
└─────────────────────────────────────┘
```

## Collection Routing

| Collection | Handler | Operation |
|------------|---------|-----------|
| `net.vza.forum.post` | PostWriter | insertPost, updatePost, deletePost |
| `net.vza.forum.board` | ConfigSync | syncBoard |
| `net.vza.forum.acl` | ConfigSync | syncAcl |
| `net.vza.forum.config` | ConfigSync | syncConfig |
| `net.vza.forum.membership` | ConfigSync | syncMembership |
| `net.vza.forum.settings` | (ignored) | User settings - not synced |
| `net.vza.forum.vote` | (future) | Poll votes |
| `net.vza.forum.reaction` | (future) | Post reactions |
| `net.vza.forum.bookmark` | (ignored) | User-local data |
| `net.vza.forum.subscription` | (ignored) | User-local data |

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Unknown collection | N/A | Log at debug, skip |
| Record not in blocks | `PROC_RECORD_NOT_FOUND` | Log warning, skip |
| CAR parse error | `PROC_CAR_PARSE_ERROR` | Log error, skip |
| Validation failed | `PROC_VALIDATION_FAILED` | Log warning, skip |
| Handler exception | `PROC_HANDLER_ERROR` | Log error, skip, continue |
| Repo DID invalid | `PROC_INVALID_DID` | Log warning, skip |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `NAMESPACE_FILTER` | string | `net.vza.forum.` | Collection prefix to process |
| `STRICT_VALIDATION` | bool | false | Reject records that fail validation |
| `LOG_UNKNOWN_COLLECTIONS` | bool | false | Log filtered-out collections |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Process `net.vza.forum.post` create | PostWriter.insertPost called |
| Process `net.vza.forum.post` update | PostWriter.updatePost called |
| Process `net.vza.forum.post` delete | PostWriter.deletePost called |
| Process `net.vza.forum.board` create | ConfigSync.syncBoard called |
| Process `app.bsky.feed.post` create | Filtered out, no handler called |
| Process invalid record | Logged, skipped, no crash |
| Process delete (no record data) | Handler called with DID, collection, rkey only |
| CAR file missing CID | Warning logged, event skipped |

## Implementation Notes

### Processor Implementation

```php
<?php

namespace phpbb\atproto\sync\Firehose;

class Processor implements ProcessorInterface
{
    private FilterInterface $filter;
    private CarReaderInterface $carReader;
    private EventRouterInterface $router;
    private \Psr\Log\LoggerInterface $logger;

    public function processCommit(array $commit): void
    {
        $repo = $commit['repo'] ?? null;
        $ops = $commit['ops'] ?? [];
        $blocks = $commit['blocks'] ?? '';

        if (!$repo || empty($ops)) {
            return;
        }

        // Parse blocks once for all operations
        $blockMap = $this->carReader->parseBlocks($blocks);

        foreach ($ops as $op) {
            $this->processOperation($repo, $op, $blockMap);
        }
    }

    private function processOperation(string $repo, array $op, array $blockMap): void
    {
        $action = $op['action'] ?? null;
        $path = $op['path'] ?? null;
        $cid = $op['cid'] ?? null;

        if (!$action || !$path) {
            return;
        }

        // Parse path into collection and rkey
        $pathParts = explode('/', $path, 2);
        if (count($pathParts) !== 2) {
            return;
        }
        [$collection, $rkey] = $pathParts;

        // Filter by namespace
        if (!$this->shouldProcess($collection)) {
            return;
        }

        // Extract record data for create/update
        $record = null;
        if ($action !== 'delete' && $cid) {
            try {
                $record = $blockMap[(string)$cid] ?? null;
                if (!$record) {
                    $this->logger->warning("Record CID not found in blocks", [
                        'repo' => $repo,
                        'collection' => $collection,
                        'rkey' => $rkey,
                        'cid' => (string)$cid,
                    ]);
                    return;
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to extract record", [
                    'error' => $e->getMessage(),
                    'repo' => $repo,
                    'collection' => $collection,
                ]);
                return;
            }
        }

        // Route to handler
        switch ($action) {
            case 'create':
                $this->handleCreate($repo, $collection, $rkey, $record, (string)$cid);
                break;
            case 'update':
                $this->handleUpdate($repo, $collection, $rkey, $record, (string)$cid);
                break;
            case 'delete':
                $this->handleDelete($repo, $collection, $rkey);
                break;
        }
    }

    public function shouldProcess(string $collection): bool
    {
        return $this->filter->matches($collection);
    }

    public function handleCreate(
        string $repo,
        string $collection,
        string $rkey,
        array $record,
        string $cid
    ): void {
        $atUri = "at://{$repo}/{$collection}/{$rkey}";

        $this->router->route($collection, 'create', [
            'repo' => $repo,
            'collection' => $collection,
            'rkey' => $rkey,
            'record' => $record,
            'cid' => $cid,
            'uri' => $atUri,
        ]);
    }

    // ... handleUpdate and handleDelete similar
}
```

### CAR File Parsing

CAR (Content Addressable Archive) files contain IPLD blocks:

```php
class CarReader implements CarReaderInterface
{
    public function parseBlocks(string $car): array
    {
        $blocks = [];
        $offset = 0;

        // Skip CAR header (varint length + header CBOR)
        $headerLen = $this->readVarint($car, $offset);
        $offset += $headerLen;

        // Read blocks
        while ($offset < strlen($car)) {
            // Each block: varint length + CID + data
            $blockLen = $this->readVarint($car, $offset);
            if ($blockLen === 0) break;

            $cidLen = $this->readCidLength($car, $offset);
            $cid = substr($car, $offset, $cidLen);
            $offset += $cidLen;

            $dataLen = $blockLen - $cidLen;
            $data = substr($car, $offset, $dataLen);
            $offset += $dataLen;

            // Decode CBOR data
            $cidStr = $this->encodeCid($cid);
            $blocks[$cidStr] = $this->decodeCbor($data);
        }

        return $blocks;
    }
}
```

### Security Considerations
- Validate record $type matches expected collection
- Reject records with unexpected fields (if strict mode)
- Limit record size to prevent memory exhaustion
- Verify repo DID format before processing

### Performance Considerations
- Parse CAR blocks once per commit, not per operation
- Skip filtered collections as early as possible
- Batch database writes where possible
- Use memory-efficient CBOR streaming for large records

## References
- [AT Protocol Repository Sync](https://atproto.com/specs/sync)
- [CAR File Format](https://ipld.io/specs/transport/car/)
- [CBOR Specification](https://cbor.io/)
- [lexicons/net.vza.forum.post.json](../../../lexicons/net.vza.forum.post.json)
- [docs/architecture.md](../../../docs/architecture.md) - Event Processor description
