# Milestone 1.2 — Core Intelligence + Material Bank

**Status:** Approved for Implementation  
**Date:** 2024  
**Commit Plan:** 1.2.1 → 1.2.2 → 1.2.3 → 1.2.4

---

## Source of Truth: Approved Architecture Plan

This document contains the approved Milestone 1.2 architecture and implementation plan. All implementation must follow this specification exactly.

---

## In Scope

### Data Model Extensions
- **New Tables:**
  - `n88_materials` — Material bank (admin-managed)
  - `n88_item_materials` — Item-material join table
  - `n88_material_requests` — Schema only (no workflow)
- **Item Field Additions:**
  - `sourcing_type` — User-selectable enum (local, global, custom, hybrid)
  - `timeline_type` — Derived from sourcing_type (not user-editable)
  - `dimension_width_cm`, `dimension_depth_cm`, `dimension_height_cm` — Normalized dimensions
  - `dimension_units_original` — Original unit used for input
  - `cbm` — Calculated cubic meters

### Intelligence Rules
- **Sourcing Type:** User-selectable, designers can correct in Phase 1
- **Timeline Type:** Always derived from sourcing_type, automatically recalculates
- **Unit Normalization:** Convert to cm (canonical), preserve originals in edit history
- **CBM Calculation:** Automatic recalculation on dimension changes
- **Change Handling:** Full edit history and event logging for all intelligence changes

### Material Bank
- **Admin-Managed:** Only admins can create/edit/deactivate materials
- **Designer Read-Only:** Designers can browse, filter, and attach materials to items
- **Soft Delete:** Materials can be deactivated or soft-deleted (preserves history)

### Materials-in-Mind
- **User Uploads:** Reuse existing upload hardening patterns
- **File Storage:** Use existing `n88_item_files` table (from 1.1)
- **No Bank Pollution:** Materials-in-mind are separate from material bank

### Security & Validation
- Prevent designers from modifying material bank
- Validate material IDs on attach
- Prevent cross-user attachment abuse
- Validate dimension ranges and units
- Ensure recalculation logic cannot be abused

### Events & Audit Trail
- `item_sourcing_type_set`
- `item_timeline_type_derived`
- `item_dimension_changed`
- `item_cbm_recalculated`
- `material_created`
- `material_updated`
- `material_deactivated`
- `item_material_attached`
- `item_material_detached`
- `item_material_in_mind_uploaded`

---

## Out of Scope / Not Allowed

### ❌ DO NOT Redesign Milestone 1.1
- Do NOT modify existing `n88_items`, `n88_boards`, `n88_events`, `n88_item_edits` table structures
- Do NOT change ownership or authorization logic from 1.1
- Do NOT modify event spine architecture

### ❌ DO NOT Introduce New Workflows
- No projects, quotes, timelines UI
- No pricing calculations or pricing UI
- No material request workflow (schema only)

### ❌ DO NOT Introduce Infrastructure
- No caching, queues, background jobs
- No AI or async processing
- No new file storage systems

### ❌ DO NOT Expand Public Surface Area
- No new public/AJAX endpoints beyond what's necessary for 1.2
- Reuse existing endpoint patterns

### ❌ DO NOT Create New File Systems
- Materials-in-mind MUST use existing `n88_item_files` table
- MUST reuse existing upload hardening patterns
- No parallel "files" schema

### ❌ DO NOT Make Timeline Type Immutable
- Timeline type is NOT immutable in Phase 1
- Locking happens only later (Phase 2/3, project promotion)
- Timeline type can change if sourcing_type changes

---

## Key Non-Negotiables

### 1. Timeline Type Derivation
- **ALWAYS derived from sourcing_type**
- **NOT user-editable**
- **Automatically recalculates** when sourcing_type changes
- **NOT immutable in Phase 1** (locking happens later)

### 2. Sourcing Type Editable in Phase 1
- Designers can set and correct sourcing_type on their own items
- No admin gate required for correction
- Changes trigger timeline_type recalculation

### 3. No New Public CRUD Endpoints
- Reuse existing endpoint patterns
- Extend existing item update endpoint for dimensions/sourcing
- Material bank management: Admin-only endpoints (not public)

### 4. Reuse n88_item_files for Materials-in-Mind
- Materials-in-mind MUST use existing `n88_item_files` table
- Set `attachment_type` to `material_reference` or `material_in_mind`
- NO new file system model

### 5. Unit Normalization
- Canonical unit: **centimeters (cm)**
- Store normalized values in `dimension_*_cm` columns
- Preserve original unit in `dimension_units_original`
- Original values preserved in edit history

### 6. CBM Calculation
- Formula: `(width_cm × depth_cm × height_cm) / 1,000,000`
- Round to 6 decimal places
- Recalculate automatically on dimension changes
- NULL if any dimension missing

### 7. Material Bank Access
- **Admins:** Full CRUD on `n88_materials`
- **Designers:** Read-only (browse, filter, attach only)
- **No editing/deletion by designers**

---

## Commit Plan

### Commit 1.2.1: Schema Extensions
**Files:**
- `includes/class-n88-rfq-installer.php` — Add Phase 1.2 tables and item field additions

**Contains:**
- CREATE TABLE for `n88_materials`
- CREATE TABLE for `n88_item_materials`
- CREATE TABLE for `n88_material_requests` (schema only)
- ALTER TABLE for `n88_items` (add new columns)
- Indexes for new fields

**Reversible:** Yes (DROP TABLE, ALTER TABLE DROP COLUMN)

**Reviewable:** SQL schema only, no application logic

---

### Commit 1.2.2: Intelligence Helpers
**Files:**
- `includes/class-n88-intelligence.php` (new) — Intelligence calculation helpers

**Contains:**
- Unit normalization functions (convert to cm)
- CBM calculation function
- Timeline type derivation function
- Dimension validation functions

**Reversible:** Yes (can disable by removing class instantiation)

**Reviewable:** Calculation logic only, no endpoints

---

### Commit 1.2.3: Material Bank Management
**Files:**
- `includes/class-n88-materials.php` (new) — Material bank management

**Contains:**
- Admin-only CRUD endpoints for materials
- Designer read-only endpoints (browse, filter)
- Material attach/detach endpoints
- Authorization checks (admin vs designer)

**Reversible:** Yes (can disable endpoints)

**Reviewable:** Material management logic only

---

### Commit 1.2.4: Item Intelligence Integration
**Files:**
- `includes/class-n88-items.php` — Extend existing item endpoints

**Contains:**
- Extend `ajax_create_item` to accept sourcing_type and dimensions
- Extend `ajax_update_item` to accept sourcing_type and dimensions
- Automatic timeline_type derivation
- Automatic CBM recalculation
- Event logging for intelligence changes
- Edit history for dimension changes

**Reversible:** Yes (can revert item endpoint changes)

**Reviewable:** Item intelligence integration only

---

### Commit 1.2.5: Materials-in-Mind Integration
**Files:**
- Reuse existing upload endpoints (if needed, extend)

**Contains:**
- Extend existing file upload to support `attachment_type='material_reference'`
- Link materials-in-mind via `n88_item_files` table
- Event logging for material-in-mind uploads

**Reversible:** Yes (can revert upload extensions)

**Reviewable:** Materials-in-mind integration only

---

### Commit 1.2.6: Integration & Bootstrap
**Files:**
- `n88-rfq-platform.php` — Class instantiation

**Contains:**
- Instantiate `N88_Intelligence` (if needed as singleton)
- Instantiate `N88_Materials`
- Integration with existing system

**Reversible:** Yes (can comment out Phase 1.2 instantiation)

**Reviewable:** Integration points only

---

### Commit 1.2.7: Tests / QA Checklist
**Files:**
- `QA_MILESTONE_1_2.md` (new) — Manual QA checklist

**Contains:**
- 14+ test cases covering all intelligence rules
- Material bank access tests
- Event verification tests
- Edit history verification tests

**Reversible:** Yes (can remove test file)

**Reviewable:** Test cases only

---

## Detailed Architecture Reference

### Intelligence Rules

#### Sourcing Type Detection
- **Allowed Values:** `local`, `global`, `custom`, `hybrid`
- **Default:** `local` for new items
- **User Override:** Designers can set and correct (no admin gate in Phase 1)
- **Storage:** Stored in `sourcing_type` column

#### Timeline Type Assignment
- **Derivation Rules:**
  - `local` → `standard`
  - `global` → `extended`
  - `custom` → `variable`
  - `hybrid` → `extended`
- **Recalculation:** Automatic when sourcing_type changes
- **Storage:** Stored in `timeline_type` column (not computed on-demand)
- **Immutability:** NOT immutable in Phase 1

#### Unit Normalization
- **Supported Units:** `cm`, `mm`, `m`, `in`, `ft`
- **Canonical Unit:** `cm`
- **Conversion Rules:**
  - `mm → cm`: divide by 10
  - `m → cm`: multiply by 100
  - `in → cm`: multiply by 2.54
  - `ft → cm`: multiply by 30.48
- **Storage:** Normalized in `dimension_*_cm` columns, original unit in `dimension_units_original`

#### CBM Calculation
- **Formula:** `(width_cm × depth_cm × height_cm) / 1,000,000`
- **Rounding:** 6 decimal places
- **Recalculation:** Automatic on dimension changes
- **Edge Cases:**
  - NULL if any dimension missing
  - 0.000000 if any dimension is 0
  - Reject negative or extreme values (>1000m)

### Material Bank Structure

#### n88_materials Fields
- `name` (required)
- `description` (optional)
- `category` (optional)
- `material_code` (optional)
- `supplier_name` (optional)
- `supplier_contact` (optional)
- `unit_cost` (optional)
- `currency` (default: USD)
- `lead_time_days` (optional)
- `minimum_order_quantity` (optional)
- `notes` (optional)
- `is_active` (default: 1)
- `created_by_user_id` (required)
- `deleted_at` (nullable)

#### Designer Behavior
- Browse/filter materials (read-only)
- Attach materials to own items via `n88_item_materials`
- Cannot edit or delete materials

#### Admin Behavior
- Full CRUD on materials
- Can deactivate (`is_active = 0`) or soft delete (`deleted_at`)
- Existing item attachments preserved on material deletion

### Materials-in-Mind
- **Storage:** WordPress media library (wp_posts + wp_postmeta)
- **Linking:** Via `n88_item_files` table (existing from 1.1)
- **Attachment Type:** `material_reference` or `material_in_mind`
- **Upload:** Reuse existing upload hardening patterns
- **No Bank Pollution:** Separate from material bank, no automatic promotion

### Events & Audit Trail

All events logged to `n88_events` table with appropriate payloads:

1. **item_sourcing_type_set** — When sourcing_type is set or changed
2. **item_timeline_type_derived** — When timeline_type is calculated
3. **item_dimension_changed** — When any dimension changes
4. **item_cbm_recalculated** — When CBM is recalculated
5. **material_created** — When admin creates material
6. **material_updated** — When admin updates material
7. **material_deactivated** — When material is deactivated/deleted
8. **item_material_attached** — When designer attaches material
9. **item_material_detached** — When designer detaches material
10. **item_material_in_mind_uploaded** — When user uploads material reference

### Security & Validation

- **Material Bank Access:** Admin-only write, designer read-only
- **Material Attach:** Validate material exists, is active, not deleted
- **Cross-User Protection:** Ownership checks before attach/detach
- **Dimension Validation:** Reject invalid units, negative values, extreme values
- **Recalculation Protection:** Server-side only, no client-provided calculated values

---

## Testing Requirements

At least 14 concrete test cases covering:

1. Unit conversion correctness
2. CBM edge cases (zero, missing, extreme values)
3. Dimension change → recalculation
4. Timeline type derivation
5. Material attach/detach permissions
6. Designer vs admin access rules
7. Event creation verification
8. Edit history verification
9. Materials-in-mind upload
10. Material bank soft delete impact
11. Sourcing type correction by designers
12. CBM recalculation on dimension change
13. Material attach idempotency
14. Cross-user attachment prevention

---

## Implementation Notes

- All changes are **additive only** (no breaking changes to 1.1)
- Reuse existing patterns from 1.1 (events, authorization, sanitization)
- Follow same commit strategy as 1.1 (small, reviewable commits)
- Each commit must be reversible and reviewable independently

---

**End of Milestone 1.2 Documentation**

