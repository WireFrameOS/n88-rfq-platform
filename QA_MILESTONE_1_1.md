# Milestone 1.1 QA Verification Script

**Purpose:** Verify that the Milestone 1.1 foundation works correctly and securely.

**Prerequisites:**
- WordPress installation with plugin activated
- Database access (phpMyAdmin or WP-CLI)
- Two test users (User A and User B)
- One admin user

---

## Setup Steps

### 1. Create Test Users

**Via WordPress Admin or WP-CLI:**

```bash
# Create User A (regular user)
wp user create user_a user_a@test.local --user_pass=password123 --role=subscriber

# Create User B (regular user)
wp user create user_b user_b@test.local --user_pass=password123 --role=subscriber

# Create Admin User (if not exists)
wp user create admin_user admin@test.local --user_pass=password123 --role=administrator
```

**Note User IDs:**
- User A ID: `__USER_A_ID__` (replace in commands below)
- User B ID: `__USER_B_ID__` (replace in commands below)
- Admin ID: `__ADMIN_ID__` (replace in commands below)

### 2. How to Obtain Nonces and Auth Cookies

**IMPORTANT:** Nonces are user-specific and session-specific. Each user must obtain their own nonce while logged in.

#### Method 1: WP-CLI (Recommended for Testing)

**Get Nonce for User A:**
```bash
# Get User A's nonce (replace USER_A_ID with actual user ID)
wp eval "echo wp_create_nonce('n88-rfq-nonce');" --user=user_a
```

**Get Nonce for User B:**
```bash
# Get User B's nonce
wp eval "echo wp_create_nonce('n88-rfq-nonce');" --user=user_b
```

**Get Nonce for Admin:**
```bash
# Get Admin's nonce
wp eval "echo wp_create_nonce('n88-rfq-nonce');" --user=admin_user
```

**Note:** Nonces expire after 24 hours by default. Generate fresh nonces for each test session.

#### Method 2: Browser Console (Manual Testing)

**Steps:**
1. Log in to WordPress as User A
2. Open browser Developer Tools (F12)
3. Go to Console tab
4. Run:
```javascript
// Get nonce from WordPress (if wpApiSettings exists)
var nonce = (typeof wpApiSettings !== 'undefined' && wpApiSettings.nonce) 
    ? wpApiSettings.nonce 
    : '';
console.log('Nonce:', nonce);

// Alternative: If nonce is in a form or data attribute
// Check the test page: WordPress Admin > N88 RFQ > Items & Boards
// The page includes a nonce in the JavaScript
```

**Note:** If `wpApiSettings` is not available, use Method 1 (WP-CLI) or Method 3.

#### Method 3: PHP Debug Script (One-Time Setup)

**Create temporary file:** `wp-content/debug-nonce.php` (DELETE AFTER USE)

```php
<?php
require_once('wp-load.php');

if (!is_user_logged_in()) {
    die('You must be logged in. Visit: ' . wp_login_url($_SERVER['REQUEST_URI']));
}

$user = wp_get_current_user();
$nonce = wp_create_nonce('n88-rfq-nonce');

echo "User ID: " . $user->ID . "\n";
echo "Username: " . $user->user_login . "\n";
echo "Nonce: " . $nonce . "\n";
echo "\n";
echo "Cookie Name: wordpress_logged_in_" . COOKIEHASH . "\n";
echo "Cookie Value: " . $_COOKIE['wordpress_logged_in_' . COOKIEHASH] . "\n";
```

**Usage:**
1. Log in as User A in browser
2. Visit: `http://yoursite.local/wp-content/debug-nonce.php`
3. Copy nonce and cookie value
4. **DELETE THE FILE IMMEDIATELY** (security risk)

#### Method 4: Extract Cookie from Browser

**Steps:**
1. Log in to WordPress as User A
2. Open Developer Tools (F12)
3. Go to Application/Storage tab > Cookies
4. Find cookie: `wordpress_logged_in_xxx` (where `xxx` is your site hash)
5. Copy the cookie value

**Cookie Format:**
```
wordpress_logged_in_xxx=USERNAME|EXPIRY|HASH
```

**For curl commands, use:**
```bash
--cookie "wordpress_logged_in_xxx=USERNAME|EXPIRY|HASH"
```

#### Method 5: Automated Script (All Users at Once)

**Create:** `wp-content/get-test-credentials.php` (DELETE AFTER USE)

```php
<?php
require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Admin access required');
}

$users = array('user_a', 'user_b', 'admin_user');

foreach ($users as $username) {
    $user = get_user_by('login', $username);
    if (!$user) continue;
    
    wp_set_current_user($user->ID);
    $nonce = wp_create_nonce('n88-rfq-nonce');
    
    echo "=== $username (ID: {$user->ID}) ===\n";
    echo "Nonce: $nonce\n";
    echo "Cookie: wordpress_logged_in_" . COOKIEHASH . "\n";
    echo "\n";
}
```

**Usage:**
1. Log in as admin
2. Visit: `http://yoursite.local/wp-content/get-test-credentials.php`
3. Copy all nonces
4. **DELETE THE FILE IMMEDIATELY**

---

#### Quick Reference: Nonce Values

After obtaining nonces, replace in test commands:
- `__NONCE_A__` = User A's nonce
- `__NONCE_B__` = User B's nonce
- `__NONCE_ADMIN__` = Admin's nonce

**Cookie Values:**
- `__USER_A_COOKIE__` = User A's `wordpress_logged_in_xxx` cookie value
- `__USER_B_COOKIE__` = User B's `wordpress_logged_in_xxx` cookie value
- `__ADMIN_COOKIE__` = Admin's `wordpress_logged_in_xxx` cookie value

---

## Test Coverage Matrix

| Requirement | Test ID | Test Name | Expected Result |
|------------|---------|-----------|----------------|
| Ownership Tampering | T1 | User B cannot access User A's item | HTTP 403 |
| Ownership Tampering | T2 | User B cannot update User A's item | HTTP 403 |
| Ownership Tampering | T3 | User B cannot access User A's board | HTTP 403 |
| Ownership Tampering | T4 | User B cannot update User A's board layout | HTTP 403 |
| Event Immutability | T5 | No UPDATE method exists in N88_Events | Method does not exist |
| Event Immutability | T6 | Events are INSERT-only | No UPDATE queries possible |
| Edit History | T7 | Item update creates edit records | n88_item_edits rows created |
| Edit History | T8 | Multiple field updates create multiple edit records | One row per field |
| Layout Update | T9 | Layout update persists to database | n88_board_layout updated |
| Layout Update | T10 | Layout update creates event | n88_events row created |
| Layout Update | T11 | Rate limit triggers at threshold | HTTP 429 after 100 requests |
| Installer Idempotency | T12 | Installer runs twice without errors | No fatal errors |
| Installer Idempotency | T13 | Schema version remains correct | Version = 1.1.0 |
| Admin Override | T14 | Admin can access any item | HTTP 200 |
| Admin Override | T15 | Non-admin cannot bypass ownership | HTTP 403 |
| Nonce Tampering | T16 | User B cannot use User A's nonce | HTTP 403/404 |

---

## Test Execution

### T1: User B Cannot Access User A's Item

**Step 1: User A creates an item**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_create_item" \
  -d "title=User A Item" \
  -d "description=Test item" \
  -d "item_type=furniture" \
  -d "status=draft" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "item_id": 123,
    "message": "Item created successfully."
  }
}
```

**Note Item ID:** `__ITEM_A_ID__` (replace below)

**Step 2: User B attempts to access User A's item**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Hacked Title" \
  -d "nonce=__NONCE_B__" \
  --cookie "wordpress_logged_in_xxx=__USER_B_COOKIE__"
```

**Expected Response:**
```json
{
  "success": false,
  "data": {
    "message": "Item not found or access denied."
  }
}
```

**Expected HTTP Status:** `403 Forbidden`

**DB Verification:**
```sql
-- Verify item title unchanged
SELECT id, title, owner_user_id 
FROM wp_n88_items 
WHERE id = __ITEM_A_ID__;

-- Expected: title = "User A Item", owner_user_id = __USER_A_ID__
```

---

### T2: User B Cannot Update User A's Item

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Unauthorized Update" \
  -d "nonce=__NONCE_B__" \
  --cookie "wordpress_logged_in_xxx=__USER_B_COOKIE__"
```

**Expected Response:**
```json
{
  "success": false,
  "data": {
    "message": "Item not found or access denied."
  }
}
```

**Expected HTTP Status:** `403 Forbidden`

**DB Verification:**
```sql
-- Verify no edit records created by User B
SELECT * 
FROM wp_n88_item_edits 
WHERE item_id = __ITEM_A_ID__ 
  AND editor_user_id = __USER_B_ID__;

-- Expected: 0 rows

-- Verify item unchanged
SELECT title, version 
FROM wp_n88_items 
WHERE id = __ITEM_A_ID__;

-- Expected: title = "User A Item", version unchanged
```

---

### T3: User B Cannot Access User A's Board

**Step 1: User A creates a board**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_create_board" \
  -d "name=User A Board" \
  -d "description=Test board" \
  -d "view_mode=grid" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "board_id": 456,
    "message": "Board created successfully."
  }
}
```

**Note Board ID:** `__BOARD_A_ID__` (replace below)

**Step 2: User B attempts to add item to User A's board**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_add_item_to_board" \
  -d "board_id=__BOARD_A_ID__" \
  -d "item_id=__ITEM_A_ID__" \
  -d "nonce=__NONCE_B__" \
  --cookie "wordpress_logged_in_xxx=__USER_B_COOKIE__"
```

**Expected Response:**
```json
{
  "success": false,
  "data": {
    "message": "Board not found or access denied."
  }
}
```

**Expected HTTP Status:** `403 Forbidden`

**DB Verification:**
```sql
-- Verify no board-item relationship created
SELECT * 
FROM wp_n88_board_items 
WHERE board_id = __BOARD_A_ID__ 
  AND added_by_user_id = __USER_B_ID__;

-- Expected: 0 rows
```

---

### T4: User B Cannot Update User A's Board Layout

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_board_layout" \
  -d "board_id=__BOARD_A_ID__" \
  -d "item_id=__ITEM_A_ID__" \
  -d "position_x=100" \
  -d "position_y=200" \
  -d "nonce=__NONCE_B__" \
  --cookie "wordpress_logged_in_xxx=__USER_B_COOKIE__"
```

**Expected Response:**
```json
{
  "success": false,
  "data": {
    "message": "Board not found or access denied."
  }
}
```

**Expected HTTP Status:** `403 Forbidden`

**DB Verification:**
```sql
-- Verify no layout records created by User B
SELECT * 
FROM wp_n88_board_layout 
WHERE board_id = __BOARD_A_ID__;

-- Expected: 0 rows (if item not on board) OR existing rows unchanged
```

---

### T5: Event Immutability - No UPDATE Method Exists

**Verification Method: Code Inspection**

**Check File:** `includes/class-n88-events.php`

**Verification:**
```bash
# Search for update/delete methods
grep -i "function.*update\|function.*delete" includes/class-n88-events.php
```

**Expected Result:** No matches (only `insert_event` method exists)

**Manual Code Review:**
- Open `includes/class-n88-events.php`
- Verify only `insert_event()` method exists
- Verify no `update_event()` or `delete_event()` methods
- Verify no `$wpdb->update()` or `$wpdb->delete()` calls

**Expected:** Only `insert_event()` method, only `$wpdb->insert()` calls

---

### T6: Events Are INSERT-Only

**Step 1: Create an event**

**Request (via User A creating item):**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_create_item" \
  -d "title=Test Item for Event" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Note Event ID:** `__EVENT_ID__` (from DB query below)

**Step 2: Verify event exists**

**DB Verification:**
```sql
-- Get latest event
SELECT id, event_type, object_type, created_at 
FROM wp_n88_events 
ORDER BY id DESC 
LIMIT 1;

-- Note the event ID: __EVENT_ID__
```

**Step 3: Attempt to update event (should fail)**

**DB Verification (Direct SQL - should be prevented by application):**
```sql
-- This should NOT work if application enforces immutability
-- But we verify the application prevents it

-- Check if any UPDATE queries exist in codebase
grep -r "\$wpdb->update.*n88_events" includes/
```

**Expected Result:** No matches

**Manual Verification:**
- Search codebase for `$wpdb->update` with `n88_events` table
- Expected: No such queries exist

---

### T7: Item Update Creates Edit Records

**Step 1: User A updates item title**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Updated Title" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "item_id": __ITEM_A_ID__,
    "message": "Item updated successfully.",
    "changed_fields": ["title"]
  }
}
```

**Expected HTTP Status:** `200 OK`

**DB Verification:**
```sql
-- Verify item updated
SELECT id, title, version 
FROM wp_n88_items 
WHERE id = __ITEM_A_ID__;

-- Expected: title = "Updated Title", version incremented

-- Verify edit record created
SELECT id, item_id, field_name, old_value, new_value, editor_user_id, created_at 
FROM wp_n88_item_edits 
WHERE item_id = __ITEM_A_ID__ 
  AND field_name = 'title'
ORDER BY id DESC 
LIMIT 1;

-- Expected: 
-- old_value = "User A Item" (or previous title)
-- new_value = "Updated Title"
-- editor_user_id = __USER_A_ID__

-- Verify event created
SELECT id, event_type, object_type, item_id 
FROM wp_n88_events 
WHERE item_id = __ITEM_A_ID__ 
  AND event_type = 'item_field_changed'
ORDER BY id DESC 
LIMIT 1;

-- Expected: event exists with correct item_id
```

---

### T8: Multiple Field Updates Create Multiple Edit Records

**Step 1: User A updates multiple fields**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Multi Field Update" \
  -d "description=New description" \
  -d "status=active" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "item_id": __ITEM_A_ID__,
    "message": "Item updated successfully.",
    "changed_fields": ["title", "description", "status"]
  }
}
```

**Expected HTTP Status:** `200 OK`

**DB Verification:**
```sql
-- Verify multiple edit records (one per changed field)
SELECT field_name, old_value, new_value, created_at 
FROM wp_n88_item_edits 
WHERE item_id = __ITEM_A_ID__ 
  AND editor_user_id = __USER_A_ID__
ORDER BY id DESC 
LIMIT 3;

-- Expected: 3 rows
-- Row 1: field_name = 'title', new_value = 'Multi Field Update'
-- Row 2: field_name = 'description', new_value = 'New description'
-- Row 3: field_name = 'status', new_value = 'active'

-- Verify single event created
SELECT COUNT(*) as event_count 
FROM wp_n88_events 
WHERE item_id = __ITEM_A_ID__ 
  AND event_type = 'item_field_changed'
  AND payload_json LIKE '%Multi Field Update%';

-- Expected: event_count >= 1
```

---

### T9: Layout Update Persists to Database

**Step 1: User A adds item to board (if not already added)**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_add_item_to_board" \
  -d "board_id=__BOARD_A_ID__" \
  -d "item_id=__ITEM_A_ID__" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Step 2: User A updates board layout**

**Request:**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_board_layout" \
  -d "board_id=__BOARD_A_ID__" \
  -d "item_id=__ITEM_A_ID__" \
  -d "position_x=150.50" \
  -d "position_y=250.75" \
  -d "position_z=5" \
  -d "size_width=100.00" \
  -d "size_height=200.00" \
  -d "view_mode=grid" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_A_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "board_id": __BOARD_A_ID__,
    "item_id": __ITEM_A_ID__,
    "message": "Board layout updated successfully."
  }
}
```

**Expected HTTP Status:** `200 OK`

**DB Verification:**
```sql
-- Verify layout persisted
SELECT board_id, item_id, position_x, position_y, position_z, size_width, size_height, view_mode, updated_at 
FROM wp_n88_board_layout 
WHERE board_id = __BOARD_A_ID__ 
  AND item_id = __ITEM_A_ID__;

-- Expected:
-- position_x = 150.50
-- position_y = 250.75
-- position_z = 5
-- size_width = 100.00
-- size_height = 200.00
-- view_mode = 'grid'
```

---

### T10: Layout Update Creates Event

**DB Verification (after T9):**
```sql
-- Verify event created
SELECT id, event_type, object_type, board_id, item_id, payload_json, created_at 
FROM wp_n88_events 
WHERE board_id = __BOARD_A_ID__ 
  AND item_id = __ITEM_A_ID__ 
  AND event_type = 'board_layout_updated'
ORDER BY id DESC 
LIMIT 1;

-- Expected: 
-- event_type = 'board_layout_updated'
-- object_type = 'board_layout'
-- board_id = __BOARD_A_ID__
-- item_id = __ITEM_A_ID__
-- payload_json contains position_x, position_y, position_z, view_mode
```

---

### T11: Rate Limit Triggers at Threshold

**Step 1: Make 101 layout update requests rapidly**

**Script (bash):**
```bash
#!/bin/bash
NONCE="__NONCE_A__"
BOARD_ID=__BOARD_A_ID__
ITEM_ID=__ITEM_A_ID__
COOKIE="wordpress_logged_in_xxx=__USER_A_COOKIE__"

for i in {1..101}; do
  echo "Request $i:"
  curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "action=n88_update_board_layout" \
    -d "board_id=$BOARD_ID" \
    -d "item_id=$ITEM_ID" \
    -d "position_x=$i" \
    -d "position_y=$i" \
    -d "nonce=$NONCE" \
    --cookie "$COOKIE" \
    -w "\nHTTP Status: %{http_code}\n\n"
  
  sleep 0.1
done
```

**Expected Results:**
- Requests 1-100: HTTP 200 OK
- Request 101: HTTP 429 Too Many Requests

**Expected Response (Request 101):**
```json
{
  "success": false,
  "data": {
    "message": "Rate limit exceeded. Please try again in X second(s).",
    "retry_after": 60
  }
}
```

**DB Verification:**
```sql
-- Verify rate limit transient exists
SELECT option_name, option_value 
FROM wp_options 
WHERE option_name LIKE 'n88_rfq_board_layout_update_%';

-- Expected: Transient exists with count >= 100
```

---

### T12: Installer Idempotency - Run Twice

**Step 1: Deactivate plugin**

**Via WP-CLI:**
```bash
wp plugin deactivate n88-rfq-platform
```

**Step 2: Activate plugin (first run)**

**Via WP-CLI:**
```bash
wp plugin activate n88-rfq-platform
```

**Check for errors:**
```bash
wp plugin list | grep n88-rfq-platform
```

**Expected:** Plugin active, no errors

**Step 3: Deactivate and reactivate (second run)**

**Via WP-CLI:**
```bash
wp plugin deactivate n88-rfq-platform
wp plugin activate n88-rfq-platform
```

**Expected:** No fatal errors, plugin activates successfully

**DB Verification:**
```sql
-- Verify tables exist
SHOW TABLES LIKE 'wp_n88_%';

-- Expected: All Phase 1.1 tables exist:
-- wp_n88_designer_profiles
-- wp_n88_items
-- wp_n88_boards
-- wp_n88_board_items
-- wp_n88_board_layout
-- wp_n88_events
-- wp_n88_item_edits
-- wp_n88_firms (schema-only)
-- wp_n88_firm_members (schema-only)
-- wp_n88_board_areas (schema-only)
-- wp_n88_item_files (schema-only)

-- Verify schema version
SELECT option_value 
FROM wp_options 
WHERE option_name = 'n88_phase_1_1_schema_version';

-- Expected: '1.1.0'

-- Verify no duplicate keys
SELECT COUNT(*) as table_count 
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
  AND table_name LIKE 'wp_n88_%';

-- Expected: 11 tables (no duplicates)
```

---

### T13: Schema Version Remains Correct

**DB Verification (after T12):**
```sql
-- Verify schema version
SELECT option_value 
FROM wp_options 
WHERE option_name = 'n88_phase_1_1_schema_version';

-- Expected: '1.1.0' (exact match)

-- Verify version is set
SELECT COUNT(*) as version_count 
FROM wp_options 
WHERE option_name = 'n88_phase_1_1_schema_version' 
  AND option_value = '1.1.0';

-- Expected: 1
```

---

### T14: Admin Can Access Any Item

**Step 1: Admin attempts to access User A's item**

**Request (as Admin):**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Admin Updated Title" \
  -d "nonce=__NONCE_ADMIN__" \
  --cookie "wordpress_logged_in_xxx=__ADMIN_COOKIE__"
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "item_id": __ITEM_A_ID__,
    "message": "Item updated successfully.",
    "changed_fields": ["title"]
  }
}
```

**Expected HTTP Status:** `200 OK`

**DB Verification:**
```sql
-- Verify admin can update item
SELECT id, title, version 
FROM wp_n88_items 
WHERE id = __ITEM_A_ID__;

-- Expected: title = "Admin Updated Title", version incremented

-- Verify edit record shows admin as editor
SELECT editor_user_id, editor_role 
FROM wp_n88_item_edits 
WHERE item_id = __ITEM_A_ID__ 
ORDER BY id DESC 
LIMIT 1;

-- Expected: editor_user_id = __ADMIN_ID__, editor_role = 'admin'
```

---

### T15: Non-Admin Cannot Bypass Ownership

**Step 1: User B attempts to access User A's item (already tested in T1/T2)**

**Verification:** Same as T1/T2 - should return HTTP 403

**Additional Check - Verify capability check:**
```sql
-- Verify User B does NOT have manage_options capability
SELECT user_id, meta_key, meta_value 
FROM wp_usermeta 
WHERE user_id = __USER_B_ID__ 
  AND meta_key = 'wp_capabilities';

-- Expected: meta_value does NOT contain 'administrator' or 'manage_options'

-- Verify User B cannot access items they don't own
-- (Already verified in T1/T2 - returns 403)
```

---

### T16: Nonce Tampering - User B Cannot Use User A's Nonce

**Purpose:** Verify that nonces are user-specific. Even if User B steals/borrows User A's nonce, the request should fail because the nonce is tied to User A's session, not User B's cookie.

**Prerequisites:**
- User A has created an item (ID: `__ITEM_A_ID__`)
- User A's nonce obtained: `__NONCE_A__`
- User B's cookie obtained: `__USER_B_COOKIE__`

**Step 1: User B attempts to update User A's item using User A's nonce**

**Request (User B's cookie + User A's nonce):**
```bash
curl -X POST "http://yoursite.local/wp-admin/admin-ajax.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=n88_update_item" \
  -d "item_id=__ITEM_A_ID__" \
  -d "title=Stolen Nonce Attack" \
  -d "nonce=__NONCE_A__" \
  --cookie "wordpress_logged_in_xxx=__USER_B_COOKIE__"
```

**Expected Response:**
```json
{
  "success": false,
  "data": {
    "message": "Security check failed. Please refresh the page and try again."
  }
}
```

**Expected HTTP Status:** `403 Forbidden` OR `400 Bad Request`

**Why This Should Fail:**
- WordPress nonces are tied to the logged-in user's session
- User A's nonce is cryptographically bound to User A's user ID and session
- When User B (different user ID) uses User A's nonce, WordPress detects the mismatch
- The nonce verification fails before ownership checks occur

**DB Verification:**
```sql
-- Verify item unchanged (no update occurred)
SELECT id, title, version, updated_at 
FROM wp_n88_items 
WHERE id = __ITEM_A_ID__;

-- Expected: 
-- title = "User A Item" (unchanged, or whatever it was before)
-- version unchanged
-- updated_at unchanged

-- Verify no edit records created
SELECT * 
FROM wp_n88_item_edits 
WHERE item_id = __ITEM_A_ID__ 
  AND editor_user_id = __USER_B_ID__
  AND created_at > NOW() - INTERVAL 1 MINUTE;

-- Expected: 0 rows (no recent edits by User B)

-- Verify no events created for this failed attempt
SELECT * 
FROM wp_n88_events 
WHERE item_id = __ITEM_A_ID__ 
  AND actor_user_id = __USER_B_ID__
  AND created_at > NOW() - INTERVAL 1 MINUTE;

-- Expected: 0 rows (no events for failed nonce verification)
```

**Additional Verification - Check Nonce Verification Logic:**

**Code Inspection:**
```bash
# Verify nonce verification happens before ownership checks
grep -A 10 "verify_ajax_nonce" includes/class-n88-items.php
```

**Expected:** `N88_RFQ_Helpers::verify_ajax_nonce()` is called at the start of `ajax_update_item()`, before any ownership checks.

**Security Note:**
This test confirms that:
1. Nonces cannot be "borrowed" between users
2. Nonce verification happens before business logic (defense in depth)
3. Failed nonce verification does not create audit records (prevents log pollution)

---



---

## Quick Reference: Endpoint Payloads

### Create Item
```json
{
  "action": "n88_create_item",
  "title": "Item Title",
  "description": "Item description",
  "item_type": "furniture",
  "status": "draft",
  "nonce": "__NONCE__"
}
```

### Update Item
```json
{
  "action": "n88_update_item",
  "item_id": 123,
  "title": "Updated Title",
  "description": "Updated description",
  "status": "active",
  "item_type": "lighting",
  "nonce": "__NONCE__"
}
```

### Create Board
```json
{
  "action": "n88_create_board",
  "name": "Board Name",
  "description": "Board description",
  "view_mode": "grid",
  "nonce": "__NONCE__"
}
```

### Add Item to Board
```json
{
  "action": "n88_add_item_to_board",
  "board_id": 456,
  "item_id": 123,
  "nonce": "__NONCE__"
}
```

### Update Board Layout
```json
{
  "action": "n88_update_board_layout",
  "board_id": 456,
  "item_id": 123,
  "position_x": 100.50,
  "position_y": 200.75,
  "position_z": 5,
  "size_width": 100.00,
  "size_height": 200.00,
  "view_mode": "grid",
  "nonce": "__NONCE__"
}
```

---


**End of QA Script**

