/**
 * useDebouncedSave Hook
 * 
 * Milestone 1.3.5: Debounced Save + Failure Recovery
 * 
 * Implements 500ms trailing-edge debounce for board layout saves.
 * Handles client-side revision tracking for last-write-wins concurrency.
 * Manages unsynced state and failure recovery.
 */

(function() {
    'use strict';

    // Hard fail if React is not available
    if (typeof window === 'undefined' || !window.React || !window.React.useRef || !window.React.useCallback || !window.React.useEffect) {
        throw new Error('useDebouncedSave: React is required. Please load React before this script.');
    }

    const React = window.React;

    /**
     * useDebouncedSave - Custom hook for debounced board layout saves
     * 
     * @param {number} boardId - Board ID to save
     * @param {Function} getItems - Function that returns current items array from store
     * @returns {Object} { triggerSave, unsynced, clearUnsynced }
     */
    function useDebouncedSave(boardId, getItems) {
        // Client-side revision counter (monotonically increasing)
        const clientRevisionRef = React.useRef(0);
        
        // Debounce timer ref
        const debounceTimerRef = React.useRef(null);
        
        // Unsynced state
        const [unsynced, setUnsynced] = React.useState(false);
        
        // Pending save ref (to track if we're waiting for a response)
        const pendingSaveRef = React.useRef(null);

        /**
         * Save function - sends full snapshot to server
         */
        const performSave = React.useCallback(function(revision) {
            // Skip save for demo mode (boardId = 0) - handled separately via localStorage
            if (!boardId || boardId === 0) {
                console.log('useDebouncedSave: Skipping performSave for demo mode (boardId = 0)');
                // Clear unsynced state for demo mode since it's handled by localStorage
                setUnsynced(false);
                return;
            }

            const items = getItems();
            if (!Array.isArray(items)) {
                console.warn('useDebouncedSave: getItems() must return an array');
                return;
            }
            
            // Filter items to only include fields allowed by server
            // Server expects: id, x, y, z, width, height, sizeKey, displayMode
            var allowedKeys = ['id', 'x', 'y', 'z', 'width', 'height', 'sizeKey', 'displayMode'];
            var filteredItems = items.map(function(item) {
                var filtered = {};
                allowedKeys.forEach(function(key) {
                    if (item.hasOwnProperty(key)) {
                        filtered[key] = item[key];
                    }
                });
                return filtered;
            });
            
            console.log('useDebouncedSave: Filtered items from', items.length, 'to', filteredItems.length, 'items with allowed keys only');

            // Prepare full snapshot payload
            const payload = {
                board_id: boardId,
                items: items,
                client_revision: revision,
            };

            // Store pending save info (for reference, but we'll use the revision parameter directly)
            pendingSaveRef.current = {
                revision: revision,
                timestamp: Date.now(),
            };

            // Get AJAX URL and nonce from WordPress
            const ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';
            // wp_localize_script creates: window.n88BoardNonce = { nonce: '...' }
            const nonce = (window.n88BoardNonce && window.n88BoardNonce.nonce) ? window.n88BoardNonce.nonce : '';

            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'n88_save_board_layout');
            formData.append('board_id', boardId);
            formData.append('items', JSON.stringify(filteredItems));
            formData.append('client_revision', revision);
            if (nonce) {
                formData.append('nonce', nonce);
            } else {
                console.warn('useDebouncedSave: No nonce available!');
            }

            console.log('useDebouncedSave: Sending AJAX request to', ajaxurl, 'with boardId =', boardId, 'items count =', items.length);
            console.log('useDebouncedSave: Nonce available:', !!nonce);
            console.log('useDebouncedSave: Sample item (first):', items.length > 0 ? items[0] : 'No items');
            
            // Log what fields are in the items
            if (items.length > 0) {
                var sampleItem = items[0];
                console.log('useDebouncedSave: Item keys:', Object.keys(sampleItem));
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
            .then(function(response) {
                console.log('useDebouncedSave: Response status =', response.status, response.statusText);
                
                // Try to parse response even if status is not OK to see error message
                return response.json().then(function(data) {
                    return { ok: response.ok, status: response.status, data: data };
                }).catch(function() {
                    // If JSON parsing fails, return error
                    return { ok: false, status: response.status, data: { success: false, message: 'Failed to parse response' } };
                });
            })
            .then(function(result) {
                var data = result.data;
                
                // Check if this response matches the latest client revision
                // Compare the revision that was sent with the current revision
                // If not, ignore it (stale response)
                if (revision !== clientRevisionRef.current) {
                    console.log('useDebouncedSave: Ignoring stale response (revision ' + revision + ' vs current ' + clientRevisionRef.current + ')');
                    return;
                }

                // Clear pending save
                pendingSaveRef.current = null;

                // WordPress wp_send_json_success returns { success: true, data: {...} }
                if (result.ok && data && data.success === true) {
                    // Success - clear unsynced state
                    setUnsynced(false);
                    console.log('useDebouncedSave: Save succeeded, clearing unsynced state');
                } else {
                    // Failure - mark as unsynced
                    setUnsynced(true);
                    var errorMsg = (data && data.data && data.data.message) ? data.data.message : 
                                   (data && data.message) ? data.message : 
                                   'Unknown error (status: ' + result.status + ')';
                    console.error('useDebouncedSave: Save failed -', errorMsg, 'Full response:', data);
                }
            })
            .catch(function(error) {
                // Network error or other failure
                // Check if this is still the latest revision (compare sent revision with current)
                if (revision !== clientRevisionRef.current) {
                    console.log('useDebouncedSave: Ignoring stale error (revision ' + revision + ' vs current ' + clientRevisionRef.current + ')');
                    return;
                }

                // Clear pending save
                pendingSaveRef.current = null;

                // Mark as unsynced
                setUnsynced(true);
                console.error('useDebouncedSave: Save error', error);
            });
        }, [boardId, getItems]);

        /**
         * Trigger save - debounced with 500ms trailing edge
         */
        const triggerSave = React.useCallback(function() {
            // Skip if boardId is 0 (demo mode - handled separately via localStorage)
            // Don't set unsynced or make any AJAX calls for demo mode
            if (!boardId || boardId === 0) {
                console.log('useDebouncedSave: Skipping save for demo mode (boardId = 0) - handled by localStorage');
                // Keep unsynced as false for demo mode (localStorage handles it)
                setUnsynced(false);
                return;
            }
            
            console.log('useDebouncedSave: triggerSave called for boardId =', boardId);
            
            // Mark as unsynced immediately when changes are made (only for real boards)
            setUnsynced(true);

            // Clear existing timer
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
                debounceTimerRef.current = null;
            }

            // Increment client revision
            clientRevisionRef.current += 1;
            const currentRevision = clientRevisionRef.current;

            // Set new debounce timer (trailing edge - fires after 500ms of inactivity)
            debounceTimerRef.current = setTimeout(function() {
                debounceTimerRef.current = null;
                console.log('useDebouncedSave: Debounce timer fired, calling performSave');
                performSave(currentRevision);
            }, 500);
        }, [performSave, boardId]);

        /**
         * Clear unsynced state (manual clear)
         */
        const clearUnsynced = React.useCallback(function() {
            setUnsynced(false);
        }, []);

        // Cleanup on unmount
        React.useEffect(function() {
            return function() {
                if (debounceTimerRef.current) {
                    clearTimeout(debounceTimerRef.current);
                }
            };
        }, []);

        return {
            triggerSave: triggerSave,
            unsynced: unsynced,
            clearUnsynced: clearUnsynced,
        };
    }

    // Export to global namespace for WordPress UMD pattern
    if (typeof window.N88StudioOS === 'undefined') {
        window.N88StudioOS = {};
    }
    window.N88StudioOS.useDebouncedSave = useDebouncedSave;
})();

