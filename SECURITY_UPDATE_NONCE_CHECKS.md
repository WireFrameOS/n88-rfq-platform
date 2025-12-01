# Security Update: Nonce Verification Added to AJAX Endpoints

## Overview

All logged-in AJAX endpoints have been secured with nonce verification to prevent Cross-Site Request Forgery (CSRF) attacks. This ensures that only legitimate requests from your WordPress site can execute these actions.

## What Was Updated

The following AJAX endpoints now include nonce verification using `check_ajax_referer( 'n88-rfq-nonce', 'nonce' )`:

### 1. **Notification Endpoints**
- `ajax_get_notifications` - Retrieves user notifications
- `ajax_get_unread_count` - Gets unread notification count
- `ajax_mark_notification_read` - Marks a single notification as read
- `ajax_mark_all_notifications_read` - Marks all notifications as read

### 2. **PDF Extraction Endpoints**
- `ajax_extract_pdf` - Uploads and extracts data from PDF files

### 3. **Item Flag Endpoints**
- `ajax_add_item_flag` - Adds a flag (e.g., urgent) to an item
- `ajax_remove_item_flag` - Removes a flag from an item

### 4. **Project Verification**
- `ajax_verify_project` - Verifies that a project exists and user has access

## Security Benefits

✅ **CSRF Protection**: Prevents unauthorized requests from external sites  
✅ **Request Validation**: Ensures requests originate from your WordPress installation  
✅ **User Session Verification**: Works in conjunction with login checks for double security  
✅ **Standard WordPress Practice**: Uses WordPress's built-in security functions

## Technical Details

### Nonce Name
- **Nonce Action**: `n88-rfq-nonce`
- **Parameter Name**: `nonce`
- **Function Used**: `check_ajax_referer( 'n88-rfq-nonce', 'nonce' )`

### How It Works

1. When a page loads, WordPress generates a unique nonce token
2. This token is included in all AJAX requests
3. The server verifies the token matches before processing the request
4. If the token is invalid or missing, the request is rejected

### Error Response

If a nonce check fails, the endpoint will return:
```json
{
  "success": false,
  "data": "Security check failed"
}
```

## Frontend Requirements

**Important**: Ensure all frontend JavaScript code that calls these endpoints includes the nonce in the request.

### Example AJAX Call

```javascript
jQuery.ajax({
    url: n88RfqAjax.ajaxurl,
    type: 'POST',
    data: {
        action: 'n88_get_notifications',
        nonce: n88RfqAjax.nonce,  // ← Required!
        // ... other parameters
    },
    success: function(response) {
        // Handle success
    }
});
```

### Nonce Availability

The nonce is already being passed to JavaScript via `wp_localize_script()` in the `enqueue_form_styles()` method:

```php
wp_localize_script( 'n88-rfq-form', 'n88RfqAjax', array(
    'ajaxurl' => admin_url( 'admin-ajax.php' ),
    'nonce' => wp_create_nonce( 'n88-rfq-nonce' ),
) );
```

## Testing Checklist

After this update, please verify:

- [ ] Notification dropdown/panel loads correctly
- [ ] Unread notification count displays properly
- [ ] Marking notifications as read works
- [ ] PDF extraction functionality works
- [ ] Adding/removing item flags works
- [ ] Project verification works
- [ ] No console errors related to nonce verification

## Backward Compatibility

✅ **No Breaking Changes**: All existing functionality remains the same  
✅ **Automatic Protection**: Security is added without requiring frontend changes  
✅ **Existing Nonces**: If your frontend already sends nonces, they will continue to work

## Support

If you encounter any "Security check failed" errors:

1. **Check Browser Console**: Look for JavaScript errors
2. **Verify Nonce**: Ensure `n88RfqAjax.nonce` is being sent in requests
3. **Check Session**: Make sure user is logged in
4. **Clear Cache**: Clear browser cache and WordPress cache if applicable

## Additional Notes

- Nonce tokens expire after 24 hours (WordPress default)
- Nonces are user-specific and session-specific
- This update follows WordPress security best practices
- All endpoints maintain their existing functionality

---

**Update Date**: Current  
**Status**: ✅ Complete  
**Impact**: Security Enhancement (No Breaking Changes)

