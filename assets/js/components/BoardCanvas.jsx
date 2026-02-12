/**
 * Board Canvas Component
 * 
 * Milestone 1.3.5: Canvas Shell + BoardItem with Debounced Save
 * 
 * Fixed viewport canvas that serves as positioning context for board items.
 * Integrates debounced save functionality for persistence.
 */

import React from 'react';
import BoardItem from './BoardItem';
import UnsyncedToast from './UnsyncedToast';
import WelcomeModal from './WelcomeModal';

// Access Zustand store from global namespace (WordPress UMD pattern)
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found. Ensure useBoardStore.js is loaded before this component.');
});

// Access useDebouncedSave hook from global namespace
const useDebouncedSave = window.N88StudioOS?.useDebouncedSave || (() => {
    throw new Error('useDebouncedSave not found. Ensure useDebouncedSave.js is loaded before this component.');
});

/**
 * BoardCanvas - Fixed viewport canvas container with debounced save
 * 
 * @param {Object} props
 * @param {number} props.boardId - Board ID for saving (required for persistence)
 * @param {Function} props.onLayoutChanged - Optional callback when layout changes (for logging/debugging)
 * @param {number} props.userId - Current user ID (for welcome modal)
 * @param {Object} props.concierge - Concierge data { name: string, avatarUrl: string }
 */
const BoardCanvas = ({ boardId, onLayoutChanged, userId, concierge }) => {
    const items = useBoardStore((state) => state.items);
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Use Zustand's store getter to avoid stale state
    const getItems = React.useCallback(
        () => window.N88StudioOS.useBoardStore.getState().items,
        []
    );

    // Initialize debounced save hook
    const { triggerSave, unsynced, clearUnsynced } = useDebouncedSave(boardId || 0, getItems);

    // Refresh board item status (CAD requested, payment, etc.) so designer card updates without full reload
    const refreshBoardItemsStatus = React.useCallback(() => {
        if (!boardId || boardId <= 0 || typeof updateLayout !== 'function') return;
        const nonce = (window.n88BoardNonce && window.n88BoardNonce.nonce_get_board_items_status) || (window.n88BoardNonce && window.n88BoardNonce.nonce) || '';
        if (!nonce) return;
        const formData = new FormData();
        formData.append('action', 'n88_get_board_items_status');
        formData.append('board_id', String(boardId));
        formData.append('_ajax_nonce', nonce);
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.data && Array.isArray(data.data.items)) {
                    data.data.items.forEach(function (row) {
                        if (row.id && typeof updateLayout === 'function') {
                            updateLayout(row.id, {
                                has_prototype_payment: row.has_prototype_payment,
                                prototype_payment_status: row.prototype_payment_status,
                                cad_status: row.cad_status,
                                cad_current_version: row.cad_current_version,
                                prototype_status: row.prototype_status,
                                has_prototype_video_submitted: row.has_prototype_video_submitted,
                                has_payment_receipt_uploaded: row.has_payment_receipt_uploaded,
                                has_awarded_bid: row.has_awarded_bid,
                                has_unread_operator_messages: row.has_unread_operator_messages,
                                action_required: row.action_required,
                            });
                        }
                    });
                }
            })
            .catch(function () {});
    }, [boardId, updateLayout]);

    // On mount: refresh status after a short delay (catches stale initial HTML)
    React.useEffect(() => {
        if (!boardId || boardId <= 0) return;
        const t = setTimeout(refreshBoardItemsStatus, 800);
        return () => clearTimeout(t);
    }, [boardId, refreshBoardItemsStatus]);

    // When tab becomes visible: refresh so designer sees updates (e.g. after operator marked payment)
    React.useEffect(() => {
        if (!boardId || boardId <= 0) return;
        function onVisible() {
            if (document.visibilityState === 'visible') refreshBoardItemsStatus();
        }
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [boardId, refreshBoardItemsStatus]);

    // When item modal closes: refresh so card updates after award bid / request CAD
    React.useEffect(() => {
        if (!boardId || boardId <= 0) return;
        function onRefreshEvent() {
            refreshBoardItemsStatus();
        }
        window.addEventListener('n88-board-refresh-status', onRefreshEvent);
        return () => window.removeEventListener('n88-board-refresh-status', onRefreshEvent);
    }, [boardId, refreshBoardItemsStatus]);

    // Handle layout changes - trigger debounced save
    const handleLayoutChanged = React.useCallback((data) => {
        // Call optional callback for logging/debugging
        if (onLayoutChanged) {
            onLayoutChanged(data);
        }

        // Trigger debounced save (only if boardId is provided)
        if (boardId && boardId > 0) {
            triggerSave();
        }
    }, [boardId, triggerSave, onLayoutChanged]);

    return (
        <>
            <div
                style={{
                    position: 'relative',
                    width: '100%',
                    minHeight: '100%',
                    height: '100%',
                    backgroundColor: 'transparent',
                    zIndex: 1, // Lower than modal (20000) to ensure modal appears on top
                    padding: '20px',
                }}
            >
                {items && items.length > 0 ? (
                    items.map((item) => (
                        <BoardItem
                            key={item.id}
                            item={item}
                            onLayoutChanged={handleLayoutChanged}
                            boardId={boardId}
                        />
                    ))
                ) : (
                    <div style={{ padding: '20px', color: '#888', fontFamily: 'ui-monospace, monospace' }}>No items on board</div>
                )}
            </div>
            {/* Welcome Modal - shown once per user */}
            <WelcomeModal userId={userId} />
            {/* Unsynced toast notification */}
            <UnsyncedToast unsynced={unsynced} onDismiss={clearUnsynced} />
        </>
    );
};

export default BoardCanvas;

