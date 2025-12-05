# Database Schema Review Document
## Phase 3 - Milestone 3.1

**Purpose:** Permanent record of system structure for future developers.

**Date:** December 2025  
**Version:** 1.0

---

## 1. CREATE TABLE: `wp_n88_timeline_events`

### Complete SQL Schema

```sql
CREATE TABLE wp_n88_timeline_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL COMMENT 'Item index within project (0-based)',
    step_key VARCHAR(50) NOT NULL COMMENT 'e.g. prototype, frame_structure, surface_treatment, sourcing, qc, packing',
    event_type VARCHAR(30) NOT NULL COMMENT 'step_started, step_completed, step_reopened, step_delayed, step_unblocked, override_applied, file_added, comment_added, video_added, note_added, status_changed',
    status VARCHAR(30) DEFAULT NULL COMMENT 'pending, in_progress, completed, blocked, delayed',
    event_data LONGTEXT NULL COMMENT 'JSON blob for extra data (reason, old_status, new_status, file_id, comment_id, video_id, user_agent, etc.)',
    created_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NULL COMMENT 'User ID who triggered the event',
    PRIMARY KEY (id),
    KEY idx_project_item (project_id, item_id),
    KEY idx_item_step (item_id, step_key),
    KEY idx_project (project_id),
    KEY idx_event_type (event_type, created_at),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_created_by (created_by)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Column Descriptions

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `project_id` | BIGINT UNSIGNED | Links to `wp_projects.id` |
| `item_id` | INT UNSIGNED | Item index (0-based) within project's `n88_repeater_raw` array |
| `step_key` | VARCHAR(50) | Step identifier (e.g., `prototype`, `frame_structure`, `surface_treatment`) |
| `event_type` | VARCHAR(30) | Event category (see Event Types Enumeration document) |
| `status` | VARCHAR(30) | Current status: `pending`, `in_progress`, `completed`, `blocked`, `delayed` |
| `event_data` | LONGTEXT | JSON-encoded additional event data |
| `created_at` | DATETIME | Timestamp when event occurred |
| `created_by` | BIGINT UNSIGNED | User ID who triggered the event (nullable) |

### Indexes

- **PRIMARY KEY:** `id` - Unique identifier
- **idx_project_item:** Composite index on `(project_id, item_id)` - Fast queries for all events for a specific item
- **idx_item_step:** Composite index on `(item_id, step_key)` - Fast queries for step-specific events
- **idx_project:** Index on `project_id` - Fast queries for all events in a project
- **idx_event_type:** Composite index on `(event_type, created_at)` - Fast queries by event type with date sorting
- **idx_status:** Index on `status` - Fast filtering by status
- **idx_created_at:** Index on `created_at` - Fast date range queries
- **idx_created_by:** Index on `created_by` - Fast queries by user

### Foreign Key Relationships

**Note:** WordPress does not enforce foreign keys by default. The following relationships are logical:

- `project_id` → `wp_projects.id`
- `created_by` → `wp_users.ID`

---

## 2. CREATE TABLE: `wp_n88_project_videos`

### Complete SQL Schema

```sql
CREATE TABLE wp_n88_project_videos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NULL COMMENT 'Item index (0-based), NULL if project-level video',
    step_key VARCHAR(50) NULL COMMENT 'Timeline step key, NULL if item-level or project-level',
    youtube_id VARCHAR(20) NOT NULL COMMENT 'YouTube video ID (extracted from URL)',
    youtube_url VARCHAR(255) NOT NULL COMMENT 'Full YouTube URL (always youtube-nocookie.com)',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    thumbnail_attachment_id BIGINT UNSIGNED NULL COMMENT 'WP Media Library attachment ID for custom thumbnail',
    display_order INT UNSIGNED DEFAULT 0 COMMENT 'Order within step/item/project',
    created_at DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project (project_id),
    KEY idx_item (item_id),
    KEY idx_step (step_key),
    KEY idx_project_item_step (project_id, item_id, step_key),
    KEY idx_youtube_id (youtube_id),
    KEY idx_created_at (created_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Column Descriptions

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `project_id` | BIGINT UNSIGNED | Links to `wp_projects.id` (required) |
| `item_id` | INT UNSIGNED | Item index (0-based), NULL = project-level video |
| `step_key` | VARCHAR(50) | Timeline step key, NULL = item-level or project-level video |
| `youtube_id` | VARCHAR(20) | Extracted YouTube video ID (e.g., `dQw4w9WgXcQ`) |
| `youtube_url` | VARCHAR(255) | Full YouTube URL, always in `youtube-nocookie.com` format |
| `title` | VARCHAR(255) | Video title |
| `description` | TEXT | Video description (nullable) |
| `thumbnail_attachment_id` | BIGINT UNSIGNED | WordPress Media Library attachment ID for custom thumbnail (nullable) |
| `display_order` | INT UNSIGNED | Order within step/item/project (default: 0) |
| `created_at` | DATETIME | When video was added |
| `created_by` | BIGINT UNSIGNED | User ID who added the video (nullable) |
| `updated_at` | DATETIME | Last update timestamp |

### Indexes

- **PRIMARY KEY:** `id` - Unique identifier
- **idx_project:** Index on `project_id` - Fast queries for all videos in a project
- **idx_item:** Index on `item_id` - Fast queries for item-level videos
- **idx_step:** Index on `step_key` - Fast queries for step-level videos
- **idx_project_item_step:** Composite index on `(project_id, item_id, step_key)` - Fast filtering by all three dimensions
- **idx_youtube_id:** Index on `youtube_id` - Fast deduplication checks
- **idx_created_at:** Index on `created_at` - Fast date sorting

### Video Linking Logic

**Project-Level Video:**
- `project_id` = [project ID]
- `item_id` = NULL
- `step_key` = NULL

**Item-Level Video:**
- `project_id` = [project ID]
- `item_id` = [item index, 0-based]
- `step_key` = NULL

**Step-Level Video:**
- `project_id` = [project ID]
- `item_id` = [item index, 0-based]
- `step_key` = [step key, e.g., `"prototype"`]

---

## 3. Timeline Structure in Item JSON

### Storage Location

The `timeline_structure` is stored as a field within each item object in the `n88_repeater_raw` JSON array, which is stored in the `wp_project_metadata` table with `meta_key = 'n88_repeater_raw'`.

### No ALTER TABLE Required

**Important:** No database schema change is needed. The `timeline_structure` is added programmatically to the item JSON structure in PHP code.

### Migration Approach

1. When loading items, check if `timeline_structure` exists in the item JSON
2. If missing, auto-assign based on `product_category` (or project-level `sourcing_category` as fallback)
3. Save the generated `timeline_structure` back to the item JSON
4. This happens automatically via `N88_RFQ_Timeline::ensure_item_timeline()`

### Default JSON Structure

See `02-json-structure-definition.md` for complete JSON structure examples.

---

## 4. Implementation Notes

### Table Creation

Both tables are created in `includes/class-n88-rfq-installer.php`:
- In `activate()` method (on plugin activation)
- In `maybe_upgrade()` method (on plugin update without reactivation)

### Charset and Collation

All tables use:
- **Charset:** `utf8mb4`
- **Collation:** `utf8mb4_unicode_ci`

This ensures full Unicode support including emojis and special characters.

### WordPress Compatibility

- Uses `$wpdb->prefix` for table name prefix
- Uses `dbDelta()` for safe table creation/updates
- Follows WordPress database naming conventions

---

## 5. Future Considerations

### Phase 4 & 5 Requirements

These tables are designed to support:
- **Phase 4:** Pricing & optimization engine (will query event data)
- **Phase 5:** Analytics & insights (will aggregate event data)

### Performance Optimization

Indexes are optimized for:
- Filtering by project, item, step
- Date range queries
- Event type filtering
- Status filtering
- User activity tracking

### Scalability

- `event_data` is JSON (flexible, but consider normalization for very high volume)
- Indexes support efficient queries even with millions of events
- Consider partitioning by `created_at` for very large datasets (future enhancement)

---

**Document Status:** ✅ Complete  
**Last Updated:** December 2025  
**Maintained By:** N88 Development Team

