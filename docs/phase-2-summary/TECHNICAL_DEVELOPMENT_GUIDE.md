# N88 RFQ Platform - Technical Development Guide

## Table of Contents
1. [File Map](#file-map)
2. [Development Standards](#development-standards)
3. [JavaScript Modules](#javascript-modules)
4. [Phase 2 Completion Summary](#phase-2-completion-summary)
5. [Architecture Overview](#architecture-overview)

---

## File Map

### Core Plugin Files
```
n88-rfq-platform.php                    # Main plugin bootstrap file
```

### PHP Class Files (`includes/`)
```
class-n88-rfq-installer.php            # Database installation & upgrades
class-n88-rfq-projects.php              # Project CRUD operations
class-n88-rfq-admin.php                 # Admin dashboard & quote management
class-n88-rfq-frontend.php              # Frontend display & AJAX handlers
class-n88-rfq-comments.php              # Comment system (Phase 2A)
class-n88-rfq-quotes.php                 # Quote management & PDF generation
class-n88-rfq-notifications.php         # Notification system (Phase 2A)
class-n88-rfq-audit.php                 # Audit logging
class-n88-rfq-pdf-extractor.php        # PDF extraction engine (Phase 2B)
class-n88-rfq-item-flags.php            # Item flagging system (Phase 2B)
class-n88-rfq-pricing.php               # Pricing calculations (Phase 2B)
```

### JavaScript Modules (`assets/`)
```
n88-rfq-modal.js                        # Modal, comments, timeline, notifications (Phase 2A)
n88-rfq-pdf-extraction.js               # PDF upload & extraction UI (Phase 2B)
```

### CSS Files (`assets/css/`)
```
n88-rfq-form.css                        # Form styles, modal styles, Phase 2A/B styles
```

### Libraries (`includes/lib/`)
```
fpdf.php                                # PDF generation library
```

### Vendor Dependencies (`vendor/`)
```
smalot/pdfparser/                      # PDF parsing library (Phase 2B)
symfony/polyfill-mbstring/              # String handling polyfill
```

---

## Development Standards

This codebase follows the **N88 Studio OS Development Standards** for consistency, maintainability, and scalability.

### 1. Architecture Principles

- **Custom tables are the system's core** (never WP posts/meta)
- **One source of truth per feature/module**
- **PHP = business logic only**
- **JS = UI interactions + AJAX requests**
- **Templates = markup only**
- **Never store combined strings** (e.g., "24 × 26 × 42") — always store atomic values (length, depth, height)

### 2. Security Requirements

Every write operation must include:
- **Nonce** verification
- **Capability check** (user permissions)
- **Input sanitization** (sanitize_text_field, sanitize_textarea_field, wp_kses_post)
- **Backend validation** (never trust frontend values)

### 3. Naming Conventions

#### PHP
- **Classes:** `N88_RFQ_ClassName` or `namespace N88RFQ; class ClassName {}`
- **Methods:** `camelCase`, e.g. `saveProjectMetadata()`
- **SQL Tables:** `wp_n88_*`
- **Columns:** `snake_case`, e.g. `primary_material`, `created_at`, `updated_at`

#### JavaScript
- **Namespace:** `window.N88StudioOS` (required for all modules)
- **Objects:** `N88StudioOS.PDFExtraction`, `N88StudioOS.Dashboard`, `N88StudioOS.Items`
- **Functions:** `camelCase`, e.g. `renderItemsTable()`, `parseDimensions()`
- **Files:** `n88-rfq-pdf-extraction.js`, `n88-project-timeline.js`

#### CSS
- **BEM Convention:** `n88-card__header--highlight`
  - Block: `n88-card`
  - Element: `__header`
  - Modifier: `--highlight`

### 4. JavaScript Module Structure

All JavaScript modules must:
1. Use `window.N88StudioOS` namespace
2. Initialize namespace if it doesn't exist:
   ```javascript
   if (typeof window.N88StudioOS === 'undefined') {
       window.N88StudioOS = {};
   }
   ```
3. Attach module to namespace:
   ```javascript
   window.N88StudioOS.ModuleName = self;
   ```
4. Use IIFE (Immediately Invoked Function Expression) for encapsulation
5. Follow single source of truth principle (one implementation per feature)

### 5. Code Organization

```
Plugin Root
├── n88-rfq-platform.php          # Main plugin bootstrap
├── includes/                      # PHP business logic
│   ├── class-n88-rfq-*.php      # Core classes
│   └── lib/                      # Third-party libraries
├── assets/                       # Frontend assets
│   ├── n88-rfq-*.js             # JavaScript modules
│   └── css/                      # Stylesheets
└── vendor/                       # Composer dependencies
```

### 6. Database Guidelines

- **Index at minimum:** `project_id`, `user_id`, `created_at`, `updated_at`, `status`
- **Avoid SELECT \*** - Always specify columns
- **Never load > 500 rows** without pagination
- **Use prepared SQL queries** everywhere
- **Use caching** for slow or frequently repeated queries

### 7. Performance Guidelines

- **Backend:** Avoid heavy loops inside AJAX handlers
- **Frontend:** Pages must feel fast on normal hotel Wi-Fi (aim < 1s perceived load)
- **Assets:** Minify & version JS/CSS
- **Lazy-loading:** Use for long lists or large datasets
- **Progressive updates:** Use small, progressive UI updates instead of one big redraw

### 8. Commit Message Format

Every commit must follow this format:

```
[type]: short description

What changed?
Why did it change?
Any backward compatibility notes?
```

**Valid types:**
- `feat:` new feature
- `fix:` bug fix
- `update:` improvements
- `refactor:` internal improvements
- `security:` security fixes
- `db:` database migrations
- `perf:` performance enhancements

**Examples:**
- `feat: add Global Sourcing Timeline logic to project details`
- `fix: correct dimension parsing for PDF extraction`
- `db: add lead_time column to project_quotes table`

---

## JavaScript Modules

### 1. `n88-rfq-modal.js` - Phase 2A Frontend Extensions

**Location:** `assets/n88-rfq-modal.js`

**Purpose:** Handles modal interactions, comments, timeline, notifications, quotes, and item management.

**Main Components:**

#### A. N88Modal Object
- **`openProjectModal(projectId)`** - Opens project detail modal
- **`renderProjectModal(project)`** - Renders modal with project data
- **`getStepInfo(project)`** - Calculates timeline step progress
- **`renderSummaryTab(project)`** - Renders project summary tab
- **`renderItemsTab(container, project)`** - Renders project items with expand/collapse
- **`toggleItemExpand(header)`** - Expands/collapses item details
- **`renderItemComments(projectId, itemId)`** - Loads and displays item comments
- **`renderItemFiles(projectId, itemId)`** - Loads and displays item files
- **`switchTab(tabName)`** - Switches between modal tabs (summary, items, timeline, comments, quote, notifications, files)
- **`renderTimelineTab()`** - Renders project timeline with steps
- **`renderNotificationsTab()`** - Renders notifications list
- **`renderFilesTab()`** - Renders project files
- **`renderQuotePanel()`** - Renders quote information
- **`closeModal()`** - Closes modal and cleans up

**AJAX Actions Used:**
- `n88_get_project_modal` - Fetch project data for modal
- `n88_get_project_timeline` - Fetch timeline data
- `n88_get_notifications` - Fetch notifications
- `n88_get_project_files` - Fetch project files

#### B. N88Comments Object
- **`loadProjectComments(projectId)`** - Loads all comments for a project
- **`loadItemComments(projectId, itemId)`** - Loads comments for a specific item
- **`addComment(projectId, itemId, commentText, isUrgent, parentCommentId, videoId)`** - Adds new comment
- **`deleteComment(commentId)`** - Deletes a comment
- **`renderComments(comments, container)`** - Renders comment list with threading
- **`formatComment(comment)`** - Formats comment for display
- **`toggleUrgent(commentId, isUrgent)`** - Toggles urgent flag on comment

**AJAX Actions Used:**
- `n88_add_comment` - Add comment
- `n88_get_comments` - Get comments
- `n88_get_project_comments` - Get all project comments
- `n88_delete_comment` - Delete comment

#### C. N88Notifications Object
- **`loadNotifications()`** - Loads user notifications
- **`getUnreadCount()`** - Gets unread notification count
- **`markAsRead(notificationId)`** - Marks notification as read
- **`markAllAsRead()`** - Marks all notifications as read
- **`renderNotifications(notifications, container)`** - Renders notification list
- **`formatNotification(notification)`** - Formats notification for display

**AJAX Actions Used:**
- `n88_get_notifications` - Get notifications
- `n88_get_unread_count` - Get unread count
- `n88_mark_notification_read` - Mark as read
- `n88_mark_all_notifications_read` - Mark all as read

#### D. N88Quotes Object
- **`loadQuote(projectId)`** - Loads quote for project
- **`renderQuote(quote)`** - Renders quote display
- **`updateQuote(projectId, data)`** - Updates quote (client-side)

**AJAX Actions Used:**
- `n88_get_project_quote` - Get quote
- `n88_update_client_quote` - Update quote (client)

#### E. N88ItemCards Object
- **`renderItemCard(item, projectId)`** - Renders item card component
- **`toggleItemDetails(itemId)`** - Toggles item detail view
- **`editItem(itemId)`** - Opens item edit interface

#### F. Event Handlers
- Modal open/close events
- Tab switching
- Item expand/collapse
- Comment submission
- Notification interactions
- Quote interactions
- Edit button handlers (for quote items)

**Key Features:**
- Event delegation for dynamically loaded content
- jQuery compatibility for edit button handlers
- Auto-calculation of total price in quote items table
- Real-time comment threading
- Urgent comment flagging
- Video attachment support in comments

---

### 2. `n88-rfq-pdf-extraction.js` - Phase 2B PDF Extraction Handler

**Location:** `assets/n88-rfq-pdf-extraction.js`

**Namespace:** `window.N88StudioOS.PDFExtraction`

**Purpose:** Handles PDF upload, extraction preview, and item import into forms.

**Main Components:**

#### A. N88StudioOS.PDFExtraction Object
- **`init()`** - Initializes PDF extraction handlers
- **`setupHandlers()`** - Sets up all event handlers
- **`setupEntryModeToggle()`** - Handles manual vs PDF entry mode toggle
- **`toggleEntryMode(mode)`** - Toggles between manual and PDF entry modes
- **`setupPDFUploadHandlers()`** - Sets up PDF upload dropzone and file input
- **`isValidPDF(file)`** - Validates PDF file type
- **`handlePDFUpload(file)`** - Handles PDF file selection/upload
- **`createDraftProject(pdfFile)`** - Creates draft project before extraction
- **`createDraftProjectViaAJAX(...)`** - Creates project via AJAX (returns project_id)
- **`verifyProjectExists(projectId)`** - Verifies project exists on server
- **`uploadPDFForExtraction(file)`** - Uploads PDF for extraction processing
- **`showExtractionPreview(extractionData)`** - Displays extraction preview with detected items
- **`formatDimensions(item)`** - Formats dimensions for display
- **`formatMaterials(item)`** - Formats materials for display
- **`setupExtractionHandlers()`** - Sets up confirm/cancel extraction buttons
- **`confirmExtraction()`** - Imports extracted items into form
- **`cancelExtraction()`** - Cancels extraction and resets
- **`getProjectId()`** - Gets project ID from form or URL
- **`escapeHtml(text)`** - Escapes HTML to prevent XSS

**AJAX Actions Used:**
- `n88_extract_pdf` - Extract items from PDF
- `n88_confirm_extraction` - Confirm and import extracted items
- `n88_create_draft_for_pdf` - Create draft project for PDF extraction
- `n88_verify_project` - Verify project exists

**Key Features:**
- Drag-and-drop PDF upload
- Automatic project creation if no project_id exists
- Extraction preview with item table (thumbnail, title, dimensions, materials, quantity, status)
- Item status badges (Extracted ✓ / Needs Review ■)
- Automatic import into form with locked fields for extracted items
- Support for "needs review" items (unlocked for editing)
- Field locking for successfully extracted items
- Removes empty items before importing
- Scrolls to imported items after confirmation

**Data Flow:**
1. User uploads PDF → `handlePDFUpload()`
2. If no project_id → `createDraftProject()` → `verifyProjectExists()`
3. Upload PDF → `uploadPDFForExtraction()` → AJAX `n88_extract_pdf`
4. Display preview → `showExtractionPreview()` with extracted items
5. User confirms → `confirmExtraction()` → Import items into form
6. Switch to manual mode to show imported items

---

## Phase 2 Completion Summary

### Phase 2A: Core Frontend Extensions (COMPLETED ✅)

#### Completed Features:
1. **Modal System**
   - ✅ Project detail modal with tabbed interface
   - ✅ Summary, Items, Timeline, Comments, Quote, Notifications, Files tabs
   - ✅ Responsive modal design
   - ✅ Status summary block with project status badges

2. **Comments System**
   - ✅ Add comments to projects and items
   - ✅ Threaded replies (parent/child comments)
   - ✅ Urgent comment flagging
   - ✅ Video attachment support
   - ✅ Real-time comment loading
   - ✅ Comment deletion
   - ✅ Admin vs user comment distinction

3. **Timeline System**
   - ✅ Project timeline with steps
   - ✅ Step completion tracking
   - ✅ Progress calculation
   - ✅ Timeline rendering in modal

4. **Notifications System**
   - ✅ In-app notifications
   - ✅ Email notifications
   - ✅ Unread count badge
   - ✅ Mark as read / Mark all as read
   - ✅ Notification types: comment_added, comment_reply, admin_commented, quote_sent, quote_updated, etc.
   - ✅ Urgent comment notifications
   - ✅ Reply notifications

5. **Quote Management (Client-Side)**
   - ✅ View quote in modal
   - ✅ Edit quote items (dimensions, material, quantity, notes)
   - ✅ Auto-calculate total price
   - ✅ Update quote via AJAX
   - ✅ "Edit" button in Actions column
   - ✅ Message to admin field

6. **Item Management**
   - ✅ Item cards with expand/collapse
   - ✅ Item comments display
   - ✅ Item files display
   - ✅ Item editing interface

#### Files Modified/Created:
- `assets/n88-rfq-modal.js` (created, ~2760 lines)
- `assets/css/n88-rfq-form.css` (extended with modal styles)
- `includes/class-n88-rfq-frontend.php` (AJAX handlers added)
- `includes/class-n88-rfq-comments.php` (created)
- `includes/class-n88-rfq-notifications.php` (created)

---

### Phase 2B: PDF Extraction & Enhanced Features (COMPLETED ✅)

#### Completed Features:
1. **PDF Extraction System**
   - ✅ PDF upload via drag-and-drop or file input
   - ✅ Automatic item extraction from PDFs
   - ✅ Extraction preview with item table
   - ✅ Item status detection (Extracted / Needs Review)
   - ✅ Import extracted items into form
   - ✅ Field locking for extracted items
   - ✅ Support for "needs review" items (unlocked)
   - ✅ Automatic project creation if needed
   - ✅ Extraction error handling

2. **Item Flagging System**
   - ✅ Add/remove item flags (urgent, changed, etc.)
   - ✅ Flag summary on dashboard
   - ✅ Flag notifications
   - ✅ Flag display on project detail page

3. **Enhanced Quote Management**
   - ✅ Admin can edit all quote fields after sending
   - ✅ Client can edit quote items
   - ✅ "Updated by Client" status display
   - ✅ Highlighted client update section (admin view)
   - ✅ "Mark as Viewed" for client updates
   - ✅ Quote status: sent → quote_updated
   - ✅ Important message to client field
   - ✅ Quote file upload/replacement
   - ✅ Recalculate pricing button
   - ✅ Dynamic pricing summary update

4. **Instant Pricing Calculator**
   - ✅ Real-time pricing calculation
   - ✅ CBM calculation
   - ✅ Lead time calculation
   - ✅ Volume rules application

5. **Enhanced Notifications**
   - ✅ Extraction failure notifications
   - ✅ Needs review item notifications
   - ✅ Urgent flag notifications
   - ✅ Quote update notifications
   - ✅ Admin update notifications

#### Files Modified/Created:
- `assets/n88-rfq-pdf-extraction.js` (created, ~1197 lines)
- `includes/class-n88-rfq-pdf-extractor.php` (created)
- `includes/class-n88-rfq-item-flags.php` (created)
- `includes/class-n88-rfq-pricing.php` (created)
- `includes/class-n88-rfq-admin.php` (extended with quote editing)
- `includes/class-n88-rfq-frontend.php` (extended with PDF extraction AJAX)
- `includes/class-n88-rfq-quotes.php` (extended with client_message, quote_items)
- `assets/css/n88-rfq-form.css` (extended with PDF extraction styles)

#### Database Changes:
- Added `client_message` column to `project_quotes` table
- Added `quote_items` column to `project_quotes` table
- Project metadata flags: `n88_has_client_updates`, `n88_client_updates_viewed`, `n88_has_admin_updates`

---

### Partially Completed / Future Enhancements

#### Known Limitations:
1. **PDF Extraction**
   - Extraction accuracy depends on PDF structure
   - Some PDFs may require manual review
   - Complex table structures may not extract perfectly

2. **Quote Status**
   - Status transitions are mostly complete
   - May need additional status types in future

3. **Notifications**
   - Email templates could be more customizable
   - Push notifications not implemented (future enhancement)

4. **Timeline**
   - Timeline steps are basic (could be enhanced with dependencies)
   - No timeline editing interface yet

---

## Architecture Overview

### Frontend Architecture
```
User Action
    ↓
JavaScript Module (window.N88StudioOS.*)
    ↓
AJAX Request (WordPress AJAX)
    ↓
PHP Handler (class-n88-rfq-frontend.php)
    ↓
Business Logic (Class methods)
    ↓
Database (WordPress + Custom Tables)
    ↓
Response (JSON)
    ↓
JavaScript Update UI
```

### Key Design Patterns

1. **Module Pattern** - JavaScript uses IIFE (Immediately Invoked Function Expression) for encapsulation
2. **Namespace Pattern** - All modules use `window.N88StudioOS` namespace
3. **AJAX Pattern** - All frontend interactions use WordPress AJAX
4. **Nonce Security** - All AJAX requests include nonce verification
5. **Event Delegation** - Used for dynamically loaded content
6. **Progressive Enhancement** - Works with and without JavaScript
7. **Single Source of Truth** - One implementation per feature/module

### Database Schema

**Custom Tables:**
- `wp_project_metadata` - Project metadata (key-value pairs)
- `wp_project_quotes` - Quote data (includes client_message, quote_items)
- `wp_project_comments` - Comments system
- `wp_project_notifications` - Notification system
- `wp_project_audit_log` - Audit trail

**WordPress Tables Used:**
- `wp_users` - User accounts
- `wp_posts` - Project posts (if using post type)
- `wp_postmeta` - Project metadata (alternative storage)

---

## Development Notes

### Adding New Features

1. **New JavaScript Module:**
   - Create file in `assets/` following naming convention: `n88-rfq-*.js`
   - Use `window.N88StudioOS` namespace
   - Initialize namespace if it doesn't exist
   - Attach module: `window.N88StudioOS.ModuleName = self;`
   - Enqueue in `class-n88-rfq-frontend.php` or `class-n88-rfq-admin.php`
   - Use WordPress AJAX pattern
   - Follow single source of truth principle

2. **New AJAX Handler:**
   - Add action in `__construct()` of `class-n88-rfq-frontend.php`
   - Create public method `ajax_*()`
   - Include nonce verification
   - Include capability check
   - Sanitize all inputs
   - Validate on backend (never trust frontend)
   - Return JSON response

3. **New Database Column:**
   - Add to table schema in `class-n88-rfq-installer.php`
   - Use `snake_case` for column names
   - Update `maybe_upgrade()` method
   - Handle migration if needed
   - Add appropriate indexes

4. **New PHP Class:**
   - Follow naming convention: `N88_RFQ_ClassName` or use namespace `N88RFQ`
   - Place in `includes/` directory
   - Use `camelCase` for methods
   - Include proper documentation


---

## Version History

- **v1.3.0** - Codebase standardization (N88 Studio OS namespace, development standards)
- **v1.2.0** - Phase 2B completion (PDF extraction, enhanced quotes, item flags)
- **v1.1.0** - Phase 2A completion (modal, comments, timeline, notifications)
- **v1.0.0** - Initial release (Phase 1)

---


*Last Updated: 1st December 2025*
*Maintained by: NorthEightyEight Development Team*
*Standards: N88 Studio OS Development Standards v1.0*

