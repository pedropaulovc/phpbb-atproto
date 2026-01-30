# Component: Label Display

## Overview
- **Purpose**: Filter and modify post display based on moderation labels from the Ozone labeler
- **Location**: `ext/phpbb/atproto/event/`
- **Dependencies**: label-subscriber (provides cached labels), migrations
- **Dependents**: None (end of display path)

## Acceptance Criteria
- [ ] AC-1: Posts with `!hide` label are excluded from listings
- [ ] AC-2: Posts with `!warn` label show expandable warning overlay
- [ ] AC-3: Posts with `spam` label respect forum/user spam settings
- [ ] AC-4: Labels are matched by URI only (sticky moderation)
- [ ] AC-5: Expired labels are not applied
- [ ] AC-6: Moderators can see hidden posts in MCP
- [ ] AC-7: Topic listings hide topics where first post is labeled

## File Structure
```
ext/phpbb/atproto/
├── event/
│   └── display_listener.php    # View event hooks
├── services/
│   └── label_checker.php       # Label lookup service
├── includes/
│   └── label_formatter.php     # Label display formatting
└── styles/
    └── prosilver/
        └── template/
            └── event/
                ├── viewtopic_body_postrow_post_content_footer_append.html
                └── viewforum_body_topic_title_append.html
```

## Interface Definitions

### LabelCheckerInterface

```php
<?php

namespace phpbb\atproto\services;

interface LabelCheckerInterface
{
    /**
     * Check if a subject has a specific active label.
     *
     * @param string $subjectUri AT URI of the subject
     * @param string $labelValue Label to check
     * @return bool True if label is active
     */
    public function hasLabel(string $subjectUri, string $labelValue): bool;

    /**
     * Get all active labels for a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @return array Array of label values
     */
    public function getLabels(string $subjectUri): array;

    /**
     * Check if a post should be hidden.
     *
     * @param string $subjectUri AT URI of the post
     * @return bool True if post should be hidden
     */
    public function isHidden(string $subjectUri): bool;

    /**
     * Check if a post should show a warning.
     *
     * @param string $subjectUri AT URI of the post
     * @return bool True if post should show warning
     */
    public function shouldWarn(string $subjectUri): bool;

    /**
     * Batch check labels for multiple subjects.
     *
     * @param array $subjectUris Array of AT URIs
     * @return array Map of URI => array of labels
     */
    public function batchGetLabels(array $subjectUris): array;
}
```

### LabelFormatterInterface

```php
<?php

namespace phpbb\atproto\includes;

interface LabelFormatterInterface
{
    /**
     * Format warning overlay HTML for a labeled post.
     *
     * @param array $labels Active labels for the post
     * @param int $postId Post ID for expand action
     * @return string HTML for warning overlay
     */
    public function formatWarning(array $labels, int $postId): string;

    /**
     * Format label badges for display.
     *
     * @param array $labels Active labels
     * @return string HTML for label badges
     */
    public function formatBadges(array $labels): string;

    /**
     * Get CSS classes for a labeled post.
     *
     * @param array $labels Active labels
     * @return string Space-separated CSS classes
     */
    public function getPostClasses(array $labels): string;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `core.viewtopic_modify_post_row` | Filter/modify post display | `$event['row']`, `$event['post_row']` |
| `core.viewforum_modify_topicrow` | Filter/modify topic display | `$event['row']`, `$event['topic_row']` |
| `core.viewtopic_get_post_data` | Modify post query | `$event['sql_ary']` |
| `core.viewforum_get_topic_data` | Modify topic query | `$event['sql_ary']` |
| `core.search_get_posts_data` | Filter search results | `$event['sql_ary']` |

## Database Interactions

### Tables Read
- `phpbb_atproto_labels` - Label cache
- `phpbb_atproto_posts` - Post AT URI mapping

### Key Queries

```php
// Join posts with labels for filtering
$sql_ary['LEFT_JOIN'][] = [
    'FROM' => [$this->table_prefix . 'atproto_posts' => 'ap'],
    'ON' => 'p.post_id = ap.post_id',
];
$sql_ary['LEFT_JOIN'][] = [
    'FROM' => [$this->table_prefix . 'atproto_labels' => 'al'],
    'ON' => "ap.at_uri = al.subject_uri
             AND al.label_value = '!hide'
             AND al.negated = 0
             AND (al.expires_at IS NULL OR al.expires_at > " . time() . ")",
];

// Exclude hidden posts (for non-moderators)
$sql_ary['WHERE'] .= ' AND al.id IS NULL';

// Get labels for a post
$sql = 'SELECT al.label_value
        FROM ' . $this->table_prefix . 'atproto_labels al
        JOIN ' . $this->table_prefix . 'atproto_posts ap ON al.subject_uri = ap.at_uri
        WHERE ap.post_id = ?
          AND al.negated = 0
          AND (al.expires_at IS NULL OR al.expires_at > ?)';

// Batch get labels for multiple posts
$sql = 'SELECT ap.post_id, al.label_value
        FROM ' . $this->table_prefix . 'atproto_labels al
        JOIN ' . $this->table_prefix . 'atproto_posts ap ON al.subject_uri = ap.at_uri
        WHERE ap.post_id IN (?)
          AND al.negated = 0
          AND (al.expires_at IS NULL OR al.expires_at > ?)';
```

## Display Logic

### Label Effects

| Label | Effect on Display |
|-------|-------------------|
| `!hide` | Post completely hidden (SQL exclusion) |
| `!warn` | Post wrapped in expandable warning |
| `spam` | Post shown/hidden based on spam preference |
| `nsfw` | Media blurred until click |
| `spoiler` | Content collapsed behind "Show Spoiler" |
| `off-topic` | Badge displayed, no filtering |

### View Topic Flow

```
User views topic
    │
    ▼
┌─────────────────────────────────────┐
│ Modify SQL query to join labels     │
│ (core.viewtopic_get_post_data)      │
│                                      │
│ - Join phpbb_atproto_posts          │
│ - Join phpbb_atproto_labels         │
│ - WHERE: exclude !hide (if not mod) │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ For each post row:                   │
│ (core.viewtopic_modify_post_row)     │
│                                      │
│ - Get AT URI from row               │
│ - Batch fetch labels for all posts  │
│ - Apply display modifications       │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Apply label effects:                 │
│                                      │
│ !warn → Add warning overlay         │
│ nsfw → Add blur class to media      │
│ spoiler → Wrap in collapsed div     │
│ off-topic → Add badge               │
└─────────────────────────────────────┘
```

### View Forum Flow (Topics)

```
User views forum index
    │
    ▼
┌─────────────────────────────────────┐
│ Modify SQL to check first post label│
│ (core.viewforum_get_topic_data)      │
│                                      │
│ - Join first post's labels          │
│ - Exclude topics with !hide root    │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ For each topic row:                  │
│ (core.viewforum_modify_topicrow)     │
│                                      │
│ - Check first post labels           │
│ - Add warning indicator if needed   │
└─────────────────────────────────────┘
```

## Error Handling

| Condition | Recovery |
|-----------|----------|
| Missing AT URI mapping | Display post normally (legacy) |
| Label query fails | Log error, display posts normally |
| Invalid label value | Ignore unknown labels |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `atproto_label_filtering` | bool | true | Enable label filtering |
| `atproto_spam_default` | string | 'warn' | Default spam handling (hide/warn/show) |
| `atproto_nsfw_default` | string | 'warn' | Default NSFW handling |
| `atproto_mods_see_hidden` | bool | true | Moderators can see !hide posts |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| View topic with !hide post | Post not visible |
| View topic with !warn post | Post shows warning overlay |
| Moderator views !hide post | Post visible with "hidden" indicator |
| View topic with spoiler post | Post content collapsed |
| Label expires | Post becomes visible |
| Label negated | Post becomes visible |
| Legacy post (no AT URI) | Post displays normally |

## Implementation Notes

### Display Listener Implementation

```php
<?php

namespace phpbb\atproto\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DisplayListener implements EventSubscriberInterface
{
    private array $labelCache = [];

    public static function getSubscribedEvents()
    {
        return [
            'core.viewtopic_get_post_data' => 'onViewtopicQuery',
            'core.viewtopic_modify_post_row' => 'onViewtopicPostRow',
            'core.viewforum_get_topic_data' => 'onViewforumQuery',
            'core.viewforum_modify_topicrow' => 'onViewforumTopicRow',
        ];
    }

    public function onViewtopicQuery($event)
    {
        if (!$this->config['atproto_label_filtering']) {
            return;
        }

        $sql_ary = $event['sql_ary'];

        // Join AT Proto posts mapping
        $sql_ary['LEFT_JOIN'][] = [
            'FROM' => [$this->table_prefix . 'atproto_posts' => 'ap'],
            'ON' => 'p.post_id = ap.post_id',
        ];

        // Add AT URI to SELECT
        $sql_ary['SELECT'] .= ', ap.at_uri';

        // For non-moderators, exclude !hide posts
        if (!$this->auth->acl_get('m_approve')) {
            $sql_ary['LEFT_JOIN'][] = [
                'FROM' => [$this->table_prefix . 'atproto_labels' => 'al_hide'],
                'ON' => "ap.at_uri = al_hide.subject_uri
                         AND al_hide.label_value = '!hide'
                         AND al_hide.negated = 0
                         AND (al_hide.expires_at IS NULL OR al_hide.expires_at > " . time() . ")",
            ];
            $sql_ary['WHERE'] .= ' AND al_hide.id IS NULL';
        }

        $event['sql_ary'] = $sql_ary;
    }

    public function onViewtopicPostRow($event)
    {
        $row = $event['row'];
        $post_row = $event['post_row'];

        $atUri = $row['at_uri'] ?? null;
        if (!$atUri) {
            return; // Legacy post
        }

        // Get labels for this post
        $labels = $this->labelChecker->getLabels($atUri);
        if (empty($labels)) {
            return;
        }

        // Apply label effects
        foreach ($labels as $label) {
            switch ($label) {
                case '!hide':
                    // For moderators who can see hidden posts
                    $post_row['S_POST_HIDDEN'] = true;
                    $post_row['POST_CLASS'] .= ' post-hidden';
                    break;

                case '!warn':
                    $post_row['S_POST_WARNING'] = true;
                    $post_row['WARNING_HTML'] = $this->formatter->formatWarning(
                        $labels,
                        $row['post_id']
                    );
                    break;

                case 'nsfw':
                    $post_row['S_NSFW'] = true;
                    $post_row['POST_CLASS'] .= ' post-nsfw';
                    break;

                case 'spoiler':
                    $post_row['S_SPOILER'] = true;
                    break;

                case 'off-topic':
                    $post_row['LABEL_BADGES'] = ($post_row['LABEL_BADGES'] ?? '') .
                        $this->formatter->formatBadge('off-topic');
                    break;
            }
        }

        $event['post_row'] = $post_row;
    }

    public function onViewforumQuery($event)
    {
        if (!$this->config['atproto_label_filtering']) {
            return;
        }

        $sql_ary = $event['sql_ary'];

        // For topic listings, check the first post's labels
        // Topics with !hide on first post should be hidden
        $sql_ary['LEFT_JOIN'][] = [
            'FROM' => [$this->table_prefix . 'atproto_posts' => 'ap_first'],
            'ON' => 't.topic_first_post_id = ap_first.post_id',
        ];

        if (!$this->auth->acl_get('m_approve')) {
            $sql_ary['LEFT_JOIN'][] = [
                'FROM' => [$this->table_prefix . 'atproto_labels' => 'al_topic'],
                'ON' => "ap_first.at_uri = al_topic.subject_uri
                         AND al_topic.label_value = '!hide'
                         AND al_topic.negated = 0
                         AND (al_topic.expires_at IS NULL OR al_topic.expires_at > " . time() . ")",
            ];
            $sql_ary['WHERE'] .= ' AND al_topic.id IS NULL';
        }

        $sql_ary['SELECT'] .= ', ap_first.at_uri AS topic_at_uri';

        $event['sql_ary'] = $sql_ary;
    }
}
```

### Warning Overlay Template

```html
<!-- viewtopic_body_postrow_post_content_footer_append.html -->
{% if post_row.S_POST_WARNING %}
<div class="atproto-warning-overlay" data-post-id="{{ post_row.POST_ID }}">
    <div class="warning-icon">⚠️</div>
    <div class="warning-text">{{ lang('ATPROTO_CONTENT_WARNING') }}</div>
    <button class="warning-reveal" onclick="revealPost({{ post_row.POST_ID }})">
        {{ lang('ATPROTO_SHOW_CONTENT') }}
    </button>
</div>
<div class="atproto-hidden-content" id="post-content-{{ post_row.POST_ID }}" style="display:none;">
    {{ post_row.MESSAGE }}
</div>
{% endif %}
```

### CSS Styles

```css
/* styles/prosilver/theme/atproto.css */

.post-hidden {
    opacity: 0.5;
    border: 2px dashed #c00;
}

.post-hidden::before {
    content: "Hidden by moderation";
    display: block;
    background: #fcc;
    padding: 5px;
    text-align: center;
}

.atproto-warning-overlay {
    background: #fff3cd;
    border: 1px solid #ffc107;
    padding: 20px;
    text-align: center;
    border-radius: 4px;
}

.post-nsfw .postbody img,
.post-nsfw .postbody video {
    filter: blur(20px);
    cursor: pointer;
}

.post-nsfw .postbody img:hover,
.post-nsfw .postbody video:hover {
    filter: blur(5px);
}

.label-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 0.8em;
    margin-left: 5px;
}

.label-badge-off-topic {
    background: #e0e0e0;
    color: #666;
}
```

### Security Considerations
- Labels are advisory - don't rely solely on JS for hiding
- SQL-level filtering for `!hide` is authoritative
- Moderators bypass filters for moderation purposes
- Don't expose label details to non-moderators

### Performance Considerations
- Batch label queries for all posts in view
- Cache labels in memory during request
- Index on `subject_uri` for fast lookups
- Avoid N+1 queries

## References
- [phpBB Template Events](https://wiki.phpbb.com/Event_List)
- [docs/moderation-flow.md](../../../docs/moderation-flow.md) - Label filtering
- [docs/risks.md](../../../docs/risks.md) - D2: Sticky Moderation
