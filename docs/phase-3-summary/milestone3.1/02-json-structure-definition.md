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

### Mutable Fields (Can Change)

- `current_status` - Updated when events occur
- `started_at` - Set when step is started
- `completed_at` - Set when step is completed
- `completed_by` - Set when step is completed
- `admin_notes` - Can be edited by admins
- `actual_days` - Calculated when step completes
- `is_locked` - Updated based on dependency status
- `locked_reason` - Updated when lock status changes
- `total_actual_days` - Calculated when all steps complete
- `started_at` (root level) - Set when first step starts
- `completed_at` (root level) - Set when last step completes

### Stable Fields (Must Remain Constant for Analytics)

- `timeline_type` - **DO NOT CHANGE** (used for analytics grouping)
- `assigned_at` - **DO NOT CHANGE** (historical record)
- `assigned_by_category` - **DO NOT CHANGE** (historical record)
- `step_key` - **DO NOT CHANGE** (used for event linking)
- `label` - **DO NOT CHANGE** (used for reporting)
- `order` - **DO NOT CHANGE** (used for sequencing)
- `description` - **DO NOT CHANGE** (used for documentation)
- `icon` - **DO NOT CHANGE** (used for UI consistency)
- `estimated_days` - **DO NOT CHANGE** (used for comparison with actual_days)

---

## Where Video/File References Will Be Inserted Later

### Phase 3.3+ Enhancements

**Videos:**
- Videos are stored in `wp_n88_project_videos` table
- Linked via `project_id`, `item_id`, and `step_key`
- No direct video IDs stored in `timeline_structure` JSON (to avoid duplication)
- Frontend queries videos table separately and groups by step

**Files:**
- Files are stored in WordPress Media Library or custom uploads directory
- File metadata stored in `wp_n88_timeline_events` table with `event_type = 'file_added'`
- No direct file IDs stored in `timeline_structure` JSON (to avoid duplication)
- Frontend queries events table for file references

**Future Consideration:**
- If needed, we could add `video_ids` and `file_ids` arrays to each step object
- This would require migration script for existing data
- Currently, querying separate tables is preferred for data integrity

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

