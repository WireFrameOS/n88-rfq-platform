/**
 * BoardItem Component
 * 
 * Milestone 1.3.6: Size Presets Only (S / D / L / XL)
 * 
 * Draggable board item with z-index stacking, displayMode morphing, and fixed size presets.
 * Free-form resize has been removed in favor of discrete size selections.
 */

import React from 'react';
import { motion, AnimatePresence, useMotionValue } from 'framer-motion';
import ItemDetailModal from './ItemDetailModal';

// Access Zustand store from global namespace (WordPress UMD pattern)
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found. Ensure useBoardStore.js is loaded before this component.');
});

// Locked size presets (DO NOT CHANGE)
const CARD_SIZES = {
    S: { w: 160, h: 200 },
    D: { w: 200, h: 250 }, // CURRENT DEFAULT — LOCKED
    L: { w: 280, h: 350 },
    XL: { w: 360, h: 450 },
};

/**
 * BoardItem - Draggable board item with morphing display modes and size presets
 * 
 * @param {Object} props
 * @param {Object} props.item - Board item from store {id, x, y, z, width, height, displayMode}
 * @param {Function} props.onLayoutChanged - Callback when layout changes
 * @param {number} props.boardId - Board ID for saving item facts
 */
const BoardItem = ({ item, onLayoutChanged, boardId }) => {
    const bringToFront = useBoardStore((state) => state.bringToFront);
    const updateLayout = useBoardStore((state) => state.updateLayout);
    const setItems = useBoardStore((state) => state.setItems);
    
    // Local state to track if card is expanded (showing details)
    const [isExpanded, setIsExpanded] = React.useState(item.displayMode === 'full');
    
    // Phase 2.1.1: Local state to track if price was requested (frontend only, no persistence)
    const [priceRequested, setPriceRequested] = React.useState(false);
    
    // Commit 1.3.8: Modal state
    const [isModalOpen, setIsModalOpen] = React.useState(false);
    
    // Motion values for drag position
    const x = useMotionValue(item.x);
    const y = useMotionValue(item.y);

    // Update motion values when item position changes from store
    React.useEffect(() => {
        x.set(item.x);
        y.set(item.y);
    }, [item.x, item.y, x, y]);

    // Sync expanded state with displayMode
    React.useEffect(() => {
        setIsExpanded(item.displayMode === 'full');
    }, [item.displayMode]);

    // Determine current size preset based on item dimensions
    // Prefer sizeKey if available (for forward compatibility), otherwise match by dimensions
    const getCurrentSize = () => {
        // If sizeKey exists and is valid, use it
        if (item.sizeKey && CARD_SIZES[item.sizeKey]) {
            return item.sizeKey;
        }
        
        // Fallback: match by dimensions (for backward compatibility)
        const { width, height } = item;
        for (const [size, dims] of Object.entries(CARD_SIZES)) {
            if (Math.abs(width - dims.w) < 1 && Math.abs(height - dims.h) < 1) {
                return size;
            }
        }
        // Default to D if no match found
        return 'D';
    };

    const currentSize = getCurrentSize();
    
    // Calculate z-index: XL and L items should appear above others
    // Use item.z as base, but add extra boost for XL and L sizes
    const calculatedZIndex = (currentSize === 'XL' || currentSize === 'L') ? item.z + 1000 : item.z;

    // Handle size preset selection
    const handleSizeChange = (size, e) => {
        if (e) {
            e.stopPropagation();
            e.preventDefault();
        }
        
        const newSize = CARD_SIZES[size];
        if (!newSize) return;

        // Update layout with exact preset dimensions and sizeKey
        updateLayout(item.id, {
            width: newSize.w,
            height: newSize.h,
            sizeKey: size, // Save sizeKey for forward compatibility
        });

        // Trigger layout changed callback (triggers debounced save)
        if (onLayoutChanged) {
            onLayoutChanged({
                id: item.id,
                x: item.x,
                y: item.y,
                width: newSize.w,
                height: newSize.h,
                sizeKey: size,
                displayMode: item.displayMode,
            });
        }
    };

    const handlePointerDown = () => {
        // Bring item to front on pointer down (click or drag start)
        // Compute maxZ accounting for L/XL boost (they get +1000 to calculated z-index)
        const currentItems = window.N88StudioOS.useBoardStore.getState().items;
        
        // Calculate max calculated z-index considering L/XL boost
        const maxCalculatedZ = Math.max(...currentItems.map(i => {
            const baseZ = i.z || 0;
            // Determine size for this item (same logic as getCurrentSize)
            let itemSize = 'D';
            if (i.sizeKey && CARD_SIZES[i.sizeKey]) {
                itemSize = i.sizeKey;
            } else {
                // Fallback: match by dimensions
                const { width, height } = i;
                for (const [size, dims] of Object.entries(CARD_SIZES)) {
                    if (Math.abs(width - dims.w) < 1 && Math.abs(height - dims.h) < 1) {
                        itemSize = size;
                        break;
                    }
                }
            }
            // Apply same boost as calculatedZIndex
            return (itemSize === 'XL' || itemSize === 'L') ? baseZ + 1000 : baseZ;
        }), 0);
        
        // Get current item's calculated z-index
        const currentCalculatedZ = calculatedZIndex;
        
        // Only update if not already at max (compare calculated z-indexes)
        if (currentCalculatedZ !== maxCalculatedZ) {
            // Calculate what base z value we need to set
            // If this is an L/XL item, we need: newZ + 1000 > maxCalculatedZ
            // If this is a regular item, we need: newZ > maxCalculatedZ
            let newZ;
            if (currentSize === 'XL' || currentSize === 'L') {
                // For L/XL: newZ + 1000 should be > maxCalculatedZ
                // So: newZ > maxCalculatedZ - 1000
                // But we also want it to be higher than max base z for consistency
                const maxBaseZ = Math.max(...currentItems.map(i => (i.z || 0)), 0);
                newZ = Math.max(maxBaseZ + 1, maxCalculatedZ - 1000 + 1);
            } else {
                // For regular items: newZ should be > maxCalculatedZ
                // But if maxCalculatedZ is from an L/XL item (e.g., 1005), we need newZ > 1005
                const maxBaseZ = Math.max(...currentItems.map(i => (i.z || 0)), 0);
                newZ = Math.max(maxBaseZ + 1, maxCalculatedZ + 1);
            }
            
            // Update z via updateLayout (triggers state update)
            updateLayout(item.id, { z: newZ });
            
            // Trigger save with updated z-index
            if (onLayoutChanged) {
                onLayoutChanged({
                    id: item.id,
                    x: item.x,
                    y: item.y,
                    z: newZ,
                    width: item.width,
                    height: item.height,
                    displayMode: item.displayMode,
                });
            }
        }
    };

    const handleDragStart = () => {
        // Bring item to front on drag start (redundant but safe)
        handlePointerDown();
    };

    const handleDragEnd = (event, info) => {
        // Get final position from motion values (they track the drag)
        const newX = x.get();
        const newY = y.get();

        // Get current z-index from store (may have changed via bringToFront)
        const currentItems = window.N88StudioOS.useBoardStore.getState().items;
        const currentItem = currentItems.find(i => i.id === item.id);
        const currentZ = currentItem ? currentItem.z : item.z;

        // Update local state optimistically
        updateLayout(item.id, {
            x: newX,
            y: newY,
        });

        // Emit layoutChanged callback to parent (triggers debounced save)
        // Include z-index so stacking order persists
        if (onLayoutChanged) {
            onLayoutChanged({
                id: item.id,
                x: newX,
                y: newY,
                z: currentZ,
                width: item.width,
                height: item.height,
                displayMode: item.displayMode,
            });
        }
    };
    
    // Handle delete item
    const handleDeleteItem = async () => {
        if (!window.confirm('Are you sure you want to delete this item?')) {
            return;
        }
        
        if (!boardId || boardId === 0) {
            alert('Cannot delete item: Board ID is missing.');
            return;
        }
        
        // Extract numeric ID from item.id (handles both "item-5" and "5" formats)
        let itemId = item.id;
        if (typeof itemId === 'string' && itemId.indexOf('item-') === 0) {
            itemId = parseInt(itemId.replace('item-', ''), 10);
        } else if (typeof itemId === 'string') {
            itemId = parseInt(itemId, 10);
        }
        
        if (isNaN(itemId) || itemId <= 0) {
            alert('Invalid item ID. Cannot delete item.');
            return;
        }
        
        try {
            const response = await fetch(window.n88BoardData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'n88_remove_item_from_board',
                    board_id: boardId,
                    item_id: itemId,
                    nonce: window.n88BoardData?.nonce || '',
                }),
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Remove item from store - use the item_id from response for accurate matching
                const deletedItemId = data.data?.item_id;
                const currentItems = useBoardStore.getState().items;
                
                // Filter out the deleted item by comparing numeric IDs
                const updatedItems = currentItems.filter(i => {
                    // Extract numeric ID from item.id (handles "item-5", "5", or 5)
                    let currentNumericId = null;
                    if (typeof i.id === 'string' && i.id.startsWith('item-')) {
                        currentNumericId = parseInt(i.id.replace('item-', ''), 10);
                    } else if (typeof i.id === 'string') {
                        currentNumericId = parseInt(i.id, 10);
                    } else {
                        currentNumericId = parseInt(i.id, 10);
                    }
                    
                    // Compare with deleted item ID
                    return currentNumericId !== deletedItemId;
                });
                
                // Update store with filtered items
                setItems(updatedItems);
                
                // Update item count display immediately
                const countElement = document.querySelector('span[data-item-count]');
                if (countElement) {
                    countElement.textContent = updatedItems.length;
                }
                
                // Force a re-render by updating the layout
                if (onLayoutChanged) {
                    onLayoutChanged();
                }
            } else {
                alert(data.data?.message || 'Failed to delete item. Please try again.');
            }
        } catch (error) {
            console.error('Error deleting item:', error);
            alert('An error occurred while deleting the item. Please try again.');
        }
    };

    return (
        <motion.div
            layoutId={`board-item-${item.id}`}
            style={{
                position: 'absolute',
                x,
                y,
                width: item.width,
                height: item.height,
                zIndex: calculatedZIndex,
                cursor: 'grab',
            }}
            drag={true}
            dragMomentum={false}
            onPointerDown={handlePointerDown}
            onDragStart={handlePointerDown}
            onDragEnd={handleDragEnd}
            whileDrag={{ cursor: 'grabbing', scale: 1.05 }}
            transition={{
                layout: { duration: 0.3, ease: 'easeOut' },
            }}
        >
            {/* Main tile container */}
            <div
                style={{
                    width: '100%',
                    height: '100%',
                    backgroundColor: '#ffffff',
                    border: '1px solid #e0e0e0',
                    borderRadius: '8px',
                    overflow: 'hidden',
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
                    position: 'relative',
                }}
            >
                {/* Hero image */}
                <div
                    style={{
                        width: '100%',
                        height: item.displayMode === 'photo_only' ? '100%' : '60%',
                        backgroundColor: '#e0e0e0',
                        backgroundImage: item.imageUrl ? `url(${item.imageUrl})` : 'none',
                        backgroundSize: 'contain',
                        backgroundPosition: 'center',
                        backgroundRepeat: 'no-repeat',
                        position: 'relative',
                    }}
                >
                    {/* Show item title overlay only if no image */}
                    {!item.imageUrl && (
                        <div style={{
                            position: 'absolute',
                            top: '50%',
                            left: '50%',
                            transform: 'translate(-50%, -50%)',
                            backgroundColor: 'rgba(255,255,255,0.8)',
                            padding: '4px 8px',
                            borderRadius: '4px',
                            fontSize: '14px',
                            color: '#999',
                        }}>
                            {item.title || `Item ${item.id}`}
                        </div>
                    )}
                    
                    {/* Delete button - always visible */}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            handleDeleteItem();
                        }}
                        style={{
                            position: 'absolute',
                            top: '10px',
                            right: '10px',
                            width: '24px',
                            height: '24px',
                            padding: 0,
                            fontSize: '16px',
                            fontWeight: 'bold',
                            cursor: 'pointer',
                            backgroundColor: '#d32f2f',
                            color: '#fff',
                            border: 'none',
                            borderRadius: '50%',
                            boxShadow: '0 2px 4px rgba(0,0,0,0.3)',
                            transition: 'all 0.2s',
                            zIndex: 20,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            lineHeight: '1',
                        }}
                        onMouseEnter={(e) => {
                            e.target.style.backgroundColor = '#b71c1c';
                            e.target.style.transform = 'scale(1.1)';
                        }}
                        onMouseLeave={(e) => {
                            e.target.style.backgroundColor = '#d32f2f';
                            e.target.style.transform = 'scale(1)';
                        }}
                        title="Delete item"
                    >
                        ×
                    </button>
                    
                    {/* Show Card button - appears when in photo_only mode */}
                    {item.displayMode === 'photo_only' && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                e.preventDefault();
                                const newMode = 'full';
                                updateLayout(item.id, { displayMode: newMode });
                                setIsExpanded(true);
                                setTimeout(() => {
                                    if (onLayoutChanged) {
                                        onLayoutChanged({
                                            id: item.id,
                                            x: item.x,
                                            y: item.y,
                                            width: item.width,
                                            height: item.height,
                                            displayMode: newMode,
                                        });
                                    }
                                }, 350);
                            }}
                            style={{
                                position: 'absolute',
                                top: '10px',
                                right: '40px',
                                padding: '6px 12px',
                                fontSize: '11px',
                                fontWeight: '500',
                                cursor: 'pointer',
                                backgroundColor: '#0073aa',
                                color: '#fff',
                                border: '1px solid #0073aa',
                                borderRadius: '4px',
                                boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
                                transition: 'all 0.2s',
                                zIndex: 10,
                            }}
                            onMouseEnter={(e) => {
                                e.target.style.backgroundColor = '#005a87';
                            }}
                            onMouseLeave={(e) => {
                                e.target.style.backgroundColor = '#0073aa';
                            }}
                        >
                            Show Card
                        </button>
                    )}
                </div>

                {/* Metadata section - always visible when not photo_only */}
                {item.displayMode !== 'photo_only' && (
                    <div
                        style={{
                            padding: (currentSize === 'S' || currentSize === 'D') ? '6px' : '12px',
                            backgroundColor: '#ffffff',
                            overflow: 'visible',
                        }}
                    >
                        {/* Category (small label) */}
                        {item.item_type && (
                            <div style={{ 
                                fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px', 
                                color: '#999',
                                textTransform: 'uppercase',
                                marginBottom: (currentSize === 'S' || currentSize === 'D') ? '2px' : '4px',
                            }}>
                                {item.item_type}
                            </div>
                        )}

                        {/* Description */}
                        {item.description && (
                            <div style={{ 
                                fontSize: (currentSize === 'S' || currentSize === 'D') ? '10px' : '12px', 
                                color: '#666', 
                                marginBottom: (currentSize === 'S' || currentSize === 'D') ? '4px' : '8px',
                                lineHeight: '1.4',
                            }}>
                                {item.description}
                            </div>
                        )}

                        {/* Size Preset Controls (S / D / L / XL) */}
                        <div
                            style={{
                                display: 'flex',
                                gap: '2px',
                                marginBottom: (currentSize === 'S' || currentSize === 'D') ? '4px' : '8px',
                                pointerEvents: 'auto',
                                flexWrap: 'wrap',
                            }}
                        >
                            {['S', 'D', 'L', 'XL'].map((size) => (
                                <button
                                    key={size}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        e.preventDefault();
                                        handleSizeChange(size, e);
                                    }}
                                    style={{
                                        padding: (currentSize === 'S' || currentSize === 'D') ? '2px 4px' : '3px 6px',
                                        fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px',
                                        fontWeight: currentSize === size ? 'bold' : 'normal',
                                        cursor: 'pointer',
                                        backgroundColor: currentSize === size ? '#0073aa' : '#f0f0f0',
                                        color: currentSize === size ? '#fff' : '#333',
                                        border: `1px solid ${currentSize === size ? '#0073aa' : '#ccc'}`,
                                        borderRadius: '3px',
                                        minWidth: (currentSize === 'S' || currentSize === 'D') ? '25px' : '30px',
                                        flex: '1 1 0',
                                        transition: 'all 0.2s',
                                    }}
                                    onMouseEnter={(e) => {
                                        if (currentSize !== size) {
                                            e.target.style.backgroundColor = '#e0e0e0';
                                        }
                                    }}
                                    onMouseLeave={(e) => {
                                        if (currentSize !== size) {
                                            e.target.style.backgroundColor = '#f0f0f0';
                                        }
                                    }}
                                >
                                    {size}
                                </button>
                            ))}
                        </div>

                        {/* Photo only | Full | Request price row */}
                        <div
                            style={{
                                display: 'flex',
                                gap: '4px',
                                alignItems: 'center',
                                pointerEvents: 'auto',
                            }}
                        >
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    // Toggle between photo_only and full
                                    const newMode = item.displayMode === 'photo_only' ? 'full' : 'photo_only';
                                    updateLayout(item.id, { displayMode: newMode });
                                    setIsExpanded(newMode !== 'photo_only');
                                    setTimeout(() => {
                                        if (onLayoutChanged) {
                                            onLayoutChanged({
                                                id: item.id,
                                                x: item.x,
                                                y: item.y,
                                                width: item.width,
                                                height: item.height,
                                                displayMode: newMode,
                                            });
                                        }
                                    }, 350);
                                }}
                                style={{
                                    padding: (currentSize === 'S' || currentSize === 'D') ? '3px 6px' : '4px 8px',
                                    fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px',
                                    fontWeight: item.displayMode === 'photo_only' ? 'bold' : 'normal',
                                    cursor: 'pointer',
                                    backgroundColor: item.displayMode === 'photo_only' ? '#0073aa' : 'transparent',
                                    color: item.displayMode === 'photo_only' ? '#fff' : '#666',
                                    border: `1px solid ${item.displayMode === 'photo_only' ? '#0073aa' : '#ddd'}`,
                                    borderRadius: '3px',
                                    transition: 'all 0.2s',
                                }}
                            >
                                Photo only
                            </button>
                            <span style={{ color: '#ccc', fontSize: (currentSize === 'S' || currentSize === 'D') ? '8px' : '10px' }}>|</span>
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    // Commit 1.3.8: Open item detail modal
                                    setIsModalOpen(true);
                                }}
                                style={{
                                    padding: (currentSize === 'S' || currentSize === 'D') ? '3px 6px' : '4px 8px',
                                    fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px',
                                    fontWeight: 'normal',
                                    cursor: 'pointer',
                                    backgroundColor: 'transparent',
                                    color: '#666',
                                    border: '1px solid #ddd',
                                    borderRadius: '3px',
                                    transition: 'all 0.2s',
                                }}
                                onMouseEnter={(e) => {
                                    e.target.style.backgroundColor = '#f0f0f0';
                                }}
                                onMouseLeave={(e) => {
                                    e.target.style.backgroundColor = 'transparent';
                                }}
                            >
                                Full
                            </button>
                            <span style={{ color: '#ccc', fontSize: (currentSize === 'S' || currentSize === 'D') ? '8px' : '10px' }}>|</span>
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    setPriceRequested(true);
                                }}
                                disabled={priceRequested}
                                style={{
                                    padding: (currentSize === 'S' || currentSize === 'D') ? '3px 6px' : '4px 8px',
                                    fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px',
                                    fontWeight: 'normal',
                                    cursor: priceRequested ? 'not-allowed' : 'pointer',
                                    backgroundColor: priceRequested ? '#ccc' : 'transparent',
                                    color: priceRequested ? '#999' : '#666',
                                    border: `1px solid ${priceRequested ? '#ccc' : '#ddd'}`,
                                    borderRadius: '3px',
                                    transition: 'all 0.2s',
                                }}
                            >
                                {priceRequested ? 'Price Requested' : 'Request price'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
            
            {/* Commit 1.3.8: Item Detail Modal */}
            <ItemDetailModal
                item={item}
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                onSave={async (itemId, payload) => {
                    // Save item facts via AJAX
                    if (boardId && boardId > 0) {
                        try {
                            const response = await fetch(window.n88BoardData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    action: 'n88_save_item_facts',
                                    board_id: boardId,
                                    item_id: itemId,
                                    nonce: window.n88BoardData?.nonce || '',
                                    payload: JSON.stringify(payload),
                                }),
                            });
                            
                            const data = await response.json();
                            if (!data.success) {
                                throw new Error(data.data?.message || 'Failed to save item facts');
                            }
                        } catch (error) {
                            console.error('Error saving item facts:', error);
                            throw error;
                        }
                    } else {
                        // Demo mode - just update store (no AJAX)
                        console.log('Demo mode: Item facts saved to store only');
                    }
                }}
            />
        </motion.div>
    );
};

export default BoardItem;
