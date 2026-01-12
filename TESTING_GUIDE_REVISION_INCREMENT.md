# Testing Guide: Revision Increment & Specs Updated Panel

## Prerequisites
- Designer account access
- Supplier account access (optional, for bid testing)
- At least one item created on a board

## Test Scenario 1: Basic Revision Increment

### Step 1: Create Item & Submit RFQ
1. Login as Designer
2. Open board, create new item
3. Fill basic details (title, description)
4. Open item modal
5. Click "Request Quote"
6. Fill RFQ form:
   - Dimensions: W=10, D=10, H=10, Unit=in
   - Quantity: 5
   - Delivery: US, ZIP=12345
7. Submit RFQ
8. **Expected**: Status shows "RFQ Sent"

### Step 2: Change Dimensions/Quantity
1. Same item modal open (or reopen)
2. Change dimensions: W=12, D=12, H=12
   OR
   Change quantity: 10
3. Click "Save"
4. **Expected**:
   - Save successful
   - Item card status changes to "Standby"
   - Backend: `rfq_revision_current` = 2
   - Backend: `revision_changed` = true

### Step 3: Verify Specs Updated Panel
1. Reopen same item modal
2. **Expected**:
   - RFQ form is HIDDEN
   - "Specs Updated" panel is VISIBLE
   - Panel shows:
     - Title: "Specs Updated"
     - Message about suppliers notified
     - Current dims/qty (read-only)
     - Revision label: "Revision 2"

## Test Scenario 2: Bid Filtering

### Step 1: Create Bids Before Revision Change
1. Login as Supplier
2. Find item with RFQ sent
3. Submit bid for Revision 1
4. **Expected**: Bid has `rfq_revision_at_submit = 1`

### Step 2: Designer Changes Specs
1. Login as Designer
2. Change dims/qty (triggers revision increment)
3. **Expected**: Old bids marked stale (`rfq_revision_at_submit = NULL`)

### Step 3: Verify Bid Display
1. Designer opens item modal
2. Check "Specs Updated" panel bids area
3. **Expected**:
   - Old bids show under "Outdated Bids (previous specs)"
   - Current revision bids show normally
   - If no current bids: "Waiting for updated bids (Revision 2)" message

### Step 4: Supplier Submits New Bid
1. Login as Supplier
2. Submit new bid for updated item
3. **Expected**: Bid has `rfq_revision_at_submit = 2`

### Step 5: Verify Status Update
1. Designer checks item card
2. **Expected**: Status shows "Bids Received (1)" (if valid bid for Revision 2)

## Test Scenario 3: Supplier Notifications

### Step 1: Setup
1. Designer submits RFQ
2. Supplier receives RFQ (route created)

### Step 2: Designer Changes Specs
1. Designer changes dims/qty
2. **Expected**: Backend sends notification to all suppliers with routes

### Step 3: Verify Notification
1. Supplier checks notifications
2. **Expected**: Notification appears: "Specifications changed for item: [item_title]..."

## Database Verification

### Check Item Meta
```sql
SELECT id, meta_json FROM wp_n88_items WHERE id = [item_id];
```
**Expected in meta_json**:
- `rfq_revision_current`: 2 (after first change)
- `revision_changed`: true

### Check Bids
```sql
SELECT bid_id, item_id, rfq_revision_at_submit, status 
FROM wp_n88_item_bids 
WHERE item_id = [item_id];
```
**Expected**:
- Old bids: `rfq_revision_at_submit = NULL` (stale)
- New bids: `rfq_revision_at_submit = 2` (current)

### Check Notifications
```sql
SELECT * FROM wp_project_notifications 
WHERE notification_type = 'specs_changed' 
AND related_id = [item_id];
```
**Expected**: Notifications created for all suppliers with routes

## Browser Console Checks

### Open Browser DevTools (F12)
1. Check Network tab for AJAX calls:
   - `n88_save_item_facts` - should return `has_warning: true` if bids exist
   - `n88_get_item_rfq_state` - should return `revision_changed: true`, `rfq_revision_current: 2`

2. Check Console for errors:
   - No JavaScript errors
   - No React warnings

## Common Issues & Solutions

### Issue: Specs Updated panel not showing
**Check**:
- `item.revision_changed === true` in console
- `itemState.has_rfq === true` in console
- Refresh modal (close and reopen)

### Issue: Status not updating to "Standby"
**Check**:
- Backend returns `revision_changed: true`
- Frontend `getItemStatus()` function logic
- Refresh board

### Issue: Bids not filtering correctly
**Check**:
- Bids have `rfq_revision_at_submit` field
- Current revision matches bid revision
- Check browser console for bid data structure

### Issue: Notifications not sent
**Check**:
- Suppliers have active routes for item
- `N88_RFQ_Notifications` class exists
- Check error logs for notification failures

## Quick Test Commands

### PHP Debug (add to ajax_save_item_facts)
```php
error_log('Revision Increment Test - Item: ' . $item_id);
error_log('Current Revision: ' . $current_revision);
error_log('New Revision: ' . $new_revision);
error_log('Stale Bids Updated: ' . $stale_bids_updated);
error_log('Suppliers Notified: ' . count($supplier_ids));
```

### JavaScript Debug (add to ItemDetailModal)
```javascript
console.log('Item State:', itemState);
console.log('Item Revision Changed:', item.revision_changed);
console.log('Item Revision Current:', item.rfq_revision_current);
console.log('Show Specs Updated Panel:', itemState.revision_changed && itemState.has_rfq);
```
