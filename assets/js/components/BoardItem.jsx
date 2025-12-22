/**
 * BoardItem Component
 * 
 * Milestone 1.3.4: Canvas Shell + BoardItem (Drag + Z + Morph)
 * 
 * Draggable board item with z-index stacking and displayMode morphing.
 */

import React from 'react';
import { motion, AnimatePresence, useMotionValue } from 'framer-motion';

// Access Zustand store from global namespace (WordPress UMD pattern)
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found. Ensure useBoardStore.js is loaded before this component.');
});

/**
 * BoardItem - Draggable board item with morphing display modes
 * 
 * @param {Object} props
 * @param {Object} props.item - Board item from store {id, x, y, z, width, height, displayMode}
 * @param {Function} props.onLayoutChanged - Callback when layout changes
 */
const BoardItem = ({ item, onLayoutChanged }) => {
    const bringToFront = useBoardStore((state) => state.bringToFront);
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Motion values for drag position
    const x = useMotionValue(item.x);
    const y = useMotionValue(item.y);
    
    // Local state for resize (live preview during resize)
    const [resizeState, setResizeState] = React.useState({
        isResizing: false,
        startX: 0,
        startY: 0,
        startWidth: 0,
        startHeight: 0,
    });
    
    // Minimum dimensions (enforced during resize)
    const MIN_WIDTH = 100;
    const MIN_HEIGHT = 100;

    // Update motion values when item position changes from store
    React.useEffect(() => {
        x.set(item.x);
        y.set(item.y);
    }, [item.x, item.y, x, y]);
    
    // Current dimensions (use resize preview if resizing, otherwise use store)
    const currentWidth = resizeState.isResizing ? resizeState.currentWidth : item.width;
    const currentHeight = resizeState.isResizing ? resizeState.currentHeight : item.height;

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
                width: currentWidth,
                height: currentHeight,
                displayMode: item.displayMode,
            });
        }
    };
    
    // Resize start handler
    const handleResizeStart = (e) => {
        e.stopPropagation(); // Prevent drag from starting
        e.preventDefault();
        
        // Bring item to front
        bringToFront(item.id);
        
        // Get initial mouse position and item dimensions
        const startX = e.clientX;
        const startY = e.clientY;
        
        setResizeState({
            isResizing: true,
            startX: startX,
            startY: startY,
            startWidth: item.width,
            startHeight: item.height,
            currentWidth: item.width,
            currentHeight: item.height,
        });
    };
    
    // Resize move handler (live preview only - NO saves)
    React.useEffect(() => {
        if (!resizeState.isResizing) return;
        
        const handleMouseMove = (e) => {
            // Calculate new dimensions
            const deltaX = e.clientX - resizeState.startX;
            const deltaY = e.clientY - resizeState.startY;
            
            let newWidth = resizeState.startWidth + deltaX;
            let newHeight = resizeState.startHeight + deltaY;
            
            // Enforce minimum dimensions
            newWidth = Math.max(newWidth, MIN_WIDTH);
            newHeight = Math.max(newHeight, MIN_HEIGHT);
            
            // Update local state for live preview (NO API calls, NO saves)
            setResizeState(prev => ({
                ...prev,
                currentWidth: newWidth,
                currentHeight: newHeight,
            }));
        };
        
        const handleMouseUp = () => {
            // Get final dimensions from state
            setResizeState(prev => {
                const finalWidth = prev.currentWidth;
                const finalHeight = prev.currentHeight;
                
                // Update store with final dimensions
                updateLayout(item.id, {
                    width: finalWidth,
                    height: finalHeight,
                });
                
                // Emit layoutChanged callback to parent (triggers existing debounced save from 1.3.5)
                if (onLayoutChanged) {
                    onLayoutChanged({
                        id: item.id,
                        x: item.x,
                        y: item.y,
                        width: finalWidth,
                        height: finalHeight,
                        displayMode: item.displayMode,
                    });
                }
                
                // Clear resize state
                return {
                    isResizing: false,
                    startX: 0,
                    startY: 0,
                    startWidth: 0,
                    startHeight: 0,
                };
            });
        };
        
        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);
        
        return () => {
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);
        };
    }, [resizeState.isResizing, resizeState.startX, resizeState.startY, resizeState.startWidth, resizeState.startHeight, item.id, item.x, item.y, item.displayMode, updateLayout, onLayoutChanged]);

    return (
        <motion.div
            layoutId={`board-item-${item.id}`}
            style={{
                position: 'absolute',
                x,
                y,
                width: currentWidth,
                height: currentHeight,
                zIndex: item.z,
                cursor: resizeState.isResizing ? 'nwse-resize' : 'grab',
            }}
            drag
            dragMomentum={false}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            whileDrag={{ cursor: 'grabbing', scale: 1.05 }}
            transition={{
                layout: { duration: 0.3, ease: 'easeOut' },
            }}
        >
            {/* Main tile container - does not remount */}
            <div
                style={{
                    width: '100%',
                    height: '100%',
                    backgroundColor: '#ffffff',
                    border: '1px solid #e0e0e0',
                    borderRadius: '8px',
                    overflow: 'hidden',
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
                }}
            >
                {/* Hero image */}
                <div
                    style={{
                        width: '100%',
                        height: item.displayMode === 'photo_only' ? '100%' : '60%',
                        backgroundColor: '#e0e0e0',
                        backgroundImage: item.imageUrl ? `url(${item.imageUrl})` : 'none',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        position: 'relative',
                    }}
                >
                    {/* Toggle button for photo_only mode (overlay on image) */}
                    {item.displayMode === 'photo_only' && (
                        <button
                            onClick={(e) => {
                                e.stopPropagation();
                                updateLayout(item.id, { displayMode: 'full' });
                                // Trigger save after animation completes (300ms + small buffer)
                                setTimeout(() => {
                                    if (onLayoutChanged) {
                                        onLayoutChanged({
                                            id: item.id,
                                            x: item.x,
                                            y: item.y,
                                            width: item.width,
                                            height: item.height,
                                            displayMode: 'full',
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
                            Show Full
                        </button>
                    )}
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
                            {/* Toggle button for full mode */}
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    updateLayout(item.id, { displayMode: 'photo_only' });
                                    // Trigger save after animation completes (300ms + small buffer)
                                    setTimeout(() => {
                                        if (onLayoutChanged) {
                                            onLayoutChanged({
                                                id: item.id,
                                                x: item.x,
                                                y: item.y,
                                                width: item.width,
                                                height: item.height,
                                                displayMode: 'photo_only',
                                            });
                                        }
                                    }, 350);
                                }}
                                style={{
                                    marginTop: '8px',
                                    padding: '4px 8px',
                                    fontSize: '11px',
                                    cursor: 'pointer',
                                    backgroundColor: '#0073aa',
                                    color: '#fff',
                                    border: 'none',
                                    borderRadius: '3px',
                                }}
                            >
                                Toggle: Photo Only
                            </button>
                        </motion.div>
                    )}
                </AnimatePresence>
                
                {/* Resize handle (SE corner) */}
                <div
                    onMouseDown={handleResizeStart}
                    style={{
                        position: 'absolute',
                        bottom: 0,
                        right: 0,
                        width: '16px',
                        height: '16px',
                        cursor: 'nwse-resize',
                        backgroundColor: 'rgba(0, 0, 0, 0.1)',
                        borderTopLeftRadius: '8px',
                        zIndex: 20,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                    }}
                >
                    {/* Visual indicator */}
                    <div
                        style={{
                            width: '8px',
                            height: '8px',
                            borderRight: '2px solid rgba(0, 0, 0, 0.3)',
                            borderBottom: '2px solid rgba(0, 0, 0, 0.3)',
                        }}
                    />
                </div>
            </div>
        </motion.div>
    );
};

export default BoardItem;

