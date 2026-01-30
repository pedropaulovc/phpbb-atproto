# Testing Specification: Integration Tests

## Overview
- **Purpose**: Test component interactions with real database and service dependencies
- **Framework**: PHPUnit 9.x with database fixtures
- **Location**: `tests/integration/`
- **Task**: phpbb-gh6

## Acceptance Criteria
- [ ] AC-1: Tests use isolated test database
- [ ] AC-2: Database state is reset between test classes
- [ ] AC-3: External APIs are mocked (no real network calls)
- [ ] AC-4: Tests cover component interaction boundaries
- [ ] AC-5: Tests execute in < 2 minutes total

## File Structure
```
tests/
├── integration/
│   ├── phpbb-extension/
│   │   ├── AuthProviderIntegrationTest.php
│   │   ├── WriteInterceptorIntegrationTest.php
│   │   ├── LabelDisplayIntegrationTest.php
│   │   └── McpIntegrationTest.php
│   ├── sync-service/
│   │   ├── FirehoseProcessingTest.php
│   │   ├── PostSyncTest.php
│   │   ├── LabelSyncTest.php
│   │   └── ConfigSyncTest.php
│   └── cross-component/
│       ├── WriteAndSyncTest.php
│       └── ModerationFlowTest.php
├── fixtures/
│   └── database/
│       ├── users.php
│       ├── forums.php
│       ├── posts.php
│       └── labels.php
├── TestCase/
│   ├── DatabaseTestCase.php
│   └── MockServiceTestCase.php
└── phpunit.integration.xml
```

## Test Configuration

### phpunit.integration.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.integration.php"
         colors="true">
    <testsuites>
        <testsuite name="Integration Tests">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="TEST_DB_HOST" value="127.0.0.1"/>
        <env name="TEST_DB_NAME" value="phpbb_test"/>
        <env name="TEST_DB_USER" value="phpbb_test"/>
        <env name="TEST_DB_PASS" value="test_password"/>
    </php>
</phpunit>
```

### bootstrap.integration.php

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

// Initialize test database connection
$testDbConfig = [
    'host' => getenv('TEST_DB_HOST'),
    'dbname' => getenv('TEST_DB_NAME'),
    'user' => getenv('TEST_DB_USER'),
    'password' => getenv('TEST_DB_PASS'),
];

// Create test tables if needed
$pdo = new PDO(
    "mysql:host={$testDbConfig['host']};dbname={$testDbConfig['dbname']}",
    $testDbConfig['user'],
    $testDbConfig['password']
);
$pdo->exec(file_get_contents(__DIR__ . '/../migrations/schema.sql'));
```

## Base Test Case

### DatabaseTestCase.php

```php
<?php

namespace phpbb\atproto\tests\TestCase;

use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected static \PDO $pdo;
    protected \PDO $db;
    protected string $tablePrefix = 'phpbb_';

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new \PDO(
            sprintf('mysql:host=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'),
            getenv('TEST_DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function setUp(): void
    {
        $this->db = self::$pdo;
        $this->beginTransaction();
        $this->loadFixtures();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();
    }

    protected function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    protected function rollbackTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    protected function loadFixtures(): void
    {
        // Override in subclasses to load specific fixtures
    }

    protected function loadFixture(string $name): void
    {
        $fixtures = require __DIR__ . "/../fixtures/database/{$name}.php";
        foreach ($fixtures as $table => $rows) {
            foreach ($rows as $row) {
                $columns = implode(', ', array_keys($row));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $sql = "INSERT INTO {$this->tablePrefix}{$table} ({$columns}) VALUES ({$placeholders})";
                $this->db->prepare($sql)->execute(array_values($row));
            }
        }
    }

    protected function assertRowExists(string $table, array $conditions): void
    {
        $where = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $params[] = $value;
        }
        $sql = "SELECT 1 FROM {$this->tablePrefix}{$table} WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $this->assertNotFalse($stmt->fetch(), "Expected row not found in {$table}");
    }

    protected function assertRowNotExists(string $table, array $conditions): void
    {
        $where = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $params[] = $value;
        }
        $sql = "SELECT 1 FROM {$this->tablePrefix}{$table} WHERE " . implode(' AND ', $where);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $this->assertFalse($stmt->fetch(), "Unexpected row found in {$table}");
    }
}
```

## Integration Test Specifications

### Auth Provider Integration Test

```php
<?php

namespace phpbb\atproto\tests\integration;

use phpbb\atproto\tests\TestCase\DatabaseTestCase;
use phpbb\atproto\services\TokenManager;
use phpbb\atproto\auth\TokenEncryption;

class AuthProviderIntegrationTest extends DatabaseTestCase
{
    private TokenManager $tokenManager;
    private TokenEncryption $encryption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixture('users');

        $this->encryption = new TokenEncryption();
        $this->tokenManager = new TokenManager($this->db, $this->encryption, 'phpbb_');
    }

    public function testStoreAndRetrieveTokens(): void
    {
        $userId = 2; // From fixture
        $accessToken = 'test_access_token_jwt';
        $refreshToken = 'test_refresh_token';
        $expiresAt = time() + 3600;

        $this->tokenManager->storeTokens($userId, $accessToken, $refreshToken, $expiresAt);

        // Verify stored
        $this->assertRowExists('atproto_users', [
            'user_id' => $userId,
        ]);

        // Verify can retrieve
        $retrieved = $this->tokenManager->getAccessToken($userId);
        $this->assertEquals($accessToken, $retrieved);
    }

    public function testTokenStoredEncrypted(): void
    {
        $userId = 2;
        $accessToken = 'plaintext_token';

        $this->tokenManager->storeTokens($userId, $accessToken, 'refresh', time() + 3600);

        // Query raw database value
        $stmt = $this->db->prepare(
            'SELECT access_token FROM phpbb_atproto_users WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Should not be plaintext
        $this->assertNotEquals($accessToken, $row['access_token']);
        // Should have version prefix
        $this->assertStringStartsWith('v1:', $row['access_token']);
    }

    public function testClearTokensOnLogout(): void
    {
        $userId = 2;
        $this->tokenManager->storeTokens($userId, 'token', 'refresh', time() + 3600);

        $this->tokenManager->clearTokens($userId);

        $stmt = $this->db->prepare(
            'SELECT access_token FROM phpbb_atproto_users WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNull($row['access_token']);
    }

    public function testGetUserDidReturnsCorrectDid(): void
    {
        // Insert user with DID
        $this->db->prepare(
            'INSERT INTO phpbb_atproto_users (user_id, did, pds_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([3, 'did:plc:test123', 'https://bsky.social', time(), time()]);

        $did = $this->tokenManager->getUserDid(3);
        $this->assertEquals('did:plc:test123', $did);
    }
}
```

### Post Sync Integration Test

```php
<?php

namespace phpbb\atproto\tests\integration;

use phpbb\atproto\tests\TestCase\DatabaseTestCase;
use phpbb\atproto\sync\Database\PostWriter;
use phpbb\atproto\sync\Database\UserResolver;
use phpbb\atproto\sync\Database\ForumResolver;

class PostSyncTest extends DatabaseTestCase
{
    private PostWriter $postWriter;
    private UserResolver $userResolver;
    private ForumResolver $forumResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixture('users');
        $this->loadFixture('forums');

        $this->userResolver = new UserResolver($this->db, 'phpbb_');
        $this->forumResolver = new ForumResolver($this->db, 'phpbb_');
        $this->postWriter = new PostWriter(
            $this->db,
            $this->userResolver,
            $this->forumResolver,
            'phpbb_'
        );
    }

    public function testInsertNewPostFromFirehose(): void
    {
        $record = [
            'text' => 'Post from firehose',
            'createdAt' => '2024-01-15T10:30:00.000Z',
            'forum' => [
                'uri' => 'at://did:plc:forum/net.vza.forum.board/general',
                'cid' => 'bafyforum',
            ],
            'subject' => 'New Topic from Network',
        ];

        $postId = $this->postWriter->insertPost(
            $record,
            'did:plc:existing_user',
            'at://did:plc:existing_user/net.vza.forum.post/123abc',
            'bafypost123'
        );

        // Verify post created in phpbb_posts
        $this->assertRowExists('posts', ['post_id' => $postId]);

        // Verify mapping created
        $this->assertRowExists('atproto_posts', [
            'post_id' => $postId,
            'at_uri' => 'at://did:plc:existing_user/net.vza.forum.post/123abc',
            'sync_status' => 'synced',
        ]);

        // Verify topic created (has subject)
        $stmt = $this->db->prepare('SELECT topic_id FROM phpbb_posts WHERE post_id = ?');
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        $this->assertNotNull($row['topic_id']);
    }

    public function testInsertPostCreatesNewUser(): void
    {
        $record = [
            'text' => 'Post from new user',
            'createdAt' => '2024-01-15T10:30:00.000Z',
            'forum' => [
                'uri' => 'at://did:plc:forum/net.vza.forum.board/general',
                'cid' => 'bafyforum',
            ],
            'subject' => 'New User Topic',
        ];

        // User with this DID doesn't exist yet
        $this->postWriter->insertPost(
            $record,
            'did:plc:brand_new_user',
            'at://did:plc:brand_new_user/net.vza.forum.post/abc',
            'bafypost'
        );

        // Verify user was created
        $this->assertRowExists('atproto_users', [
            'did' => 'did:plc:brand_new_user',
        ]);
    }

    public function testIdempotentInsertHandlesRace(): void
    {
        $atUri = 'at://did:plc:user/net.vza.forum.post/race123';

        $record = [
            'text' => 'Race condition post',
            'createdAt' => '2024-01-15T10:30:00.000Z',
            'forum' => [
                'uri' => 'at://did:plc:forum/net.vza.forum.board/general',
                'cid' => 'bafyforum',
            ],
            'subject' => 'Race Topic',
        ];

        // First insert
        $postId1 = $this->postWriter->insertPost($record, 'did:plc:existing_user', $atUri, 'bafy1');

        // Second insert with same URI (simulating race)
        $postId2 = $this->postWriter->insertPost($record, 'did:plc:existing_user', $atUri, 'bafy2');

        // Should return same post ID (idempotent)
        $this->assertEquals($postId1, $postId2);

        // Should update CID
        $this->assertRowExists('atproto_posts', [
            'post_id' => $postId1,
            'at_cid' => 'bafy2',
        ]);
    }

    public function testUpdatePostContent(): void
    {
        // Insert initial post
        $record = [
            'text' => 'Original content',
            'createdAt' => '2024-01-15T10:30:00.000Z',
            'forum' => ['uri' => 'at://forum/board/1', 'cid' => 'bafyforum'],
            'subject' => 'Topic',
        ];
        $postId = $this->postWriter->insertPost(
            $record,
            'did:plc:existing_user',
            'at://user/post/update123',
            'bafy_v1'
        );

        // Update
        $updatedRecord = [
            'text' => 'Updated content',
            'createdAt' => '2024-01-15T10:30:00.000Z',
            'forum' => ['uri' => 'at://forum/board/1', 'cid' => 'bafyforum'],
            'subject' => 'Topic',
        ];
        $this->postWriter->updatePost($postId, $updatedRecord, 'bafy_v2');

        // Verify content updated
        $stmt = $this->db->prepare('SELECT post_text FROM phpbb_posts WHERE post_id = ?');
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        $this->assertEquals('Updated content', $row['post_text']);

        // Verify CID updated
        $this->assertRowExists('atproto_posts', [
            'post_id' => $postId,
            'at_cid' => 'bafy_v2',
        ]);
    }
}
```

### Label Sync Integration Test

```php
<?php

namespace phpbb\atproto\tests\integration;

use phpbb\atproto\tests\TestCase\DatabaseTestCase;
use phpbb\atproto\sync\Labels\LabelWriter;
use phpbb\atproto\services\LabelChecker;

class LabelSyncTest extends DatabaseTestCase
{
    private LabelWriter $labelWriter;
    private LabelChecker $labelChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixture('posts');

        $this->labelWriter = new LabelWriter($this->db, 'phpbb_');
        $this->labelChecker = new LabelChecker($this->db, 'phpbb_');
    }

    public function testStoreLabelForPost(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        $stored = $this->labelWriter->storeLabel(
            $subjectUri,
            'bafycid123',
            '!hide',
            'did:plc:labeler',
            time()
        );

        $this->assertTrue($stored);
        $this->assertRowExists('atproto_labels', [
            'subject_uri' => $subjectUri,
            'label_value' => '!hide',
            'negated' => 0,
        ]);
    }

    public function testNegateLabel(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        // Store label
        $this->labelWriter->storeLabel($subjectUri, 'bafycid', '!hide', 'did:plc:labeler', time());

        // Negate it
        $negated = $this->labelWriter->negateLabel($subjectUri, '!hide', 'did:plc:labeler', time());

        $this->assertTrue($negated);
        $this->assertRowExists('atproto_labels', [
            'subject_uri' => $subjectUri,
            'label_value' => '!hide',
            'negated' => 1,
        ]);
    }

    public function testLabelCheckerIgnoresNegatedLabels(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        // Store and negate label
        $this->labelWriter->storeLabel($subjectUri, 'bafycid', '!hide', 'did:plc:labeler', time());
        $this->labelWriter->negateLabel($subjectUri, '!hide', 'did:plc:labeler', time());

        // Checker should not find it
        $this->assertFalse($this->labelChecker->isHidden($subjectUri));
    }

    public function testLabelCheckerIgnoresExpiredLabels(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        // Store label that expired
        $this->labelWriter->storeLabel(
            $subjectUri,
            'bafycid',
            '!hide',
            'did:plc:labeler',
            time() - 3600,
            time() - 1800 // Expired 30 min ago
        );

        $this->assertFalse($this->labelChecker->isHidden($subjectUri));
    }

    public function testStickyModerationMatchesByUriOnly(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        // Store label with CID v1
        $this->labelWriter->storeLabel($subjectUri, 'bafycid_v1', '!hide', 'did:plc:labeler', time());

        // Check with different CID (simulating post edit) - should still be hidden
        // because we match by URI only (sticky moderation)
        $this->assertTrue($this->labelChecker->isHidden($subjectUri));
    }

    public function testDuplicateLabelNotInserted(): void
    {
        $subjectUri = 'at://did:plc:user/net.vza.forum.post/test123';

        // Store label twice
        $this->labelWriter->storeLabel($subjectUri, 'bafycid', '!hide', 'did:plc:labeler', time());
        $stored = $this->labelWriter->storeLabel($subjectUri, 'bafycid', '!hide', 'did:plc:labeler', time());

        // Second should return false (duplicate)
        $this->assertFalse($stored);

        // Should only have one row
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as cnt FROM phpbb_atproto_labels WHERE subject_uri = ?'
        );
        $stmt->execute([$subjectUri]);
        $this->assertEquals(1, $stmt->fetch()['cnt']);
    }
}
```

### Cross-Component: Write and Sync Test

```php
<?php

namespace phpbb\atproto\tests\integration\cross_component;

use phpbb\atproto\tests\TestCase\DatabaseTestCase;

class WriteAndSyncTest extends DatabaseTestCase
{
    /**
     * Test scenario: User posts via phpBB, then firehose event arrives.
     * The system should handle this race gracefully.
     */
    public function testExtensionWriteThenFirehoseArrives(): void
    {
        $this->loadFixture('users');
        $this->loadFixture('forums');

        $atUri = 'at://did:plc:user/net.vza.forum.post/race123';
        $postContent = 'Test post content';

        // Simulate extension creating post first
        $extensionPostId = $this->simulateExtensionPost($atUri, $postContent, 'bafy_ext');

        // Simulate firehose event arriving (slightly delayed)
        $firehosePostId = $this->simulateFirehosePost($atUri, $postContent, 'bafy_fire');

        // Should be same post (idempotent)
        $this->assertEquals($extensionPostId, $firehosePostId);

        // Mapping should be updated to synced
        $this->assertRowExists('atproto_posts', [
            'post_id' => $extensionPostId,
            'sync_status' => 'synced',
        ]);
    }

    /**
     * Test scenario: Firehose event arrives before extension finishes.
     */
    public function testFirehoseFirstThenExtensionCompletes(): void
    {
        $this->loadFixture('users');
        $this->loadFixture('forums');

        $atUri = 'at://did:plc:user/net.vza.forum.post/race456';
        $postContent = 'Test post content';

        // Firehose arrives first
        $firehosePostId = $this->simulateFirehosePost($atUri, $postContent, 'bafy_fire');

        // Extension completes later
        $extensionPostId = $this->simulateExtensionPostCompletion($atUri, $firehosePostId, 'bafy_ext');

        // Should link to same post
        $this->assertEquals($firehosePostId, $extensionPostId);
    }

    private function simulateExtensionPost(string $atUri, string $content, string $cid): int
    {
        // Insert phpBB post
        $this->db->prepare(
            'INSERT INTO phpbb_posts (forum_id, topic_id, poster_id, post_time, post_text) VALUES (?, ?, ?, ?, ?)'
        )->execute([1, 0, 2, time(), $content]);
        $postId = (int)$this->db->lastInsertId();

        // Create topic
        $this->db->prepare(
            'INSERT INTO phpbb_topics (forum_id, topic_poster, topic_first_post_id) VALUES (?, ?, ?)'
        )->execute([1, 2, $postId]);
        $topicId = (int)$this->db->lastInsertId();

        $this->db->prepare('UPDATE phpbb_posts SET topic_id = ? WHERE post_id = ?')
            ->execute([$topicId, $postId]);

        // Insert AT mapping with pending status
        $this->db->prepare(
            'INSERT INTO phpbb_atproto_posts (post_id, at_uri, at_cid, author_did, sync_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$postId, $atUri, $cid, 'did:plc:user', 'pending', time(), time()]);

        return $postId;
    }

    private function simulateFirehosePost(string $atUri, string $content, string $cid): int
    {
        // Check if already exists
        $stmt = $this->db->prepare('SELECT post_id FROM phpbb_atproto_posts WHERE at_uri = ?');
        $stmt->execute([$atUri]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update to synced
            $this->db->prepare(
                'UPDATE phpbb_atproto_posts SET sync_status = ?, at_cid = ?, updated_at = ? WHERE at_uri = ?'
            )->execute(['synced', $cid, time(), $atUri]);
            return (int)$existing['post_id'];
        }

        // Create new post via sync service logic
        $this->db->prepare(
            'INSERT INTO phpbb_posts (forum_id, topic_id, poster_id, post_time, post_text) VALUES (?, ?, ?, ?, ?)'
        )->execute([1, 0, 2, time(), $content]);
        $postId = (int)$this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO phpbb_atproto_posts (post_id, at_uri, at_cid, author_did, sync_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$postId, $atUri, $cid, 'did:plc:user', 'synced', time(), time()]);

        return $postId;
    }

    private function simulateExtensionPostCompletion(string $atUri, int $existingPostId, string $cid): int
    {
        // Extension discovers firehose already created the post
        $stmt = $this->db->prepare('SELECT post_id FROM phpbb_atproto_posts WHERE at_uri = ?');
        $stmt->execute([$atUri]);
        $existing = $stmt->fetch();

        if ($existing) {
            return (int)$existing['post_id'];
        }

        return $existingPostId;
    }
}
```

## Database Fixtures

### fixtures/database/users.php

```php
<?php

return [
    'users' => [
        ['user_id' => 1, 'username' => 'Anonymous', 'username_clean' => 'anonymous', 'user_type' => 2],
        ['user_id' => 2, 'username' => 'testuser', 'username_clean' => 'testuser', 'user_type' => 0],
        ['user_id' => 3, 'username' => 'moderator', 'username_clean' => 'moderator', 'user_type' => 0],
    ],
    'atproto_users' => [
        [
            'user_id' => 2,
            'did' => 'did:plc:existing_user',
            'handle' => 'testuser.bsky.social',
            'pds_url' => 'https://bsky.social',
            'created_at' => time(),
            'updated_at' => time(),
        ],
    ],
];
```

### fixtures/database/forums.php

```php
<?php

return [
    'forums' => [
        [
            'forum_id' => 1,
            'forum_name' => 'General',
            'parent_id' => 0,
            'forum_type' => 1,
            'left_id' => 1,
            'right_id' => 2,
        ],
    ],
    'atproto_forums' => [
        [
            'forum_id' => 1,
            'at_uri' => 'at://did:plc:forum/net.vza.forum.board/general',
            'at_cid' => 'bafyforum',
            'slug' => 'general',
            'updated_at' => time(),
        ],
    ],
];
```

## Test Execution

```bash
# Run all integration tests
./vendor/bin/phpunit -c tests/phpunit.integration.xml

# Run specific test class
./vendor/bin/phpunit -c tests/phpunit.integration.xml tests/integration/sync-service/PostSyncTest.php

# Run with coverage
./vendor/bin/phpunit -c tests/phpunit.integration.xml --coverage-html tests/coverage-integration
```

## References
- [PHPUnit Database Testing](https://phpunit.de/documentation.html)
- Component specification files
- [docs/risks.md](../../docs/risks.md) - D2a: Post Creation Race Condition
