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
import ConciergeOverlay from './ConciergeOverlay';
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
    
    // Use Zustand's store getter to avoid stale state
    const getItems = React.useCallback(
        () => window.N88StudioOS.useBoardStore.getState().items,
        []
    );

    // Initialize debounced save hook
    const { triggerSave, unsynced, clearUnsynced } = useDebouncedSave(boardId || 0, getItems);

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
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    width: '100vw',
                    height: '100vh',
                    overflow: 'hidden',
                    backgroundColor: '#f5f5f5',
                }}
            >
                {items.map((item) => (
                    <BoardItem
                        key={item.id}
                        item={item}
                        onLayoutChanged={handleLayoutChanged}
                    />
                ))}
                {/* Concierge Overlay - read-only, non-blocking */}
                <ConciergeOverlay concierge={concierge} />
            </div>
            {/* Welcome Modal - shown once per user */}
            <WelcomeModal userId={userId} />
            {/* Unsynced toast notification */}
            <UnsyncedToast unsynced={unsynced} onDismiss={clearUnsynced} />
        </>
    );
};

export default BoardCanvas;

