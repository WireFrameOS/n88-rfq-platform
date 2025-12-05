# Event Types Enumeration
## Phase 3 - Milestone 3.1

**Purpose:** Enables Phase 5 analytics and Phase 4 automation.

**Date:** December 2025  
**Version:** 1.0

---

## Overview

All timeline events are logged to the `wp_n88_timeline_events` table. Each event has an `event_type` that categorizes the action. This document defines all event types and their required data structures.

---

## Minimum Required Event Types

### Core Step Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `step_started` | `EVENT_STEP_STARTED` | Step marked as in progress | User/admin starts a step |
| `step_completed` | `EVENT_STEP_COMPLETED` | Step marked as completed | User/admin completes a step |
| `step_reopened` | `EVENT_STEP_REOPENED` | Completed step reopened | User/admin reopens a completed step |
| `step_overridden` | `EVENT_OVERRIDE_APPLIED` | Admin force-completed step out of order | Admin uses "Force Complete" |

### Status Change Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `step_delayed` | `EVENT_STEP_DELAYED` | Step marked as delayed | User/admin marks step as delayed |
| `step_unblocked` | `EVENT_STEP_UNBLOCKED` | Blocked step unblocked | User/admin unblocks a step |
| `status_changed` | `EVENT_STATUS_CHANGED` | Generic status change | Any status change not covered above |

### Content Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `video_added` | `EVENT_VIDEO_ADDED` | Video assigned to step | User/admin assigns video to step |
| `video_removed` | `EVENT_VIDEO_REMOVED` | Video removed from step | User/admin removes video from step |
| `file_added` | `EVENT_FILE_ADDED` | File uploaded to step | User/admin uploads file to step |
| `file_removed` | `EVENT_FILE_REMOVED` | File deleted from step | User/admin deletes file from step |
| `comment_added` | `EVENT_COMMENT_ADDED` | Comment added to step | User/admin adds comment to step |
| `comment_removed` | `EVENT_COMMENT_REMOVED` | Comment deleted from step | User/admin deletes comment |

### Admin Notes Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `note_added` | `EVENT_NOTE_ADDED` | Admin note added to step | Admin adds note to step |
| `note_updated` | `EVENT_NOTE_UPDATED` | Admin note updated | Admin updates note |

### Dependency Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `dependency_unlocked` | `EVENT_DEPENDENCY_UNLOCKED` | Step unlocked due to dependency completion | Previous step completed, unlocking this step |
| `dependency_locked` | `EVENT_DEPENDENCY_LOCKED` | Step locked due to dependency | Previous step reopened/delayed, locking this step |

### Special Events

| Event Type | Constant | Description | When Triggered |
|------------|----------|-------------|----------------|
| `out_of_sequence` | `EVENT_OUT_OF_SEQUENCE` | Steps done in wrong order | Step completed before previous step |

---

## Event Data Structure

All events store additional data in the `event_data` column as JSON. Below are the required fields for each event type.

### `step_started`

```json
{
    "old_status": "pending",
    "new_status": "in_progress"
}
```

**Required Fields:**
- `old_status` (string): Previous status
- `new_status` (string): New status (always `"in_progress"`)

---

### `step_completed`

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

**Required Fields:**
- `old_status` (string): Previous status
- `new_status` (string): New status (always `"completed"`)
- `completed_by` (integer): User ID who completed the step

**Optional Fields:**
- `completion_method` (string): `"normal"` or `"override"`
- `duration_days` (float): Actual duration in days
- `notes` (string): Additional notes

---

### `step_reopened`

```json
{
    "old_status": "completed",
    "new_status": "in_progress",
    "reason": "Quality issue found, needs rework"
}
```

**Required Fields:**
- `old_status` (string): Previous status (usually `"completed"`)
- `new_status` (string): New status (usually `"in_progress"` or `"pending"`)
- `reason` (string): Reason for reopening

---

### `override_applied` (step_overridden)

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

**Required Fields:**
- `old_status` (string): Previous status
- `new_status` (string): New status (usually `"completed"`)
- `override_reason` (string): Reason for override
- `previous_step_status` (object): Status of all previous steps
- `override_by` (integer): Admin user ID
- `admin_notes` (string): Admin notes about the override

**Optional Fields:**
- `approval_required` (boolean): Whether approval was required

---

### `step_delayed`

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

**Required Fields:**
- `old_status` (string): Previous status
- `new_status` (string): New status (always `"delayed"`)
- `reason` (string): Reason for delay
- `expected_completion_date` (string): ISO 8601 datetime

**Optional Fields:**
- `delay_days` (integer): Number of days delayed
- `notified_users` (array): Array of user IDs notified

---

### `step_unblocked`

```json
{
    "old_status": "blocked",
    "new_status": "pending",
    "reason": "Dependency completed, step is now available"
}
```

**Required Fields:**
- `old_status` (string): Previous status (usually `"blocked"`)
- `new_status` (string): New status (usually `"pending"`)
- `reason` (string): Reason for unblocking

---

### `file_added`

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

**Required Fields:**
- `file_id` (integer): File ID or attachment ID
- `file_name` (string): Original file name
- `file_type` (string): MIME type
- `file_size` (integer): File size in bytes
- `file_url` (string): File URL or path
- `uploaded_by` (integer): User ID who uploaded

---

### `file_removed`

```json
{
    "file_id": 456,
    "file_name": "prototype_photo_001.jpg",
    "removed_by": 123
}
```

**Required Fields:**
- `file_id` (integer): File ID that was removed
- `file_name` (string): File name that was removed
- `removed_by` (integer): User ID who removed the file

---

### `comment_added`

```json
{
    "comment_id": 789,
    "comment_preview": "The wood grain looks perfect. Please proceed with the same finish for all pieces.",
    "is_urgent": false,
    "comment_by": 456,
    "parent_comment_id": null
}
```

**Required Fields:**
- `comment_id` (integer): Comment ID
- `comment_preview` (string): First 100 characters of comment
- `is_urgent` (boolean): Whether comment is marked urgent
- `comment_by` (integer): User ID who added comment

**Optional Fields:**
- `parent_comment_id` (integer|null): Parent comment ID for threaded comments

---

### `comment_removed`

```json
{
    "comment_id": 789,
    "comment_preview": "The wood grain looks perfect..."
}
```

**Required Fields:**
- `comment_id` (integer): Comment ID that was removed
- `comment_preview` (string): Preview of removed comment

---

### `video_added`

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

**Required Fields:**
- `video_id` (integer): Video record ID from `wp_n88_project_videos`
- `youtube_id` (string): YouTube video ID
- `youtube_url` (string): Full YouTube URL (youtube-nocookie.com format)
- `video_title` (string): Video title
- `assigned_by` (integer): User ID who assigned the video

**Optional Fields:**
- `thumbnail_attachment_id` (integer): WordPress attachment ID for custom thumbnail

---

### `video_removed`

```json
{
    "video_id": 12,
    "youtube_id": "dQw4w9WgXcQ"
}
```

**Required Fields:**
- `video_id` (integer): Video record ID that was removed
- `youtube_id` (string): YouTube video ID

---

### `note_added`

```json
{
    "note_preview": "Factory confirmed materials are ready. Proceeding with production."
}
```

**Required Fields:**
- `note_preview` (string): First 100 characters of admin note

---

### `note_updated`

```json
{
    "old_note_preview": "Factory confirmed materials are ready.",
    "new_note_preview": "Factory confirmed materials are ready. Updated delivery date to Feb 20."
}
```

**Required Fields:**
- `old_note_preview` (string): Preview of old note
- `new_note_preview` (string): Preview of new note

---

### `status_changed`

```json
{
    "old_status": "in_progress",
    "new_status": "blocked",
    "reason": "Waiting for material delivery"
}
```

**Required Fields:**
- `old_status` (string): Previous status
- `new_status` (string): New status
- `reason` (string): Reason for status change

---

### `dependency_unlocked`

```json
{
    "unlocked_by_step_key": "prototype",
    "unlocked_by_step_order": 1
}
```

**Required Fields:**
- `unlocked_by_step_key` (string): Step key that was completed, unlocking this step
- `unlocked_by_step_order` (integer): Order of the step that was completed

---

### `dependency_locked`

```json
{
    "locked_by_step_key": "prototype",
    "locked_by_step_order": 1,
    "reason": "Previous step was reopened, locking dependent steps"
}
```

**Required Fields:**
- `locked_by_step_key` (string): Step key that was reopened/delayed, locking this step
- `locked_by_step_order` (integer): Order of the step that caused the lock
- `reason` (string): Reason for locking

---

### `out_of_sequence`

```json
{
    "step_key": "surface_treatment",
    "previous_steps_status": {
        "prototype": "completed",
        "frame_structure": "pending"
    },
    "warning": "Step completed out of sequence"
}
```

**Required Fields:**
- `step_key` (string): Step key that was completed out of sequence
- `previous_steps_status` (object): Status of all previous steps at time of completion
- `warning` (string): Warning message

---

## Implementation

### PHP Class

All event types are defined as constants in `N88_RFQ_Timeline_Events` class:

```php
class N88_RFQ_Timeline_Events {
    const EVENT_STEP_STARTED = 'step_started';
    const EVENT_STEP_COMPLETED = 'step_completed';
    // ... etc
}
```

### Logging Method

Events are logged using:

```php
N88_RFQ_Timeline_Events::log_event(
    $project_id,
    $item_id,
    $step_key,
    $event_type,
    $status,
    $event_data,
    $user_id
);
```

### Helper Methods

Convenience methods available:
- `log_step_started()`
- `log_step_completed()`
- `log_out_of_sequence()`

---

## Analytics Use Cases (Phase 5)

These event types enable:

1. **Bottleneck Detection:** Analyze `step_delayed` events to find common delays
2. **Completion Time Analysis:** Compare `estimated_days` vs `actual_days` from `step_completed` events
3. **Override Frequency:** Count `override_applied` events to identify workflow issues
4. **User Activity:** Track `created_by` across all events for user analytics
5. **Out-of-Sequence Analysis:** Analyze `out_of_sequence` events to improve workflow
6. **Content Engagement:** Track `video_added`, `file_added`, `comment_added` for engagement metrics

---

**Document Status:** ✅ Complete  
**Last Updated:** December 2025  
**Maintained By:** N88 Development Team

