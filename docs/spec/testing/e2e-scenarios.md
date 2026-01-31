# Testing Specification: End-to-End Scenarios

## Overview
- **Purpose**: Validate complete user journeys through the phpBB AT Protocol integration
- **Framework**: Playwright or Cypress for browser automation, PHPUnit for API tests
- **Location**: `tests/e2e/`

## Acceptance Criteria
- [ ] AC-1: Tests run against fully deployed stack (Docker Compose)
- [ ] AC-2: Each scenario tests complete user flow from start to finish
- [ ] AC-3: Tests verify both UI state and database/PDS state
- [ ] AC-4: Tests are independent and can run in any order
- [ ] AC-5: Failed tests capture screenshots and logs

## Test Environment

### Docker Compose Test Environment

```yaml
# docker-compose.test.yml
version: '3.8'

services:
  phpbb:
    image: phpbb/phpbb:3.3
    environment:
      PHPBB_DB_HOST: mysql
      PHPBB_DB_NAME: phpbb_e2e
      PHPBB_DB_USER: phpbb
      PHPBB_DB_PASSWORD: e2e_password
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: phpbb_e2e
      MYSQL_USER: phpbb
      MYSQL_PASSWORD: e2e_password

  sync-service:
    build: ./sync-service
    environment:
      MYSQL_HOST: mysql
      MYSQL_DATABASE: phpbb_e2e
      RELAY_URL: ${MOCK_RELAY_URL}
    depends_on:
      - mysql

  mock-pds:
    build: ./tests/e2e/mock-pds
    ports:
      - "3000:3000"

  mock-relay:
    build: ./tests/e2e/mock-relay
    ports:
      - "3001:3001"
```

## Test Scenarios

### Scenario 1: User Authentication Flow

**Description**: User authenticates via AT Protocol OAuth and gains access to post.

**Preconditions**:
- User has AT Protocol account (mock PDS)
- phpBB is installed with AT Protocol extension

**Steps**:
1. Navigate to phpBB login page
2. Click "Login with AT Protocol"
3. Enter handle (e.g., `testuser.mock.local`)
4. Redirected to mock PDS authorization page
5. Approve authorization
6. Redirected back to phpBB
7. Verify user is logged in
8. Verify user can access forum

**Expected Results**:
- User session created
- `phpbb_atproto_users` row created with DID and encrypted tokens
- User can view and post to forums

**Test Code**:

```typescript
// tests/e2e/auth.spec.ts
import { test, expect } from '@playwright/test';

test.describe('AT Protocol Authentication', () => {
  test('should complete OAuth flow and create session', async ({ page }) => {
    // Navigate to login
    await page.goto('/ucp.php?mode=login');

    // Click AT Protocol login
    await page.click('[data-testid="atproto-login"]');

    // Enter handle
    await page.fill('[name="handle"]', 'testuser.mock.local');
    await page.click('[type="submit"]');

    // Should redirect to mock PDS
    await expect(page).toHaveURL(/mock-pds.*authorize/);

    // Approve authorization
    await page.click('[data-testid="authorize-approve"]');

    // Should redirect back to phpBB
    await expect(page).toHaveURL(/\/index\.php/);

    // Should be logged in
    await expect(page.locator('.username-coloured')).toContainText('testuser');

    // Verify database state
    const dbResult = await queryDatabase(
      'SELECT did, handle FROM phpbb_atproto_users WHERE handle = ?',
      ['testuser.mock.local']
    );
    expect(dbResult.length).toBe(1);
    expect(dbResult[0].did).toMatch(/^did:plc:/);
  });

  test('should handle OAuth denial gracefully', async ({ page }) => {
    await page.goto('/ucp.php?mode=login');
    await page.click('[data-testid="atproto-login"]');
    await page.fill('[name="handle"]', 'testuser.mock.local');
    await page.click('[type="submit"]');

    // Deny authorization
    await page.click('[data-testid="authorize-deny"]');

    // Should redirect back with error
    await expect(page).toHaveURL(/\/ucp\.php.*mode=login/);
    await expect(page.locator('.error')).toContainText('Authorization denied');
  });
});
```

### Scenario 2: Create Post and Verify PDS Write

**Description**: Authenticated user creates a post, verify it's written to PDS.

**Preconditions**:
- User is authenticated with AT Protocol
- Forum exists and user has post permission

**Steps**:
1. Navigate to forum
2. Click "New Topic"
3. Enter subject and message
4. Submit post
5. Verify post appears in forum
6. Verify post exists on mock PDS
7. Verify AT URI mapping stored

**Expected Results**:
- Post created in phpBB database
- Record created on user's PDS (`net.vza.forum.post`)
- `phpbb_atproto_posts` mapping created
- Post visible in topic view

**Test Code**:

```typescript
// tests/e2e/posting.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Post Creation', () => {
  test.beforeEach(async ({ page }) => {
    // Login via helper
    await loginWithAtProto(page, 'testuser.mock.local');
  });

  test('should create topic and write to PDS', async ({ page }) => {
    // Navigate to forum
    await page.goto('/viewforum.php?f=1');

    // Create new topic
    await page.click('[data-testid="new-topic"]');
    await page.fill('[name="subject"]', 'E2E Test Topic');
    await page.fill('[name="message"]', 'This is a test post from e2e tests.');
    await page.click('[name="post"]');

    // Wait for post to appear
    await expect(page.locator('.post-content')).toContainText('This is a test post');

    // Verify PDS write
    const pdsRecord = await mockPds.getRecord(
      'did:plc:testuser',
      'net.vza.forum.post'
    );
    expect(pdsRecord).toBeDefined();
    expect(pdsRecord.value.text).toBe('This is a test post from e2e tests.');
    expect(pdsRecord.value.subject).toBe('E2E Test Topic');

    // Verify database mapping
    const mapping = await queryDatabase(
      'SELECT at_uri, sync_status FROM phpbb_atproto_posts WHERE post_id = ?',
      [getPostIdFromUrl(page.url())]
    );
    expect(mapping[0].at_uri).toMatch(/^at:\/\/did:plc:testuser/);
    expect(mapping[0].sync_status).toBe('synced');
  });

  test('should handle PDS unavailable gracefully', async ({ page }) => {
    // Disable mock PDS
    await mockPds.setUnavailable(true);

    await page.goto('/viewforum.php?f=1');
    await page.click('[data-testid="new-topic"]');
    await page.fill('[name="subject"]', 'Offline Test Topic');
    await page.fill('[name="message"]', 'Test post while PDS offline.');
    await page.click('[name="post"]');

    // Post should still appear (local save)
    await expect(page.locator('.post-content')).toContainText('Test post while PDS offline');

    // Should show syncing indicator
    await expect(page.locator('.sync-status')).toContainText('Syncing');

    // Verify queued in database
    const queue = await queryDatabase(
      'SELECT * FROM phpbb_atproto_queue WHERE status = ?',
      ['pending']
    );
    expect(queue.length).toBeGreaterThan(0);

    // Re-enable PDS
    await mockPds.setUnavailable(false);

    // Trigger retry (or wait for background job)
    await triggerQueueRetry();

    // Verify sync completed
    await page.reload();
    await expect(page.locator('.sync-status')).not.toBeVisible();
  });
});
```

### Scenario 3: External Post Arrives via Firehose

**Description**: Post created by external client appears in phpBB.

**Preconditions**:
- Sync Service running and connected to mock relay
- Forum configured with AT URI mapping

**Steps**:
1. Create post record directly on mock PDS
2. Emit commit event to mock relay firehose
3. Wait for Sync Service to process
4. Navigate to forum in browser
5. Verify post appears

**Expected Results**:
- Sync Service receives firehose event
- Post inserted into phpBB database
- Post visible in forum view
- Author resolved/created as phpBB user

**Test Code**:

```typescript
// tests/e2e/firehose-sync.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Firehose Sync', () => {
  test('should display posts from external clients', async ({ page }) => {
    // Create post on mock PDS (external client)
    const atUri = await mockPds.createRecord('did:plc:external', 'net.vza.forum.post', {
      text: 'Post from external AT Protocol client',
      createdAt: new Date().toISOString(),
      forum: {
        uri: 'at://did:plc:forum/net.vza.forum.board/general',
        cid: 'bafyforum',
      },
      subject: 'External Topic',
    });

    // Emit to mock relay
    await mockRelay.emitCommit({
      repo: 'did:plc:external',
      ops: [{
        action: 'create',
        path: `net.vza.forum.post/${extractRkey(atUri)}`,
        cid: 'bafypost',
      }],
    });

    // Wait for sync (with timeout)
    await waitForSync(atUri, 10000);

    // Navigate to forum
    await page.goto('/viewforum.php?f=1');

    // Verify topic appears
    await expect(page.locator('.topiclist')).toContainText('External Topic');

    // Click into topic
    await page.click('text=External Topic');

    // Verify post content
    await expect(page.locator('.post-content')).toContainText('Post from external AT Protocol client');

    // Verify author created
    const user = await queryDatabase(
      'SELECT * FROM phpbb_atproto_users WHERE did = ?',
      ['did:plc:external']
    );
    expect(user.length).toBe(1);
  });

  test('should handle firehose reconnection', async ({ page }) => {
    // Get current cursor
    const initialCursor = await getCursor('firehose');

    // Disconnect mock relay
    await mockRelay.disconnect();

    // Wait for reconnection attempt
    await sleep(5000);

    // Reconnect
    await mockRelay.connect();

    // Emit new post
    await emitTestPost('Post after reconnection');

    // Wait for sync
    await waitForPostContent('Post after reconnection', 15000);

    // Verify cursor advanced
    const newCursor = await getCursor('firehose');
    expect(newCursor).toBeGreaterThan(initialCursor);
  });
});
```

### Scenario 4: Moderator Applies Label

**Description**: Moderator disapproves a post, label is applied via Ozone.

**Preconditions**:
- User with moderator permissions exists
- Moderator is Ozone team member
- Post exists with AT URI

**Steps**:
1. Login as moderator
2. Navigate to MCP
3. Select post in queue
4. Click "Disapprove"
5. Verify post hidden from regular view
6. Verify label stored in database
7. Login as regular user
8. Verify post not visible

**Expected Results**:
- Label emitted to Ozone
- Label stored in `phpbb_atproto_labels`
- Post hidden from non-moderators
- Moderator can still see post with "hidden" indicator

**Test Code**:

```typescript
// tests/e2e/moderation.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Moderation', () => {
  test('should hide post when moderator disapproves', async ({ page, context }) => {
    // Create a post as regular user
    const regularPage = await context.newPage();
    await loginWithAtProto(regularPage, 'regularuser.mock.local');
    const postUrl = await createPost(regularPage, 'Post to be moderated', 'Content that violates rules');
    const postId = getPostIdFromUrl(postUrl);
    await regularPage.close();

    // Login as moderator
    await loginWithAtProto(page, 'moderator.mock.local');

    // Navigate to MCP
    await page.goto('/mcp.php?i=queue&mode=unapproved_posts');

    // Find and select the post
    await page.check(`[name="post_ids[]"][value="${postId}"]`);

    // Click disapprove
    await page.click('[name="action"][value="disapprove"]');
    await page.click('[name="confirm"]');

    // Verify success message
    await expect(page.locator('.successbox')).toContainText('disapproved');

    // Verify label in database
    const labels = await queryDatabase(
      'SELECT * FROM phpbb_atproto_labels WHERE subject_uri = (SELECT at_uri FROM phpbb_atproto_posts WHERE post_id = ?)',
      [postId]
    );
    expect(labels.length).toBe(1);
    expect(labels[0].label_value).toBe('!hide');

    // Login as regular user and verify post hidden
    await page.goto('/ucp.php?mode=logout');
    await loginWithAtProto(page, 'anotheruser.mock.local');

    await page.goto(postUrl);

    // Post should not be visible
    await expect(page.locator('.post')).toHaveCount(0);
    // Or show "post hidden" message
    await expect(page.locator('.error, .notice')).toContainText(/not found|hidden/i);
  });

  test('should restore post when label negated', async ({ page }) => {
    // Setup: Create and hide a post
    const postId = await createAndHidePost();

    // Login as moderator
    await loginWithAtProto(page, 'moderator.mock.local');

    // Navigate to deleted posts view
    await page.goto(`/mcp.php?i=queue&mode=deleted_posts`);

    // Find and restore the post
    await page.check(`[name="post_ids[]"][value="${postId}"]`);
    await page.click('[name="action"][value="restore"]');
    await page.click('[name="confirm"]');

    // Verify label negated
    const labels = await queryDatabase(
      'SELECT negated FROM phpbb_atproto_labels WHERE subject_uri = (SELECT at_uri FROM phpbb_atproto_posts WHERE post_id = ?)',
      [postId]
    );
    expect(labels[0].negated).toBe(1);

    // Verify post visible to regular users
    await page.goto('/ucp.php?mode=logout');
    await loginWithAtProto(page, 'regularuser.mock.local');

    const postUrl = `/viewtopic.php?p=${postId}`;
    await page.goto(postUrl);

    await expect(page.locator('.post')).toBeVisible();
  });
});
```

### Scenario 5: Admin Config Sync

**Description**: Admin changes forum structure, changes sync to forum PDS.

**Preconditions**:
- Admin user exists
- Forum PDS configured

**Steps**:
1. Login as admin
2. Navigate to ACP > Forums
3. Create new forum
4. Verify forum created on PDS
5. Edit forum name
6. Verify change synced to PDS
7. Delete forum
8. Verify deleted from PDS

**Expected Results**:
- Board records created/updated/deleted on forum PDS
- Local mapping maintained
- Changes visible to all instances

**Test Code**:

```typescript
// tests/e2e/config-sync.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Admin Config Sync', () => {
  test('should sync forum creation to PDS', async ({ page }) => {
    await loginAsAdmin(page);

    // Navigate to ACP forum management
    await page.goto('/adm/index.php?i=acp_forums&mode=manage');

    // Create new forum
    await page.click('[data-testid="create-forum"]');
    await page.fill('[name="forum_name"]', 'E2E Test Forum');
    await page.fill('[name="forum_desc"]', 'Forum created by e2e test');
    await page.selectOption('[name="forum_type"]', 'forum');
    await page.click('[name="submit"]');

    // Wait for success
    await expect(page.locator('.successbox')).toBeVisible();

    // Verify created on mock PDS
    const boards = await mockPds.listRecords('did:plc:forum', 'net.vza.forum.board');
    const newBoard = boards.find(b => b.value.name === 'E2E Test Forum');
    expect(newBoard).toBeDefined();
    expect(newBoard.value.slug).toBe('e2e-test-forum');

    // Verify database mapping
    const mapping = await queryDatabase(
      'SELECT at_uri FROM phpbb_atproto_forums WHERE slug = ?',
      ['e2e-test-forum']
    );
    expect(mapping.length).toBe(1);
    expect(mapping[0].at_uri).toBe(newBoard.uri);
  });

  test('should handle concurrent admin edits', async ({ page, context }) => {
    // Create a forum first
    const forumId = await createTestForum('Conflict Test Forum');

    // Open two admin sessions
    const admin1 = page;
    const admin2 = await context.newPage();

    await loginAsAdmin(admin1);
    await loginAsAdmin(admin2);

    // Both navigate to edit same forum
    const editUrl = `/adm/index.php?i=acp_forums&mode=manage&action=edit&f=${forumId}`;
    await admin1.goto(editUrl);
    await admin2.goto(editUrl);

    // Admin1 changes name
    await admin1.fill('[name="forum_name"]', 'Name from Admin 1');
    await admin1.click('[name="submit"]');

    // Admin2 tries to change name (should conflict)
    await admin2.fill('[name="forum_name"]', 'Name from Admin 2');
    await admin2.click('[name="submit"]');

    // Should show conflict resolution
    await expect(admin2.locator('.conflict-resolution')).toBeVisible();
    await expect(admin2.locator('.their-changes')).toContainText('Name from Admin 1');

    await admin2.close();
  });
});
```

## Test Helpers

### Mock PDS API

```typescript
// tests/e2e/helpers/mock-pds.ts
export class MockPdsClient {
  private baseUrl: string;

  constructor(baseUrl: string = 'http://localhost:3000') {
    this.baseUrl = baseUrl;
  }

  async createRecord(did: string, collection: string, record: object): Promise<string> {
    const response = await fetch(`${this.baseUrl}/xrpc/com.atproto.repo.createRecord`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ repo: did, collection, record }),
    });
    const result = await response.json();
    return result.uri;
  }

  async getRecord(did: string, collection: string, rkey?: string): Promise<any> {
    const url = new URL(`${this.baseUrl}/xrpc/com.atproto.repo.getRecord`);
    url.searchParams.set('repo', did);
    url.searchParams.set('collection', collection);
    if (rkey) url.searchParams.set('rkey', rkey);

    const response = await fetch(url);
    return response.json();
  }

  async listRecords(did: string, collection: string): Promise<any[]> {
    const url = new URL(`${this.baseUrl}/xrpc/com.atproto.repo.listRecords`);
    url.searchParams.set('repo', did);
    url.searchParams.set('collection', collection);

    const response = await fetch(url);
    const result = await response.json();
    return result.records;
  }

  async setUnavailable(unavailable: boolean): Promise<void> {
    await fetch(`${this.baseUrl}/_test/availability`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ available: !unavailable }),
    });
  }
}
```

### Database Helper

```typescript
// tests/e2e/helpers/database.ts
import mysql from 'mysql2/promise';

let pool: mysql.Pool;

export async function initDatabase() {
  pool = mysql.createPool({
    host: process.env.TEST_DB_HOST || 'localhost',
    database: process.env.TEST_DB_NAME || 'phpbb_e2e',
    user: process.env.TEST_DB_USER || 'phpbb',
    password: process.env.TEST_DB_PASS || 'e2e_password',
  });
}

export async function queryDatabase(sql: string, params: any[] = []): Promise<any[]> {
  const [rows] = await pool.execute(sql, params);
  return rows as any[];
}

export async function resetDatabase() {
  // Truncate test tables
  const tables = [
    'phpbb_atproto_posts',
    'phpbb_atproto_users',
    'phpbb_atproto_labels',
    'phpbb_atproto_queue',
    'phpbb_atproto_cursors',
  ];
  for (const table of tables) {
    await pool.execute(`TRUNCATE TABLE ${table}`);
  }
}
```

## Test Execution

```bash
# Start test environment
docker-compose -f docker-compose.test.yml up -d

# Run e2e tests
npx playwright test tests/e2e/

# Run specific scenario
npx playwright test tests/e2e/auth.spec.ts

# Run with UI mode for debugging
npx playwright test --ui

# Generate report
npx playwright show-report
```

## CI/CD Integration

```yaml
# .github/workflows/e2e.yml
name: E2E Tests

on: [push, pull_request]

jobs:
  e2e:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Start test environment
        run: docker-compose -f docker-compose.test.yml up -d

      - name: Wait for services
        run: |
          timeout 60 bash -c 'until curl -s http://localhost:80 > /dev/null; do sleep 1; done'

      - name: Install Playwright
        run: npx playwright install --with-deps

      - name: Run E2E tests
        run: npx playwright test

      - name: Upload test results
        uses: actions/upload-artifact@v3
        if: always()
        with:
          name: playwright-report
          path: playwright-report/
```

## References
- [Playwright Documentation](https://playwright.dev/docs/intro)
- Component specification files
- [docs/architecture.md](../../docs/architecture.md) - System flows
- [docs/moderation-flow.md](../../docs/moderation-flow.md) - Moderation scenarios
