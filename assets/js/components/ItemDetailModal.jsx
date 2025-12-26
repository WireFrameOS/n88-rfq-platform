/**
 * ItemDetailModal Component
 * 
 * Commit 1.3.8: Item Detail Modal (Phase 1.2 Verification View)
 * 
 * Right-side drawer modal for viewing and editing item facts.
 * This is the source of truth for item metadata.
 */

import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';

// Access Zustand store from global namespace
const useBoardStore = window.N88StudioOS?.useBoardStore || (() => {
    throw new Error('useBoardStore not found');
});

/**
 * Normalize dimensions to cm
 */
const normalizeToCm = (value, unit) => {
    if (!value || isNaN(value)) return null;
    const num = parseFloat(value);
    switch (unit) {
        case 'mm': return num / 10;
        case 'cm': return num;
        case 'm': return num * 100;
        case 'in': return num * 2.54;
        default: return num;
    }
};

/**
 * Calculate CBM (Cubic Meters)
 */
const calculateCBM = (wCm, dCm, hCm) => {
    if (!wCm || !dCm || !hCm) return null;
    const wM = wCm / 100;
    const dM = dCm / 100;
    const hM = hCm / 100;
    return Math.round((wM * dM * hM) * 1000) / 1000; // Round to 3 decimals
};

/**
 * Infer sourcing_type from category and description
 */
const inferSourcingType = (category, description) => {
    const furnitureCategories = ['sofa', 'chair', 'table', 'desk', 'cabinet', 'shelf', 'bed', 'furniture'];
    const sourcingCategories = ['electronics', 'hardware', 'fixture', 'lighting', 'appliance'];
    
    const catLower = (category || '').toLowerCase();
    const descLower = (description || '').toLowerCase();
    
    // Check category match
    if (furnitureCategories.some(f => catLower.includes(f))) {
        return 'furniture';
    }
    if (sourcingCategories.some(s => catLower.includes(s))) {
        return 'global_sourcing';
    }
    
    // Keyword scan in description
    const furnitureKeywords = ['furniture', 'upholstery', 'cushion', 'fabric', 'wood', 'metal frame'];
    const sourcingKeywords = ['electronic', 'component', 'hardware', 'fixture', 'bulb', 'led'];
    
    if (furnitureKeywords.some(k => descLower.includes(k))) {
        return 'furniture';
    }
    if (sourcingKeywords.some(k => descLower.includes(k))) {
        return 'global_sourcing';
    }
    
    // Default to furniture
    return 'furniture';
};

/**
 * Assign timeline_type based on sourcing_type
 */
const assignTimelineType = (sourcingType) => {
    return sourcingType === 'furniture' ? 'furniture_6_step' : 'sourcing_4_step';
};

/**
 * ItemDetailModal - Right-side drawer for item facts
 */
const ItemDetailModal = ({ item, isOpen, onClose, onSave }) => {
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Form state - convert numbers to strings for input fields
    const [category, setCategory] = React.useState(item.category || item.item_type || '');
    const [description, setDescription] = React.useState(item.description || '');
    const [width, setWidth] = React.useState(item.dims?.w ? String(item.dims.w) : '');
    const [depth, setDepth] = React.useState(item.dims?.d ? String(item.dims.d) : '');
    const [height, setHeight] = React.useState(item.dims?.h ? String(item.dims.h) : '');
    const [unit, setUnit] = React.useState(item.dims?.unit || 'in');
    const [inspiration, setInspiration] = React.useState(item.inspiration || []);
    const [isSaving, setIsSaving] = React.useState(false);
    
    // Computed values (read-only) - initialize from saved item data
    const [computedValues, setComputedValues] = React.useState({
        dimsCm: item.dims_cm || null,
        cbm: item.cbm || null,
        sourcingType: item.sourcing_type || null,
        timelineType: item.timeline_type || null,
    });
    
    // Recompute when dimensions change
    React.useEffect(() => {
        // If all dimensions are entered, compute CBM
        if (width && depth && height) {
            const wCm = normalizeToCm(width, unit);
            const dCm = normalizeToCm(depth, unit);
            const hCm = normalizeToCm(height, unit);
            
            if (wCm && dCm && hCm) {
                const cbm = calculateCBM(wCm, dCm, hCm);
                const sourcingType = inferSourcingType(category, description);
                const timelineType = assignTimelineType(sourcingType);
                
                setComputedValues({
                    dimsCm: { w_cm: wCm, d_cm: dCm, h_cm: hCm },
                    cbm,
                    sourcingType,
                    timelineType,
                });
            } else {
                // Dimensions entered but normalization failed
                const sourcingType = inferSourcingType(category, description);
                const timelineType = assignTimelineType(sourcingType);
                setComputedValues({
                    dimsCm: null,
                    cbm: null,
                    sourcingType,
                    timelineType,
                });
            }
        } else {
            // Not all dimensions entered - preserve saved computed values if they exist
            // This ensures saved CBM is displayed even if user hasn't entered dimensions yet
            const sourcingType = inferSourcingType(category, description);
            const timelineType = assignTimelineType(sourcingType);
            setComputedValues(prev => ({
                dimsCm: prev.dimsCm || item.dims_cm || null,
                cbm: prev.cbm !== null && prev.cbm !== undefined ? prev.cbm : (item.cbm !== null && item.cbm !== undefined ? item.cbm : null),
                sourcingType,
                timelineType,
            }));
        }
    }, [width, depth, height, unit, category, description]);
    
    // Handle save
    const handleSave = async () => {
        setIsSaving(true);
        
        try {
            // Prepare payload
            const dimsCm = computedValues.dimsCm;
            const payload = {
                category,
                description,
                dims: {
                    w: width ? parseFloat(width) : null,
                    d: depth ? parseFloat(depth) : null,
                    h: height ? parseFloat(height) : null,
                    unit,
                },
                dims_cm: dimsCm,
                cbm: computedValues.cbm,
                sourcing_type: computedValues.sourcingType,
                timeline_type: computedValues.timelineType,
                inspiration,
            };
            
            // Update item in store
            updateLayout(item.id, payload);
            
            // Call onSave callback (handles AJAX and event logging)
            if (onSave) {
                await onSave(item.id, payload);
            }
            
            // Close modal
            onClose();
        } catch (error) {
            console.error('Error saving item facts:', error);
            alert('Failed to save item facts. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };
    
    // Handle inspiration image upload via WordPress Media Library
    const handleInspirationAdd = () => {
        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('WordPress Media Library is not available. Please refresh the page.');
            return;
        }
        
        // Create or reuse media frame
        if (!window.n88InspirationMediaFrame) {
            window.n88InspirationMediaFrame = wp.media({
                title: 'Select Inspiration Image',
                button: {
                    text: 'Use this image'
                },
                multiple: true,
                library: {
                    type: 'image'
                }
            });
            
            // When images are selected
            window.n88InspirationMediaFrame.on('select', () => {
                const attachments = window.n88InspirationMediaFrame.state().get('selection').toJSON();
                const newInspiration = [...inspiration];
                
                attachments.forEach((attachment) => {
                    newInspiration.push({
                        type: 'image',
                        url: attachment.url,
                        id: attachment.id,
                        title: attachment.title || attachment.filename
                    });
                });
                
                setInspiration(newInspiration);
            });
        }
        
        // Open the media frame
        window.n88InspirationMediaFrame.open();
    };
    
    if (!isOpen) return null;
    
    return (
        <AnimatePresence>
            {isOpen && (
                <>
                    {/* Backdrop */}
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={onClose}
                        style={{
                            position: 'fixed',
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            backgroundColor: 'rgba(0, 0, 0, 0.5)',
                            zIndex: 10000,
                        }}
                    />
                    
                    {/* Drawer */}
                    <motion.div
                        initial={{ x: '100%' }}
                        animate={{ x: 0 }}
                        exit={{ x: '100%' }}
                        transition={{ type: 'spring', damping: 25, stiffness: 200 }}
                        style={{
                            position: 'fixed',
                            top: 0,
                            right: 0,
                            width: '480px',
                            maxWidth: '90vw',
                            height: '100vh',
                            backgroundColor: '#fff',
                            boxShadow: '-2px 0 10px rgba(0,0,0,0.2)',
                            zIndex: 10001,
                            display: 'flex',
                            flexDirection: 'column',
                            overflow: 'hidden',
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Header */}
                        <div style={{
                            padding: '20px',
                            borderBottom: '1px solid #e0e0e0',
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                        }}>
                            <h2 style={{ margin: 0, fontSize: '20px', fontWeight: '600' }}>
                                Item Detail
                            </h2>
                            <button
                                onClick={onClose}
                                style={{
                                    background: 'none',
                                    border: 'none',
                                    fontSize: '24px',
                                    cursor: 'pointer',
                                    padding: '0',
                                    width: '30px',
                                    height: '30px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                }}
                            >
                                ×
                            </button>
                        </div>
                        
                        {/* Scrollable Content */}
                        <div style={{
                            flex: 1,
                            overflowY: 'auto',
                            padding: '20px',
                        }}>
                            {/* Image Preview */}
                            <div style={{ marginBottom: '24px' }}>
                                <h3 style={{ fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    Image Preview
                                </h3>
                                <div style={{
                                    width: '100%',
                                    height: '200px',
                                    backgroundColor: '#f0f0f0',
                                    backgroundImage: item.imageUrl ? `url(${item.imageUrl})` : 'none',
                                    backgroundSize: 'contain',
                                    backgroundPosition: 'center',
                                    backgroundRepeat: 'no-repeat',
                                    border: '1px solid #e0e0e0',
                                    borderRadius: '4px',
                                }} />
                            </div>
                            
                            {/* Category */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    Category
                                </label>
                                <input
                                    type="text"
                                    value={category}
                                    onChange={(e) => setCategory(e.target.value)}
                                    placeholder="e.g., Sofa"
                                    style={{
                                        width: '100%',
                                        padding: '10px',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        fontSize: '14px',
                                    }}
                                />
                            </div>
                            
                            {/* Description */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    Description
                                </label>
                                <textarea
                                    value={description}
                                    onChange={(e) => setDescription(e.target.value)}
                                    placeholder="Lobby seating for reception area"
                                    rows={4}
                                    style={{
                                        width: '100%',
                                        padding: '10px',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        fontSize: '14px',
                                        fontFamily: 'inherit',
                                        resize: 'vertical',
                                    }}
                                />
                            </div>
                            
                            {/* Dimensions (User Input) */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    Dimensions (User Input)
                                </label>
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '10px', marginBottom: '10px' }}>
                                    <div>
                                        <input
                                            type="number"
                                            value={width}
                                            onChange={(e) => setWidth(e.target.value)}
                                            placeholder="Width"
                                            style={{
                                                width: '100%',
                                                padding: '10px',
                                                border: '1px solid #ddd',
                                                borderRadius: '4px',
                                                fontSize: '14px',
                                            }}
                                        />
                                    </div>
                                    <div>
                                        <input
                                            type="number"
                                            value={depth}
                                            onChange={(e) => setDepth(e.target.value)}
                                            placeholder="Depth"
                                            style={{
                                                width: '100%',
                                                padding: '10px',
                                                border: '1px solid #ddd',
                                                borderRadius: '4px',
                                                fontSize: '14px',
                                            }}
                                        />
                                    </div>
                                    <div>
                                        <input
                                            type="number"
                                            value={height}
                                            onChange={(e) => setHeight(e.target.value)}
                                            placeholder="Height"
                                            style={{
                                                width: '100%',
                                                padding: '10px',
                                                border: '1px solid #ddd',
                                                borderRadius: '4px',
                                                fontSize: '14px',
                                            }}
                                        />
                                    </div>
                                </div>
                                <select
                                    value={unit}
                                    onChange={(e) => setUnit(e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '10px',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        fontSize: '14px',
                                    }}
                                >
                                    <option value="mm">mm</option>
                                    <option value="cm">cm</option>
                                    <option value="m">m</option>
                                    <option value="in">in</option>
                                </select>
                            </div>
                            
                            {/* System Computed (Read-Only) */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    System Computed (Read-Only)
                                </label>
                                {computedValues.dimsCm ? (
                                    <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px' }}>
                                        <strong>Normalized:</strong> {computedValues.dimsCm.w_cm.toFixed(1)}cm × {computedValues.dimsCm.d_cm.toFixed(1)}cm × {computedValues.dimsCm.h_cm.toFixed(1)}cm
                                    </div>
                                ) : (
                                    <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px', color: '#999' }}>
                                        Enter dimensions to compute
                                    </div>
                                )}
                                {computedValues.cbm !== null ? (
                                    <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px' }}>
                                        <strong>CBM:</strong> {computedValues.cbm}
                                    </div>
                                ) : (
                                    <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px', color: '#999' }}>
                                        CBM: — (requires all dimensions)
                                    </div>
                                )}
                            </div>
                            
                            {/* System Assignments (Read-Only) */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    System Assignments (Read-Only)
                                </label>
                                <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px' }}>
                                    <strong>sourcing_type:</strong> {computedValues.sourcingType || '—'}
                                </div>
                                <div style={{ marginBottom: '8px', padding: '10px', backgroundColor: '#f9f9f9', borderRadius: '4px', fontSize: '13px' }}>
                                    <strong>timeline_type:</strong> {computedValues.timelineType || '—'}
                                </div>
                            </div>
                            
                            {/* Materials / Inspiration */}
                            <div style={{ marginBottom: '24px' }}>
                                <label style={{ display: 'block', fontSize: '14px', fontWeight: '600', marginBottom: '8px', textTransform: 'uppercase', color: '#666' }}>
                                    Materials / Inspiration
                                </label>
                                <div style={{ marginBottom: '10px' }}>
                                    {inspiration.map((insp, idx) => (
                                        <div key={idx} style={{
                                            marginBottom: '8px',
                                            padding: '8px',
                                            backgroundColor: '#f9f9f9',
                                            borderRadius: '4px',
                                            display: 'flex',
                                            justifyContent: 'space-between',
                                            alignItems: 'center',
                                        }}>
                                            <span style={{ fontSize: '13px', color: '#666' }}>{insp.url}</span>
                                            <button
                                                onClick={() => setInspiration(inspiration.filter((_, i) => i !== idx))}
                                                style={{
                                                    background: 'none',
                                                    border: 'none',
                                                    color: '#d32f2f',
                                                    cursor: 'pointer',
                                                    fontSize: '18px',
                                                }}
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                                <button
                                    onClick={handleInspirationAdd}
                                    style={{
                                        padding: '8px 16px',
                                        backgroundColor: '#f0f0f0',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                        fontSize: '13px',
                                    }}
                                >
                                    + Upload Image
                                </button>
                            </div>
                        </div>
                        
                        {/* Footer Actions */}
                        <div style={{
                            padding: '20px',
                            borderTop: '1px solid #e0e0e0',
                            display: 'flex',
                            gap: '10px',
                            justifyContent: 'flex-end',
                        }}>
                            <button
                                onClick={onClose}
                                style={{
                                    padding: '10px 20px',
                                    backgroundColor: '#f0f0f0',
                                    border: '1px solid #ddd',
                                    borderRadius: '4px',
                                    cursor: 'pointer',
                                    fontSize: '14px',
                                }}
                            >
                                Close
                            </button>
                            <button
                                onClick={handleSave}
                                disabled={isSaving}
                                style={{
                                    padding: '10px 20px',
                                    backgroundColor: '#0073aa',
                                    color: '#fff',
                                    border: 'none',
                                    borderRadius: '4px',
                                    cursor: isSaving ? 'not-allowed' : 'pointer',
                                    fontSize: '14px',
                                    opacity: isSaving ? 0.6 : 1,
                                }}
                            >
                                {isSaving ? 'Saving...' : 'Save Item Facts'}
                            </button>
                        </div>
                    </motion.div>
                </>
            )}
        </AnimatePresence>
    );
};

export default ItemDetailModal;

