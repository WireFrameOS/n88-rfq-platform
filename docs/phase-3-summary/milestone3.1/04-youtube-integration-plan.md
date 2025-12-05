# YouTube Integration Plan
## Phase 3 - Milestone 3.1

**Purpose:** Ensures consistency across all future video features.

**Date:** December 2025  
**Version:** 1.0

---

## Overview

All videos in the N88 RFQ Platform use YouTube as the hosting service. Videos are embedded using `youtube-nocookie.com` for privacy compliance, and thumbnails are stored in the WordPress Media Library with fallback to YouTube's default thumbnails.

---

## 1. Video Storage Architecture

### Database Table

**Table Name:** `wp_n88_project_videos`

**Primary Storage Location:** All video metadata is stored in this dedicated table.

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `youtube_id` | VARCHAR(20) | Extracted YouTube video ID (e.g., `dQw4w9WgXcQ`) |
| `youtube_url` | VARCHAR(255) | Full YouTube URL, **always in `youtube-nocookie.com` format** |
| `thumbnail_attachment_id` | BIGINT UNSIGNED | WordPress Media Library attachment ID for custom thumbnail (nullable) |

**See:** `01-database-schema-review.md` for complete table schema.

---

## 2. How URLs Are Stored

### Storage Format

**Field:** `youtube_url` in `wp_n88_project_videos` table

**Format:** Always stored as `https://www.youtube-nocookie.com/embed/{YOUTUBE_ID}`

**Example:**
```
https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ
```

### URL Processing

**Input:** User provides YouTube URL in any format:
- `https://www.youtube.com/watch?v=dQw4w9WgXcQ`
- `https://youtu.be/dQw4w9WgXcQ`
- `https://www.youtube.com/embed/dQw4w9WgXcQ`
- `dQw4w9WgXcQ` (just the ID)

**Processing Steps:**
1. Extract YouTube ID using regex: `/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/`
2. Store extracted ID in `youtube_id` column
3. Always convert to `youtube-nocookie.com` format for storage:
   - Stored URL: `https://www.youtube-nocookie.com/embed/{youtube_id}`

**Helper Function:**
```php
/**
 * Extract YouTube ID from any YouTube URL format
 * 
 * @param string $url YouTube URL or ID
 * @return string|false YouTube ID or false on failure
 */
function n88_extract_youtube_id( $url ) {
    // Implementation in N88_RFQ_Videos class
}

/**
 * Generate youtube-nocookie.com embed URL
 * 
 * @param string $youtube_id YouTube video ID
 * @return string Embed URL
 */
function n88_get_youtube_embed_url( $youtube_id ) {
    return "https://www.youtube-nocookie.com/embed/{$youtube_id}?rel=0&modestbranding=1";
}
```

---

## 3. How Thumbnails Are Stored

### Primary Method: WordPress Media Library

**Storage:**
- Thumbnails are stored as standard WordPress attachments (Media Library)
- Attachment ID is stored in `thumbnail_attachment_id` column
- Admins choose/upload thumbnails via the native WordPress media picker

**Retrieval:**
```php
if ( ! empty( $video->thumbnail_attachment_id ) ) {
    $thumbnail_url = wp_get_attachment_image_url( 
        $video->thumbnail_attachment_id, 
        'medium' 
    );
}
```

### Fallback Method: YouTube Default Thumbnail

**When Used:**
- If `thumbnail_attachment_id` is NULL or attachment doesn't exist
- Use YouTube's default thumbnail

**URL Format:**
```
https://img.youtube.com/vi/{youtube_id}/maxresdefault.jpg
```

**Fallback Chain:**
1. Try `maxresdefault.jpg` (highest quality)
2. If not available, fall back to `hqdefault.jpg`
3. If not available, fall back to `mqdefault.jpg`

**Helper Function:**
```php
/**
 * Get video thumbnail URL with fallback
 * 
 * @param object $video Video object from database
 * @return string Thumbnail URL
 */
function n88_get_video_thumbnail_url( $video ) {
    // Try WordPress Media Library first
    if ( ! empty( $video->thumbnail_attachment_id ) ) {
        $thumbnail_url = wp_get_attachment_image_url( 
            $video->thumbnail_attachment_id, 
            'medium' 
        );
        if ( $thumbnail_url ) {
            return $thumbnail_url;
        }
    }
    
    // Fallback to YouTube thumbnail
    $youtube_id = $video->youtube_id;
    return "https://img.youtube.com/vi/{$youtube_id}/maxresdefault.jpg";
}
```

---

## 4. All Metadata Fields

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `project_id` | BIGINT UNSIGNED | Links to `wp_projects.id` (required) |
| `youtube_id` | VARCHAR(20) | YouTube video ID (required) |
| `youtube_url` | VARCHAR(255) | Full YouTube URL (required) |
| `title` | VARCHAR(255) | Video title (required) |
| `created_at` | DATETIME | When video was added (required) |
| `updated_at` | DATETIME | Last update timestamp (required) |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `item_id` | INT UNSIGNED | Item index (0-based), NULL = project-level video |
| `step_key` | VARCHAR(50) | Timeline step key, NULL = item-level or project-level video |
| `description` | TEXT | Video description |
| `thumbnail_attachment_id` | BIGINT UNSIGNED | WordPress Media Library attachment ID |
| `display_order` | INT UNSIGNED | Order within step/item/project (default: 0) |
| `created_by` | BIGINT UNSIGNED | User ID who added the video |

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

## 5. Exact Embed Pattern

### HTML Embed Code

**For Display:**
```html
<iframe 
    src="https://www.youtube-nocookie.com/embed/{youtube_id}?rel=0&modestbranding=1&enablejsapi=1" 
    loading="lazy"
    frameborder="0"
    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
    allowfullscreen
    width="560"
    height="315">
</iframe>
```

### URL Parameters

**Required Parameters:**
- `rel=0` - Don't show related videos
- `modestbranding=1` - Minimal YouTube branding

**Optional Parameters:**
- `enablejsapi=1` - Enable JavaScript API (for lazy loading)
- `autoplay=1` - Autoplay (use sparingly)
- `start={seconds}` - Start at specific time

### Lazy-Loading Implementation

**Initial State (Before Click):**
```html
<div class="n88-video-placeholder" data-youtube-id="{youtube_id}">
    <img src="{thumbnail_url}" alt="{video_title}" />
    <div class="n88-play-button">
        <svg>...</svg>
    </div>
</div>
```

**After Click (JavaScript):**
```javascript
// Replace placeholder with iframe
const placeholder = document.querySelector('.n88-video-placeholder');
const iframe = document.createElement('iframe');
iframe.src = `https://www.youtube-nocookie.com/embed/${youtubeId}?rel=0&modestbranding=1&enablejsapi=1`;
iframe.loading = 'lazy';
iframe.frameBorder = '0';
iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
iframe.allowFullscreen = true;
placeholder.replaceWith(iframe);
```

**Benefits:**
- Faster page load (no iframe until clicked)
- Less resource usage
- Cleaner UX in modals with multiple videos
- Better mobile performance

---

## 6. Lazy-Loading Logic

### Implementation Details

**Step 1: Initial Render**
- Show thumbnail image (from Media Library or YouTube)
- Show play button overlay
- Store `youtube_id` in `data-youtube-id` attribute

**Step 2: User Interaction**
- User clicks thumbnail/play button
- JavaScript event handler fires
- Replace placeholder div with iframe
- Load YouTube player

**Step 3: Player Load**
- Iframe loads YouTube-nocookie.com embed URL
- Video becomes playable
- No page reload required

### JavaScript Helper Function

```javascript
/**
 * Initialize lazy-loading YouTube player
 * 
 * @param {string} youtubeId YouTube video ID
 * @param {string} thumbnailUrl Thumbnail image URL
 * @param {string} videoTitle Video title
 * @returns {HTMLElement} Placeholder div element
 */
function createLazyYouTubePlayer(youtubeId, thumbnailUrl, videoTitle) {
    const placeholder = document.createElement('div');
    placeholder.className = 'n88-video-placeholder';
    placeholder.setAttribute('data-youtube-id', youtubeId);
    
    const img = document.createElement('img');
    img.src = thumbnailUrl;
    img.alt = videoTitle;
    
    const playButton = document.createElement('div');
    playButton.className = 'n88-play-button';
    playButton.innerHTML = '▶'; // Or SVG icon
    
    placeholder.appendChild(img);
    placeholder.appendChild(playButton);
    
    // Click handler
    placeholder.addEventListener('click', function() {
        const iframe = document.createElement('iframe');
        iframe.src = `https://www.youtube-nocookie.com/embed/${youtubeId}?rel=0&modestbranding=1&enablejsapi=1`;
        iframe.loading = 'lazy';
        iframe.frameBorder = '0';
        iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.width = '560';
        iframe.height = '315';
        
        placeholder.replaceWith(iframe);
    });
    
    return placeholder;
}
```

---

## 7. Query Patterns

### Get All Videos for a Project

```sql
SELECT * FROM wp_n88_project_videos 
WHERE project_id = %d 
ORDER BY display_order ASC, created_at ASC
```

### Get All Videos for an Item

```sql
SELECT * FROM wp_n88_project_videos 
WHERE project_id = %d AND item_id = %d AND step_key IS NULL
ORDER BY display_order ASC, created_at ASC
```

### Get All Videos for a Specific Step

```sql
SELECT * FROM wp_n88_project_videos 
WHERE project_id = %d AND item_id = %d AND step_key = %s
ORDER BY display_order ASC, created_at ASC
```

### Get Project-Level Videos

```sql
SELECT * FROM wp_n88_project_videos 
WHERE project_id = %d AND item_id IS NULL AND step_key IS NULL
ORDER BY display_order ASC, created_at ASC
```

---

## 8. WordPress Media Library Integration

### Custom Postmeta for Video Attachments

When a thumbnail is uploaded via Media Library, store custom postmeta:

```php
// On thumbnail upload/selection
update_post_meta( $attachment_id, 'n88_video_project_id', $project_id );
update_post_meta( $attachment_id, 'n88_video_item_id', $item_id );
update_post_meta( $attachment_id, 'n88_video_step_key', $step_key );
update_post_meta( $attachment_id, 'n88_youtube_id', $youtube_id );
```

**Note:** This is optional metadata for easier querying. The primary relationship is stored in `wp_n88_project_videos` table.

---

## 9. Consistency Requirements

### All Video Embeds Must:

1. ✅ Use `youtube-nocookie.com` domain (never regular `youtube.com`)
2. ✅ Include `rel=0` parameter (no related videos)
3. ✅ Include `modestbranding=1` parameter (minimal branding)
4. ✅ Use lazy-loading pattern (thumbnail first, iframe on click)
5. ✅ Store `youtube_id` separately for easy extraction
6. ✅ Support custom thumbnails via WordPress Media Library
7. ✅ Fallback to YouTube default thumbnails if custom not available

### Code Locations

- **Backend:** `includes/class-n88-rfq-videos.php` (to be created in Milestone 3.3)
- **Frontend:** JavaScript module for lazy-loading (to be created in Milestone 3.3)
- **Helper Functions:** `n88_get_youtube_embed_url()`, `n88_get_video_thumbnail_url()`

---

## 10. Future Enhancements

### Phase 4+ Considerations

- Video analytics (view count, watch time)
- Video chapters/timestamps
- Video transcripts
- Multiple video sources (not just YouTube)
- Video playlists

**Note:** Current architecture supports these enhancements without schema changes.

---

**Document Status:** ✅ Complete  
**Last Updated:** December 2025  
**Maintained By:** N88 Development Team

