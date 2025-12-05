# PHASE 3 — MASTER ROADMAP (FINAL WITH MILESTONES + KEYWORD LISTS)

**Status:** Official, Locked Roadmap for Phase 3 Development  
**Last Updated:** December 2025  
**Purpose:** Complete implementation guide for Phase 3 with all milestones, deliverables, and keyword lists

---

## Overview

This is the official, locked roadmap for Phase 3 development. All milestones below are part of the implementation plan. This document includes timeline logic + keyword triggers for Indoor, Outdoor, and Sourcing categories.

---

## MILESTONE 3.1 — Database & Architecture Foundations (Analytics-Ready)

**Status:** Must be completed BEFORE any Phase 3 UI or timeline logic is coded.

This milestone establishes the foundation needed for Phase 3, Phase 4 (pricing/optimization), AND Phase 5 (analytics + insights).

### SECTION A — Core Implementation Deliverables

#### 1. Add `timeline_structure` JSON Column to Items

**What:** Add a JSON column to store the entire timeline blueprint for each item.

**Stores:**
- Step order
- Step names
- Step IDs
- Timestamps
- Completion states
- Video IDs, file IDs (later phases)

**Why this matters:**
- Each item can have a different timeline type (6-step vs 4-step)
- The frontend timeline UI depends on this JSON
- Phase 5 analytics reads directly from this JSON and events table

**Developer Requirements:**
- Column is created properly in the DB
- Default value structure is valid JSON
- It loads correctly when an item is rendered

**Implementation Notes:**
- Column should be added to the item JSON structure in `n88_repeater_raw` (stored in `wp_project_metadata` table)
- No ALTER TABLE needed - handled in PHP code as part of item JSON structure
- When loading items, check if `timeline_structure` exists
- If missing, auto-assign based on `product_category` (or project-level `sourcing_category` as fallback)
- Save the generated `timeline_structure` back to the item JSON

#### 2. Create the `n88_timeline_events` Table

**What:** Analytics and history engine of the OS.

**Must store every action on every item:**
- Step started
- Step completed
- Step overridden
- Step reopened
- Video added
- File uploaded
- Status changed
- Out-of-sequence warning triggered

**Schema Requirements:**
- `project_id` (BIGINT UNSIGNED)
- `item_id` (INT UNSIGNED) - Item index within project (0-based)
- `step_id` / `step_key` (VARCHAR(50)) - Step identifier
- `event_type` (VARCHAR(30)) - Event category
- `event_description` (TEXT) - Human-readable description
- `timestamp` (DATETIME) - When event occurred
- `user_id` (BIGINT UNSIGNED) - User who performed action
- `status` (VARCHAR(30)) - Current status (pending, in_progress, completed, blocked, delayed)
- `event_data` (LONGTEXT JSON) - Additional structured data

**This table is the backbone of:**
- Future analytics
- Future optimization engine
- Client reporting
- Audit logs

**Developer Requirements:**
- Schema supports all required fields
- Proper indexes for Phase 5 analytics queries
- Foreign key relationships where appropriate

#### 3. Implement Event Logging Structure

**What:** Automatic event generation for all user/admin actions.

**Required Event Categories:**
- `completed` - Step marked as completed
- `in_progress` - Step started/in progress
- `override` - Admin forced completion (out of order)
- `out_of_sequence` - Steps done in wrong order

**Developer Requirements:**
- Logging fires automatically on the backend
- Every event writes a clean row into `n88_timeline_events`
- No frontend-only logic – the logging must be backend enforced
- Event data stored as JSON for flexibility

**Event Types (Minimum Required):**
- `step_started`
- `step_completed`
- `step_reopened`
- `step_overridden`
- `video_added`
- `file_added`
- `comment_added`
- `out_of_sequence`

#### 4. Implement Automatic Timeline Assignment Logic

**What:** Category → Timeline Type assignment function.

**Assignment Rules:**

**6-Step Furniture Timeline** → Triggered by:
- **Indoor Furniture Keywords:**
  - Indoor Furniture
  - Indoor Sofa
  - Indoor Sectional
  - Indoor Lounge Chair
  - Indoor Dining Chair
  - Indoor Dining Table
  - Casegoods
  - Beds
  - Consoles
  - Desks
  - Cabinets
  - Nightstands
  - Upholstered Furniture
  - Millwork / Cabinetry
  - Fully Upholstered Pieces (for aluminum-frame indoor items too)

- **Outdoor Furniture Keywords:**
  - Outdoor Furniture
  - Outdoor Sofa
  - Outdoor Sectional
  - Outdoor Lounge Chair
  - Outdoor Dining
  - Outdoor Dining Chair
  - Outdoor Dining Table
  - Daybed
  - Chaise Lounge
  - Pool Furniture
  - Sun Lounger
  - Outdoor Seating Sets

**4-Step Global Sourcing Timeline** → Triggered by:
- **Global Sourcing Keywords:**
  - Lighting
  - Flooring
  - Marble / Stone
  - Granite
  - Carpets
  - Drapery
  - Window Treatments
  - Accessories
  - Hardware
  - Metalwork
  - Any product sourced from external factories
  - Any RFQ item that is not furniture fabrication

**No Timeline** → Triggered by:
- Material Sample Kit only

**Developer Requirements:**
- Write a clean category → timeline assignment function
- Use exact keyword lists provided above
- Ensure the function is called:
  - When a project is created
  - When a new item is added ("Needs Quote" or "Quoted")
- Fallback to project-level `sourcing_category` if item `product_category` is missing

#### 5. Validate Schema & Architecture

**Developer Requirements:**
- No SQL errors
- No conflicts with existing data
- JSON loads properly
- API endpoints align with the milestone

**This validation is mandatory before moving to Milestone 3.2.**

### SECTION B — Analytics-Critical Documentation

**Location:** `/phase3/milestone3.1/`

**Developer must produce four documents and commit them to GitHub:**

#### 1. Database Schema Review Document

**Must include:**
- ✔ CREATE TABLE `n88_timeline_events` with all columns, types, indexes, foreign keys
- ✔ ALTER TABLE or JSON structure for `timeline_structure` in items
- ✔ Default JSON example or notes

**Purpose:** Permanent record of system structure for future developers.

#### 2. JSON Structure Definition (`timeline_structure`)

**Must document the full JSON shape for:**
- ✔ 6-Step Furniture Timeline (complete example)
- ✔ 4-Step Global Sourcing Timeline (complete example)
- ✔ Which fields may change in future
- ✔ Which fields must remain stable for analytics
- ✔ Where video/file references will be inserted later

**Purpose:** Permanent contract between backend and frontend.

#### 3. Event Types Enumeration

**Minimum required types:**
- `step_started`
- `step_completed`
- `step_reopened`
- `step_overridden`
- `video_added`
- `file_added`
- `comment_added`
- `out_of_sequence`

**Purpose:** Enables Phase 5 analytics and Phase 4 automation.

#### 4. YouTube Integration Plan

**Must document in detail:**
- ✔ How URLs are stored (e.g., in `n88_videos` table, field: `youtube_url`)
- ✔ How thumbnails are stored (WordPress attachment ID, reference field in DB)
- ✔ All metadata fields (project_id, item_id, step_id, title, description, created_at)
- ✔ Exact embed pattern (must use `youtube-nocookie.com`)
- ✔ Lazy-loading logic (iframe loads on click, thumbnails load first)

**Purpose:** Ensures consistency across all future video features.

---

## MILESTONE 3.2 — Timeline Rendering Engine (FULLY DETAILED + WITH KEYWORDS)

**Status:** UI + UX + Logic + Interaction (not database structure)

This milestone is where the system begins showing the timelines — not assigning them (that's 3.1), but rendering them visually, interactively, and consistently across ALL items in a project.

### 1. Timeline Rendering Requirements

**Must implement two distinct timeline components:**

#### A) 6-Step Furniture Production Timeline

**Shown ONLY for items whose category matches any Indoor/Outdoor Furniture keyword.**

**Steps:**
1. Prototype
2. Frame / Structure
3. Surface Treatment
4. Upholstery / Fabrication
5. Final QC
6. Packing & Delivery

**For each step, the UI must include:**
- Step title
- Status indicator (Not Started / In Progress / Completed)
- Timestamp(s)
- Step-level comment thread
- Step-level file uploads
- Videos assigned to this step

#### B) 4-Step Global Sourcing Timeline

**Shown ONLY for items whose category matches any Global Sourcing keyword.**

**Steps:**
1. Sourcing
2. Production / Procurement
3. Quality Check
4. Packing & Delivery

**Each step includes:**
- Status indicator
- Timestamps
- Comments
- Files
- Videos

#### C) No Timeline

**Only for Material Sample Kit items.**

The correct timeline type will already be assigned during Milestone 3.1 — Milestone 3.2 is about how to display it.

### 2. Keyword Sets (Used in Rendering + Validation)

**These keyword sets MUST be placed inside the blueprint so all logic is consistent in future phases.**

#### Indoor Furniture Keywords (→ 6-Step Furniture Timeline)
- Indoor Furniture
- Indoor Sofa
- Indoor Sectional
- Indoor Lounge Chair
- Indoor Dining Chair
- Indoor Dining Table
- Casegoods
- Beds
- Consoles
- Desks
- Cabinets
- Nightstands
- Upholstered Furniture
- Millwork / Cabinetry
- Fully Upholstered Pieces (for aluminum-frame indoor items too)

#### Outdoor Furniture Keywords (→ 6-Step Furniture Timeline)
- Outdoor Furniture
- Outdoor Sofa
- Outdoor Sectional
- Outdoor Lounge Chair
- Outdoor Dining
- Outdoor Dining Chair
- Outdoor Dining Table
- Daybed
- Chaise Lounge
- Pool Furniture
- Sun Lounger
- Outdoor Seating Sets

#### Global Sourcing Keywords (→ 4-Step Sourcing Timeline)
- Lighting
- Flooring
- Marble / Stone
- Granite
- Carpets
- Drapery
- Window Treatments
- Accessories
- Hardware
- Metalwork
- Any product sourced from external factories
- Any RFQ item that is not furniture fabrication

### 3. What Milestone 3.2 Must Deliver

#### ✔ Render 6-Step Furniture Timeline

**UI Requirements:**
- Display 6 steps in vertical or horizontal layout
- Each step shows: title, status indicator, timestamps, comment thread, file uploads, videos
- Timeline renders automatically IF the item category matches any Indoor or Outdoor furniture keyword

#### ✔ Render 4-Step Global Sourcing Timeline

**UI Requirements:**
- Display 4 steps
- Each step shows: status indicator, timestamps, comments, files, videos
- Timeline renders automatically IF category matches any Global Sourcing keyword

#### ✔ Step UI: Statuses, Timestamps, Comments, Files

**For every step (in both timelines), the step card must include:**
- Status toggle: not started → in progress → completed
- Timestamps: when step started, when completed
- Comment thread: each step has its own message thread
- File upload area: images, PDFs, shop drawings, QC reports

**These elements must update the database and event log correctly.**

#### ✔ Sequential-Step Enforcement

**Rules:**
- Cannot mark Step 3 as completed unless Step 2 is completed
- Cannot start Step 4 unless Step 3 is in progress or completed
- If user tries to skip ahead, show a warning or disable the action

**Purpose:** Ensures data integrity for future Analytics (Phase 5).

#### ✔ Admin Override

**Admins must be able to:**
- Mark any step as completed, regardless of sequence
- Edit timestamps
- Reopen or reset steps
- Override locked steps

**Every override MUST create an event in `n88_timeline_events`.**

#### ✔ Out-of-Sequence Warning Logic

**If a step is completed before a previous step:**
- System must show a warning badge: "⚠ Out-of-Sequence Action Logged"
- Automatically insert an `event_type = out_of_sequence` into the event log
- Display this warning ONLY to admin, not the designer-user

**Purpose:** Prepares the system for Phase 5 Analytics.

#### ✔ Step-Level File Upload & Comment Threads

**For each timeline step:**
- Must support multiple files
- Must support threaded comments (like a chat within the step)
- Must show uploader name + timestamp
- Must appear instantly after upload (AJAX or equivalent)

**These comments and files must also appear in:**
- Completed project summary
- PDF export

### 4. Summary

**Milestone 3.2 is complete when:**
- Both timelines render correctly for items based on the keyword rule sets
- Step UI works perfectly for all steps
- Status changes update UI + DB
- Timestamps auto-generate
- Sequential logic blocks invalid actions
- Admin override works and logs events
- Out-of-sequence logic triggers correctly
- Timeline steps support files, videos, comments
- All controls function without closing the modal
- Timeline loads instantly when switching items in the modal

---

## MILESTONE 3.3 — Video System Implementation (Detailed Spec)

**Goal:** Implement a complete, consistent video system that uses YouTube-nocookie, integrates with the WordPress Media Library, and cleanly links videos to projects, items, and timeline steps inside the project modal.

### 1. Full YouTube-nocookie Support

**Requirements:**
- All embedded videos must use: `https://www.youtube-nocookie.com/embed/{YOUTUBE_ID}`
- No regular `youtube.com` embeds in any N88 UI
- Video record should store: YouTube ID (preferred) or full URL (from which we derive the ID)
- Any extra params stored in a structured way, not concatenated strings

**Implementation:**
- Single helper function to generate the embed URL (centralized logic)
- Input: YouTube ID
- Output: `https://www.youtube-nocookie.com/embed/{id}?rel=0&modestbranding=1` (or similar consistent pattern)
- All front-end rendering calls this helper, not hard-coded strings

### 2. WordPress Media Library Integration

**Requirements:**
- Thumbnails and any video-related images stored as standard WP attachments (Media Library)
- Admins choose/upload thumbnails via the native media picker
- Store the selected thumbnail's attachment ID

**For each video attachment, save custom postmeta:**
- `n88_video_project_id`
- `n88_video_item_id`
- `n88_video_step_key` (e.g. prototype, surface_treatment, qc)
- `n88_youtube_id`

**Display:**
- Use `wp_get_attachment_image_url( $attachment_id, 'appropriate_size' )` for thumbnails

### 3. Custom Thumbnails (With Fallback)

**Requirements:**
- Video record should include: `thumbnail_attachment_id` (nullable)

**Display Logic:**
1. If `thumbnail_attachment_id` exists and is a valid image:
   - Use WordPress image URL from that attachment
2. Else:
   - Build YouTube thumbnail URL: `https://img.youtube.com/vi/{id}/maxresdefault.jpg`

**Implementation:**
- Single helper function: `n88_get_video_thumbnail_url( $video )` (no duplication)

### 4. Lazy-Loaded YouTube Player

**Requirements:**
- Player iframe does NOT load immediately
- Initially show: thumbnail image + play icon overlay
- Only when user clicks, load the actual iframe embed

**Markup Pattern:**
- Div wrapper with: `<img>` thumbnail, play button overlay, `data-youtube-id` attribute
- JS logic: On click, replace placeholder div with iframe pointing to youtube-nocookie URL

**Benefits:**
- Faster page load
- Less resource usage
- Cleaner UX in modals with multiple videos

### 5. Video Metadata Linking (Project, Item, Step)

**Requirements:**
- Each video record must include:
  - `project_id` (required)
  - `item_id` (nullable, but required when video is item/step-specific)
  - `step_key` (nullable, e.g. prototype, frame, surface_treatment, sourcing, qc, etc.)

**Storage:**
- Stored in dedicated N88 video table or as postmeta on attachment/"video" post type (per architecture defined in 3.1)

**Queries must easily support:**
- "All videos for this project"
- "All videos for this item"
- "All videos for this specific step in this item"

### 6. Video Panel Inside Project Modal

**Requirements:**

**In the project modal:**

**Step View:**
- Each step block shows: list or small grid of videos attached to that step
- Each entry: thumbnail, title, short description, timestamp
- Clicking the thumbnail opens the lazy-loaded player

**Video Panel / Tab:**
- Dedicated section (e.g., a tab or panel) listing:
  - All videos for the current item (grouped by step)
  - Optionally, a filter by step

**For both:**
- Use the same metadata and helper functions described above
- Ensure this works with cross-item navigation: when user switches items, the video panel and step lists update correctly without closing the modal

---

## MILESTONE 3.4 — Content Manager (N88 Studio OS Menu)

**Goal:** Turn the plugin into a first-class "application" inside WordPress by creating a dedicated N88 Studio OS admin area, with all Phase 3–7 tools living under one top-level menu.

### 1. Top-Level Admin Menu: N88 Studio OS

**Requirements:**
- Create a new top-level menu in the WP admin sidebar (not a submenu of anything else)
- Menu title: **N88 Studio OS**
- Page title (main screen): **N88 Studio OS – Dashboard**
- Capability: use a custom capability (e.g. `manage_n88_os`) so we can control access later (for now, map it to Administrator)
- Menu slug (suggested): `n88-os-dashboard`
- Icon: any Dashicon or custom SVG (grid/monitor/etc.)
- Position: high in the menu (near Dashboard), so it feels like a primary app, not a buried plugin

**All other N88 admin pages in Phase 3 should live as submenus under this one top-level item.**

### 2. Required Subpages & Responsibilities

#### 2.1 Dashboard
- **Slug:** `n88-os-dashboard`
- **Purpose:** High-level entry point to the OS
- **Contents (Phase 3 minimum):**
  - Brief summary tiles for:
    - Active projects count
    - Items with timelines
    - Recent timeline events (last 5–10)
    - Recent videos added
  - This can be simple for now, but the layout should anticipate future widgets (Phase 4+ analytics)
- **Note:** This is the default page when clicking "N88 Studio OS"

#### 2.2 Projects
- **Slug:** `n88-os-projects`
- **Purpose:** Shortcut to the existing Projects list / RFQ dashboard (whatever we currently use in Phase 2)
- **Behavior:** Reuse the existing projects view, but make sure it is reachable via this submenu
- **Over time:** This becomes the central place to open the project modal (with timelines, videos, etc.)

#### 2.3 Content Manager
- **Slug:** `n88-os-content-manager`
- **Purpose:** High-level hub for all media/content-related tools
- **Contents (Phase 3 minimum):**
  - Simple overview of:
    - Total number of videos in the system
    - Recently added videos
  - Link buttons to:
    - Video Library
    - Upload Video
    - Auto-Extracted Items
- **Think of this as a "control tower" for content operations**

#### 2.4 Video Library
- **Slug:** `n88-os-video-library`
- **Purpose:** List of all videos stored/linked in the system (Phase 3 scope: YouTube videos with metadata)
- **Required behavior:**
  - Table/grid listing:
    - Video title
    - YouTube ID or URL
    - Linked project (if any)
    - Linked item (if any)
    - Linked step (if any)
    - Thumbnail preview (small)
    - Date added
  - Basic filters/search (even simple text search is ok for Phase 3)
  - Actions:
    - Edit video (open edit screen or inline edit)
    - Delete / deactivate video

#### 2.5 Upload Video
- **Slug:** `n88-os-upload-video`
- **Purpose:** Main form to add a new video into the system
- **Form fields (must match what the timeline / steps expect):**
  - Video title (text)
  - Video description (textarea)
  - YouTube URL (required) – must support youtube-nocookie.com in rendering, even if the stored value is a normal YouTube URL
  - Thumbnail: WordPress Media Library picker (attachment ID) – Optional, but if omitted we'll fall back to YouTube default in rendering
  - Linking fields:
    - Project (dropdown or autocomplete)
    - Item (dependent dropdown after project is chosen)
    - Step key/step number (for attaching to timeline steps)
- **On save:** Store all metadata consistently (so the timeline engine knows how to find these videos)
- **Redirect:** To Video Library or show a clear success state

#### 2.6 Auto-Extracted Items
- **Slug:** `n88-os-auto-extracted-items`
- **Purpose:** Central place to view/manage items that came from PDF extraction (Phase 2 feature)
- **Contents:**
  - Table listing auto-extracted items:
    - Project
    - Source PDF
    - Item description
    - Status (extracted / confirmed / mapped to item)
    - Date of extraction
  - Actions:
    - View details
    - Link/confirm to an existing item
    - Clean up / delete if invalid
- **Note:** This leverages the PDF extraction pipeline already implemented in Phase 2, but now surfaced under the N88 Studio OS menu

#### 2.7 Settings
- **Slug:** `n88-os-settings`
- **Purpose:** Central place for OS-level configuration (we can expand over time)
- **Phase 3 minimum:**
  - A basic settings page scaffolded with:
    - Sections for:
      - Timeline settings (e.g., default behavior toggles)
      - Video / YouTube settings
      - Debug / logging flags
  - Even if some settings are read-only or "coming soon", the structure should be there
- **Important:** Use WordPress settings API or at least a clean structure so we can easily add real config options in Phase 4+

### 3. Permissions & Capabilities

**Define custom capabilities:**
- `manage_n88_os` – access to N88 Studio OS menu
- `manage_n88_projects`
- `manage_n88_content`
- `upload_n88_videos`

**For now:**
- Map all of these to administrator so only admins see the N88 Studio OS menus in Phase 3
- Make sure all subpages check capabilities correctly so we can safely open access to non-admins later (e.g., factory managers, internal staff)

### 4. UX & Visual Polish (Phase 3 level)

**Requirements:**
- Consistent page headers (e.g., "N88 Studio OS – Video Library")
- Use a consistent wrapper / layout so all subpages feel part of the same system
- Clear breadcrumbs or at least a visible title so users always know where they are
- Basic responsiveness (no broken layouts on typical viewport sizes)

---

## MILESTONE 3.5 — Add Another Item (Needs Quote / Quoted)

**Goal:** Allow both the project owner (designer) and admin to add new items into an existing project from inside the project modal, with:
- Correct initial status: Needs Quote or Quoted
- Automatic timeline type assignment (6-step Furniture / 4-step Global Sourcing / No Timeline)
- Full integration into navigation, timelines, events, and PDF structure

**This must work consistently for both Furniture and Global Sourcing items.**

### 1. UI Locations & Buttons

**Inside the project modal, in the Items / Quote Status section, add two actions (buttons or dropdown actions):**
- "Add Another Item – Needs Quote"
- "Add Another Item – Quoted"

**These actions must be visible to:**
- Project owner (designer user) — only for their own projects
- Admin / internal N88 team — via capability (e.g., `manage_options` or custom capability)

**These buttons should feel like part of the existing item list / quote status UI (not a separate page).**

### 2. Add-Item Form (Same for Both Buttons)

**When either button is clicked, open a small add-item form, either as:**
- A small modal inside the project modal, or
- An inline new row at the bottom of the item list

**Form fields (minimum):**
- Item title / description (free text)
- Product category (dropdown or autocomplete) — **This is where the keyword logic runs to decide:**
  - Indoor / Outdoor Furniture → 6-step Furniture timeline
  - Global Sourcing categories → 4-step Sourcing timeline
  - Sample-only → No timeline
- Dimensions: Length (L), Depth (D), Height (H)
- Quantity
- Basic notes (free text, optional)

**You can reuse the same field structure used for Phase 2 item creation so it stays consistent.**

**Validation:**
- Required: title/description, category, quantity
- Dimensions: can be optional, but validate if filled (numbers, no invalid strings)
- Show inline error messages if fields are missing or invalid

### 3. Behaviour — "Add Another Item – Needs Quote"

**When the user clicks "Add Another Item – Needs Quote" and submits the form:**

1. **Create a new item record linked to:**
   - The current project
   - The current user (owner), unless it's admin adding on behalf of the user

2. **Set status for the new item to:** Needs Quote

3. **Assign timeline type automatically using the same keyword logic as in Phase 2 / section 3.1:**
   - **If category matches Indoor Furniture / Outdoor Furniture keywords:**
     - Sofas, Sectionals, Lounge chairs, Dining chairs, Dining tables, Casegoods (beds, desks, consoles, cabinets, nightstands), Millwork / cabinetry, Upholstered pieces, Outdoor seating, Outdoor dining, Daybeds, Chaise lounges, Pool furniture
     - → Assign 6-Step Furniture Timeline
   - **If category matches Global Sourcing keywords:**
     - Lighting, Flooring, Marble / stone, Drapery, Accessories, Hardware, Metalwork, Any non-furniture fabrication, Mixed product sourcing, Any product where N88 is sourcing from external factories
     - → Assign 4-Step Global Sourcing Timeline
   - **If category = Sample Kit only**
     - → No timeline (timeline panel hidden for that item)

4. **Immediately append the new item to:**
   - The project's item list in the modal
   - The timeline panel (if timeline type is Furniture or Sourcing)
   - The internal PDF export data structure (so it appears in exports later)

5. **Create initial timeline events for this item as needed:**
   - e.g. `item_created`, `timeline_initialized` with default step statuses

6. **The new item must appear in:**
   - Cross-item navigation (sidebar / tabs)
   - Completed Project Summary when the project is later marked completed
   - Any analytics/events reporting based on `n88_timeline_events`

### 4. Behaviour — "Add Another Item – Quoted"

**When the user clicks "Add Another Item – Quoted" and submits the form:**

1. **Same add-item form and validation as above**

2. **Create a new item record linked to the same project and user/admin**

3. **Set status for the new item to:** Quoted (assumes quote is already prepared)

4. **Timeline type is assigned using the same category keyword logic as above:**
   - Furniture → 6-step Furniture timeline
   - Global Sourcing → 4-step Sourcing timeline
   - Sample Kit → No timeline

5. **Optionally (if the RFQ data model already supports it), allow price fields to be set at creation**

6. **Append the item to:**
   - The item list
   - The timeline panel (if applicable)
   - The PDF export structure

7. **Log the relevant events:**
   - `item_created`
   - `item_status_set_quoted`
   - `timeline_initialized`

**This path is used when we:**
- Add late items that are already priced, or
- Manually enter an item that was quoted outside the OS and now needs to be tracked

### 5. Permissions Logic

**Designer / Project Owner (user):**
- Can use: "Add Another Item – Needs Quote" and "Add Another Item – Quoted"
- Any items they add must be tied to their user ID and their project

**Admin / Internal N88 Team:**
- Same abilities as designer, but not restricted by user ID
- Can add items to any project
- Controlled by a WordPress capability, e.g.: `manage_options` or a custom `manage_n88_projects` capability

**Security & validation should follow the same pattern as other endpoints:**
- Logged-in check
- Nonce verification (using the new nonce helper)
- Project ownership or admin override (using `get_project` / `get_project_admin`)

### 6. Interaction With Timelines, Navigation, Summary & PDF

**For every new item created via these buttons:**
- A proper timeline type is set (6-step Furniture, 4-step Sourcing, or none)
- Timeline steps are initialized with default statuses (pending / equivalent)
- The item is selectable via cross-item navigation (sidebar/tabs)
- Step-level capabilities (videos, files, comments, notes) are available based on the assigned timeline

**When the project is later marked Completed:**
- These newly added items must be included in:
  - The Final Production Summary (item list, statuses, lead times)
  - The PDF export (all relevant data: timelines, comments, files, video links)

---

## MILESTONE 3.6 — Cross-Item Navigation (Inside Project Modal)

**Goal:** Allow the user (designer or admin) to move between different items in the same project without closing the modal, while always seeing the correct, fully loaded data for the selected item (timeline, comments, files, videos, flags, pricing panel, etc.).

### 1. Navigation UI (Sidebar or Tabs)

**Implementation Options:**
- Left sidebar list of items, or
- Horizontal tabs at the top of the modal

**Requirements:**
- The navigation area must show one entry per item in the project (e.g. "Sofa – Lobby", "Dining Chair – Terrace", "Lighting – Corridor")
- Each entry should display at least:
  - Item title / short label
  - Optional: a small status indicator (e.g. "In Production", "Needs Quote", "Quoted")
- The currently active item must be visually highlighted (active state)
- Clicking on another item:
  - Does NOT close the modal
  - Does NOT do a full page reload
  - Only reloads the item-specific content area inside the modal

### 2. Dynamic Loading of Item Content

**When the user clicks on an item in the sidebar/tabs:**

1. Capture the item ID (and project ID if needed)
2. Make an AJAX request (or equivalent internal call) to fetch all the data for that item
3. Replace the content area on the right side (or below the tabs) with the newly loaded item data

**The dynamically reloaded content must include:**
- **Timeline panel**
  - Correct timeline type (6-step furniture / 4-step sourcing / none for sample kits)
  - All step statuses, timestamps, and notes for that item
- **Step-level comments panel**
- **Item-level general comments thread**
- **Files section**
  - Step-level files and item-level files
- **Videos section**
  - Step-level videos (from Milestone 3.3 logic)
  - Any item-level or project-level videos that should show in the modal
- **Urgent flags / status badges**
- **Pricing / quote status panel**
  - "Needs Quote" or "Quoted" status (from Milestone 3.5)
  - Any other quote-related info that currently exists

**Key point:** All of this must be item-specific. When you switch items, nothing from the previous item should "leak" through in the UI.

### 3. Performance & UX Behavior

**To keep the experience smooth:**
- Show a small loading indicator in the content area when switching items (spinner or skeleton state)
- Avoid full page loads — this should all happen inside the modal content via AJAX
- Preserve the scroll position and open panels only if it makes sense per item
- Example: If an item has many videos, the video section might be scrolled independently

### 4. Interaction with Other Milestones

**Cross-item navigation must work cleanly with:**
- **Milestone 3.2 – Timeline Rendering Engine**
  - When you switch items, the correct timeline (6-step furniture or 4-step sourcing) must render using the same rendering logic you built in 3.2
- **Milestone 3.3 – Video System**
  - Videos for the selected item should appear in the video panel and in the relevant step panels
- **Milestone 3.5 – Add Another Item**
  - When a new item is created from inside the modal:
    - It must immediately appear in the navigation list (sidebar/tabs)
    - Clicking it should load its fresh timeline, comments, files, etc. (even if many are empty initially)
- **Completed Project Summary (3.7)**
  - Even when a project is "Completed" and the modal is in read-only mode, the navigation should still allow switching between items to review their timelines and details

### 5. State Management / Data Integrity

**Requirements:**
- Make sure that unsaved changes (if any) are handled sensibly when switching items:
  - Either auto-save before switching, or
  - Show a confirmation if the user has unsaved edits that would be lost
- Ensure that:
  - The event logging (from `n88_timeline_events`) still works correctly when steps are updated after switching items
  - All actions (step completion, comments, video additions) always apply to the currently active item only

---

## MILESTONE 3.7 — Completed Project Summary + PDF Structure (DETAILED SPEC)

**Goal:** When a project is marked Completed, the OS should switch that project into a read-only "Final Summary" mode and have all the data structured so a PDF export can be generated in Phase 4 without changing the data model later.

**Think of this milestone as:** "Build the view and the data structure for the final report. The actual PDF generation can come later."

### 1. Trigger: When Does the Summary View Appear?

**The Completed Project Summary view should appear when:**
- Project status is set to Completed in the system

**Once a project is in Completed state:**
- The default view in the modal should be the Final Production Summary
- Editable fields should be locked (read-only), except for specific admin-only notes if you decide to allow that later

### 2. Final Summary View — What It Must Show

**Inside the project modal, when the project is Completed:**

#### Top Section – Project Header
- Project name / title
- Project ID (if available)
- Client / designer name (if stored)
- Project creation date
- Project completion date (calculated from first created → marked completed)

#### Core Summary Metrics
- **Total lead time**
  - E.g. "Total Lead Time: 72 days"
  - This can be calculated as: `date_completed - date_created`
  - If you're already recording any timeline events (like first step start), you can later refine this in Phase 5, but at minimum we need a place for it now
- **Item count**
  - Number of items in the project (furniture + sourcing)
  - Can be broken down by type (optional):
    - X furniture items
    - Y sourcing items
    - Z sample-only items
- **Factory / Vendor info** (if available in current schema)
  - Factory name / vendor per project (or note: "Multiple factories" if that's how you structure it)
  - Admin can add / see a note about which factory handled most of the work
- **Admin notes**
  - A read-only field that shows final internal notes for the project (pulled from wherever you store admin-only notes)
  - If you prefer, keep this editable for admins only, but render it in the summary view

### 3. Timeline Summaries (Per Item, Aggregated)

**The summary should show each item with its timeline status in a compact format. This will later be used to populate the PDF.**

**For each item in the project, show:**
- Item title / identifier (e.g., "Sofa #1 – Lobby")
- Item category (e.g., "Indoor Furniture / Sofa", "Outdoor Furniture / Dining Chair", "Lighting / Sconce")
- Timeline type:
  - 6-Step Furniture Production Timeline
  - 4-Step Global Sourcing Timeline
  - No Timeline (Sample Kit Only)
- **Per-step status summary (for that item):**
  - **For 6-step furniture:**
    - Prototype → Completed date or "Not completed"
    - Frame / Structure → Completed date or "Not completed"
    - Surface Treatment → Completed date or "Not completed"
    - Upholstery → Completed date or "Not completed"
    - Final QC → Completed date or "Not completed"
    - Packing & Delivery → Completed date or "Not completed"
  - **For 4-step sourcing:**
    - Sourcing → Completed date or "Not completed"
    - Production / Procurement → Completed date or "Not completed"
    - Quality Check → Completed date or "Not completed"
    - Packing & Delivery → Completed date or "Not completed"

**Implementation detail:**
- You don't have to show every event; you can show the latest status per step using the data coming from `n88_timeline_events` and `timeline_structure`
- The important part is that the summary view pulls from the same structures you're building in 3.1 / 3.2

### 4. Files, Comments, and Videos in the Summary

**The Completed Project Summary must include references to all:**
- Files
- Comments
- Videos

**for each item, so that Phase 4 can generate a full PDF report from this same data.**

**You don't need a super complex UI; it can be tabbed, accordions, or grouped, but the data must be wired correctly.**

**For each item, show:**

#### Files
- List of all files attached to that item and/or its steps:
  - File name
  - File type (PDF, image, etc.)
  - Step association (if any)
  - Upload timestamp
- These entries must be in a consistent data structure so the PDF export can read them

#### Comments
- **Item-level general comment thread:**
  - Show the list of comments in chronological order
  - At minimum: author, timestamp, comment text
- **Step-level comments:**
  - Group comments under each step (Prototype, Frame, etc.)
  - Again, author, timestamp, comment

#### Videos
- List of all videos attached to:
  - The project
  - Individual items
  - Individual steps
- Each video entry should show:
  - Title
  - Short description
  - Associated YouTube-nocookie URL (or stored ID)
  - Thumbnail (custom via Media Library, or fallback)
  - Where it's attached (project / item / step)

**Key point:** For this milestone, what matters is that all these associations are visible and correctly linked so that later the PDF exporter can loop through: `items → steps → files → comments → videos` without needing new schema changes.

### 5. PDF Export Data Model (Structure-Only in Phase 3)

**In Phase 3, you don't need to generate the actual PDF, but you must:**

1. **Define and implement a data structure (PHP array / JSON) that represents the full report**

2. **Ensure this structure can be generated for any completed project with a single function / method, for example:**
   ```php
   $n88_report_data = N88_Report_Builder::build_completed_project_summary( $project_id );
   ```

3. **This structure should include:**
   - Project header info
   - Summary metrics
   - For each item:
     - Basic item details
     - Timeline type + step statuses
     - Files
     - Comments (item + step)
     - Videos

**Example top-level structure (PHP array or JSON):**
```php
[
    'project' => [
        'id' => 123,
        'name' => 'XYZ Hotel – Outdoor Package',
        'client' => 'ABC Design Studio',
        'created_at' => '2025-01-01',
        'completed_at' => '2025-03-15',
        'total_lead_time_days' => 73,
        'item_count' => 14,
        'factory' => 'N88 Main Factory',
        'admin_notes' => 'Final QC approved by client on Mar 12.'
    ],
    'items' => [
        [
            'id' => 1,
            'title' => 'Pool Lounge Chair – Type A',
            'category' => 'Outdoor Furniture / Lounge Chair',
            'timeline_type' => '6step_furniture',
            'steps' => [
                [
                    'key' => 'prototype',
                    'label' => 'Prototype',
                    'status' => 'completed',
                    'completed_at' => '2025-01-20'
                ],
                // ... more steps
            ],
            'files' => [
                // file metadata
            ],
            'comments' => [
                'item_level' => [ /* ... */ ],
                'steps' => [
                    'prototype' => [ /* ... */ ],
                    // ...
                ]
            ],
            'videos' => [
                // video references linked to this item or its steps
            ]
        ],
        // more items...
    ]
];
```

**You don't need to mirror this exact structure, but you must:**
- Have one canonical place where all the report-ready data is assembled
- Make sure the structure includes everything the PDF will need later

### 6. Read-Only Behavior and UX Expectations

**When viewing a Completed project:**
- All core production fields (timelines, statuses, etc.) should be read-only
- No step changes should be made in Completed state (unless you decide to allow admin-only edits later—but for now, assume read-only)
- The main impression for the user: "This project is done. I can review everything that happened and all supporting documentation in one place."

### Summary for Milestone 3.7

**Milestone 3.7 is complete when:**
- A project in Completed state shows a Final Project Summary view inside the modal
- That view:
  - Displays project-level summary details
  - Shows per-item timeline summaries
  - Lists all files, comments, and videos associated with items and steps
- A single function (or class method) can generate a structured data array (or JSON) containing everything needed for a PDF report
- No additional DB schema changes are required later to support PDF export in Phase 4

---

## MILESTONE 3.8 — Final QA (Quality Assurance & Stability Pass)

**Goal:** Make sure the entire Phase 3 feature set is stable, consistent, and production-ready — no broken flows, no missing logs, and no obvious UX issues.

### 1. Bug Fixes

**What this means:**
Go through all Phase 3 features (timelines, video system, cross-item navigation, add-another-item, completed summary) and:
- Fix any PHP errors, JS errors, and warnings in the console
- Fix any obvious layout breaks, misaligned elements, or styling issues introduced by Phase 3
- Fix broken links, missing labels, or wrong text where applicable

**Expected output:**
A clean pass with:
- No PHP notices/warnings in debug log for the new code paths
- No JavaScript errors in browser console when using any Phase 3 features
- All obvious functional bugs (anything that blocks a user or admin) resolved

### 2. UI Refinement

**What this means:**
Do a visual and UX polish pass on:
- Timeline steps (6-step furniture + 4-step sourcing)
- Step badges, statuses, warning labels (sequential/out-of-sequence)
- Video thumbnails and players
- Cross-item navigation inside the modal
- "Add Another Item – Needs Quote / Quoted" UI
- Completed Project Summary view

**Focus on:**
- Consistent typography, spacing, and button styles with the existing N88 UI
- Clear labels and tooltips where needed (e.g., warnings, admin override)
- Making sure nothing feels "unfinished" or obviously placeholder

### 3. Full Workflow Testing

**What this means:**
Run through end-to-end flows as both a designer user and an admin, for both Furniture and Global Sourcing scenarios:

#### Create Project → Items → Timelines
- Confirm automatic timeline assignment works for indoor furniture, outdoor furniture, and global sourcing items
- Confirm sample-kit-only items correctly hide the timeline panel

#### Update Steps
- Mark steps as started/completed in order
- Try out-of-sequence completion (with admin override where required)
- Confirm statuses, timestamps, and comments behave correctly

#### Videos
- Add videos via Content Manager
- Add videos via a timeline step
- Confirm thumbnails, lazy-loading, and playback all work

#### Add Another Item
- Add items with "Needs Quote" and "Quoted" for both Furniture and Global Sourcing
- Confirm they:
  - Get the correct timeline type
  - Appear in the item list, navigation, timelines, and summary

#### Completed Project Summary
- Mark project as Completed
- Check that:
  - The edit controls collapse where they should
  - The summary shows all items, steps, videos, files, and comments

**Goal:** Every core path in Phase 3 should work without errors from start → finish.

### 4. Validate Events Logging (Analytics-Ready)

**What this means:**
Confirm that `n88_timeline_events` is recording events correctly when:
- A step is started
- A step is completed
- An admin override occurs
- An out-of-sequence completion happens (if applicable)
- Videos are added to a step (if part of events model)

**Verify that:**
- Each event includes the correct `item_id`, `step_key`/number, `event_type`, `timestamp`, and `user_id`
- No duplicate or obviously incorrect records are being generated

**Goal:** By the end of 3.8, the event log should be trustworthy enough for Phase 5 analytics (no missing or nonsense data).

### 5. Cross-Browser & Mobile Checks

**What this means:**
Test the Phase 3 UI in at least:

#### Desktop:
- Chrome
- Safari
- Firefox (basic check)

#### Mobile / Responsive:
- Chrome on Android (or emulator)
- Safari on iOS (or responsive mode in dev tools)

**Focus on:**
- Timeline layout is readable and doesn't break on smaller screens
- Cross-item navigation in the modal is usable on mobile (no clipped content or impossible taps)
- Video thumbnails and players are tappable and usable on mobile
- "Add Another Item" form is usable on smaller screens

### Summary for Milestone 3.8

**Milestone 3.8 is complete when:**
- All Phase 3 features work without errors
- UI is polished and consistent
- All workflows tested end-to-end
- Event logging is validated and analytics-ready
- Cross-browser and mobile compatibility confirmed
- System is production-ready for Phase 4 development

---

## Phase 3 Completion Checklist

### Milestone 3.1 — Database & Architecture Foundations
- [ ] `timeline_structure` JSON column added to items
- [ ] `n88_timeline_events` table created with proper schema
- [ ] Event logging structure implemented
- [ ] Automatic timeline assignment logic implemented
- [ ] Schema & architecture validated
- [ ] Database Schema Review Document created
- [ ] JSON Structure Definition documented
- [ ] Event Types Enumeration documented
- [ ] YouTube Integration Plan documented

### Milestone 3.2 — Timeline Rendering Engine
- [ ] 6-Step Furniture Timeline renders correctly
- [ ] 4-Step Global Sourcing Timeline renders correctly
- [ ] Step UI with statuses, timestamps, comments, files implemented
- [ ] Sequential-step enforcement working
- [ ] Admin override functionality implemented
- [ ] Out-of-sequence warning logic working
- [ ] Step-level file upload & comment threads functional

### Milestone 3.3 — Video System Implementation
- [ ] Full YouTube-nocookie support implemented
- [ ] WordPress Media Library integration complete
- [ ] Custom thumbnails with fallback working
- [ ] Lazy-loaded YouTube player implemented
- [ ] Video metadata linking (project, item, step) complete
- [ ] Video panel inside project modal functional

### Milestone 3.4 — Content Manager (N88 Studio OS Menu)
- [ ] Top-level "N88 Studio OS" admin menu created
- [ ] Dashboard subpage implemented
- [ ] Projects subpage implemented
- [ ] Content Manager subpage implemented
- [ ] Video Library subpage implemented
- [ ] Upload Video subpage implemented
- [ ] Auto-Extracted Items subpage implemented
- [ ] Settings subpage implemented
- [ ] Permissions & capabilities defined
- [ ] UX & visual polish applied

### Milestone 3.5 — Add Another Item
- [ ] "Add Another Item – Needs Quote" button implemented
- [ ] "Add Another Item – Quoted" button implemented
- [ ] Add-item form with validation working
- [ ] Timeline assignment on item creation working
- [ ] Integration with navigation, timelines, events, PDF structure complete
- [ ] Permissions logic implemented

### Milestone 3.6 — Cross-Item Navigation
- [ ] Navigation UI (sidebar or tabs) implemented
- [ ] Dynamic loading of item content working
- [ ] Performance & UX behavior optimized
- [ ] Interaction with other milestones verified
- [ ] State management / data integrity ensured

### Milestone 3.7 — Completed Project Summary + PDF Structure
- [ ] Completed project summary view implemented
- [ ] Final summary view showing all required data
- [ ] Timeline summaries per item working
- [ ] Files, comments, and videos in summary linked correctly
- [ ] PDF export data model structure defined
- [ ] Read-only behavior implemented

### Milestone 3.8 — Final QA
- [ ] All bugs fixed
- [ ] UI refined and polished
- [ ] Full workflow testing completed
- [ ] Events logging validated
- [ ] Cross-browser & mobile checks passed
- [ ] System production-ready

---

## Implementation Notes

### Development Order
1. **Milestone 3.1** must be completed first (database foundation)
2. **Milestone 3.2** can begin once 3.1 is validated
3. **Milestone 3.3** (videos) can be developed in parallel with 3.2
4. **Milestone 3.4** (Content Manager) should follow 3.3
5. **Milestone 3.5** (Add Another Item) requires 3.1 and 3.2
6. **Milestone 3.6** (Cross-Item Navigation) requires 3.2, 3.3, and 3.5
7. **Milestone 3.7** (Completed Summary) requires all previous milestones
8. **Milestone 3.8** (Final QA) is the final step

### Keyword Lists Reference

**Indoor Furniture Keywords (→ 6-Step Furniture Timeline):**
- Indoor Furniture, Indoor Sofa, Indoor Sectional, Indoor Lounge Chair, Indoor Dining Chair, Indoor Dining Table, Casegoods, Beds, Consoles, Desks, Cabinets, Nightstands, Upholstered Furniture, Millwork / Cabinetry, Fully Upholstered Pieces

**Outdoor Furniture Keywords (→ 6-Step Furniture Timeline):**
- Outdoor Furniture, Outdoor Sofa, Outdoor Sectional, Outdoor Lounge Chair, Outdoor Dining, Outdoor Dining Chair, Outdoor Dining Table, Daybed, Chaise Lounge, Pool Furniture, Sun Lounger, Outdoor Seating Sets

**Global Sourcing Keywords (→ 4-Step Sourcing Timeline):**
- Lighting, Flooring, Marble / Stone, Granite, Carpets, Drapery, Window Treatments, Accessories, Hardware, Metalwork, Any product sourced from external factories, Any RFQ item that is not furniture fabrication

**No Timeline:**
- Material Sample Kit only

### Technical Standards
- Follow N88 Studio OS Development Standards
- Use `window.N88StudioOS` namespace for JavaScript
- All AJAX endpoints must include nonce verification
- Database queries must use prepared statements
- All user inputs must be sanitized
- All outputs must be escaped

---

**Document Status:** ✅ Complete  
**Last Updated:** December 2025  
**Next Phase:** Phase 4 — Pricing & Optimization Engine