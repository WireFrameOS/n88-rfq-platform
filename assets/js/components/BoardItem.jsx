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
    
    // HIGH APPROACH: Cooldown period after drag - block clicks for 2 seconds after drag ends
    const isDraggingRef = React.useRef(false); // Track if currently dragging
    const dragCooldownActiveRef = React.useRef(false); // Track if in cooldown period (2 seconds after drag)
    const dragCooldownTimerRef = React.useRef(null); // Timer reference for cooldown
    
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
        // Default to L if no match found
        return 'L';
    };

    const currentSize = getCurrentSize();
    
    // Calculate z-index: XL and L items should appear above others
    // Use item.z as base, but add extra boost for XL and L sizes
    const calculatedZIndex = (currentSize === 'XL' || currentSize === 'L') ? item.z + 1000 : item.z;

    // Calculate item status based on available data
    const getItemStatus = () => {
        const hasUnreadOperatorMessages = item.has_unread_operator_messages === true || item.has_unread_operator_messages === 'true' || item.has_unread_operator_messages === 1;
        const ps = (item.prototype_status || '').toLowerCase() || null;
        const hasPrototypeVideoSubmitted = item.has_prototype_video_submitted === true || item.has_prototype_video_submitted === 'true' || item.has_prototype_video_submitted === 1;

        // Priority 1: Bid Awarded — when designer has awarded a bid, show this over Prototype Approved
        let hasAwardedBid = item.has_awarded_bid === true || item.has_awarded_bid === 'true' || item.has_awarded_bid === 1 || item.has_awarded_bid === '1';
        if (!hasAwardedBid && item.meta && item.meta.item_status === 'Awarded') hasAwardedBid = true;
        if (!hasAwardedBid && item.meta && item.meta.awarded_bid_snapshot) hasAwardedBid = true;
        if (hasAwardedBid) {
            return { text: 'Bid Awarded', color: '#00ff00', dot: '#00ff00' };
        }

        // Priority 2: Prototype Approved (designer approved prototype) — must beat Action Required
        if (ps === 'approved') {
            return { text: 'Prototype Approved', color: '#00ff00', dot: '#00ff00' };
        }
        // Priority 3: Action Required (unread operator messages; or supplier submitted/resubmitted prototype video — designer must review)
        if (hasUnreadOperatorMessages || ps === 'submitted' || (ps == null && hasPrototypeVideoSubmitted)) {
            return { text: 'Action Required', color: '#ff0000', dot: '#ff0000' };
        }
        // Priority 4: Video Changes Requested (designer requested changes; waiting for supplier to resubmit)
        if (ps === 'changes_requested') {
            return { text: 'Video Changes Requested', color: '#ff8800', dot: '#ff8800' };
        }

        // Priority 5: Designer approved CAD — show Pending Prototype Video (waiting for supplier to submit video)
        const hasPrototypePayment = item.has_prototype_payment === true || item.has_prototype_payment === 'true' || item.has_prototype_payment === 1;
        const cadStatus = (item.cad_status || '').toLowerCase() || null;
        if (hasPrototypePayment && cadStatus === 'approved' && ps !== 'approved') {
            return { text: 'Pending Prototype Video', color: '#2196f3', dot: '#2196f3' };
        }

        // Priority 6: Awaiting payment confirmation (designer uploaded receipt; operator has not yet marked received)
        const prototypePaymentStatus = item.prototype_payment_status || null;
        const hasPaymentReceiptUploaded = item.has_payment_receipt_uploaded === true || item.has_payment_receipt_uploaded === 'true' || item.has_payment_receipt_uploaded === 1;
        if (hasPrototypePayment && prototypePaymentStatus === 'requested' && hasPaymentReceiptUploaded) {
            return { text: 'Awaiting payment confirmation', color: '#ff8800', dot: '#ff8800' };
        }
        // Priority 6b: Awaiting Payment (prototype payment requested, no receipt yet) - Commit 2.3.9.1C, Fix #27
        if (hasPrototypePayment && prototypePaymentStatus === 'requested') {
            return { text: 'Awaiting Payment', color: '#ff8800', dot: '#ff8800' };
        }
        // Priority 6c: Payment confirmed — when operator sent CAD to designer, show Review CAD; when designer approved CAD, show Pending Prototype Video
        if (hasPrototypePayment && prototypePaymentStatus === 'marked_received') {
            if (cadStatus === 'approved' && ps !== 'approved') {
                return { text: 'Pending Prototype Video', color: '#2196f3', dot: '#2196f3' };
            }
            const cadVersion = Number(item.cad_current_version) || 0;
            const operatorSentCad = cadStatus === 'uploaded' || cadStatus === 'revision_requested' || (cadVersion > 0 && cadStatus !== 'approved');
            if (operatorSentCad) {
                return { text: 'Review CAD', color: '#2196f3', dot: '#2196f3' };
            }
            if (cadStatus && cadStatus !== 'approved') {
                return { text: 'Preparing CAD', color: '#2196f3', dot: '#2196f3' };
            }
            return { text: 'Payment received', color: '#4caf50', dot: '#4caf50' };
        }
        
        // Check if item has award_set (In Production)
        if (item.award_set === true || item.award_set === 'true' || item.award_set === 1) {
            return { text: 'In Production', color: '#4caf50', dot: '#4caf50' };
        }
        // Safeguard: when backend sets action_required (CAD/operator interaction), show Action Required so we don't show Bids Received
        const actionRequired = item.action_required === true || item.action_required === 'true' || item.action_required === 1;
        if (actionRequired) {
            return { text: 'Action Required', color: '#ff0000', dot: '#ff0000' };
        }
        // Check if item has bids (Bids Received)
        const bidCount = item.bid_count || item.bids_count || 0;
        if (bidCount > 0 || item.has_bids === true || item.has_bids === 'true') {
            return { text: 'Bids Received', color: '#2196f3', dot: '#2196f3' };
        }
        
        // Check if RFQ exists (RFQ Sent)
        if (item.has_rfq === true || item.has_rfq === 'true' || item.rfq_status === 'sent' || item.rfq_status === 'submitted') {
            return { text: 'RFQ Sent', color: '#ff9800', dot: '#ff9800' };
        }
        
        // Default: Draft (item exists but no RFQ)
        return { text: 'Draft', color: '#999', dot: '#999' };
    };

    const itemStatus = getItemStatus();

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
            let itemSize = 'L';
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

    const handleDragStart = (event, info) => {
        // Bring item to front on drag start
        handlePointerDown();
        
        // Mark that drag is in progress - BLOCK ALL CLICKS
        isDraggingRef.current = true;
        dragCooldownActiveRef.current = true; // Start blocking immediately
        
        // Clear any existing cooldown timer
        if (dragCooldownTimerRef.current) {
            clearTimeout(dragCooldownTimerRef.current);
            dragCooldownTimerRef.current = null;
        }
    };

    const handleDragEnd = (event, info) => {
        // Check if item was actually dragged (moved more than a few pixels)
        const dragDistance = Math.sqrt(Math.pow(info.delta.x, 2) + Math.pow(info.delta.y, 2));
        
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
        
        // Reset isDragging flag - drag has ended
        isDraggingRef.current = false;
        
        // HIGH APPROACH: If drag distance is significant, start 2-second cooldown period
        // During cooldown, ALL clicks are blocked to prevent modal opening
        if (dragDistance > 5) {
            // Significant drag occurred - start 2 second cooldown
            dragCooldownActiveRef.current = true;
            
            // Clear any existing timer
            if (dragCooldownTimerRef.current) {
                clearTimeout(dragCooldownTimerRef.current);
            }
            
            // After 2 seconds, allow clicks again
            dragCooldownTimerRef.current = setTimeout(() => {
                dragCooldownActiveRef.current = false;
                dragCooldownTimerRef.current = null;
            }, 2000); // 2 seconds cooldown period
        } else {
            // Very small movement - might be accidental, allow clicks immediately
            dragCooldownActiveRef.current = false;
            if (dragCooldownTimerRef.current) {
                clearTimeout(dragCooldownTimerRef.current);
                dragCooldownTimerRef.current = null;
            }
        }
    };
    
    // Handle pointer down on image area
    const handleImagePointerDown = (e) => {
        // Don't track if clicking on interactive elements (buttons, etc.)
        if (e.target.closest('button')) {
            return;
        }
        
        // If in cooldown period or currently dragging, don't allow interaction
        if (dragCooldownActiveRef.current || isDraggingRef.current) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
    };
    
    // Handle pointer up on image area - only open modal if not in cooldown period
    const handleImagePointerUp = (e) => {
        // Don't open modal if clicking on interactive elements (buttons, etc.)
        if (e.target.closest('button')) {
            return;
        }
        
        // HIGH APPROACH: If in cooldown period or currently dragging, BLOCK modal completely
        if (dragCooldownActiveRef.current || isDraggingRef.current) {
            e.preventDefault();
            e.stopPropagation();
            return; // Exit immediately - don't allow modal
        }
        
        // If not in cooldown and not dragging, allow click to open modal
        // Use small delay to ensure drag handlers have finished
        setTimeout(() => {
            // Final check: verify still not in cooldown or dragging
            if (!dragCooldownActiveRef.current && !isDraggingRef.current) {
                setIsModalOpen(true);
            }
        }, 100);
    };
    
    // HIGH APPROACH: Handle click event - check cooldown period
    const handleImageClick = (e) => {
        // Don't open modal if clicking on interactive elements (buttons, etc.)
        if (e.target.closest('button')) {
            return;
        }
        
        // HIGH APPROACH: If in cooldown period or currently dragging, BLOCK modal completely
        if (dragCooldownActiveRef.current || isDraggingRef.current) {
            e.preventDefault();
            e.stopPropagation();
            return; // Exit immediately - don't allow modal
        }
        
        // If not in cooldown and not dragging, allow click to open modal
        setTimeout(() => {
            // Final check: verify still not in cooldown or dragging
            if (!dragCooldownActiveRef.current && !isDraggingRef.current) {
                setIsModalOpen(true);
            }
        }, 100);
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

    // Block click on motion div if in cooldown or dragging
    const handleMotionDivClick = (e) => {
        if (dragCooldownActiveRef.current || isDraggingRef.current) {
            e.preventDefault();
            e.stopPropagation();
        }
    };
    
    // Cleanup timer on unmount
    React.useEffect(() => {
        return () => {
            if (dragCooldownTimerRef.current) {
                clearTimeout(dragCooldownTimerRef.current);
            }
        };
    }, []);

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
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onClick={handleMotionDivClick}
            whileDrag={{ cursor: 'grabbing', scale: 1.05 }}
            transition={{
                layout: { duration: 0.3, ease: 'easeOut' },
            }}
        >
            {/* Main tile container - Photo-First Design */}
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
                    display: 'flex',
                    flexDirection: 'column',
                    boxSizing: 'border-box',
                }}
            >
                {/* Photo Section - 75% of card (100% when photo_only mode) */}
                <div
                    onPointerDown={handleImagePointerDown}
                    onPointerUp={handleImagePointerUp}
                    style={{
                        width: '100%',
                        flex: item.displayMode === 'photo_only' ? '0 0 100%' : '0 0 75%',
                        minHeight: 0,
                        backgroundColor: '#e0e0e0',
                        backgroundImage: item.imageUrl ? `url(${item.imageUrl})` : 'none',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        backgroundRepeat: 'no-repeat',
                        position: 'relative',
                        boxSizing: 'border-box',
                        cursor: 'pointer',
                    }}
                >
                    {/* Delete button - top right */}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            e.preventDefault();
                            handleDeleteItem();
                        }}
                        style={{
                            position: 'absolute',
                            top: '8px',
                            right: '8px',
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
                    {item.displayMode !== 'photo_only' && (
                <div
                    style={{
                        width: '100%',
                        flex: '0 0 25%',
                        minHeight: 0,
                        backgroundColor: '#ffffff',
                        borderTop: '1px solid #e0e0e0',
                        padding: (currentSize === 'S' || currentSize === 'D') ? '6px 8px' : '8px 12px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '6px',
                        boxSizing: 'border-box',
                        flexShrink: 0,
                    }}
                >
                    {/* Status Text with Dot */}
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '6px',
                            fontSize: (currentSize === 'S' || currentSize === 'D') ? '10px' : '12px',
                            color: '#333',
                        }}
                    >
                        <span
                            style={{
                                width: '8px',
                                height: '8px',
                                borderRadius: '50%',
                                backgroundColor: itemStatus.dot,
                                display: 'inline-block',
                            }}
                        />
                        <span style={{ fontWeight: 500 }}>{itemStatus.text}</span>
                    </div>

                    {/* Sizes Button Row - Show all sizes inline */}
                    <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }} data-sizes-container>
                        {['S', 'D', 'L', 'XL'].map((size) => (
                            <button
                                key={size}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    handleSizeChange(size, e);
                                }}
                                style={{
                                    padding: (currentSize === 'S' || currentSize === 'D') ? '3px 6px' : '4px 8px',
                                    fontSize: (currentSize === 'S' || currentSize === 'D') ? '9px' : '10px',
                                    fontWeight: currentSize === size ? 'bold' : 'normal',
                                    cursor: 'pointer',
                                    backgroundColor: currentSize === size ? '#0073aa' : '#f0f0f0',
                                    color: currentSize === size ? '#fff' : '#333',
                                    border: `1px solid ${currentSize === size ? '#0073aa' : '#ccc'}`,
                                    borderRadius: '3px',
                                    transition: 'all 0.2s',
                                    minWidth: (currentSize === 'S' || currentSize === 'D') ? '24px' : '28px',
                                    flex: 1,
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
                                title={`Set size to ${size}`}
                            >
                                {size}
                            </button>
                        ))}
                    </div>
                </div>
                )}
                </div>

                {/* Status Strip - 25% of card - Only show when not in photo_only mode */}
               
            </div>
            
            {/* Commit 1.3.8: Item Detail Modal */}
            <ItemDetailModal
                item={item}
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                boardId={boardId}
                priceRequested={priceRequested}
                onPriceRequest={() => setPriceRequested(true)}
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
                            // Commit 2.3.5.1: Return data.data to access has_warning flag
                            return data.data || data;
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
