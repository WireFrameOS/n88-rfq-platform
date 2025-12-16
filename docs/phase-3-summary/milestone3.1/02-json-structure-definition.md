# JSON Structure Definition (`timeline_structure`)
## Phase 3 - Milestone 3.1

**Purpose:** Permanent contract between backend and frontend.

**Date:** December 2025  
**Version:** 1.0

---

## Overview

The `timeline_structure` is stored as a field within each item object in the `n88_repeater_raw` JSON array. Each item can have a different timeline type (6-step furniture, 4-step sourcing, or none).

---

## Root Level Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `timeline_type` | string | Yes | `"6step_furniture"`, `"4step_sourcing"`, or `"none"` |
| `assigned_at` | string (ISO 8601) | Yes | When timeline was assigned (e.g., `"2024-01-15T10:30:00Z"`) |
| `assigned_by_category` | string | Yes | Product category that triggered this assignment |
| `steps` | array | Yes | Array of step objects (empty array for `"none"` type) |
| `total_estimated_days` | integer | Yes | Sum of all step `estimated_days` |
| `total_actual_days` | integer\|null | Yes | Sum of all step `actual_days` (calculated when all steps complete) |
| `started_at` | string\|null (ISO 8601) | Yes | When first step was started (null if not started) |
| `completed_at` | string\|null (ISO 8601) | Yes | When last step was completed (null if not completed) |

---

## Step Object Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `step_key` | string | Yes | Unique identifier (e.g., `"prototype"`, `"frame_structure"`, `"sourcing"`) |
| `label` | string | Yes | Display name (e.g., `"Prototype"`, `"Frame / Structure"`) |
| `order` | integer | Yes | Sequential order (1-6 for furniture, 1-4 for sourcing) |
| `description` | string | Yes | Step description text |
| `icon` | string | Yes | Icon identifier for UI (e.g., `"prototype-icon"`) |
| `current_status` | string | Yes | `"pending"`, `"in_progress"`, `"completed"`, `"blocked"`, `"delayed"` |
| `started_at` | string\|null (ISO 8601) | Yes | When step was started (null if not started) |
| `completed_at` | string\|null (ISO 8601) | Yes | When step was completed (null if not completed) |
| `completed_by` | integer\|null | Yes | User ID who completed the step (null if not completed) |
| `admin_notes` | string | Yes | Admin-only notes for this step (empty string if none) |
| `estimated_days` | integer | Yes | Estimated duration in days |
| `actual_days` | integer\|null | Yes | Actual duration (calculated from `started_at` to `completed_at`) |
| `is_locked` | boolean | Yes | Whether step is locked due to dependencies |
| `locked_reason` | string\|null | Yes | Reason for lock (e.g., "Complete Step X before starting this step") |

**Note:** The `current_status` in JSON is a cached value. The source of truth for current status is the latest event in `wp_n88_timeline_events` table. The JSON `current_status` is updated when events are logged for performance (to avoid querying events table on every page load).

---

## 6-Step Furniture Production Timeline

### Complete Example

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

### Step Keys (6-Step Furniture)

1. `prototype` - Prototype
2. `frame_structure` - Frame / Structure
3. `surface_treatment` - Surface Treatment
4. `upholstery_fabrication` - Upholstery / Fabrication
5. `final_qc` - Final QC
6. `packing_delivery` - Packing & Delivery

---

## 4-Step Global Sourcing Timeline

### Complete Example

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

### Step Keys (4-Step Sourcing)

1. `sourcing` - Sourcing
2. `production_procurement` - Production / Procurement
3. `quality_check` - Quality Check
4. `packing_delivery` - Packing & Delivery

---

## No Timeline Structure

### Example

```json
{
    "timeline_type": "none",
    "assigned_at": "2024-01-15T10:30:00Z",
    "assigned_by_category": "Material Sample Kit",
    "steps": [],
    "total_estimated_days": 0,
    "total_actual_days": null,
    "started_at": null,
    "completed_at": null
}
```

---

## Fields That May Change in Future

### Mutable Fields (Can Change During Timeline Lifecycle)

These fields are updated as the timeline progresses through its lifecycle. They represent the current state and can be modified by user actions or system calculations.

**Root Level Mutable Fields:**
- `total_actual_days` - Calculated when all steps complete (sum of all step `actual_days`)
- `started_at` - Set when first step is started (null until first step begins)
- `completed_at` - Set when last step is completed (null until all steps finish)

**Step Level Mutable Fields:**
- `current_status` - Updated when events occur (`"pending"` → `"in_progress"` → `"completed"`, or `"blocked"`/`"delayed"`)
- `started_at` - Set when step is started (null until step begins)
- `completed_at` - Set when step is completed (null until step finishes)
- `completed_by` - User ID who completed the step (null until step is completed)
- `admin_notes` - Can be edited by admins at any time (empty string if no notes)
- `actual_days` - Calculated when step completes (difference between `started_at` and `completed_at`)
- `is_locked` - Updated based on dependency status (true if previous step not completed)
- `locked_reason` - Updated when lock status changes (null if step is unlocked)

**Important:** These fields are the **runtime state** of the timeline. They change as work progresses, but the underlying structure (step keys, labels, order) remains constant.

---

### Stable Fields (Must Remain Constant for Analytics)

**⚠️ CRITICAL:** These fields **MUST NEVER CHANGE** after initial assignment. They are used for:
- Analytics queries and reporting
- Historical data integrity
- Event linking and correlation
- Timeline type identification
- Performance metrics calculation

**Root Level Stable Fields:**
- `timeline_type` - **DO NOT CHANGE** 
  - Used for analytics grouping (e.g., "average completion time for 6-step furniture vs 4-step sourcing")
  - Values: `"6step_furniture"`, `"4step_sourcing"`, or `"none"`
  - Changing this would break historical analytics comparisons

- `assigned_at` - **DO NOT CHANGE**
  - Historical record of when timeline was assigned
  - Used for time-based analytics (e.g., "timelines assigned in Q1 2024")
  - ISO 8601 datetime string

- `assigned_by_category` - **DO NOT CHANGE**
  - Product category that triggered this timeline assignment
  - Used for category-based analytics (e.g., "Indoor Furniture vs Outdoor Furniture completion rates")
  - Historical record of assignment context

**Step Level Stable Fields:**
- `step_key` - **DO NOT CHANGE**
  - Unique identifier for the step (e.g., `"prototype"`, `"frame_structure"`, `"sourcing"`)
  - Used for event linking (events reference `step_key` to associate with steps)
  - Used for analytics queries (e.g., "average time for prototype step across all projects")
  - Changing this would break event associations and historical analytics

- `label` - **DO NOT CHANGE**
  - Display name for the step (e.g., `"Prototype"`, `"Frame / Structure"`)
  - Used for reporting and UI display
  - Changing this would break historical reports that reference step names

- `order` - **DO NOT CHANGE**
  - Sequential order (1-6 for furniture, 1-4 for sourcing)
  - Used for sequencing and dependency logic
  - Used for analytics (e.g., "step 1 vs step 2 completion times")
  - Changing this would break step dependencies and sequencing logic

- `description` - **DO NOT CHANGE**
  - Step description text
  - Used for documentation and UI tooltips
  - Historical record of step purpose

- `icon` - **DO NOT CHANGE**
  - Icon identifier for UI (e.g., `"prototype-icon"`, `"frame-icon"`)
  - Used for UI consistency
  - Changing this would break visual consistency in historical views

- `estimated_days` - **DO NOT CHANGE**
  - Estimated duration in days
  - Used for comparison with `actual_days` (variance analysis)
  - Used for analytics (e.g., "estimated vs actual completion time")
  - Changing this would break historical variance calculations

**Analytics Impact:**
If any stable field is changed after initial assignment:
- Historical analytics queries will produce incorrect results
- Event linking may break (if `step_key` changes)
- Time-based comparisons will be invalid (if `estimated_days` changes)
- Category-based grouping will be incorrect (if `assigned_by_category` changes)
- Historical reports may reference non-existent step names (if `label` changes)

**Recommendation:** If a stable field needs to be changed for future timelines, create a new timeline type or version rather than modifying existing data.

---

## Where Video/File References Will Be Inserted Later

### Phase 3.3+ Enhancements

**Important:** Video and file references are **NOT** stored directly in the `timeline_structure` JSON. They are stored in separate database tables and linked via `project_id`, `item_id`, and `step_key`. This design prevents data duplication and maintains referential integrity.

---

### Video Storage Architecture

**Table:** `wp_n88_project_videos`

**Linking Strategy:**
- Videos are linked to steps using: `project_id` + `item_id` + `step_key`
- `item_id` is the 0-based index of the item in the `n88_repeater_raw` array
- `step_key` matches the `step_key` field in the timeline structure (e.g., `"prototype"`, `"frame_structure"`)
- If `step_key` is NULL, video is item-level (not tied to a specific step)
- If both `item_id` and `step_key` are NULL, video is project-level

**Example Query Pattern:**
```sql
-- Get all videos for a specific step
SELECT * FROM wp_n88_project_videos 
WHERE project_id = 123 
  AND item_id = 0 
  AND step_key = 'prototype'
ORDER BY display_order;
```

**Video Data Structure:**
- `youtube_id` - YouTube video ID (e.g., `"dQw4w9WgXcQ"`)
- `youtube_url` - Full embed URL (always `youtube-nocookie.com` format)
- `title` - Video title
- `description` - Video description
- `thumbnail_attachment_id` - WordPress Media Library attachment ID for custom thumbnail
- `display_order` - Order within step/item/project

**Frontend Integration:**
- Frontend queries `wp_n88_project_videos` table separately
- Groups videos by `step_key` to display in timeline UI
- No direct video IDs stored in `timeline_structure` JSON

---

### File Storage Architecture

**Table:** `wp_n88_timeline_events` (with `event_type = 'file_added'`)

**Linking Strategy:**
- Files are linked via timeline events
- Event contains: `project_id`, `item_id`, `step_key`, and `event_data` JSON
- `event_data` includes file metadata (file_id, file_name, file_url, etc.)

**Example Event Data Structure:**
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

**File Storage:**
- Files are stored in WordPress Media Library or custom uploads directory
- File metadata stored in `wp_n88_timeline_events` table with `event_type = 'file_added'`
- No direct file IDs stored in `timeline_structure` JSON

**Frontend Integration:**
- Frontend queries `wp_n88_timeline_events` table for file references
- Filters by `event_type = 'file_added'` and groups by `step_key`
- Displays files in timeline UI alongside step information

---

### Future Enhancement Consideration

**Option 1: Keep Current Design (Recommended)**
- Continue querying separate tables
- Maintains data integrity
- Avoids JSON duplication
- Easier to query and filter videos/files independently

**Option 2: Add Reference Arrays to JSON (If Needed)**
- Could add `video_ids` and `file_ids` arrays to each step object:
```json
{
    "step_key": "prototype",
    "label": "Prototype",
    // ... other fields ...
    "video_ids": [12, 13, 14],
    "file_ids": [456, 457, 458]
}
```
- **Trade-offs:**
  - Requires migration script for existing data
  - Creates data duplication (IDs stored in both JSON and tables)
  - Easier frontend access (no separate query needed)
  - Risk of data inconsistency if not kept in sync

**Current Recommendation:** Keep current design (Option 1) for Phase 3.3+. Re-evaluate in Phase 4+ if performance becomes an issue.

---

## Validation Rules

### Required Validations

1. **timeline_type:** Must be one of: `"6step_furniture"`, `"4step_sourcing"`, `"none"`
2. **assigned_at:** Must be valid ISO 8601 datetime string
3. **steps array:** 
   - For `6step_furniture`: Must have exactly 6 steps
   - For `4step_sourcing`: Must have exactly 4 steps
   - For `none`: Must be empty array
4. **step order:** Must be sequential (1, 2, 3, ...) with no gaps
5. **step_key:** Must be unique within the steps array
6. **current_status:** Must be one of: `"pending"`, `"in_progress"`, `"completed"`, `"blocked"`, `"delayed"`
7. **total_estimated_days:** Must equal sum of all step `estimated_days`

---

## Backend Generation

The timeline structure is generated by:
- **Class:** `N88_RFQ_Timeline`
- **Method:** `generate_timeline_structure( $timeline_type, $assigned_by_category )`
- **Auto-assignment:** `assign_timeline_type( $product_category, $sourcing_category )`
- **Item integration:** `ensure_item_timeline( $item, $sourcing_category )`

---

**Document Status:** ✅ Complete  
**Last Updated:** December 2025  
**Maintained By:** N88 Development Team

