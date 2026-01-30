# Component: Ozone Setup

## Overview
- **Purpose**: Configure the forum's Ozone labeler instance for content moderation
- **Location**: Forum PDS account, Ozone service configuration
- **Dependencies**: Forum PDS account with labeler capability
- **Dependents**: label-subscriber, mcp-integration, label-display
- **Task**: phpbb-e72

## Acceptance Criteria
- [ ] AC-1: Forum PDS publishes labeler service declaration record
- [ ] AC-2: Label definitions include `!hide`, `!warn`, `spam`, `nsfw`, `spoiler`, `off-topic`
- [ ] AC-3: Moderators can be added to Ozone team membership
- [ ] AC-4: Labels can be emitted via `tools.ozone.moderation.emitEvent` API
- [ ] AC-5: Labels are accessible via `com.atproto.label.subscribeLabels` endpoint
- [ ] AC-6: Moderator permissions map correctly from phpBB ACL

## File Structure
```
# No code files - this is a configuration/setup specification

Forum PDS Records:
├── at://forum-did/app.bsky.labeler.service/self    # Labeler declaration
└── Ozone team membership                            # Configured in Ozone admin

phpBB Extension:
└── ext/phpbb/atproto/services/ozone_client.php     # API client (see mcp-integration.md)
```

## Labeler Service Declaration

The forum PDS publishes this record to declare itself as a labeler:

```json
{
  "$type": "app.bsky.labeler.service",
  "policies": {
    "labelValues": ["!hide", "!warn", "spam", "nsfw", "spoiler", "off-topic"],
    "labelValueDefinitions": [
      {
        "identifier": "!hide",
        "severity": "alert",
        "blurs": "content",
        "defaultSetting": "hide",
        "adultOnly": false,
        "locales": [
          {
            "lang": "en",
            "name": "Hidden",
            "description": "Content hidden by moderators"
          }
        ]
      },
      {
        "identifier": "!warn",
        "severity": "inform",
        "blurs": "content",
        "defaultSetting": "warn",
        "adultOnly": false,
        "locales": [
          {
            "lang": "en",
            "name": "Content Warning",
            "description": "Content may be objectionable"
          }
        ]
      },
      {
        "identifier": "spam",
        "severity": "alert",
        "blurs": "content",
        "defaultSetting": "warn",
        "adultOnly": false,
        "locales": [
          {
            "lang": "en",
            "name": "Spam",
            "description": "Marked as spam by moderators"
          }
        ]
      },
      {
        "identifier": "nsfw",
        "severity": "inform",
        "blurs": "media",
        "defaultSetting": "warn",
        "adultOnly": true,
        "locales": [
          {
            "lang": "en",
            "name": "Adult Content",
            "description": "Contains adult or mature content"
          }
        ]
      },
      {
        "identifier": "spoiler",
        "severity": "inform",
        "blurs": "content",
        "defaultSetting": "warn",
        "adultOnly": false,
        "locales": [
          {
            "lang": "en",
            "name": "Spoiler",
            "description": "Contains spoilers"
          }
        ]
      },
      {
        "identifier": "off-topic",
        "severity": "none",
        "blurs": "none",
        "defaultSetting": "show",
        "adultOnly": false,
        "locales": [
          {
            "lang": "en",
            "name": "Off Topic",
            "description": "Post is off-topic for this forum"
          }
        ]
      }
    ]
  },
  "createdAt": "2024-01-01T00:00:00.000Z"
}
```

## Label Definitions

| Label | Severity | Blur | Default | phpBB Equivalent |
|-------|----------|------|---------|------------------|
| `!hide` | alert | content | hide | Soft delete, disapprove |
| `!warn` | inform | content | warn | Content warning |
| `spam` | alert | content | warn | Spam flag |
| `nsfw` | inform | media | warn | N/A (new feature) |
| `spoiler` | inform | content | warn | N/A (new feature) |
| `off-topic` | none | none | show | Informational marker |

### Label Behavior

- **`!hide`**: Content completely hidden from all users (except admins viewing moderation queue)
- **`!warn`**: Content shown with expandable warning overlay
- **`spam`**: Content filtered based on user/forum spam preferences
- **`nsfw`**: Media blurred, requires click to reveal
- **`spoiler`**: Content collapsed behind expandable spoiler box
- **`off-topic`**: Visual indicator only, no filtering

## Ozone Team Configuration

### Moderator Roles

| Ozone Role | phpBB ACL | Allowed Labels |
|------------|-----------|----------------|
| `admin` | `a_*` | All labels |
| `moderator` | `m_approve`, `m_delete` | `!hide`, `!warn`, `spam` |
| `triage` | `m_warn` | `!warn`, `spoiler`, `off-topic` |

### Team Member Structure

```json
{
  "did": "did:plc:moderator123",
  "role": "moderator",
  "disabled": false,
  "createdAt": "2024-01-15T10:00:00.000Z"
}
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `com.atproto.repo.putRecord` | POST | Publish labeler service declaration |
| `tools.ozone.moderation.emitEvent` | POST | Emit label on content |
| `tools.ozone.team.addMember` | POST | Add moderator to team |
| `tools.ozone.team.deleteMember` | POST | Remove moderator from team |
| `tools.ozone.team.listMembers` | GET | List current team members |
| `com.atproto.label.subscribeLabels` | WebSocket | Subscribe to label stream |

### Publish Labeler Declaration

```bash
# Using AT Protocol HTTP API
curl -X POST "https://${FORUM_PDS_URL}/xrpc/com.atproto.repo.putRecord" \
  -H "Authorization: Bearer ${FORUM_ACCESS_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "repo": "'${FORUM_DID}'",
    "collection": "app.bsky.labeler.service",
    "rkey": "self",
    "record": {
      "$type": "app.bsky.labeler.service",
      "policies": {
        "labelValues": ["!hide", "!warn", "spam", "nsfw", "spoiler", "off-topic"],
        "labelValueDefinitions": [...]
      },
      "createdAt": "2024-01-01T00:00:00.000Z"
    }
  }'
```

### Add Team Member

```json
// POST /xrpc/tools.ozone.team.addMember
{
  "did": "did:plc:newmoderator",
  "role": "moderator"
}
```

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `FORUM_DID` | string | (required) | Forum's DID (labeler identity) |
| `FORUM_PDS_URL` | string | (required) | Forum PDS XRPC endpoint |
| `FORUM_PDS_ACCESS_TOKEN` | string | (required) | Admin access token for labeler |
| `LABELER_DID` | string | `${FORUM_DID}` | Override if using separate labeler |

## Setup Procedure

### Step 1: Create Forum PDS Account

1. Register account on PDS (Bluesky or self-hosted)
2. Note the DID (e.g., `did:plc:abc123xyz`)
3. Store credentials securely

### Step 2: Publish Labeler Declaration

```bash
# Set environment variables
export FORUM_DID="did:plc:abc123xyz"
export FORUM_PDS_URL="https://bsky.social"
export FORUM_ACCESS_TOKEN="eyJ..."

# Publish labeler service record
php scripts/setup-labeler.php
```

### Step 3: Configure phpBB Extension

Add to `.env`:
```bash
FORUM_DID=did:plc:abc123xyz
FORUM_PDS_URL=https://bsky.social
FORUM_PDS_ACCESS_TOKEN=eyJ...
LABELER_DID=did:plc:abc123xyz
```

### Step 4: Add Moderators

For each moderator with phpBB `m_approve` or higher:
1. Get their DID (from phpbb_atproto_users)
2. Add to Ozone team via API
3. Verify with team list query

```php
// In ACP moderator management
$ozone_client->addTeamMember($moderator_did, 'moderator');
```

## Error Handling

| Condition | Recovery |
|-----------|----------|
| Labeler record already exists | Update with putRecord, swapRecord if needed |
| Team member already exists | Ignore (idempotent) |
| Invalid DID format | Reject, show error to admin |
| Ozone API unavailable | Queue operation for retry |
| Permission denied | Check forum access token |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Publish labeler declaration | Record visible at AT URI |
| Add team member | Member appears in team list |
| Remove team member | Member removed from team list |
| Emit label as moderator | Label created successfully |
| Emit label as non-moderator | Permission denied error |
| Subscribe to labels | Label events received in real-time |

## Implementation Notes

### Labeler Hosting Options

1. **Bluesky PDS**: Use Bluesky account as labeler (simplest)
2. **Self-hosted PDS**: Run own PDS with labeler capability
3. **Dedicated Ozone**: Run separate Ozone instance (most control)

### Sticky Moderation

Labels apply to AT URIs, not CIDs. This means:
- Labels persist when user edits their post
- User cannot bypass moderation by editing
- `subject_cid` is informational only

### Multi-Forum Labeling

If running multiple forum instances:
- All instances share the same labeler DID
- Labels apply network-wide to that labeler's subscribers
- Consider per-forum label namespacing if needed

### Security Considerations

- Forum access token has admin privileges - protect carefully
- Rotate access tokens periodically
- Audit moderator additions/removals
- Log all label emission events

## References
- [Ozone Documentation](https://github.com/bluesky-social/ozone)
- [Labeler Service Lexicon](https://github.com/bluesky-social/atproto/blob/main/lexicons/app/bsky/labeler/service.json)
- [AT Protocol Labels](https://atproto.com/specs/label)
- [docs/moderation-flow.md](../../../docs/moderation-flow.md) - Full moderation architecture
- [docs/architecture.md](../../../docs/architecture.md) - Labeler in system context
