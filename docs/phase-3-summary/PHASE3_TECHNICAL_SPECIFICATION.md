# Phase 3 Technical Specification - Database & Analytics Foundation

**Purpose:** This document defines the database schema, data structures, and event tracking system for Phase 3, ensuring Phase 5 analytics will work out of the box.

---

## 1. Database Schema

### 1.1 CREATE TABLE: `wp_n88_timeline_events`

```sql
CREATE TABLE wp_n88_timeline_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL COMMENT 'Item index within project (0-based)',
    step_key VARCHAR(50) NOT NULL COMMENT 'e.g. prototype, frame, surface_treatment, sourcing, qc, packing',
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

**Notes:**
- `item_id` is the 0-based index of the item within the project's `n88_repeater_raw` array
- `step_key` uses lowercase with underscores (e.g., `prototype`, `frame_structure`, `surface_treatment`)
- `event_data` is JSON for flexibility (can store file paths, comment text preview, video URLs, override reasons, etc.)
- Indexes optimized for Phase 5 analytics queries (filtering by project, item, step, event type, status, date ranges)

---

### 1.2 CREATE TABLE: `wp_n88_project_videos`

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

**Notes:**
- `item_id` and `step_key` can be NULL for project-level videos
- If `item_id` is set but `step_key` is NULL, video is item-level (not tied to a specific step)
- If both `item_id` and `step_key` are set, video is step-level
- `youtube_url` always uses `youtube-nocookie.com` format
- `thumbnail_attachment_id` links to WordPress Media Library attachment

---

### 1.3 ALTER TABLE: Add `timeline_structure` to Item JSON

**No ALTER TABLE needed.** The `timeline_structure` will be added as a new field within each item object in the existing `n88_repeater_raw` JSON stored in `wp_project_metadata` table.

**Current Item Structure (in `n88_repeater_raw` JSON):**
```json
{
    "length_in": 24.0,
    "depth_in": 26.0,
    "height_in": 42.0,
    "quantity": 1,
    "primary_material": "Oak Wood",
    "finishes": "Natural Stain",
    "construction_notes": "...",
    "notes": "...",
    "title": "Custom Sofa",
    "product_category": "Indoor Furniture"  // NEW: Added for Phase 3
}
```

**Updated Item Structure (with `timeline_structure`):**
```json
{
    "length_in": 24.0,
    "depth_in": 26.0,
    "height_in": 42.0,
    "quantity": 1,
    "primary_material": "Oak Wood",
    "finishes": "Natural Stain",
    "construction_notes": "...",
    "notes": "...",
    "title": "Custom Sofa",
    "product_category": "Indoor Furniture",
    "timeline_structure": { ... }  // NEW: See Section 2 for format
}
```

**Migration Approach:**
- When loading items, check if `timeline_structure` exists
- If missing, auto-assign based on `product_category` (or project-level `sourcing_category` as fallback)
- Save the generated `timeline_structure` back to the item JSON
- No manual ALTER TABLE required - handled in PHP code

---

## 2. `timeline_structure` JSON Format

### 2.1 Example: 6-Step Furniture Production Timeline

```json
{
    "timeline_type": "6step_furniture",
    "assigned_at": "2024-01-15T10:30:00Z",
    "assigned_by_category": "Indoor Furniture",
    "steps": [
        {
            "step_key": "prototype",
            "label": "Prototype",
            "order": 1,
            "description": "Initial prototype development and approval",
            "icon": "prototype-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 7,
            "actual_days": null,
            "is_locked": false,
            "locked_reason": null
        },
        {
            "step_key": "frame_structure",
            "label": "Frame / Structure",
            "order": 2,
            "description": "Frame construction and structural assembly",
            "icon": "frame-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 10,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 1 (Prototype) before starting this step"
        },
        {
            "step_key": "surface_treatment",
            "label": "Surface Treatment",
            "order": 3,
            "description": "Sanding, staining, finishing",
            "icon": "surface-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 5,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 2 (Frame / Structure) before starting this step"
        },
        {
            "step_key": "upholstery_fabrication",
            "label": "Upholstery / Fabrication",
            "order": 4,
            "description": "Fabric cutting, sewing, and upholstery work",
            "icon": "upholstery-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 8,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 3 (Surface Treatment) before starting this step"
        },
        {
            "step_key": "final_qc",
            "label": "Final QC",
            "order": 5,
            "description": "Quality control inspection and final approval",
            "icon": "qc-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 2,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 4 (Upholstery / Fabrication) before starting this step"
        },
        {
            "step_key": "packing_delivery",
            "label": "Packing & Delivery",
            "order": 6,
            "description": "Final packaging and shipping preparation",
            "icon": "packing-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 3,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 5 (Final QC) before starting this step"
        }
    ],
    "total_estimated_days": 35,
    "total_actual_days": null,
    "started_at": null,
    "completed_at": null
}
```

---

### 2.2 Example: 4-Step Global Sourcing Timeline

```json
{
    "timeline_type": "4step_sourcing",
    "assigned_at": "2024-01-15T10:30:00Z",
    "assigned_by_category": "Lighting",
    "steps": [
        {
            "step_key": "sourcing",
            "label": "Sourcing",
            "order": 1,
            "description": "Vendor identification and material sourcing",
            "icon": "sourcing-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 14,
            "actual_days": null,
            "is_locked": false,
            "locked_reason": null
        },
        {
            "step_key": "production_procurement",
            "label": "Production / Procurement",
            "order": 2,
            "description": "Manufacturing or procurement process",
            "icon": "production-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 21,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 1 (Sourcing) before starting this step"
        },
        {
            "step_key": "quality_check",
            "label": "Quality Check",
            "order": 3,
            "description": "Quality inspection and verification",
            "icon": "qc-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 3,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 2 (Production / Procurement) before starting this step"
        },
        {
            "step_key": "packing_delivery",
            "label": "Packing & Delivery",
            "order": 4,
            "description": "Final packaging and shipping preparation",
            "icon": "packing-icon",
            "current_status": "pending",
            "started_at": null,
            "completed_at": null,
            "completed_by": null,
            "admin_notes": "",
            "estimated_days": 5,
            "actual_days": null,
            "is_locked": true,
            "locked_reason": "Complete Step 3 (Quality Check) before starting this step"
        }
    ],
    "total_estimated_days": 43,
    "total_actual_days": null,
    "started_at": null,
    "completed_at": null
}
```

---

### 2.3 Fields in `timeline_structure` JSON

**Root Level:**
- `timeline_type` (string): `"6step_furniture"`, `"4step_sourcing"`, or `"none"`
- `assigned_at` (ISO 8601 datetime): When timeline was assigned
- `assigned_by_category` (string): Product category that triggered this assignment
- `steps` (array): Array of step objects
- `total_estimated_days` (integer): Sum of all step `estimated_days`
- `total_actual_days` (integer|null): Sum of all step `actual_days` (calculated when all steps complete)
- `started_at` (ISO 8601 datetime|null): When first step was started
- `completed_at` (ISO 8601 datetime|null): When last step was completed

**Step Object:**
- `step_key` (string): Unique identifier (e.g., `"prototype"`, `"frame_structure"`, `"sourcing"`)
- `label` (string): Display name (e.g., `"Prototype"`, `"Frame / Structure"`)
- `order` (integer): Sequential order (1-6 for furniture, 1-4 for sourcing)
- `description` (string): Step description text
- `icon` (string): Icon identifier for UI
- `current_status` (string): `"pending"`, `"in_progress"`, `"completed"`, `"blocked"`, `"delayed"`
- `started_at` (ISO 8601 datetime|null): When step was started
- `completed_at` (ISO 8601 datetime|null): When step was completed
- `completed_by` (integer|null): User ID who completed the step
- `admin_notes` (string): Admin-only notes for this step
- `estimated_days` (integer): Estimated duration in days
- `actual_days` (integer|null): Actual duration (calculated from `started_at` to `completed_at`)
- `is_locked` (boolean): Whether step is locked due to dependencies
- `locked_reason` (string|null): Reason for lock (e.g., "Complete Step X before starting this step")

**Note:** The `current_status` in JSON is a cached value. The source of truth for current status is the latest event in `wp_n88_timeline_events` table. The JSON `current_status` is updated when events are logged for performance (to avoid querying events table on every page load).

---

## 3. `event_type` Enumeration for `wp_n88_timeline_events`

### 3.1 Complete Event Type List

| Event Type | Description | When Triggered | Required `event_data` Fields |
|------------|-------------|----------------|------------------------------|
| `step_started` | Step marked as in progress | User/admin starts a step | `old_status`, `new_status` |
| `step_completed` | Step marked as completed | User/admin completes a step | `old_status`, `new_status`, `completed_by` |
| `step_reopened` | Completed step reopened | User/admin reopens a completed step | `old_status`, `new_status`, `reason` |
| `step_delayed` | Step marked as delayed | User/admin marks step as delayed | `old_status`, `new_status`, `reason`, `expected_completion_date` |
| `step_unblocked` | Blocked step unblocked | User/admin unblocks a step | `old_status`, `new_status`, `reason` |
| `override_applied` | Admin force-completed step out of order | Admin uses "Force Complete" | `old_status`, `new_status`, `override_reason`, `previous_step_status`, `admin_notes` |
| `file_added` | File uploaded to step | User/admin uploads file to step | `file_id`, `file_name`, `file_type`, `file_size`, `file_url` |
| `file_removed` | File deleted from step | User/admin deletes file from step | `file_id`, `file_name`, `removed_by` |
| `comment_added` | Comment added to step | User/admin adds comment to step | `comment_id`, `comment_preview` (first 100 chars), `is_urgent` |
| `comment_removed` | Comment deleted from step | User/admin deletes comment | `comment_id`, `comment_preview` |
| `video_added` | Video assigned to step | User/admin assigns video to step | `video_id`, `youtube_id`, `video_title` |
| `video_removed` | Video removed from step | User/admin removes video from step | `video_id`, `youtube_id` |
| `note_added` | Admin note added to step | Admin adds note to step | `note_preview` (first 100 chars) |
| `note_updated` | Admin note updated | Admin updates note | `old_note_preview`, `new_note_preview` |
| `status_changed` | Generic status change | Any status change not covered above | `old_status`, `new_status`, `reason` |
| `dependency_unlocked` | Step unlocked due to dependency completion | Previous step completed, unlocking this step | `unlocked_by_step_key`, `unlocked_by_step_order` |
| `dependency_locked` | Step locked due to dependency | Previous step reopened/delayed, locking this step | `locked_by_step_key`, `locked_by_step_order`, `reason` |

---

### 3.2 `event_data` JSON Structure Examples

**Example: `step_completed` event:**
```json
{
    "old_status": "in_progress",
    "new_status": "completed",
    "completed_by": 123,
    "completion_method": "normal",
    "duration_days": 8.5,
    "notes": "Step completed on schedule"
}
```

**Example: `override_applied` event:**
```json
{
    "old_status": "pending",
    "new_status": "completed",
    "override_reason": "Previous step delayed due to material shortage, but this step can proceed independently",
    "previous_step_status": {
        "prototype": "in_progress",
        "frame_structure": "pending"
    },
    "admin_notes": "Factory confirmed frame materials are ready, proceeding with surface treatment",
    "override_by": 1,
    "approval_required": false
}
```

**Example: `file_added` event:**
```json
{
    "file_id": 456,
    "file_name": "prototype_photo_001.jpg",
    "file_type": "image/jpeg",
    "file_size": 2456789,
    "file_url": "/wp-content/uploads/2024/01/prototype_photo_001.jpg",
    "uploaded_by": 123
}
```

**Example: `comment_added` event:**
```json
{
    "comment_id": 789,
    "comment_preview": "The wood grain looks perfect. Please proceed with the same finish for all pieces.",
    "is_urgent": false,
    "comment_by": 456,
    "parent_comment_id": null
}
```

**Example: `video_added` event:**
```json
{
    "video_id": 12,
    "youtube_id": "dQw4w9WgXcQ",
    "youtube_url": "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    "video_title": "Prototype Assembly Process",
    "thumbnail_attachment_id": 234,
    "assigned_by": 123
}
```

**Example: `step_delayed` event:**
```json
{
    "old_status": "in_progress",
    "new_status": "delayed",
    "reason": "Material shipment delayed by 3 days",
    "expected_completion_date": "2024-02-15T17:00:00Z",
    "delay_days": 3,
    "notified_users": [123, 456]
}
```

---

## 4. YouTube / Video Integration Plan

### 4.1 Video Storage Architecture

**Table:** `wp_n88_project_videos`

**Columns:**
- `id` (BIGINT): Primary key
- `project_id` (BIGINT): Links to `wp_projects.id`
- `item_id` (INT, nullable): Item index (0-based) within project's `n88_repeater_raw` array. NULL = project-level video
- `step_key` (VARCHAR(50), nullable): Timeline step key (e.g., `"prototype"`, `"frame_structure"`). NULL = item-level or project-level video
- `youtube_id` (VARCHAR(20)): Extracted YouTube video ID (e.g., `"dQw4w9WgXcQ"`)
- `youtube_url` (VARCHAR(255)): Full YouTube URL, always in `youtube-nocookie.com` format
- `title` (VARCHAR(255)): Video title
- `description` (TEXT): Video description
- `thumbnail_attachment_id` (BIGINT, nullable): WordPress Media Library attachment ID for custom thumbnail
- `display_order` (INT): Order within step/item/project (for sorting)
- `created_at` (DATETIME): When video was added
- `created_by` (BIGINT, nullable): User ID who added the video
- `updated_at` (DATETIME): Last update timestamp

---

### 4.2 Video Assignment Logic

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

### 4.3 Thumbnail Storage

**Primary Method:** WordPress Media Library
- When admin uploads custom thumbnail, create WordPress attachment via `wp_insert_attachment()`
- Store attachment ID in `thumbnail_attachment_id` column
- Retrieve thumbnail URL via `wp_get_attachment_image_url( $thumbnail_attachment_id, 'medium' )`

**Fallback Method:** YouTube Default Thumbnail
- If `thumbnail_attachment_id` is NULL, use YouTube's default thumbnail
- URL format: `https://img.youtube.com/vi/{youtube_id}/maxresdefault.jpg`
- Fallback to `hqdefault.jpg` if `maxresdefault.jpg` doesn't exist

**Thumbnail Selection Logic:**
```php
if ( ! empty( $video->thumbnail_attachment_id ) ) {
    $thumbnail_url = wp_get_attachment_image_url( $video->thumbnail_attachment_id, 'medium' );
} else {
    $thumbnail_url = "https://img.youtube.com/vi/{$video->youtube_id}/maxresdefault.jpg";
}
```

---

### 4.4 Video Link Representation

**Querying Videos:**

1. **Get all videos for a project:**
   ```sql
   SELECT * FROM wp_n88_project_videos 
   WHERE project_id = %d 
   ORDER BY display_order ASC
   ```

2. **Get all videos for an item:**
   ```sql
   SELECT * FROM wp_n88_project_videos 
   WHERE project_id = %d AND item_id = %d AND step_key IS NULL
   ORDER BY display_order ASC
   ```

3. **Get all videos for a specific step:**
   ```sql
   SELECT * FROM wp_n88_project_videos 
   WHERE project_id = %d AND item_id = %d AND step_key = %s
   ORDER BY display_order ASC
   ```

4. **Get project-level videos:**
   ```sql
   SELECT * FROM wp_n88_project_videos 
   WHERE project_id = %d AND item_id IS NULL AND step_key IS NULL
   ORDER BY display_order ASC
   ```

**Index Strategy:**
- Composite index on `(project_id, item_id, step_key)` for fast filtering
- Separate indexes on `project_id`, `item_id`, `step_key` for individual queries
- Index on `youtube_id` for deduplication checks

---

### 4.5 YouTube URL Processing

**Input:** User provides YouTube URL in any format:
- `https://www.youtube.com/watch?v=dQw4w9WgXcQ`
- `https://youtu.be/dQw4w9WgXcQ`
- `https://www.youtube.com/embed/dQw4w9WgXcQ`
- `dQw4w9WgXcQ` (just the ID)

**Processing:**
1. Extract YouTube ID using regex: `/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/`
2. Store extracted ID in `youtube_id` column
3. Always convert to `youtube-nocookie.com` format for storage:
   - Stored URL: `https://www.youtube-nocookie.com/embed/{youtube_id}`
4. For display, use lazy-loading iframe:
   ```html
   <iframe 
       src="https://www.youtube-nocookie.com/embed/{youtube_id}?enablejsapi=1" 
       loading="lazy"
       frameborder="0"
       allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
       allowfullscreen>
   </iframe>
   ```

---

## Summary

This specification ensures:

1. ✅ **Complete event tracking** from day one (all state changes logged)
2. ✅ **Flexible JSON structure** for timeline definitions (easy to modify without schema changes)
3. ✅ **Comprehensive event types** covering all Phase 3 actions (ready for Phase 5 analytics)
4. ✅ **Efficient video storage** with proper indexing and WordPress Media Library integration
5. ✅ **Analytics-ready queries** with optimized indexes for Phase 5 reporting

**Next Steps:**
- Confirm this specification
- Begin Phase 3 implementation using this as the locked source of truth
- All Phase 3 code will reference this document for data structure decisions

