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
 */
const BoardItem = ({ item, onLayoutChanged }) => {
    const bringToFront = useBoardStore((state) => state.bringToFront);
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Local state to track if card is expanded (showing details)
    const [isExpanded, setIsExpanded] = React.useState(item.displayMode === 'full');
    
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

    const handleDragStart = () => {
        // Bring item to front on drag start
        bringToFront(item.id);
    };

    const handleDragEnd = (event, info) => {
        // Get final position from motion values (they track the drag)
        const newX = x.get();
        const newY = y.get();

        // Update local state optimistically
        updateLayout(item.id, {
            x: newX,
            y: newY,
        });

        // Emit layoutChanged callback to parent (triggers debounced save)
        if (onLayoutChanged) {
            onLayoutChanged({
                id: item.id,
                x: newX,
                y: newY,
                width: item.width,
                height: item.height,
                displayMode: item.displayMode,
            });
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
            onDragStart={handleDragStart}
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
                    {/* Card Details / Show Full Image button - always visible on top right */}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            const newMode = item.displayMode === 'photo_only' ? 'full' : 'photo_only';
                            updateLayout(item.id, { displayMode: newMode });
                            setIsExpanded(newMode === 'full');
                            // Trigger save after animation completes (300ms + small buffer)
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
                            top: '8px',
                            right: '8px',
                            padding: '4px 8px',
                            fontSize: '11px',
                            cursor: 'pointer',
                            backgroundColor: '#0073aa',
                            color: '#fff',
                            border: 'none',
                            borderRadius: '3px',
                            zIndex: 10,
                            boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
                        }}
                    >
                        {isExpanded ? 'Show Full Image' : 'Card Details'}
                    </button>
                    {/* Show item ID overlay only if no image */}
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
                            Item {item.id}
                        </div>
                    )}
                </div>

                {/* Metadata section - fades in/out based on displayMode */}
                <AnimatePresence>
                    {item.displayMode === 'full' && (
                        <motion.div
                            key="metadata"
                            initial={{ opacity: 0, height: 0 }}
                            animate={{ opacity: 1, height: 'auto' }}
                            exit={{ opacity: 0, height: 0 }}
                            transition={{ duration: 0.3 }}
                            style={{
                                padding: '12px',
                                backgroundColor: '#ffffff',
                            }}
                        >
                            <div style={{ fontSize: '14px', fontWeight: 'bold' }}>
                                Item {item.id}
                            </div>
                            <div style={{ fontSize: '12px', color: '#666', marginTop: '4px' }}>
                                Position: {Math.round(item.x)}, {Math.round(item.y)}
                            </div>
                            {/* Size Preset Controls (S / D / L / XL) - in detail area */}
                            <div
                                style={{
                                    display: 'flex',
                                    gap: '2px',
                                    marginTop: '5px',
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
                                            padding: '3px 6px',
                                            fontSize: '10px',
                                            fontWeight: currentSize === size ? 'bold' : 'normal',
                                            cursor: 'pointer',
                                            backgroundColor: currentSize === size ? '#0073aa' : '#f0f0f0',
                                            color: currentSize === size ? '#fff' : '#333',
                                            border: `1px solid ${currentSize === size ? '#0073aa' : '#ccc'}`,
                                            borderRadius: '3px',
                                            minWidth: '30px',
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
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>
        </motion.div>
    );
};

export default BoardItem;
