# Phase 3 Requirements - Understanding Confirmation

Dear [Client Name],

Thank you for providing the comprehensive Phase 3 specification and answering my clarification questions. I've reviewed all requirements and want to confirm my understanding before we begin implementation.

## What I Understand About Phase 3

### 1. **Automatic Timeline Assignment (Per Item)**
- The system will automatically assign the correct timeline type to each item based on its Product Category:
  - **6-Step Furniture Production Timeline** for: Indoor/Outdoor Furniture, Sofas, Sectionals, Chairs, Tables, Casegoods, Millwork, Upholstery, etc.
  - **4-Step Global Sourcing Timeline** for: Lighting, Flooring, Marble/Stone, Drapery, Accessories, Hardware, Metalwork, External Factory Sourcing, etc.
  - **No Timeline** for: Material Sample Kit only projects
- This logic runs automatically when items are created or added to projects.

### 2. **Timeline Architecture (Hybrid Storage Model)**
- **Timeline Structure (JSON)**: Stored per-item in the item data, containing:
  - Timeline type (6step_furniture, 4step_sourcing, none)
  - Step definitions (step_key, label, order, descriptions, icons)
  - Static metadata that doesn't change frequently
- **Timeline Events Table**: New dedicated table (`wp_n88_timeline_events`) for:
  - Tracking status changes (started, completed, delayed, unblocked, forced_completion)
  - Current state of each step (pending, in_progress, completed, blocked)
  - Full audit trail with timestamps and user tracking
  - Enables future analytics and bottleneck detection

### 3. **Timeline Steps**

**6-Step Furniture Production Timeline:**
1. Prototype
2. Frame / Structure
3. Surface Treatment
4. Upholstery / Fabrication
5. Final QC
6. Packing & Delivery

**4-Step Global Sourcing Timeline:**
1. Sourcing
2. Production / Procurement
3. Quality Check
4. Packing & Delivery

Each step supports:
- YouTube-nocookie videos (lazy-loaded)
- Step-level comments (separate from item-level comments)
- Step-level file uploads
- Admin-only notes
- Completion toggle with timestamp
- Sequential dependency enforcement

### 4. **Step Dependencies & Admin Override**
- **Default Behavior**: Steps must be completed in sequential order (1 → 2 → 3 → ...)
- **User Experience**: Future steps appear locked/disabled with message: "Complete Step X before Step Y"
- **Admin Override**: Administrators can "Force Complete" any step out of order:
  - Requires reason/notes
  - Full audit trail logged in events table
  - Proper permission and nonce checks

### 5. **YouTube Video System**
- **Embedding**: Always uses `youtube-nocookie.com` for privacy and performance
- **Lazy-Loading**: Videos only load when user clicks play
- **Thumbnails**: 
  - Primary: WordPress Media Library (custom uploaded thumbnails)
  - Fallback: YouTube default thumbnail (maxresdefault.jpg)
- **Assignment**: Videos can be assigned to Project, Item, or specific Timeline Step
- **Two Entry Points**:
  1. Content Manager → Upload New Video
  2. Timeline Step → Upload Video
- **Display Locations**: Timeline steps, Modal Video Panel, Media Library, Content Manager

### 6. **Content Manager (New Admin Menu)**
- **New Top-Level Menu**: "N88 Studio OS"
- **Menu Structure**:
  - Dashboard (overview, KPIs)
  - All Projects (existing project list)
  - Content Manager
    - Video Library
    - Upload Video
    - Auto-Extracted Items
    - Media Library
  - Settings (global OS settings)
- **Purpose**: Central hub for Phase 3-7 features, treating the plugin as a standalone app within WordPress

### 7. **Cross-Item Navigation**
- Inside the project modal, users can navigate between items without closing the modal
- Navigation via tabs, sidebar, or accordion list
- Clicking an item instantly reloads:
  - Timeline (correct type based on category)
  - Files
  - Videos (item-level + step-level)
  - Comments (item-level + step-level)
  - Notes
  - Urgent flags
  - Pricing panel

### 8. **Item-Level Comments (Separate from Steps)**
- Each item has a general comment thread not tied to timeline steps
- For conversations like: "Can we lighten the wood tone?", "Change stitching color", etc.
- Always visible in item card
- Separate from step-level comments (which are tied to specific timeline steps)

### 9. **Completed Project Summary**
- When project status = "Completed":
  - Hide instant estimate calculator
  - Collapse/disable editable fields
  - Show Final Production Summary view
- **Summary Includes**:
  - Completion date
  - Total lead time
  - Item count
  - Factory name (if stored)
  - Admin notes
- **PDF Export Contains**:
  - All items
  - All timeline steps (with completion status)
  - All video links
  - All comments (item-level + step-level)
  - All files list

### 10. **"Add Another Item" Feature**
- **Location**: Inside project modal, Items/Quote Status section
- **Two Actions** (available to both Admin and User):
  1. "Add Another Item – Needs Quote"
  2. "Add Another Item – Quoted"
- **Form Fields**: Item title, Product category, Dimensions (L/D/H), Quantity, Basic notes
- **On Save**:
  - New item appended to project
  - Timeline type automatically assigned based on category
  - Appears in all relevant views (item list, timeline, navigation, PDF export)
- **Permissions**: Project owners can add to their projects; Admins can add to any project

### 11. **Dashboard vs Modal Display**
- **Dashboard**: Shows only high-level status badges ("In Production", "In Sourcing", "In QC", "Packing", "Completed") - no timeline UI
- **Modal**: Full timeline UI per item with all step details, videos, comments, files, and navigation

---

## Technical Implementation Plan

### Database Changes
1. Create `wp_n88_timeline_events` table for status tracking and audit trail
2. Create `wp_n88_project_videos` table for video management
3. Add `timeline_structure` JSON to item data structure
4. Migration script for existing items (assign timeline types based on categories)

### Backend Development
1. Timeline assignment logic (category → timeline type)
2. Timeline step management and event tracking
3. Video CRUD operations with WP Media Library integration
4. Content Manager admin interface
5. "Add Another Item" AJAX handlers
6. Cross-item navigation endpoint
7. Completed project summary logic
8. PDF export enhancement (include timeline/video/comment data)
9. Step dependency validation (sequential + admin override)

### Frontend Development
1. Timeline rendering system (6-step vs 4-step)
2. YouTube-nocookie lazy-loading implementation
3. Cross-item navigation UI
4. "Add Another Item" form/modal
5. Completed project summary view
6. Video upload interfaces (Content Manager + Timeline Step)
7. Step completion toggles with dependency checks
8. Admin "Force Complete" UI

### Admin Interface
1. New top-level "N88 Studio OS" menu
2. Content Manager submenu structure
3. Video Library page
4. Upload Video page
5. Video management UI (edit, delete, assign)

---

## Confirmation Points

✅ **Timeline Storage**: Hybrid model (JSON structure + events table)  
✅ **Video Thumbnails**: WordPress Media Library with YouTube fallback  
✅ **Content Manager**: New top-level admin menu  
✅ **Step Dependencies**: Sequential for users, admin override with audit trail  
✅ **All Phase 3 Features**: Understood and ready to implement  

---

## Ready to Proceed

I'm ready to begin Phase 3 implementation. The architecture is clear, and all technical decisions have been confirmed. 

**Proposed Implementation Order:**
1. Database schema creation
2. Timeline assignment logic
3. Timeline rendering system
4. Video management system
5. Content Manager admin interface
6. "Add Another Item" feature
7. Cross-item navigation
8. Completed project summary
9. Testing and refinement

Please confirm if this understanding aligns with your requirements, and I'll begin implementation immediately.

Best regards,  
[Your Name]

