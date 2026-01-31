# Testing Specification: Unit Tests

## Overview
- **Purpose**: Define unit test structure, mocking strategies, and test cases for individual components
- **Framework**: PHPUnit 9.x
- **Location**: `tests/unit/`

## Acceptance Criteria
- [ ] AC-1: All service interfaces have corresponding unit tests
- [ ] AC-2: Test coverage > 80% for service layer
- [ ] AC-3: Tests run in isolation with mocked dependencies
- [ ] AC-4: No network or database calls in unit tests
- [ ] AC-5: Tests execute in < 30 seconds total

## File Structure
```
tests/
├── unit/
│   ├── phpbb-extension/
│   │   ├── auth/
│   │   │   ├── TokenEncryptionTest.php
│   │   │   ├── TokenManagerTest.php
│   │   │   └── OAuthClientTest.php
│   │   ├── services/
│   │   │   ├── PdsClientTest.php
│   │   │   ├── UriMapperTest.php
│   │   │   ├── RecordBuilderTest.php
│   │   │   └── LabelCheckerTest.php
│   │   └── event/
│   │       ├── WriteListenerTest.php
│   │       ├── DisplayListenerTest.php
│   │       └── McpListenerTest.php
│   └── sync-service/
│       ├── Firehose/
│       │   ├── ProcessorTest.php
│       │   ├── FilterTest.php
│       │   ├── CarReaderTest.php
│       │   └── ReconnectStrategyTest.php
│       ├── Database/
│       │   ├── PostWriterTest.php
│       │   └── UserResolverTest.php
│       └── Labels/
│           ├── LabelWriterTest.php
│           └── SubjectMatcherTest.php
├── fixtures/
│   ├── cbor/
│   │   ├── valid_commit.bin
│   │   └── malformed_commit.bin
│   ├── car/
│   │   └── sample_blocks.car
│   └── records/
│       ├── post_record.json
│       └── board_record.json
├── bootstrap.php
└── phpunit.xml
```

## Test Configuration

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         verbose="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Extension Unit Tests">
            <directory>tests/unit/phpbb-extension</directory>
        </testsuite>
        <testsuite name="Sync Service Unit Tests">
            <directory>tests/unit/sync-service</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">ext/phpbb/atproto</directory>
            <directory suffix=".php">sync-service/src</directory>
        </include>
        <report>
            <html outputDirectory="tests/coverage"/>
            <text outputFile="php://stdout"/>
        </report>
    </coverage>
</phpunit>
```

### bootstrap.php

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Mock phpBB globals
define('IN_PHPBB', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
define('PHP_EXT', 'php');

// Set up test environment variables
putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS={"v1":"dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q="}');
putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
```

## Component Test Specifications

### TokenEncryption Tests

```php
<?php

namespace phpbb\atproto\tests\unit\auth;

use PHPUnit\Framework\TestCase;
use phpbb\atproto\auth\TokenEncryption;

class TokenEncryptionTest extends TestCase
{
    private TokenEncryption $encryption;

    protected function setUp(): void
    {
        // Set up test keys
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS={"v1":"dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q=","v2":"bmV3a2V5bmV3a2V5bmV3a2V5bmV3a2V5bmV3a2V5bmV3"}');
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
        $this->encryption = new TokenEncryption();
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.test';
        $encrypted = $this->encryption->encrypt($token);
        $decrypted = $this->encryption->decrypt($encrypted);
        $this->assertEquals($token, $decrypted);
    }

    public function testEncryptedFormatIncludesVersion(): void
    {
        $encrypted = $this->encryption->encrypt('test-token');
        $this->assertStringStartsWith('v1:', $encrypted);
    }

    public function testDecryptWithOldKeyVersion(): void
    {
        // Encrypt with v1
        $encrypted = $this->encryption->encrypt('test-token');

        // Switch to v2 as current version
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');
        $newEncryption = new TokenEncryption();

        // Should still decrypt v1 tokens
        $decrypted = $newEncryption->decrypt($encrypted);
        $this->assertEquals('test-token', $decrypted);
    }

    public function testNeedsReEncryptionForOldVersion(): void
    {
        $encrypted = $this->encryption->encrypt('test');

        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');
        $newEncryption = new TokenEncryption();

        $this->assertTrue($newEncryption->needsReEncryption($encrypted));
    }

    public function testDecryptFailsWithInvalidKey(): void
    {
        $encrypted = $this->encryption->encrypt('test');
        // Corrupt the ciphertext
        $corrupted = substr($encrypted, 0, -5) . 'xxxxx';

        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt($corrupted);
    }

    public function testDecryptFailsWithUnknownVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt('v99:invaliddata');
    }
}
```

### Event Processor Tests

```php
<?php

namespace phpbb\atproto\tests\unit\Firehose;

use PHPUnit\Framework\TestCase;
use phpbb\atproto\sync\Firehose\Processor;
use phpbb\atproto\sync\Firehose\FilterInterface;
use phpbb\atproto\sync\Firehose\CarReaderInterface;
use phpbb\atproto\sync\Firehose\EventRouterInterface;
use Psr\Log\NullLogger;

class ProcessorTest extends TestCase
{
    private Processor $processor;
    private $mockFilter;
    private $mockCarReader;
    private $mockRouter;

    protected function setUp(): void
    {
        $this->mockFilter = $this->createMock(FilterInterface::class);
        $this->mockCarReader = $this->createMock(CarReaderInterface::class);
        $this->mockRouter = $this->createMock(EventRouterInterface::class);

        $this->processor = new Processor(
            $this->mockFilter,
            $this->mockCarReader,
            $this->mockRouter,
            new NullLogger()
        );
    }

    public function testShouldProcessForumPostCollection(): void
    {
        $this->mockFilter->method('matches')
            ->with('net.vza.forum.post')
            ->willReturn(true);

        $this->assertTrue($this->processor->shouldProcess('net.vza.forum.post'));
    }

    public function testShouldNotProcessBskyCollection(): void
    {
        $this->mockFilter->method('matches')
            ->with('app.bsky.feed.post')
            ->willReturn(false);

        $this->assertFalse($this->processor->shouldProcess('app.bsky.feed.post'));
    }

    public function testProcessCommitRoutesCreateToHandler(): void
    {
        $commit = [
            'repo' => 'did:plc:test123',
            'ops' => [
                [
                    'action' => 'create',
                    'path' => 'net.vza.forum.post/3jui7kd2zoik2',
                    'cid' => 'bafyreid123',
                ],
            ],
            'blocks' => 'cardata...',
        ];

        $record = ['text' => 'Test post', 'createdAt' => '2024-01-15T00:00:00Z'];

        $this->mockFilter->method('matches')->willReturn(true);
        $this->mockCarReader->method('parseBlocks')->willReturn([
            'bafyreid123' => $record,
        ]);

        $this->mockRouter->expects($this->once())
            ->method('route')
            ->with(
                'net.vza.forum.post',
                'create',
                $this->callback(function ($params) use ($record) {
                    return $params['repo'] === 'did:plc:test123'
                        && $params['rkey'] === '3jui7kd2zoik2'
                        && $params['record'] === $record;
                })
            );

        $this->processor->processCommit($commit);
    }

    public function testProcessCommitHandlesDeleteWithoutRecord(): void
    {
        $commit = [
            'repo' => 'did:plc:test123',
            'ops' => [
                [
                    'action' => 'delete',
                    'path' => 'net.vza.forum.post/3jui7kd2zoik2',
                ],
            ],
            'blocks' => '',
        ];

        $this->mockFilter->method('matches')->willReturn(true);
        $this->mockCarReader->method('parseBlocks')->willReturn([]);

        $this->mockRouter->expects($this->once())
            ->method('route')
            ->with(
                'net.vza.forum.post',
                'delete',
                $this->callback(function ($params) {
                    return $params['repo'] === 'did:plc:test123'
                        && $params['rkey'] === '3jui7kd2zoik2'
                        && !isset($params['record']);
                })
            );

        $this->processor->processCommit($commit);
    }

    public function testProcessCommitSkipsFilteredCollections(): void
    {
        $commit = [
            'repo' => 'did:plc:test123',
            'ops' => [
                [
                    'action' => 'create',
                    'path' => 'app.bsky.feed.post/abc',
                    'cid' => 'bafyreid123',
                ],
            ],
            'blocks' => 'cardata...',
        ];

        $this->mockFilter->method('matches')->willReturn(false);

        $this->mockRouter->expects($this->never())->method('route');

        $this->processor->processCommit($commit);
    }

    public function testProcessCommitLogsAndContinuesOnMissingCid(): void
    {
        $commit = [
            'repo' => 'did:plc:test123',
            'ops' => [
                [
                    'action' => 'create',
                    'path' => 'net.vza.forum.post/3jui7kd2zoik2',
                    'cid' => 'bafyreid_missing',
                ],
            ],
            'blocks' => 'cardata...',
        ];

        $this->mockFilter->method('matches')->willReturn(true);
        $this->mockCarReader->method('parseBlocks')->willReturn([]); // CID not found

        $this->mockRouter->expects($this->never())->method('route');

        // Should not throw, just log and skip
        $this->processor->processCommit($commit);
    }
}
```

### Reconnect Strategy Tests

```php
<?php

namespace phpbb\atproto\tests\unit\Firehose;

use PHPUnit\Framework\TestCase;
use phpbb\atproto\sync\Firehose\ExponentialBackoff;

class ReconnectStrategyTest extends TestCase
{
    public function testInitialDelayIsBaseDelay(): void
    {
        $strategy = new ExponentialBackoff(1000, 60000, 2.0, 10);
        $this->assertEquals(1000, $strategy->getDelay());
    }

    public function testDelayIncreasesExponentially(): void
    {
        $strategy = new ExponentialBackoff(1000, 60000, 2.0, 10);

        $strategy->onFailure();
        $this->assertEquals(2000, $strategy->getDelay());

        $strategy->onFailure();
        $this->assertEquals(4000, $strategy->getDelay());

        $strategy->onFailure();
        $this->assertEquals(8000, $strategy->getDelay());
    }

    public function testDelayIsCappedAtMax(): void
    {
        $strategy = new ExponentialBackoff(1000, 5000, 2.0, 10);

        for ($i = 0; $i < 10; $i++) {
            $strategy->onFailure();
        }

        $this->assertEquals(5000, $strategy->getDelay());
    }

    public function testSuccessResetsFailures(): void
    {
        $strategy = new ExponentialBackoff(1000, 60000, 2.0, 10);

        $strategy->onFailure();
        $strategy->onFailure();
        $strategy->onSuccess();

        $this->assertEquals(1000, $strategy->getDelay());
        $this->assertEquals(0, $strategy->getFailureCount());
    }

    public function testShouldReconnectWithinThreshold(): void
    {
        $strategy = new ExponentialBackoff(1000, 60000, 2.0, 5);

        for ($i = 0; $i < 4; $i++) {
            $strategy->onFailure();
        }

        $this->assertTrue($strategy->shouldReconnect());
    }

    public function testShouldNotReconnectAfterMaxFailures(): void
    {
        $strategy = new ExponentialBackoff(1000, 60000, 2.0, 5);

        for ($i = 0; $i < 5; $i++) {
            $strategy->onFailure();
        }

        $this->assertFalse($strategy->shouldReconnect());
    }
}
```

### Record Builder Tests

```php
<?php

namespace phpbb\atproto\tests\unit\services;

use PHPUnit\Framework\TestCase;
use phpbb\atproto\services\RecordBuilder;
use phpbb\atproto\services\UriMapperInterface;

class RecordBuilderTest extends TestCase
{
    private RecordBuilder $builder;
    private $mockUriMapper;

    protected function setUp(): void
    {
        $this->mockUriMapper = $this->createMock(UriMapperInterface::class);
        $this->builder = new RecordBuilder($this->mockUriMapper);
    }

    public function testBuildTopicStarterRecordIncludesSubject(): void
    {
        $this->mockUriMapper->method('getForumStrongRef')
            ->willReturn(['uri' => 'at://forum/board/1', 'cid' => 'bafycid']);

        $postData = [
            'message' => 'Post content',
            'subject' => 'New Topic Title',
            'topic_type' => 0,
        ];

        $record = $this->builder->buildPostRecord($postData, 1, null, null);

        $this->assertEquals('net.vza.forum.post', $record['$type']);
        $this->assertEquals('Post content', $record['text']);
        $this->assertEquals('New Topic Title', $record['subject']);
        $this->assertArrayNotHasKey('reply', $record);
    }

    public function testBuildReplyRecordIncludesReplyRefs(): void
    {
        $this->mockUriMapper->method('getForumStrongRef')
            ->willReturn(['uri' => 'at://forum/board/1', 'cid' => 'bafycid']);
        $this->mockUriMapper->method('getTopicRootRef')
            ->willReturn(['uri' => 'at://author/post/root', 'cid' => 'bafy_root']);
        $this->mockUriMapper->method('getStrongRef')
            ->willReturn(['uri' => 'at://author/post/parent', 'cid' => 'bafy_parent']);

        $postData = [
            'message' => 'Reply content',
        ];

        $record = $this->builder->buildPostRecord($postData, 1, 123, 456);

        $this->assertArrayNotHasKey('subject', $record);
        $this->assertArrayHasKey('reply', $record);
        $this->assertEquals('at://author/post/root', $record['reply']['root']['uri']);
        $this->assertEquals('at://author/post/parent', $record['reply']['parent']['uri']);
    }

    public function testBuildRecordIncludesForumReference(): void
    {
        $this->mockUriMapper->method('getForumStrongRef')
            ->willReturn(['uri' => 'at://forum/board/general', 'cid' => 'bafyforum']);

        $record = $this->builder->buildPostRecord(['message' => 'test'], 1, null, null);

        $this->assertArrayHasKey('forum', $record);
        $this->assertEquals('at://forum/board/general', $record['forum']['uri']);
    }

    public function testBuildRecordIncludesCreatedAt(): void
    {
        $this->mockUriMapper->method('getForumStrongRef')
            ->willReturn(['uri' => 'at://forum/board/1', 'cid' => 'bafycid']);

        $record = $this->builder->buildPostRecord(['message' => 'test'], 1, null, null);

        $this->assertArrayHasKey('createdAt', $record);
        // Should be ISO 8601 format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $record['createdAt']);
    }
}
```

### Label Checker Tests

```php
<?php

namespace phpbb\atproto\tests\unit\services;

use PHPUnit\Framework\TestCase;
use phpbb\atproto\services\LabelChecker;

class LabelCheckerTest extends TestCase
{
    private LabelChecker $checker;
    private $mockDb;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(\phpbb\db\driver\driver_interface::class);
        $this->checker = new LabelChecker($this->mockDb, 'phpbb_');
    }

    public function testIsHiddenReturnsTrueForHideLabel(): void
    {
        $this->mockDb->method('sql_query')->willReturn(true);
        $this->mockDb->method('sql_fetchrow')->willReturn(['label_value' => '!hide']);

        $this->assertTrue($this->checker->isHidden('at://did/post/123'));
    }

    public function testIsHiddenReturnsFalseForNoLabel(): void
    {
        $this->mockDb->method('sql_query')->willReturn(true);
        $this->mockDb->method('sql_fetchrow')->willReturn(false);

        $this->assertFalse($this->checker->isHidden('at://did/post/123'));
    }

    public function testShouldWarnReturnsTrueForWarnLabel(): void
    {
        $this->mockDb->method('sql_query')->willReturn(true);
        $this->mockDb->method('sql_fetchrowset')->willReturn([
            ['label_value' => '!warn'],
        ]);

        $this->assertTrue($this->checker->shouldWarn('at://did/post/123'));
    }

    public function testGetLabelsReturnsAllActiveLabels(): void
    {
        $this->mockDb->method('sql_query')->willReturn(true);
        $this->mockDb->method('sql_fetchrowset')->willReturn([
            ['label_value' => 'spam'],
            ['label_value' => 'off-topic'],
        ]);

        $labels = $this->checker->getLabels('at://did/post/123');

        $this->assertEquals(['spam', 'off-topic'], $labels);
    }
}
```

## Mocking Guidelines

### phpBB Services

```php
// Database mock
$mockDb = $this->createMock(\phpbb\db\driver\driver_interface::class);
$mockDb->method('sql_query')->willReturn(true);
$mockDb->method('sql_fetchrow')->willReturn(['column' => 'value']);

// Config mock
$mockConfig = $this->createMock(\phpbb\config\config::class);
$mockConfig->method('offsetGet')->willReturnMap([
    ['atproto_enabled', true],
    ['atproto_forum_did', 'did:plc:forum'],
]);

// Auth mock
$mockAuth = $this->createMock(\phpbb\auth\auth::class);
$mockAuth->method('acl_get')->with('m_approve')->willReturn(true);
```

### HTTP Client Mock

```php
// Guzzle mock for PDS/Ozone calls
$mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
$mockResponse->method('getStatusCode')->willReturn(200);
$mockResponse->method('getBody')->willReturn(json_encode(['uri' => 'at://...']));

$mockClient = $this->createMock(\GuzzleHttp\ClientInterface::class);
$mockClient->method('request')->willReturn($mockResponse);
```

## Test Data Fixtures

### Sample Post Record (fixtures/records/post_record.json)

```json
{
  "$type": "net.vza.forum.post",
  "text": "This is a test post with some BBCode [b]formatting[/b].",
  "createdAt": "2024-01-15T10:30:00.000Z",
  "forum": {
    "uri": "at://did:plc:forum/net.vza.forum.board/general",
    "cid": "bafyreidforum123"
  },
  "subject": "Test Topic Title",
  "enableBbcode": true,
  "enableSmilies": true,
  "enableSignature": false
}
```

## Coverage Requirements

| Component | Minimum Coverage |
|-----------|-----------------|
| auth/TokenEncryption | 95% |
| auth/TokenManager | 90% |
| services/PdsClient | 85% |
| services/RecordBuilder | 90% |
| Firehose/Processor | 90% |
| Firehose/Filter | 100% |
| Database/PostWriter | 85% |
| Labels/LabelWriter | 90% |

## References
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [phpBB Testing Guide](https://area51.phpbb.com/docs/dev/3.3.x/testing/)
- Component specification files for test cases
