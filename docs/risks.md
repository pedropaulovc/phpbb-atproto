# Risk Assessment

## Executive Summary

This document identifies risks in the phpBB AT Protocol integration and provides mitigation strategies. The hybrid AppView architecture introduces distributed system challenges including network partitions, data consistency, and security concerns that differ from traditional monolithic phpBB deployments.

## Risk Matrix

| Risk | Severity | Likelihood | Impact | Priority |
|------|----------|------------|--------|----------|
| User PDS offline during post creation | High | Medium | Posts fail to save | P1 |
| Firehose connection drops | High | High | Posts stop syncing | P1 |
| OAuth token expiration | Medium | High | Write operations fail | P1 |
| Local cache diverges from PDS | High | Medium | Data inconsistency | P1 |
| Rate limiting from relay | Medium | Medium | Sync delays | P2 |
| DID verification spoofing | Critical | Low | Identity attacks | P1 |
| Concurrent edits from multiple clients | Medium | Medium | CID mismatch | P2 |
| Sync Service crashes | High | Medium | Stale local data | P1 |

---

## Technical Risks

### T1: User PDS Offline During Post Creation

**Description**: When a user submits a post, the extension writes to their PDS. If the PDS is unavailable, the write fails.

**Impact**: User experiences post failure; frustration and potential data loss.

**Mitigation**:
1. Queue failed writes in `phpbb_atproto_queue` with exponential backoff (15s, 30s, 60s, 5min, 15min)
2. Save post locally with `sync_status = 'pending'`
3. Display "syncing..." indicator to user
4. Background retry via Sync Service
5. Notify user when sync completes or permanently fails after 5 attempts

**Detection**: Monitor queue depth and age of oldest pending item.

### T2: Firehose Connection Drops / Backpressure

**Description**: The WebSocket connection to the public relay can drop due to network issues or server-side disconnects. Backpressure occurs when the Sync Service can't keep up with message volume.

**Impact**: New posts from other users stop appearing; local cache becomes stale.

**Mitigation**:
1. Persist cursor position in `phpbb_atproto_cursors` after each batch
2. Implement automatic reconnection with exponential backoff (1s, 2s, 4s, 8s, max 60s)
3. On reconnect, resume from last persisted cursor
4. Process messages in batches with configurable batch size
5. Drop duplicate messages (already processed based on cursor)
6. Health check: alert if cursor hasn't advanced in 5 minutes

**Detection**: Monitor cursor freshness; alert if `updated_at` exceeds threshold.

### T3: Rate Limiting from Public Relay

**Description**: The public relay (bsky.network) may rate-limit connections or requests.

**Impact**: Firehose subscription rejected; posts delayed.

**Mitigation**:
1. Respect rate limit headers and back off accordingly
2. Implement connection pooling if running multiple instances
3. Consider self-hosted relay for high-volume deployments
4. Cache DID document resolutions (1-hour TTL) to reduce resolver calls

**Detection**: Log rate limit responses; alert on sustained limiting.

### T4: WebSocket Reconnection Failures

**Description**: Repeated reconnection attempts fail due to network issues or relay unavailability.

**Impact**: Extended sync outage.

**Mitigation**:
1. Max backoff cap at 60 seconds
2. Health endpoint reports connection status
3. Admin notification after 10 consecutive failures
4. Fallback to periodic HTTP polling if WebSocket unavailable for >10 minutes
5. Circuit breaker pattern: stop reconnecting after 1 hour, require manual restart

**Detection**: Track consecutive failure count; alert at thresholds.

---

## Data Consistency Risks

### D1: Local Cache Diverges from PDS (Split-Brain)

**Description**: The local MySQL cache may contain data that doesn't match the authoritative PDS records, especially after network partitions or failed writes.

**Impact**: Users see different content than what exists on the network; broken references.

**Mitigation**:
1. AT URI and CID stored in mapping tables enable verification
2. Periodic reconciliation job compares local cache to PDS state
3. On CID mismatch, refetch record and update local copy
4. Clear "pending" status only after firehose confirmation
5. For writes: local update is optimistic, firehose is authoritative

**Detection**: Scheduled reconciliation reports mismatches; alert on count threshold.

### D2: CID Mismatch After Record Updates / Moderation Bypass

**Description**: When a user edits a post, the CID changes. Labels reference specific CIDs, so editing a post could theoretically bypass moderation.

**Impact**: Moderation labels may not apply to edited content; potential moderation bypass.

**Mitigation - Sticky Moderation**:
Labels are enforced by **URI only** (not CID) in the local cache. This provides "sticky moderation" where labels persist across edits:

1. `phpbb_atproto_labels.subject_cid` is informational only, not used for filtering
2. Label queries match on `subject_uri` without CID check
3. When emitting labels via Ozone, include CID for AT Protocol compliance
4. Local enforcement uses URI-only matching

```sql
-- Label filtering ignores CID
SELECT p.* FROM phpbb_posts p
JOIN phpbb_atproto_posts ap ON p.post_id = ap.post_id
LEFT JOIN phpbb_atproto_labels l
    ON l.subject_uri = ap.at_uri  -- URI only, no CID check
    AND l.label_value = '!hide'
    AND l.negated = 0
WHERE l.id IS NULL;
```

**For record operations** (not moderation):
1. Always fetch current CID before operations requiring strongRef
2. Update `at_cid` in mapping table after every successful write
3. Firehose update events include new CID - Sync Service updates mapping

**Detection**: Log CID mismatch errors; track frequency by record type.

### D2a: Post Creation Race Condition

**Description**: When a user creates a post via phpBB, both the extension and Sync Service may try to insert the same post into the local cache. The extension writes to PDS then inserts locally, but the firehose event may arrive before the local insert completes.

**Impact**: Database errors, duplicate key violations, potential data corruption.

**Mitigation - Idempotent Inserts**:
1. Sync Service uses `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE` for all operations
2. Extension checks for existing AT URI before inserting
3. Sync Service is authoritative; extension creates optimistic cache

```sql
-- Sync Service uses idempotent insert
INSERT INTO phpbb_atproto_posts (post_id, at_uri, at_cid, author_did, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    at_cid = VALUES(at_cid),
    sync_status = 'synced',
    updated_at = VALUES(updated_at);
```

```php
// Extension checks before insert
$existing = $this->uri_mapper->findByUri($atUri);
if ($existing) {
    // Sync Service already processed - just link to local post
    $this->uri_mapper->updateLocalId($atUri, $postId);
} else {
    // We're first - insert optimistic mapping
    $this->uri_mapper->insert($postId, $atUri, $cid, $authorDid);
}
```

**Detection**: Monitor duplicate key errors; should be rare with proper idempotency.

### D3: Concurrent Edits from Multiple Clients

**Description**: User edits post from web while mobile app also edits. Last write wins at PDS level.

**Impact**: One edit is lost silently.

**Mitigation**:
1. Use `swapRecord` with expected CID for conditional updates
2. If CID mismatch on write, refetch and present merge UI
3. Inform user if their edit couldn't be applied
4. Accept that AT Protocol has last-write-wins semantics at PDS level

**Detection**: Track `swapRecord` failures; alert on spike.

### D3a: Multi-Instance Admin Config Conflicts

**Description**: In multi-instance deployments, two admins on different instances may simultaneously modify forum configuration (boards, ACL, settings). Without coordination, one admin's changes can silently overwrite the other's.

**Impact**: Admin changes lost without notification; inconsistent forum state.

**Mitigation - Optimistic Locking**:
1. All forum config writes use `swapRecord` with expected CID
2. On CID mismatch (concurrent modification), fetch latest state
3. Present conflict UI showing both versions
4. Admin must manually resolve conflict

```php
class ForumPdsClient
{
    public function updateConfig(string $rkey, array $newData): array
    {
        // 1. Fetch current state
        $current = $this->getRecord('net.vza.forum.config', $rkey);

        // 2. Attempt write with expected CID
        try {
            return $this->pds->putRecord(
                'net.vza.forum.config',
                $rkey,
                $newData,
                swapRecord: $current['cid']
            );
        } catch (InvalidSwapException $e) {
            // 3. Conflict detected
            $latest = $this->getRecord('net.vza.forum.config', $rkey);
            throw new ConflictException(
                "Configuration modified by another admin",
                latest: $latest,
                attempted: $newData
            );
        }
    }
}
```

**ACP Integration**:
```php
try {
    $result = $this->forum_pds->updateConfig('self', $newConfig);
    return $this->success("Configuration saved");
} catch (ConflictException $e) {
    return $this->render('conflict_resolution', [
        'latest' => $e->getLatest(),
        'attempted' => $e->getAttempted(),
    ]);
}
```

**Detection**: Track conflict frequency; alert if admins frequently conflict (may indicate process issue).

### D4: Topic Moves Across User Repositories

**Description**: Moving a topic requires the original author to update their post's forum reference, which they may not do.

**Impact**: Topic appears in wrong forum or is orphaned.

**Mitigation**:
1. Use soft redirect approach: moderator creates redirect record on forum PDS
2. Original posts remain where they are (user-owned)
3. AppView displays posts as if moved using redirect table
4. No user action required
5. Document this limitation in admin guide

**Detection**: Monitor orphaned posts (forum reference doesn't resolve).

---

## Security Risks

### S1: OAuth Token Storage and Encryption

**Description**: Access and refresh tokens stored in `phpbb_atproto_users` are sensitive credentials.

**Impact**: Token theft enables impersonation of users.

**Mitigation**:
1. Encrypt tokens at rest using XChaCha20-Poly1305 (libsodium)
2. Encryption keys stored in environment variable with versioning for rotation
3. Never log token values
4. Tokens have server-side expiry tracked in `token_expires_at`
5. Clear tokens on user logout/disconnect
6. Database column stores: `version:base64(nonce || ciphertext || tag)`

**Key Rotation Strategy**:

Environment variables support multiple versioned keys:
```bash
# JSON object mapping version to base64-encoded 32-byte keys
TOKEN_ENCRYPTION_KEYS='{"v1":"old_key_base64","v2":"current_key_base64"}'
TOKEN_ENCRYPTION_KEY_VERSION='v2'
```

**Implementation**:
```php
class TokenEncryption
{
    private array $keys;
    private string $currentVersion;

    public function __construct()
    {
        $this->keys = json_decode(getenv('TOKEN_ENCRYPTION_KEYS'), true);
        $this->currentVersion = getenv('TOKEN_ENCRYPTION_KEY_VERSION');
    }

    public function encrypt(string $token): string
    {
        $key = base64_decode($this->keys[$this->currentVersion]);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $token, $this->currentVersion, $nonce, $key
        );
        // Format: version:base64(nonce || ciphertext)
        return $this->currentVersion . ':' . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $stored): string
    {
        [$version, $payload] = explode(':', $stored, 2);
        $key = base64_decode($this->keys[$version]);
        $decoded = base64_decode($payload);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext, $version, $nonce, $key
        );
    }
}
```

**Key Rotation Procedure**:
1. Generate new key: `php -r "echo base64_encode(random_bytes(32));"`
2. Add new version to `TOKEN_ENCRYPTION_KEYS` JSON
3. Update `TOKEN_ENCRYPTION_KEY_VERSION` to new version
4. New tokens encrypted with new key; old tokens decrypt with old key
5. Background job re-encrypts old tokens on next refresh
6. After grace period (e.g., 30 days), remove old key version

### S2: DID Verification Spoofing

**Description**: Attacker could claim to be a different DID during authentication or inject records claiming false authorship.

**Impact**: Identity spoofing; unauthorized actions.

**Mitigation**:
1. Always verify DID resolution through official resolvers
2. Validate OAuth tokens came from the DID's designated PDS
3. Cache DID documents with 1-hour TTL
4. Verify record signatures match DID's signing key
5. Reject records where repo DID doesn't match claimed author

**Detection**: Log DID verification failures; alert on patterns.

### S3: Moderator Impersonation

**Description**: Attacker gains access to moderator's OAuth tokens or creates fake Ozone credentials.

**Impact**: Unauthorized moderation; hiding legitimate content or restoring policy violations.

**Mitigation**:
1. Moderators must be registered in Ozone team membership
2. Extension verifies moderator has both phpBB ACL and Ozone role
3. Label operations require valid Ozone authentication
4. Audit log all moderation actions with moderator DID
5. Require re-authentication for elevated moderation actions

**Detection**: Audit logs reviewed for anomalous patterns.

### S4: Token Refresh Race Conditions

**Description**: Multiple concurrent requests trigger simultaneous token refresh, causing one to fail or invalidating a valid refresh token.

**Impact**: User logged out unexpectedly; failed operations.

**Mitigation**:
1. Use database-level locking during token refresh
2. Implement refresh token rotation correctly (new refresh token on each use)
3. Brief lock window: `SELECT FOR UPDATE` on user row during refresh
4. Retry with fresh token lookup on 401 response
5. Max 2 refresh attempts per request cycle

**Detection**: Track refresh failures and 401 error rates.

---

## Operational Risks

### O1: Sync Service Container Crashes/Restarts

**Description**: The Sync Service daemon may crash, be OOM-killed, or restart during deployment.

**Impact**: Gap in firehose processing; missed posts; stale local cache.

**Mitigation**:
1. Docker restart policy: `always` with health check
2. Persist cursor before each batch (not after)
3. On restart, resume from persisted cursor
4. Graceful shutdown: finish current message, persist cursor, close connections
5. Liveness probe: check cursor freshness
6. Readiness probe: check database connectivity

**Docker health check**:
```dockerfile
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD php /app/bin/healthcheck.php || exit 1
```

**Health check script**:
```php
// Check cursor updated within last 5 minutes
$sql = "SELECT updated_at FROM phpbb_atproto_cursors WHERE service = 'firehose'";
$row = $db->fetchRow($sql);
if (time() - $row['updated_at'] > 300) {
    exit(1);
}
exit(0);
```

### O2: Queue Backlog Growth

**Description**: Failed PDS writes accumulate in `phpbb_atproto_queue` faster than retries succeed.

**Impact**: Memory pressure; delayed user feedback; potential data loss if queue truncated.

**Mitigation**:
1. Exponential backoff: 15s, 30s, 60s, 5min, 15min
2. Max 5 attempts per item
3. After max attempts, move to dead-letter status
4. Admin notification at queue depth thresholds (100, 500, 1000)
5. Dashboard shows queue health metrics
6. Purge dead-letter items after 30 days with notification

**Detection**: Monitor queue depth and dead-letter count.

### O3: Database Connection Pool Exhaustion

**Description**: Sync Service opens too many connections, exhausting MySQL's `max_connections`.

**Impact**: phpBB web requests fail; database errors.

**Mitigation**:
1. Sync Service uses connection pooling with max 5 connections
2. phpBB and Sync Service share total connection budget
3. MySQL `max_connections` set appropriately (default 151)
4. Connection timeout: release idle connections after 60 seconds
5. Monitor `Threads_connected` vs `max_connections`

**Detection**: Alert when connection count exceeds 80% of max.

### O4: Container Orchestration Issues

**Description**: Restart loops, resource exhaustion, or orchestration failures.

**Impact**: Sync Service unavailable; forum operates in degraded mode.

**Mitigation**:
1. Set appropriate resource limits in docker-compose
2. `restart: always` with `stop_grace_period: 30s`
3. Memory limit: 256MB (increase if processing high volume)
4. CPU limit: 0.5 cores (adjust based on volume)
5. Crash loop backoff handled by Docker/Kubernetes
6. Alerting on container restart count

**Resource configuration**:
```yaml
deploy:
  resources:
    limits:
      memory: 256M
      cpus: '0.5'
    reservations:
      memory: 128M
```

---

## Migration Risks

### M1: Existing Posts Without PDS Accounts

**Description**: When migrating an existing phpBB forum, users have posts but no AT Protocol accounts.

**Impact**: Historical posts can't be attributed to DIDs; orphaned content.

**Mitigation**:
1. Posts remain in local cache with null AT URI
2. Display as "legacy post" with local author attribution
3. When user links DID, offer to migrate historical posts
4. Migration is opt-in and requires user consent
5. Unmigrated posts visible but clearly marked as pre-AT Protocol
6. Forum PDS can hold "archive" records for historical attribution

### M2: Historical Data Consistency

**Description**: Backfilling historical posts to PDSes may fail partially, leaving inconsistent state.

**Impact**: Some posts migrated, others not; broken reply chains.

**Mitigation**:
1. Migration runs as batched, resumable job
2. Track migration progress per user in `phpbb_atproto_users.migration_status`
3. Maintain bidirectional links: post → AT URI and AT URI → post
4. If reply target not yet migrated, queue for later
5. Validation pass after migration completes
6. Rollback capability: clear AT URI mappings, restore to legacy mode

### M3: Username/Handle Conflicts

**Description**: phpBB username may already be taken as a handle on AT Protocol, or multiple users have similar names.

**Impact**: User can't register with preferred handle; confusion.

**Mitigation**:
1. Handle is independent of phpBB username
2. Display both handle and local username where appropriate
3. Allow users to choose handle during DID linking
4. Suggest alternatives if handle taken
5. Local username remains unchanged; DID is the identity

---

## Mitigation Summary Table

| Risk ID | Risk | Primary Mitigation | Monitoring |
|---------|------|-------------------|------------|
| T1 | PDS offline | Retry queue with backoff | Queue depth |
| T2 | Firehose drops | Cursor persistence, auto-reconnect | Cursor freshness |
| T3 | Rate limiting | Backoff, caching | Rate limit responses |
| T4 | WebSocket failures | Circuit breaker | Consecutive failures |
| D1 | Cache divergence | Reconciliation job | Mismatch count |
| D2 | CID mismatch / mod bypass | Sticky moderation (URI-only labels) | Mismatch errors |
| D2a | Post creation race | Idempotent inserts | Duplicate key errors |
| D3 | Concurrent edits | swapRecord | Swap failures |
| D3a | Multi-instance config conflicts | Optimistic locking + conflict UI | Conflict frequency |
| D4 | Topic moves | Soft redirects | Orphaned posts |
| S1 | Token storage | XChaCha20 + key rotation | N/A |
| S2 | DID spoofing | Resolution verification | Verification failures |
| S3 | Moderator impersonation | Dual auth requirement | Audit logs |
| S4 | Token refresh race | Database locking | Refresh failures |
| O1 | Container crashes | Restart policy, cursor persistence | Restart count |
| O2 | Queue backlog | Exponential backoff, dead-letter | Queue depth |
| O3 | Connection exhaustion | Connection pooling | Connection count |
| O4 | Orchestration issues | Resource limits | Container health |
| M1 | Posts without PDS | Legacy post display | Migration status |
| M2 | Data consistency | Resumable migration | Validation report |
| M3 | Handle conflicts | Independent handle selection | N/A |
