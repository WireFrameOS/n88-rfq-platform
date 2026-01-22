/**
 * ItemDetailModal Component
 * 
 * Designer Item Modal with Three States:
 * - State A: Before RFQ (editable)
 * - State B: RFQ Sent, Awaiting Bids (read-only)
 * - State C: Proposals Received (comparison view)
 * 
 * Design: Dark theme, green accents, monospace font (matching wireframes)
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
 * Calculate CBM (Cubic Meters) - per unit
 */
/**
 * Calculate CBM (Cubic Meters) - per unit
 * Formula: (W_cm × D_cm × H_cm) / 1,000,000
 * This is equivalent to:
 * - Inches: (W × D × H) / 61023.7441
 * - Centimeters: (W × D × H) / 1,000,000
 * - Millimeters: (W × D × H) / 1,000,000,000
 * - Meters: W × D × H
 */
const calculateCBM = (wCm, dCm, hCm) => {
    if (!wCm || !dCm || !hCm) return null;
    // Convert cm to meters, then calculate volume
    const wM = wCm / 100;
    const dM = dCm / 100;
    const hM = hCm / 100;
    // Calculate CBM and round to 3 decimal places
    return Math.round((wM * dM * hM) * 1000) / 1000;
};

/**
 * Calculate Total CBM (item_cbm × quantity)
 * Formula: total_cbm = item_cbm × quantity
 * Returns value rounded to 3 decimal places
 */
const calculateTotalCBM = (itemCbm, quantity) => {
    if (!itemCbm || itemCbm === null || itemCbm === undefined) return null;
    if (!quantity || quantity === null || quantity === undefined || quantity === '' || quantity === 0) return null;
    const qty = parseInt(quantity);
    if (isNaN(qty) || qty <= 0) return null;
    // Calculate total CBM and round to 3 decimal places
    return Math.round((itemCbm * qty) * 1000) / 1000;
};

/**
 * Infer sourcing_type from category and description
 */
const inferSourcingType = (category, description) => {
    const furnitureCategories = ['sofa', 'chair', 'table', 'desk', 'cabinet', 'shelf', 'bed', 'furniture'];
    const sourcingCategories = ['electronics', 'hardware', 'fixture', 'lighting', 'appliance'];
    
    const catLower = (category || '').toLowerCase();
    const descLower = (description || '').toLowerCase();
    
    if (furnitureCategories.some(f => catLower.includes(f))) {
        return 'furniture';
    }
    if (sourcingCategories.some(s => catLower.includes(s))) {
        return 'global_sourcing';
    }
    
    const furnitureKeywords = ['furniture', 'upholstery', 'cushion', 'fabric', 'wood', 'metal frame'];
    const sourcingKeywords = ['electronic', 'component', 'hardware', 'fixture', 'bulb', 'led'];
    
    if (furnitureKeywords.some(k => descLower.includes(k))) {
        return 'furniture';
    }
    if (sourcingKeywords.some(k => descLower.includes(k))) {
        return 'global_sourcing';
    }
    
    return 'furniture';
};

/**
 * Assign timeline_type based on sourcing_type
 */
const assignTimelineType = (sourcingType) => {
    return sourcingType === 'furniture' ? 'furniture_6_step' : 'sourcing_4_step';
};

/**
 * Get timeline type display text from category
 */
const getTimelineTypeFromCategory = (category) => {
    if (!category) return null;
    
    const categoryLower = category.toLowerCase();
    
    // The Workflow categories
    const sixStepCategories = [
        'indoor furniture',
        'sofas & seating (indoor)',
        'chairs & armchairs (indoor)',
        'dining tables (indoor)',
        'cabinetry / millwork (custom)',
        'casegoods (beds, nightstands, desks, consoles)',
        'outdoor furniture',
        'outdoor seating',
        'outdoor dining sets',
        'outdoor loungers & daybeds',
        'pool furniture',
    ];
    
    // 4-Step Timeline categories
    const fourStepCategories = [
        'lighting',
    ];
    
    // Check if category matches 6-step
    if (sixStepCategories.some(cat => categoryLower.includes(cat.toLowerCase()))) {
        return 'The Workflow';
    }
    
    // Check if category matches 4-step
    if (fourStepCategories.some(cat => categoryLower.includes(cat.toLowerCase()))) {
        return '4-Step Timeline';
    }
    
    // Default to 6-step for furniture-related categories
    if (categoryLower.includes('furniture') || categoryLower.includes('sofa') || 
        categoryLower.includes('chair') || categoryLower.includes('table') ||
        categoryLower.includes('bed') || categoryLower.includes('cabinet')) {
        return 'The Workflow';
    }
    
    // Default to 4-step for sourcing categories
    return '4-Step Timeline';
};

/**
 * Bid Comparison Component - Shows single bid box or comparison table
 * Single bid: Compact box with all details (no CAD)
 * Multiple bids: Comparison table with 3 columns (Supplier A, B, C)
 */
const BidComparisonMatrix = ({ bids, darkBorder, greenAccent, darkText, darkBg, onImageClick, smartAlternativesEnabled = false }) => {
    // Order bids by created_at ASC, bid_id ASC (deterministic tie-breaker)
    const orderedBids = [...bids].sort((a, b) => {
        const dateA = new Date(a.created_at || 0).getTime();
        const dateB = new Date(b.created_at || 0).getTime();
        if (dateA !== dateB) return dateA - dateB;
        return (a.bid_id || 0) - (b.bid_id || 0);
    });
    
    // Track expanded state per bid column
    const [expandedSmartAltByBidId, setExpandedSmartAltByBidId] = React.useState({});
    
    const toggleSmartAltExpanded = (bidId) => {
        setExpandedSmartAltByBidId(prev => ({
            ...prev,
            [bidId]: !prev[bidId]
        }));
    };

    // Helper to get supplier label (A, B, C, etc.)
    const getSupplierLabel = (idx) => String.fromCharCode(65 + idx);

    // Helper to render media (videos + photos)
    const renderMedia = (bid, compact = false) => {
        const videoLinksByProvider = bid.video_links_by_provider || {
            youtube: [],
            vimeo: [],
            loom: [],
        };
        
        const allVideos = [
            ...(videoLinksByProvider.youtube || []).map(u => ({ provider: 'YouTube', url: u })),
            ...(videoLinksByProvider.vimeo || []).map(u => ({ provider: 'Vimeo', url: u })),
            ...(videoLinksByProvider.loom || []).map(u => ({ provider: 'Loom', url: u })),
        ].slice(0, 3);
        
        const photos = bid.photo_urls || [];
        const hasMedia = allVideos.length > 0 || photos.length > 0;

        if (!hasMedia) {
            return null;
        }

        return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: compact ? '2px' : '4px' }}>
                {allVideos.length > 0 && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                        {allVideos.map((video, idx) => (
                            <a
                                key={`video-${idx}`}
                                href={video.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{ fontSize: compact ? '10px' : '11px', color: greenAccent, textDecoration: 'none' }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                [{video.provider} ►]
                            </a>
                        ))}
                    </div>
                )}
                {photos.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px', marginTop: allVideos.length > 0 ? '2px' : '0' }}>
                        {photos.slice(0, 3).map((url, idx) => (
                            <img
                                key={`photo-${idx}`}
                                src={url}
                                alt=""
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if (onImageClick) {
                                        onImageClick(url);
                                    } else {
                                        window.open(url, '_blank');
                                    }
                                }}
                                style={{
                                    width: compact ? '28px' : '32px',
                                    height: compact ? '28px' : '32px',
                                    objectFit: 'cover',
                                    cursor: 'pointer',
                                    border: `1px solid ${darkBorder}`,
                                    borderRadius: '2px',
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>
        );
    };

    // Helper to format prototype
    const formatPrototype = (bid) => {
        const parts = [];
        if (bid.prototype_commitment) {
            parts.push('YES');
        } else {
            parts.push('NO');
        }
        if (bid.prototype_timeline) {
            parts.push(bid.prototype_timeline);
        }
        if (bid.prototype_cost !== null) {
            parts.push(`$${bid.prototype_cost}`);
        }
        return parts.length > 0 ? parts.join(' · ') : '—';
    };

    // Helper to format Smart Alternatives
    const formatSmartAlt = (bid) => {
        const sa = bid.smart_alternatives_suggestion;
        if (!sa || (typeof sa !== 'object')) {
            return '—';
        }
        // Check for data using correct field names (comparison_points, not comparisons)
        const hasData = sa.from || sa.to || sa.category || (sa.comparison_points && sa.comparison_points.length > 0) || sa.comparisons;
        if (!hasData) {
            return '—';
        }
        const parts = [];
        
        // Format category
        const categoryLabels = {
            'material': 'Material',
            'finish': 'Finish',
            'hardware': 'Hardware',
            'dimensions': 'Dimensions',
            'construction': 'Construction Method',
            'packaging': 'Packaging'
        };
        if (sa.category) {
            parts.push(categoryLabels[sa.category] || sa.category.charAt(0).toUpperCase() + sa.category.slice(1));
        }
        
        // Format From → To
        if (sa.from && sa.to) {
            const formatLabel = (str) => {
                if (!str) return '';
                const labels = {
                    'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                    'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                    'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                };
                return labels[str] || str.split('-').map(word => 
                    word.charAt(0).toUpperCase() + word.slice(1)
                ).join(' ');
            };
            parts.push(`${formatLabel(sa.from)} → ${formatLabel(sa.to)}`);
        }
        
        // Format comparison points
        const comparisonLabels = {
            'cost-reduction': 'Cost Reduction',
            'faster-production': 'Faster Production',
            'better-durability': 'Better Durability',
            'easier-sourcing': 'Easier Sourcing',
            'lighter-weight': 'Lighter Weight',
            'eco-friendly': 'Eco-Friendly'
        };
        const comparisonPoints = sa.comparison_points || sa.comparisons || [];
        if (Array.isArray(comparisonPoints) && comparisonPoints.length > 0) {
            const formattedComparisons = comparisonPoints.map(cp => comparisonLabels[cp] || cp).join(', ');
            parts.push(`(${formattedComparisons})`);
        }
        
        // Format impacts
        if (sa.price_impact || sa.lead_time_impact) {
            const impacts = [];
            if (sa.price_impact) {
                if (sa.price_impact.includes('reduces')) {
                    const percent = sa.price_impact.replace('reduces-', '').replace('-', '-');
                    impacts.push(`-${percent}% price`);
                } else if (sa.price_impact.includes('increases')) {
                    const percent = sa.price_impact.replace('increases-', '').replace('-', '-');
                    impacts.push(`+${percent}% price`);
                } else if (sa.price_impact === 'similar') {
                    impacts.push('same price');
                }
            }
            if (sa.lead_time_impact) {
                if (sa.lead_time_impact.includes('reduces')) {
                    impacts.push(`-${sa.lead_time_impact.replace('reduces-', '')} LT`);
                } else if (sa.lead_time_impact.includes('increases')) {
                    impacts.push(`+${sa.lead_time_impact.replace('increases-', '')} LT`);
                } else if (sa.lead_time_impact === 'similar') {
                    impacts.push('same LT');
                }
            }
            if (impacts.length > 0) {
                parts.push(impacts.join(' | '));
            }
        }
        return parts.length > 0 ? parts.join(' · ') : '—';
    };

    if (orderedBids.length === 0) {
        return null;
    }

    // Single bid: Show compact detail box
    if (orderedBids.length === 1) {
        const bid = orderedBids[0];
        const media = renderMedia(bid, true);
        const [isSmartAltExpanded, setIsSmartAltExpanded] = React.useState(false);
        const sa = bid.smart_alternatives_suggestion;
        const hasNote = bid.bid_smart_alternatives_note && bid.bid_smart_alternatives_note.trim();
        const hasDetails = sa && (sa.category || sa.from || sa.to || (sa.comparison_points && sa.comparison_points.length > 0));
        const hasSmartAltContent = smartAlternativesEnabled && (hasNote || hasDetails);
        
        // Format full details for single bid display
        const formatFullDetails = () => {
            if (!hasSmartAltContent) return null;
            const parts = [];
            
            if (sa) {
                const categoryLabels = {
                    'material': 'Material',
                    'finish': 'Finish',
                    'hardware': 'Hardware',
                    'dimensions': 'Dimensions',
                    'construction': 'Construction Method',
                    'packaging': 'Packaging'
                };
                
                if (sa.category) {
                    parts.push(`Category: ${categoryLabels[sa.category] || sa.category}`);
                }
                
                if (sa.from && sa.to) {
                    const formatLabel = (str) => {
                        if (!str) return '';
                        const labels = {
                            'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                            'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                            'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                        };
                        return labels[str] || str.split('-').map(word => 
                            word.charAt(0).toUpperCase() + word.slice(1)
                        ).join(' ');
                    };
                    parts.push(`From: ${formatLabel(sa.from)} → To: ${formatLabel(sa.to)}`);
                }
                
                if (sa.comparison_points && sa.comparison_points.length > 0) {
                    const comparisonLabels = {
                        'cost-reduction': 'Cost Reduction',
                        'faster-production': 'Faster Production',
                        'better-durability': 'Better Durability',
                        'easier-sourcing': 'Easier Sourcing',
                        'lighter-weight': 'Lighter Weight',
                        'eco-friendly': 'Eco-Friendly'
                    };
                    const comparisons = sa.comparison_points.map(cp => comparisonLabels[cp] || cp).join(', ');
                    parts.push(`Comparison Points: ${comparisons}`);
                }
                
                if (sa.price_impact) {
                    const priceLabels = {
                        'reduces-10-20': 'Reduces 10-20%',
                        'reduces-20-30': 'Reduces 20-30%',
                        'reduces-30-plus': 'Reduces 30%+',
                        'similar': 'Similar Price',
                        'increases-10-20': 'Increases 10-20%',
                        'increases-20-plus': 'Increases 20%+'
                    };
                    parts.push(`Price Impact: ${priceLabels[sa.price_impact] || sa.price_impact}`);
                }
                
                if (sa.lead_time_impact) {
                    const leadTimeLabels = {
                        'reduces-1-2w': 'Reduces 1-2 weeks',
                        'reduces-2-4w': 'Reduces 2-4 weeks',
                        'reduces-4w-plus': 'Reduces 4+ weeks',
                        'similar': 'Similar Lead Time',
                        'increases-1-2w': 'Increases 1-2 weeks',
                        'increases-2w-plus': 'Increases 2+ weeks'
                    };
                    parts.push(`Lead Time Impact: ${leadTimeLabels[sa.lead_time_impact] || sa.lead_time_impact}`);
                }
            }
            
            if (hasNote) {
                parts.push(`Note: ${bid.bid_smart_alternatives_note}`);
            }
            
            return parts.join('\n');
        };
        
        const fullDetails = formatFullDetails();
        const previewText = hasNote ? bid.bid_smart_alternatives_note : (sa ? formatSmartAlt(bid) : '');
        const previewLength = 100;
        const showPreview = previewText && previewText.length > previewLength && !isSmartAltExpanded;
        
        return (
            <div style={{
                border: `1px solid ${darkBorder}`,
                borderRadius: '4px',
                backgroundColor: '#111111',
                padding: '12px',
            }}>
                <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '10px', color: darkText }}>
                    Supplier A
                </div>
                
                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {media && (
                        <div>
                            <div style={{ fontSize: '10px', color: darkText, marginBottom: '4px', opacity: 0.7 }}>Media</div>
                            {media}
                        </div>
                    )}
                    
                    <div>
                        <div style={{ fontSize: '10px', color: darkText, marginBottom: '2px', opacity: 0.7 }}>Prototype/Timeline/Price</div>
                        <div style={{ fontSize: '11px', color: greenAccent }}>{formatPrototype(bid)}</div>
                    </div>
                    
                    {bid.production_lead_time && (
                        <div>
                            <div style={{ fontSize: '10px', color: darkText, marginBottom: '2px', opacity: 0.7 }}>Production Lead Time</div>
                            <div style={{ fontSize: '11px', color: greenAccent }}>{bid.production_lead_time}</div>
                        </div>
                    )}
                    
                    {bid.unit_price !== null && (
                        <div>
                            <div style={{ fontSize: '10px', color: darkText, marginBottom: '2px', opacity: 0.7 }}>Unit Price</div>
                            <div style={{ fontSize: '11px', color: greenAccent }}>${bid.unit_price}</div>
                            {bid.total_price && bid.item_quantity && bid.item_quantity > 1 && (
                                <div style={{ fontSize: '9px', color: darkText, marginTop: '2px', opacity: 0.7 }}>
                                    Total: ${parseFloat(bid.total_price).toFixed(2)} ({bid.unit_price} × {bid.item_quantity})
                                </div>
                            )}
                        </div>
                    )}
                    
                    {(bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)))) && (
                        <div>
                            <div style={{ fontSize: '10px', color: darkText, marginBottom: '2px', opacity: 0.7 }}>Door-to-Door Delivery</div>
                            <div style={{ fontSize: '11px', color: greenAccent }}>${parseFloat(bid.delivery_cost_usd).toFixed(2)}</div>
                            {bid.delivery_shipping_mode && (
                                <div style={{ fontSize: '9px', color: darkText, marginTop: '2px', opacity: 0.6 }}>
                                    Mode: {bid.delivery_shipping_mode === 'LCL' ? 'LCL' : bid.delivery_shipping_mode === 'FCL_20' ? '20\' Container' : bid.delivery_shipping_mode === 'FCL_40HQ' ? '40\' HQ Container' : bid.delivery_shipping_mode}
                                </div>
                            )}
                        </div>
                    )}
                    
                    {hasSmartAltContent && (
                        <div>
                            <div style={{ fontSize: '10px', color: darkText, marginBottom: '2px', opacity: 0.7 }}>Smart Alternatives</div>
                            {isSmartAltExpanded ? (
                                <div>
                                    <div style={{ 
                                        fontSize: '11px', 
                                        color: darkText,
                                        whiteSpace: 'pre-wrap',
                                        lineHeight: '1.4',
                                        marginBottom: '4px',
                                        padding: '6px',
                                        backgroundColor: '#0a0a0a',
                                        borderRadius: '2px'
                                    }}>
                                        {fullDetails}
                                    </div>
                                    <button
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setIsSmartAltExpanded(false);
                                        }}
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            color: greenAccent,
                                            cursor: 'pointer',
                                            fontSize: '10px',
                                            padding: '2px 0',
                                            textDecoration: 'underline',
                                        }}
                                    >
                                        Less
                                    </button>
                                </div>
                            ) : (
                                <div>
                                    <div style={{ fontSize: '11px', color: greenAccent, marginBottom: '4px' }}>
                                        {showPreview ? `${previewText.substring(0, previewLength)}...` : previewText}
                                    </div>
                                    <button
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setIsSmartAltExpanded(true);
                                        }}
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            color: greenAccent,
                                            cursor: 'pointer',
                                            fontSize: '10px',
                                            padding: '2px 0',
                                            textDecoration: 'underline',
                                        }}
                                    >
                                        More
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        );
    }

    // Multiple bids: Show comparison table with 3 columns
    const maxBids = Math.min(orderedBids.length, 3);
    const displayBids = orderedBids.slice(0, maxBids);
    const labelWidth = '140px';

    return (
        <div style={{
            border: `1px solid ${darkBorder}`,
            borderRadius: '4px',
            overflow: 'hidden',
            backgroundColor: '#111111',
        }}>
            {/* Table Header */}
            <div style={{
                display: 'grid',
                gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                borderBottom: `1px solid ${darkBorder}`,
                backgroundColor: '#0a0a0a',
            }}>
                <div style={{
                    padding: '6px 10px',
                    borderRight: `1px solid ${darkBorder}`,
                    fontSize: '10px',
                    color: darkText,
                }}></div>
                {displayBids.map((bid, idx) => (
                    <div
                        key={bid.bid_id}
                        style={{
                            padding: '6px 10px',
                            textAlign: 'center',
                            borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                            fontSize: '11px',
                            fontWeight: '600',
                        }}
                    >
                        Supplier {getSupplierLabel(idx)}
                    </div>
                ))}
            </div>

            {/* Table Body */}
            <div>
                {/* Media Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Media
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`media-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                            }}
                        >
                            {renderMedia(bid, true) || <span style={{ color: darkText }}>—</span>}
                        </div>
                    ))}
                </div>

                {/* Prototype Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Prototype/Timeline/Price
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`prototype-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                            }}
                        >
                            <span style={{ color: greenAccent }}>{formatPrototype(bid)}</span>
                        </div>
                    ))}
                </div>

                {/* Production Lead Time Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                       Production Timeline
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`leadtime-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                textAlign: 'center',
                            }}
                        >
                            {bid.production_lead_time ? (
                                <span style={{ color: greenAccent }}>{bid.production_lead_time}</span>
                            ) : (
                                <span style={{ color: darkText }}>—</span>
                            )}
                        </div>
                    ))}
                </div>

                {/* Unit Price Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Unit Price
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`price-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                textAlign: 'center',
                            }}
                        >
                            {bid.unit_price !== null ? (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                                    <span style={{ color: greenAccent }}>${bid.unit_price}</span>
                                    {bid.total_price && bid.item_quantity && bid.item_quantity > 1 && (
                                        <span style={{ color: darkText, fontSize: '9px', opacity: 0.7 }}>
                                            Total: ${parseFloat(bid.total_price).toFixed(2)}
                                        </span>
                                    )}
                                </div>
                            ) : (
                                <span style={{ color: darkText }}>—</span>
                            )}
                        </div>
                    ))}
                </div>

                {/* Door-to-Door Delivery Row (Commit 2.3.10) */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Door-to-Door Delivery
                    </div>
                    {displayBids.map((bid, idx) => {
                        const hasDelivery = bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)));
                        let modeLabel = '';
                        if (bid.delivery_shipping_mode) {
                            if (bid.delivery_shipping_mode === 'LCL') {
                                modeLabel = 'LCL';
                            } else if (bid.delivery_shipping_mode === 'FCL_20') {
                                modeLabel = '20\' Container';
                            } else if (bid.delivery_shipping_mode === 'FCL_40HQ') {
                                modeLabel = '40\' HQ Container';
                            }
                        }
                        return (
                            <div
                                key={`delivery-${bid.bid_id}`}
                                style={{
                                    padding: '6px 10px',
                                    borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                    fontSize: '10px',
                                    textAlign: 'center',
                                }}
                            >
                                {hasDelivery ? (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                                        <span style={{ color: greenAccent }}>${parseFloat(bid.delivery_cost_usd).toFixed(2)}</span>
                                        {modeLabel && (
                                            <span style={{ color: darkText, fontSize: '9px', opacity: 0.6 }}>{modeLabel}</span>
                                        )}
                                    </div>
                                ) : (
                                    <span style={{ color: darkText }}>—</span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Commit 2.4.1: Award Bid Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Award Bid
                    </div>
                    {displayBids.map((bid, idx) => {
                        return (
                            <div
                                key={`award-${bid.bid_id}`}
                                style={{
                                    padding: '6px 10px',
                                    borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                    fontSize: '10px',
                                    textAlign: 'center',
                                }}
                            >
                                {bid.is_awarded ? (
                                    <div style={{
                                        padding: '4px 8px',
                                        backgroundColor: '#003300',
                                        border: '1px solid #00ff00',
                                        borderRadius: '4px',
                                        color: '#00ff00',
                                        fontSize: '9px',
                                        fontWeight: '600',
                                    }}>
                                        ✓ Awarded
                                    </div>
                                ) : bid.is_declined ? (
                                    <div style={{
                                        padding: '4px 8px',
                                        backgroundColor: '#330000',
                                        border: '1px solid #ff6666',
                                        borderRadius: '4px',
                                        color: '#ff6666',
                                        fontSize: '9px',
                                    }}>
                                        Declined
                                    </div>
                                ) : bid.can_award ? (
                                    <button
                                        onClick={async () => {
                                            if (!window.confirm('Are you sure you want to award this bid? All other bids will be declined.')) {
                                                return;
                                            }
                                            
                                            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                            let nonce = '';
                                            if (window.n88BoardNonce && window.n88BoardNonce.nonce_award_bid) {
                                                nonce = window.n88BoardNonce.nonce_award_bid;
                                            } else if (window.n88BoardData && window.n88BoardData.nonce) {
                                                nonce = window.n88BoardData.nonce;
                                            } else if (window.n88 && window.n88.nonce) {
                                                nonce = window.n88.nonce;
                                            }
                                            
                                            if (!nonce) {
                                                alert('Security token missing. Please refresh the page and try again.');
                                                return;
                                            }
                                            
                                            try {
                                                const formData = new FormData();
                                                formData.append('action', 'n88_award_bid');
                                                formData.append('item_id', item.id);
                                                formData.append('bid_id', bid.bid_id);
                                                formData.append('_ajax_nonce', nonce);
                                                
                                                const response = await fetch(ajaxUrl, {
                                                    method: 'POST',
                                                    body: formData
                                                });
                                                
                                                const data = await response.json();
                                                
                                                if (data.success) {
                                                    alert('Bid awarded successfully!');
                                                    // Refresh the modal
                                                    if (onSave) {
                                                        await onSave(item.id, {});
                                                    }
                                                    onClose();
                                                } else {
                                                    alert('Error: ' + (data.data?.message || 'Failed to award bid'));
                                                }
                                            } catch (error) {
                                                console.error('Error awarding bid:', error);
                                                alert('Error awarding bid. Please try again.');
                                            }
                                        }}
                                        style={{
                                            padding: '6px 12px',
                                            backgroundColor: '#00ff00',
                                            color: '#000',
                                            border: 'none',
                                            borderRadius: '4px',
                                            fontSize: '9px',
                                            fontWeight: '600',
                                            cursor: 'pointer',
                                            fontFamily: 'monospace',
                                        }}
                                        onMouseOver={(e) => {
                                            e.target.style.backgroundColor = '#00cc00';
                                        }}
                                        onMouseOut={(e) => {
                                            e.target.style.backgroundColor = '#00ff00';
                                        }}
                                    >
                                        Award
                                    </button>
                                ) : (
                                    <span style={{ color: darkText, fontSize: '9px' }}>
                                        {bid.has_prototype_request && bid.prototype_status !== 'approved' 
                                            ? 'Prototype Pending' 
                                            : '—'}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Smart Alternatives Rows - Only show if enabled */}
                {smartAlternativesEnabled && (
                    <>
                        {/* Smart Alt Summary Row */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                            borderBottom: `1px solid ${darkBorder}`,
                        }}>
                            <div style={{
                                padding: '6px 10px',
                                borderRight: `1px solid ${darkBorder}`,
                                fontSize: '10px',
                                color: darkText,
                                backgroundColor: '#0a0a0a',
                                display: 'flex',
                                alignItems: 'center',
                            }}>
                                Smart Alt
                            </div>
                            {displayBids.map((bid, idx) => {
                                const hasSmartAltData = bid.smart_alternatives_suggestion && 
                                    (bid.smart_alternatives_suggestion.category || 
                                     bid.smart_alternatives_suggestion.from || 
                                     bid.smart_alternatives_suggestion.to ||
                                     (bid.smart_alternatives_suggestion.comparison_points && bid.smart_alternatives_suggestion.comparison_points.length > 0));
                                return (
                                    <div
                                        key={`smalt-${bid.bid_id}`}
                                        style={{
                                            padding: '6px 10px',
                                            borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                            fontSize: '10px',
                                        }}
                                    >
                                        {hasSmartAltData ? (
                                            <span style={{ color: greenAccent }}>{formatSmartAlt(bid)}</span>
                                        ) : (
                                            <span style={{ color: darkText }}>—</span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        {/* Smart Alt Notes Row (Expandable) */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                            borderBottom: `1px solid ${darkBorder}`,
                        }}>
                            <div style={{
                                padding: '6px 10px',
                                borderRight: `1px solid ${darkBorder}`,
                                fontSize: '10px',
                                color: darkText,
                                backgroundColor: '#0a0a0a',
                                display: 'flex',
                                alignItems: 'center',
                            }}>
                                Smart Alt Notes
                            </div>
                            {displayBids.map((bid, idx) => {
                                const sa = bid.smart_alternatives_suggestion;
                                const hasNote = bid.bid_smart_alternatives_note && bid.bid_smart_alternatives_note.trim();
                                const hasDetails = sa && (sa.category || sa.from || sa.to || (sa.comparison_points && sa.comparison_points.length > 0));
                                const hasContent = hasNote || hasDetails;
                                const isExpanded = expandedSmartAltByBidId[bid.bid_id] || false;
                                
                                // Format full details for display
                                const formatFullDetails = () => {
                                    if (!hasContent) return null;
                                    const parts = [];
                                    
                                    if (sa) {
                                        const categoryLabels = {
                                            'material': 'Material',
                                            'finish': 'Finish',
                                            'hardware': 'Hardware',
                                            'dimensions': 'Dimensions',
                                            'construction': 'Construction Method',
                                            'packaging': 'Packaging'
                                        };
                                        
                                        if (sa.category) {
                                            parts.push(`Category: ${categoryLabels[sa.category] || sa.category}`);
                                        }
                                        
                                        if (sa.from && sa.to) {
                                            const formatLabel = (str) => {
                                                if (!str) return '';
                                                const labels = {
                                                    'solid-wood': 'Solid Wood', 'plywood': 'Plywood', 'mdf': 'MDF',
                                                    'metal': 'Metal', 'plastic': 'Plastic', 'glass': 'Glass',
                                                    'fabric': 'Fabric', 'leather': 'Leather', 'other': 'Other'
                                                };
                                                return labels[str] || str.split('-').map(word => 
                                                    word.charAt(0).toUpperCase() + word.slice(1)
                                                ).join(' ');
                                            };
                                            parts.push(`From: ${formatLabel(sa.from)} → To: ${formatLabel(sa.to)}`);
                                        }
                                        
                                        if (sa.comparison_points && sa.comparison_points.length > 0) {
                                            const comparisonLabels = {
                                                'cost-reduction': 'Cost Reduction',
                                                'faster-production': 'Faster Production',
                                                'better-durability': 'Better Durability',
                                                'easier-sourcing': 'Easier Sourcing',
                                                'lighter-weight': 'Lighter Weight',
                                                'eco-friendly': 'Eco-Friendly'
                                            };
                                            const comparisons = sa.comparison_points.map(cp => comparisonLabels[cp] || cp).join(', ');
                                            parts.push(`Comparison Points: ${comparisons}`);
                                        }
                                        
                                        if (sa.price_impact) {
                                            const priceLabels = {
                                                'reduces-10-20': 'Reduces 10-20%',
                                                'reduces-20-30': 'Reduces 20-30%',
                                                'reduces-30-plus': 'Reduces 30%+',
                                                'similar': 'Similar Price',
                                                'increases-10-20': 'Increases 10-20%',
                                                'increases-20-plus': 'Increases 20%+'
                                            };
                                            parts.push(`Price Impact: ${priceLabels[sa.price_impact] || sa.price_impact}`);
                                        }
                                        
                                        if (sa.lead_time_impact) {
                                            const leadTimeLabels = {
                                                'reduces-1-2w': 'Reduces 1-2 weeks',
                                                'reduces-2-4w': 'Reduces 2-4 weeks',
                                                'reduces-4w-plus': 'Reduces 4+ weeks',
                                                'similar': 'Similar Lead Time',
                                                'increases-1-2w': 'Increases 1-2 weeks',
                                                'increases-2w-plus': 'Increases 2+ weeks'
                                            };
                                            parts.push(`Lead Time Impact: ${leadTimeLabels[sa.lead_time_impact] || sa.lead_time_impact}`);
                                        }
                                    }
                                    
                                    if (hasNote) {
                                        parts.push(`Note: ${bid.bid_smart_alternatives_note}`);
                                    }
                                    
                                    return parts.join('\n');
                                };
                                
                                const fullDetails = formatFullDetails();
                                const previewText = hasNote ? bid.bid_smart_alternatives_note : (sa ? formatSmartAlt(bid) : '');
                                const previewLength = 100;
                                const showPreview = previewText && previewText.length > previewLength && !isExpanded;
                                
                                return (
                                    <div
                                        key={`smalt-notes-${bid.bid_id}`}
                                        style={{
                                            padding: '6px 10px',
                                            borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                            fontSize: '10px',
                                        }}
                                    >
                                        {hasContent ? (
                                            <div>
                                                {isExpanded ? (
                                                    <div>
                                                        <div style={{ 
                                                            whiteSpace: 'pre-wrap', 
                                                            color: darkText,
                                                            lineHeight: '1.4',
                                                            marginBottom: '4px'
                                                        }}>
                                                            {fullDetails}
                                                        </div>
                                                        <button
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                toggleSmartAltExpanded(bid.bid_id);
                                                            }}
                                                            style={{
                                                                background: 'none',
                                                                border: 'none',
                                                                color: greenAccent,
                                                                cursor: 'pointer',
                                                                fontSize: '9px',
                                                                padding: '2px 0',
                                                                textDecoration: 'underline',
                                                            }}
                                                        >
                                                            Less
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div>
                                                        <div style={{ color: darkText, marginBottom: '4px' }}>
                                                            {showPreview ? `${previewText.substring(0, previewLength)}...` : previewText}
                                                        </div>
                                                        <button
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                toggleSmartAltExpanded(bid.bid_id);
                                                            }}
                                                            style={{
                                                                background: 'none',
                                                                border: 'none',
                                                                color: greenAccent,
                                                                cursor: 'pointer',
                                                                fontSize: '9px',
                                                                padding: '2px 0',
                                                                textDecoration: 'underline',
                                                            }}
                                                        >
                                                            More
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span style={{ color: darkText }}>—</span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}

                {/* Landed Shipping Costs Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} repeat(${maxBids}, 1fr)`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                    }}>
                        Landed Shipping Costs
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`landed-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                textAlign: 'center',
                            }}
                        >
                            <span style={{ color: darkText }}>—</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

/**
 * Bid Comparison Component - Shows bid details with video links organized by provider
 */
const BidComparisonList = ({ bids, darkBorder, greenAccent }) => {
    return (
        <div style={{
            marginTop: '16px',
            border: `1px solid ${darkBorder}`,
            borderRadius: '4px',
            padding: '12px',
            backgroundColor: '#111111',
        }}>
            {bids.map((bid, idx) => (
                <BidItem 
                    key={bid.bid_id}
                    bid={bid}
                    idx={idx}
                    totalBids={bids.length}
                    darkBorder={darkBorder}
                    greenAccent={greenAccent}
                />
            ))}
        </div>
    );
};

/**
 * Individual Bid Item Component
 */
const BidItem = ({ bid, idx, totalBids, darkBorder, greenAccent }) => {
    const supplierLabel = String.fromCharCode(65 + idx); // A, B, C, etc.
    const [expandedProvider, setExpandedProvider] = React.useState(null);
    
    // Get video links by provider
    const videoLinksByProvider = bid.video_links_by_provider || {
        youtube: [],
        vimeo: [],
        loom: [],
    };
    
    // Count total videos
    const totalVideos = (videoLinksByProvider.youtube?.length || 0) + 
                       (videoLinksByProvider.vimeo?.length || 0) + 
                       (videoLinksByProvider.loom?.length || 0);
    
    return (
        <div
            style={{
                marginBottom: idx < totalBids - 1 ? '16px' : '0',
                paddingBottom: idx < totalBids - 1 ? '16px' : '0',
                borderBottom: idx < totalBids - 1 ? `1px solid ${darkBorder}` : 'none',
            }}
        >
            <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '12px' }}>
                Supplier {supplierLabel}
            </div>
            
            {/* Video Links by Provider */}
            {totalVideos > 0 && (
                <div style={{ marginBottom: '12px' }}>
                    <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px' }}>
                        Video Links ({totalVideos})
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                        {/* YouTube Tab */}
                        {videoLinksByProvider.youtube && videoLinksByProvider.youtube.length > 0 && (
                            <div>
                                <div
                                    onClick={() => setExpandedProvider(expandedProvider === 'youtube' ? null : 'youtube')}
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        padding: '6px 8px',
                                        backgroundColor: '#1a1a1a',
                                        border: `1px solid ${darkBorder}`,
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                        fontSize: '11px',
                                        marginBottom: expandedProvider === 'youtube' ? '4px' : '0',
                                    }}
                                >
                                    <span>YouTube ({videoLinksByProvider.youtube.length})</span>
                                    <span>{expandedProvider === 'youtube' ? '▼' : '▶'}</span>
                                </div>
                                {expandedProvider === 'youtube' && (
                                    <div style={{ padding: '8px', backgroundColor: '#0a0a0a', border: `1px solid ${darkBorder}`, borderRadius: '4px' }}>
                                        {videoLinksByProvider.youtube.map((link, linkIdx) => (
                                            <div key={linkIdx} style={{ marginBottom: '4px' }}>
                                                <a
                                                    href={link}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    style={{ fontSize: '11px', color: greenAccent, textDecoration: 'none' }}
                                                >
                                                    {link}
                                                </a>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        
                        {/* Vimeo Tab */}
                        {videoLinksByProvider.vimeo && videoLinksByProvider.vimeo.length > 0 && (
                            <div>
                                <div
                                    onClick={() => setExpandedProvider(expandedProvider === 'vimeo' ? null : 'vimeo')}
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        padding: '6px 8px',
                                        backgroundColor: '#1a1a1a',
                                        border: `1px solid ${darkBorder}`,
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                        fontSize: '11px',
                                        marginBottom: expandedProvider === 'vimeo' ? '4px' : '0',
                                    }}
                                >
                                    <span>Vimeo ({videoLinksByProvider.vimeo.length})</span>
                                    <span>{expandedProvider === 'vimeo' ? '▼' : '▶'}</span>
                                </div>
                                {expandedProvider === 'vimeo' && (
                                    <div style={{ padding: '8px', backgroundColor: '#0a0a0a', border: `1px solid ${darkBorder}`, borderRadius: '4px' }}>
                                        {videoLinksByProvider.vimeo.map((link, linkIdx) => (
                                            <div key={linkIdx} style={{ marginBottom: '4px' }}>
                                                <a
                                                    href={link}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    style={{ fontSize: '11px', color: greenAccent, textDecoration: 'none' }}
                                                >
                                                    {link}
                                                </a>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                        
                        {/* Loom Tab */}
                        {videoLinksByProvider.loom && videoLinksByProvider.loom.length > 0 && (
                            <div>
                                <div
                                    onClick={() => setExpandedProvider(expandedProvider === 'loom' ? null : 'loom')}
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        padding: '6px 8px',
                                        backgroundColor: '#1a1a1a',
                                        border: `1px solid ${darkBorder}`,
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                        fontSize: '11px',
                                        marginBottom: expandedProvider === 'loom' ? '4px' : '0',
                                    }}
                                >
                                    <span>Loom ({videoLinksByProvider.loom.length})</span>
                                    <span>{expandedProvider === 'loom' ? '▼' : '▶'}</span>
                                </div>
                                {expandedProvider === 'loom' && (
                                    <div style={{ padding: '8px', backgroundColor: '#0a0a0a', border: `1px solid ${darkBorder}`, borderRadius: '4px' }}>
                                        {videoLinksByProvider.loom.map((link, linkIdx) => (
                                            <div key={linkIdx} style={{ marginBottom: '4px' }}>
                                                <a
                                                    href={link}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    style={{ fontSize: '11px', color: greenAccent, textDecoration: 'none' }}
                                                >
                                                    {link}
                                                </a>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}
            
            {bid.prototype_timeline && (
                <div style={{ marginBottom: '8px', fontSize: '12px' }}>
                    Prototype Timeline: <span style={{ color: greenAccent }}>{bid.prototype_timeline}</span>
                </div>
            )}
            
            {bid.prototype_cost !== null && (
                <div style={{ marginBottom: '8px', fontSize: '12px' }}>
                    Prototype Cost: <span style={{ color: greenAccent }}>${bid.prototype_cost}</span>
                </div>
            )}
            
            {bid.production_lead_time && (
                <div style={{ marginBottom: '8px', fontSize: '12px' }}>
                    Production Lead Time: <span style={{ color: greenAccent }}>{bid.production_lead_time}</span>
                </div>
            )}
            
            {bid.unit_price !== null && (
                <div style={{ marginBottom: '8px', fontSize: '12px' }}>
                    Unit Price: <span style={{ color: greenAccent }}>${bid.unit_price}</span>
                    {bid.total_price && bid.item_quantity && bid.item_quantity > 1 && (
                        <div style={{ fontSize: '11px', color: darkText, marginTop: '4px', opacity: 0.8 }}>
                            Total Price: <span style={{ color: greenAccent }}>${parseFloat(bid.total_price).toFixed(2)}</span>
                            <span style={{ fontSize: '10px', opacity: 0.6 }}> ({bid.unit_price} × {bid.item_quantity})</span>
                        </div>
                    )}
                </div>
            )}
            
            {(bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)))) && (
                <div style={{ marginBottom: '8px', fontSize: '12px' }}>
                    Door-to-Door Delivery: <span style={{ color: greenAccent }}>${parseFloat(bid.delivery_cost_usd).toFixed(2)}</span>
                    {bid.delivery_shipping_mode && (
                        <div style={{ fontSize: '10px', color: darkText, marginTop: '2px', opacity: 0.7 }}>
                            Mode: {bid.delivery_shipping_mode === 'LCL' ? 'LCL' : bid.delivery_shipping_mode === 'FCL_20' ? '20\' Container' : bid.delivery_shipping_mode === 'FCL_40HQ' ? '40\' HQ Container' : bid.delivery_shipping_mode}
                        </div>
                    )}
                </div>
            )}
            
            {/* Commit 2.4.1: Award Bid Button */}
            {bid.can_award && !bid.is_awarded && !bid.is_declined && (
                <div style={{ marginTop: '12px', marginBottom: '8px' }}>
                    <button
                        onClick={async () => {
                            if (!window.confirm('Are you sure you want to award this bid? All other bids will be declined.')) {
                                return;
                            }
                            
                            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                            let nonce = '';
                            if (window.n88BoardNonce && window.n88BoardNonce.nonce_award_bid) {
                                nonce = window.n88BoardNonce.nonce_award_bid;
                            } else if (window.n88BoardData && window.n88BoardData.nonce) {
                                nonce = window.n88BoardData.nonce;
                            } else if (window.n88 && window.n88.nonce) {
                                nonce = window.n88.nonce;
                            }
                            
                            if (!nonce) {
                                alert('Security token missing. Please refresh the page and try again.');
                                return;
                            }
                            
                            try {
                                const formData = new FormData();
                                formData.append('action', 'n88_award_bid');
                                formData.append('item_id', item.id);
                                formData.append('bid_id', bid.bid_id);
                                formData.append('_ajax_nonce', nonce);
                                
                                const response = await fetch(ajaxUrl, {
                                    method: 'POST',
                                    body: formData
                                });
                                
                                const data = await response.json();
                                
                                if (data.success) {
                                    alert('Bid awarded successfully!');
                                    // Refresh the modal to show updated status
                                    if (onSave) {
                                        await onSave(item.id, {});
                                    }
                                    onClose();
                                } else {
                                    alert('Error: ' + (data.data?.message || 'Failed to award bid'));
                                }
                            } catch (error) {
                                console.error('Error awarding bid:', error);
                                alert('Error awarding bid. Please try again.');
                            }
                        }}
                        style={{
                            padding: '10px 20px',
                            backgroundColor: '#00ff00',
                            color: '#000',
                            border: 'none',
                            borderRadius: '4px',
                            fontSize: '12px',
                            fontWeight: '600',
                            cursor: 'pointer',
                            fontFamily: 'monospace',
                            transition: 'all 0.2s',
                        }}
                        onMouseOver={(e) => {
                            e.target.style.backgroundColor = '#00cc00';
                        }}
                        onMouseOut={(e) => {
                            e.target.style.backgroundColor = '#00ff00';
                        }}
                    >
                        [ Award Bid ]
                    </button>
                </div>
            )}
            
            {/* Commit 2.4.1: Awarded Status Badge */}
            {bid.is_awarded && (
                <div style={{ 
                    marginTop: '12px', 
                    marginBottom: '8px',
                    padding: '10px',
                    backgroundColor: '#003300',
                    border: '1px solid #00ff00',
                    borderRadius: '4px',
                    fontSize: '12px',
                    color: '#00ff00',
                    fontWeight: '600',
                    textAlign: 'center',
                }}>
                    ✓ Bid Awarded
                </div>
            )}
            
            {/* Commit 2.4.1: Declined Status */}
            {bid.is_declined && (
                <div style={{ 
                    marginTop: '12px', 
                    marginBottom: '8px',
                    padding: '10px',
                    backgroundColor: '#330000',
                    border: '1px solid #ff6666',
                    borderRadius: '4px',
                    fontSize: '12px',
                    color: '#ff6666',
                    textAlign: 'center',
                }}>
                    Bid Declined
                </div>
            )}
            
            {/* Smart Alternatives per bid (read-only) */}
            <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '6px' }}>
                    Smart Alternatives:
                </div>
                <div style={{ fontSize: '11px', color: darkText, marginBottom: '4px' }}>
                    Status: {bid.smart_alternatives_enabled ? (
                        <span style={{ color: greenAccent }}>Enabled</span>
                    ) : (
                        <span style={{ color: '#999' }}>Disabled</span>
                    )}
                </div>
                {bid.smart_alternatives_note && bid.smart_alternatives_note.trim() && (
                    <div style={{ fontSize: '11px', color: darkText, marginTop: '4px', padding: '6px', backgroundColor: '#0a0a0a', borderRadius: '4px', whiteSpace: 'pre-wrap' }}>
                        {bid.smart_alternatives_note}
                    </div>
                )}
            </div>
        </div>
    );
};

/**
 * ItemDetailModal - Designer Item Modal with Three States
 */
const ItemDetailModal = ({ item, isOpen, onClose, onSave, priceRequested = false, onPriceRequest }) => {
    const updateLayout = useBoardStore((state) => state.updateLayout);
    
    // Item state (RFQ and bids)
    const [itemState, setItemState] = React.useState({
        has_rfq: false,
        has_bids: false,
        bids: [],
        loading: true,
        has_unread_operator_messages: false,
        unread_operator_messages: 0,
        has_prototype_payment: false,
        prototype_payment_id: null,
        prototype_payment_bid_id: null,
        prototype_payment_supplier_id: null,
        prototype_payment_status: null,
        prototype_payment_total_due: null,
        // Commit 2.3.9.2A: CAD workflow state
        cad_status: null,
        cad_revision_rounds_included: null,
        cad_revision_rounds_used: null,
        cad_approved_at: null,
        cad_approved_version: null,
        cad_released_to_supplier_at: null,
        cad_current_version: null,
        // Commit 2.3.9.2B-D: Prototype review state
        prototype_status: null,
        prototype_current_version: null,
        prototype_approved_version: null,
        prototype_submission: null, // { version, links: [], created_at }
        direction_keyword_ids: null, // Array of keyword IDs
    });
    
    // Payment Instructions Modal State
    const [showPaymentInstructions, setShowPaymentInstructions] = React.useState(false);
    
    // Commit 2.3.9.2B-D: Prototype section state
    const [prototypeSectionExpanded, setPrototypeSectionExpanded] = React.useState(false);
    const [showRequestChangesModal, setShowRequestChangesModal] = React.useState(false);
    const [feedbackPacket, setFeedbackPacket] = React.useState({}); // { keyword_id: { status, severity, phrase_ids } }
    const [availablePhrases, setAvailablePhrases] = React.useState({}); // { keyword_id: [phrases] }
    const [keywordNames, setKeywordNames] = React.useState({}); // { keyword_id: keyword_name }
    const [totalPhrasesSelected, setTotalPhrasesSelected] = React.useState(0);
    
    // Form state
    const [category, setCategory] = React.useState(item.category || item.item_type || '');
    const [description, setDescription] = React.useState(item.description || '');
    const [quantity, setQuantity] = React.useState(
        item.quantity ? String(item.quantity) : 
        (item.meta?.quantity ? String(item.meta.quantity) : '')
    );
    const [width, setWidth] = React.useState(item.dims?.w ? String(item.dims.w) : '');
    const [depth, setDepth] = React.useState(item.dims?.d ? String(item.dims.d) : '');
    const [height, setHeight] = React.useState(item.dims?.h ? String(item.dims.h) : '');
    const [unit, setUnit] = React.useState(item.dims?.unit || 'in');
    const [deliveryCountry, setDeliveryCountry] = React.useState(
        item.delivery_country || item.meta?.delivery_country || ''
    );
    const [deliveryPostal, setDeliveryPostal] = React.useState(
        item.delivery_postal || item.meta?.delivery_postal || ''
    );
    
    // Keywords state - load from item meta
    const [keywords, setKeywords] = React.useState(
        item.keywords || item.meta?.keywords || []
    );
    const [keywordInput, setKeywordInput] = React.useState('');
    
    // Smart Alternatives state - load from item meta
    const [smartAlternativesEnabled, setSmartAlternativesEnabled] = React.useState(
        item.smart_alternatives !== undefined ? item.smart_alternatives : 
        (item.meta?.smart_alternatives !== undefined ? item.meta.smart_alternatives : true)
    );
    const [smartAlternativesNote, setSmartAlternativesNote] = React.useState(
        item.smart_alternatives_note || item.meta?.smart_alternatives_note || ''
    );
    
    // Inspiration images
    const validateInspirationItem = (insp) => {
        if (!insp || typeof insp !== 'object') return false;
        const hasId = insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0;
        const url = insp.url ? String(insp.url).trim() : '';
        const hasValidUrl = url && 
            url.length > 0 &&
            (url.startsWith('http://') || url.startsWith('https://')) && 
            !url.startsWith('data:');
        return hasId || hasValidUrl;
    };
    
    const initialInspiration = (item.inspiration || []).filter(validateInspirationItem);
    const [inspiration, setInspiration] = React.useState(initialInspiration);
    const [isSaving, setIsSaving] = React.useState(false);
    const [isUploadingInspiration, setIsUploadingInspiration] = React.useState(false);
    
    // RFQ form expansion state
    const [showRfqForm, setShowRfqForm] = React.useState(false);
    
    // Invite Makers state
    const [invitedSuppliers, setInvitedSuppliers] = React.useState([]);
    const [inviteSupplierInput, setInviteSupplierInput] = React.useState('');
    const [allowSystemInvites, setAllowSystemInvites] = React.useState(false);
    const [isSubmittingRfq, setIsSubmittingRfq] = React.useState(false);
    const [rfqError, setRfqError] = React.useState('');
    
    // BIDS section expansion state
    const [bidsExpanded, setBidsExpanded] = React.useState(false);
    
    // CAD Prototype Request form state (Commit 2.3.9.1B)
    const [showCadPrototypeForm, setShowCadPrototypeForm] = React.useState(false);
    const [selectedBidId, setSelectedBidId] = React.useState(null);
    const [selectedKeywords, setSelectedKeywords] = React.useState([]);
    const [prototypeNote, setPrototypeNote] = React.useState('');
    const [availableKeywords, setAvailableKeywords] = React.useState([]);
    const [isLoadingKeywords, setIsLoadingKeywords] = React.useState(false);
    const [isSubmittingCadPrototype, setIsSubmittingCadPrototype] = React.useState(false);
    const [cadPrototypeError, setCadPrototypeError] = React.useState('');
    const [cadPrototypeSuccess, setCadPrototypeSuccess] = React.useState(false);
    
    // Commit 2.3.9.1C-a: Designer message operator state
    const [showDesignerMessageForm, setShowDesignerMessageForm] = React.useState(false);
    const [designerMessages, setDesignerMessages] = React.useState([]);
    const [isLoadingDesignerMessages, setIsLoadingDesignerMessages] = React.useState(false);
    const [designerMessageText, setDesignerMessageText] = React.useState('');
    const [designerMessageCategory, setDesignerMessageCategory] = React.useState('');
    const [isSendingDesignerMessage, setIsSendingDesignerMessage] = React.useState(false);
    // Commit 2.3.9.2A: CAD workflow actions
    const [isCadActionBusy, setIsCadActionBusy] = React.useState(false);
    const [revisionFiles, setRevisionFiles] = React.useState([]);
    const [showRevisionUpload, setShowRevisionUpload] = React.useState(false);
    
    // Commit 2.3.9.1C-B: Clarifications state
    const [clarifications, setClarifications] = React.useState([]);
    const [isLoadingClarifications, setIsLoadingClarifications] = React.useState(false);
    const [showClarificationBanner, setShowClarificationBanner] = React.useState(false);
    
    // Tab state for new layout
    const [activeTab, setActiveTab] = React.useState('details');
    
    // Auto-select first bid when form opens with single bid (Commit 2.3.9.1B)
    React.useEffect(() => {
        if (showCadPrototypeForm && !selectedBidId && itemState.bids && itemState.bids.length === 1) {
            setSelectedBidId(itemState.bids[0].bid_id);
        }
    }, [showCadPrototypeForm, itemState.bids, selectedBidId]);
    
    // Auto-expand bids in State C and set active tab
    React.useEffect(() => {
        if (currentState === 'C' && itemState.has_bids && itemState.bids && itemState.bids.length > 0) {
            setBidsExpanded(true);
            setActiveTab('bids'); // Auto-select bids tab in State C
        } else if (currentState === 'B') {
            setActiveTab('rfq'); // Auto-select RFQ tab in State B
        } else {
            setActiveTab('details'); // Default to details tab in State A
        }
    }, [currentState, itemState.has_bids, itemState.bids]);
    
    // Image lightbox state
    const [lightboxImage, setLightboxImage] = React.useState(null);
    
    // Get item ID - extract numeric ID from "item-87" format or use direct ID
    const getItemId = () => {
        const id = item.id || item.item_id || '';
        if (typeof id === 'string' && id.indexOf('item-') === 0) {
            return parseInt(id.replace('item-', ''), 10);
        } else if (typeof id === 'string') {
            return parseInt(id, 10);
        } else if (typeof id === 'number') {
            return id;
        }
        return null;
    };
    const itemId = getItemId();
    
    // Fetch item RFQ/bid state when modal opens
    React.useEffect(() => {
        if (isOpen && itemId && itemId > 0) {
            fetchItemState();
        }
    }, [isOpen, itemId]);
    
    // Fetch item state from server
    const fetchItemState = async () => {
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        // Try multiple nonce sources
        let nonce = '';
        if (window.n88BoardData && window.n88BoardData.nonce) {
            nonce = window.n88BoardData.nonce;
        } else if (window.n88 && window.n88.nonce) {
            nonce = window.n88.nonce;
        } else if (window.n88BoardNonce && window.n88BoardNonce.nonce) {
            nonce = window.n88BoardNonce.nonce;
        }
        
        if (!nonce) {
            console.error('Nonce not found. Available:', {
                n88BoardData: window.n88BoardData,
                n88: window.n88,
                n88BoardNonce: window.n88BoardNonce
            });
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'n88_get_item_rfq_state');
            formData.append('item_id', String(itemId));
            formData.append('_ajax_nonce', nonce);
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });
            
            const data = await response.json();
            
            if (data.success) {
                setItemState({
                    has_rfq: data.data.has_rfq || false,
                    has_bids: data.data.has_bids || false,
                    bids: data.data.bids || [],
                    rfq_revision_current: data.data.rfq_revision_current || null,
                    revision_changed: data.data.revision_changed || false,
                    has_unread_operator_messages: data.data.has_unread_operator_messages || false,
                    unread_operator_messages: data.data.unread_operator_messages || 0,
                    has_prototype_payment: data.data.has_prototype_payment || false,
                    prototype_payment_id: data.data.prototype_payment_id || null,
                    prototype_payment_bid_id: data.data.prototype_payment_bid_id || null,
                    prototype_payment_supplier_id: data.data.prototype_payment_supplier_id || null,
                    prototype_payment_status: data.data.prototype_payment_status || null,
                    prototype_payment_total_due: data.data.prototype_payment_total_due || null,
                    // Commit 2.3.9.2A: CAD workflow state
                    cad_status: data.data.cad_status || null,
                    cad_revision_rounds_included: (data.data.cad_revision_rounds_included !== undefined && data.data.cad_revision_rounds_included !== null) ? data.data.cad_revision_rounds_included : null,
                    cad_revision_rounds_used: (data.data.cad_revision_rounds_used !== undefined && data.data.cad_revision_rounds_used !== null) ? data.data.cad_revision_rounds_used : null,
                    cad_approved_at: data.data.cad_approved_at || null,
                    cad_approved_version: (data.data.cad_approved_version !== undefined && data.data.cad_approved_version !== null) ? data.data.cad_approved_version : null,
                    cad_released_to_supplier_at: data.data.cad_released_to_supplier_at || null,
                    cad_current_version: (data.data.cad_current_version !== undefined && data.data.cad_current_version !== null) ? data.data.cad_current_version : null,
                    // Commit 2.3.9.2B-D: Prototype review state
                    prototype_status: data.data.prototype_status || null,
                    prototype_current_version: (data.data.prototype_current_version !== undefined && data.data.prototype_current_version !== null) ? data.data.prototype_current_version : null,
                    prototype_approved_version: (data.data.prototype_approved_version !== undefined && data.data.prototype_approved_version !== null) ? data.data.prototype_approved_version : null,
                    prototype_submission: data.data.prototype_submission || null,
                    direction_keyword_ids: data.data.direction_keyword_ids || null,
                    loading: false,
                });
            } else {
                console.error('Failed to fetch item state:', data.message);
                setItemState(prev => ({ ...prev, loading: false }));
            }
        } catch (error) {
            console.error('Error fetching item state:', error);
            setItemState(prev => ({ ...prev, loading: false }));
        }
    };
    
    // Load keywords when bid is selected (Commit 2.3.9.1B)
    React.useEffect(() => {
        if (!showCadPrototypeForm || !selectedBidId || !category) {
            return;
        }

        // Get category_id from category name - we'll need to fetch it
        // For now, try to get it from item data or fetch categories
        const loadKeywordsForCategory = async () => {
            setIsLoadingKeywords(true);
            setAvailableKeywords([]);

            try {
                // First, try to get category_id - we'll need to fetch categories or use a mapping
                // For now, let's create an endpoint that accepts category name, or fetch category_id
                // Let me fetch categories first to get the ID
                const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                // Get nonce for n88_get_keywords action
                let nonce = '';
                if (window.n88BoardNonce && window.n88BoardNonce.nonce_get_keywords) {
                    nonce = window.n88BoardNonce.nonce_get_keywords;
                } else if (window.n88BoardData && window.n88BoardData.nonce) {
                    nonce = window.n88BoardData.nonce;
                } else if (window.n88 && window.n88.nonce) {
                    nonce = window.n88.nonce;
                } else if (window.n88BoardNonce && window.n88BoardNonce.nonce) {
                    nonce = window.n88BoardNonce.nonce;
                }
                
                if (!nonce) {
                    console.error('Nonce not found for n88_get_keywords_by_category');
                    setAvailableKeywords([]);
                    setIsLoadingKeywords(false);
                    return;
                }
                
                // Use category name to fetch keywords (endpoint now accepts category_name)
                const formData = new FormData();
                formData.append('action', 'n88_get_keywords_by_category');
                formData.append('category_name', category);
                formData.append('_ajax_nonce', nonce);

                const response = await fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                });

                const data = await response.json();

                if (data.success && data.data && data.data.keywords) {
                    setAvailableKeywords(data.data.keywords);
                } else {
                    console.error('Failed to load keywords:', data.message);
                    setAvailableKeywords([]);
                }
            } catch (error) {
                console.error('Error loading keywords:', error);
                setAvailableKeywords([]);
            } finally {
                setIsLoadingKeywords(false);
            }
        };

        loadKeywordsForCategory();
    }, [showCadPrototypeForm, selectedBidId, category]);
    
    // Commit 2.3.9.1C-a: Load Designer Messages
    const loadDesignerMessages = React.useCallback(async () => {
        if (!itemId) return;
        
        setIsLoadingDesignerMessages(true);
        try {
            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
            const nonce = window.n88BoardNonce?.nonce_get_item_messages || window.n88BoardNonce?.nonce || window.n88BoardData?.nonce || window.n88?.nonce || '';
            
            if (!nonce) {
                console.error('Nonce not found for n88_get_item_messages');
                setIsLoadingDesignerMessages(false);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'n88_get_item_messages');
            formData.append('item_id', String(itemId));
            formData.append('thread_type', 'designer_operator');
            formData.append('_ajax_nonce', nonce);
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });
            
            const data = await response.json();
            
            if (data.success && data.data && data.data.messages) {
                setDesignerMessages(data.data.messages);
            } else {
                setDesignerMessages([]);
            }
        } catch (error) {
            console.error('Error loading designer messages:', error);
            setDesignerMessages([]);
        } finally {
            setIsLoadingDesignerMessages(false);
        }
    }, [itemId]);
    
    // Commit 2.3.9.1C-a: Send Designer Message
    const sendDesignerMessage = React.useCallback(async (e) => {
        e.preventDefault();
        
        if (!itemId || !designerMessageText.trim()) {
            alert('Please enter a message.');
            return;
        }
        
        setIsSendingDesignerMessage(true);
        try {
            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
            const nonce = window.n88BoardNonce?.nonce_send_item_message || window.n88BoardNonce?.nonce || window.n88BoardData?.nonce || window.n88?.nonce || '';
            
            if (!nonce) {
                console.error('Nonce not found for n88_send_item_message');
                setIsSendingDesignerMessage(false);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'n88_send_item_message');
            formData.append('item_id', String(itemId));
            formData.append('thread_type', 'designer_operator');
            formData.append('message_text', designerMessageText.trim());
            formData.append('category', designerMessageCategory || '');
            formData.append('_ajax_nonce', nonce);
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });
            
            const data = await response.json();
            
            if (data.success) {
                setDesignerMessageText('');
                setDesignerMessageCategory('');
                await loadDesignerMessages();
                // Refresh item state to update unread operator messages count
                await fetchItemState();
            } else {
                alert('Error: ' + (data.data?.message || 'Failed to send message. Please try again.'));
            }
        } catch (error) {
            console.error('Error sending designer message:', error);
            alert('An error occurred. Please try again.');
        } finally {
            setIsSendingDesignerMessage(false);
        }
    }, [itemId, designerMessageText, designerMessageCategory, loadDesignerMessages]);

    // Commit 2.3.9.2A: Designer CAD actions (Request Revision / Approve CAD)
    const requestCadRevision = React.useCallback(async (files = []) => {
        if (!itemId || !itemState.prototype_payment_id) return;
        if (files.length === 0) {
            alert('Please upload at least one file for the revision request.');
            return;
        }
        if (!window.confirm('Request a CAD revision with ' + files.length + ' file(s)? This will increment the revision round counter.')) return;

        setIsCadActionBusy(true);
        try {
            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
            const nonce = window.n88BoardNonce?.nonce_request_cad_revision || '';
            if (!nonce) {
                alert('Nonce missing for CAD revision request.');
                setIsCadActionBusy(false);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'n88_request_cad_revision');
            formData.append('payment_id', String(itemState.prototype_payment_id));
            formData.append('item_id', String(itemId));
            formData.append('_ajax_nonce', nonce);
            
            // Append files
            files.forEach((file) => {
                formData.append('revision_files[]', file);
            });

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                setShowRevisionUpload(false);
                setRevisionFiles([]);
                await loadDesignerMessages();
                await fetchItemState();
            } else {
                alert('Error: ' + (data.data?.message || 'Failed to request revision.'));
            }
        } catch (err) {
            console.error('Error requesting CAD revision:', err);
            alert('An error occurred. Please try again.');
        } finally {
            setIsCadActionBusy(false);
        }
    }, [itemId, itemState.prototype_payment_id, loadDesignerMessages, fetchItemState]);

    const approveCad = React.useCallback(async () => {
        if (!itemId || !itemState.prototype_payment_id) return;
        if (!window.confirm('Approve the current CAD version? This will lock the approved version for release.')) return;

        setIsCadActionBusy(true);
        try {
            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
            const nonce = window.n88BoardNonce?.nonce_approve_cad || '';
            if (!nonce) {
                alert('Nonce missing for CAD approval.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'n88_approve_cad');
            formData.append('payment_id', String(itemState.prototype_payment_id));
            formData.append('item_id', String(itemId));
            formData.append('_ajax_nonce', nonce);

            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                await loadDesignerMessages();
                await fetchItemState();
            } else {
                alert('Error: ' + (data.data?.message || 'Failed to approve CAD.'));
            }
        } catch (err) {
            console.error('Error approving CAD:', err);
            alert('An error occurred. Please try again.');
        } finally {
            setIsCadActionBusy(false);
        }
    }, [itemId, itemState.prototype_payment_id, loadDesignerMessages]);
    
    // Auto-scroll to bottom when messages load or new message is sent
    React.useEffect(() => {
        if (showDesignerMessageForm && designerMessages.length > 0) {
            const container = document.getElementById('n88-designer-messages-container');
            if (container) {
                setTimeout(() => {
                    container.scrollTop = container.scrollHeight;
                }, 100);
            }
        }
    }, [showDesignerMessageForm, designerMessages]);
    
    // Update inspiration when item changes
    React.useEffect(() => {
        const validInspiration = (item.inspiration || []).filter(validateInspirationItem);
        setInspiration(validInspiration);
    }, [item.id, item.inspiration]);
    
    // Update form fields when item data changes (especially after reload)
    React.useEffect(() => {
        // Parse meta_json if it's a string
        let parsedMeta = item.meta;
        if (!parsedMeta && item.meta_json && typeof item.meta_json === 'string') {
            try {
                parsedMeta = JSON.parse(item.meta_json);
            } catch (e) {
                console.error('Failed to parse meta_json:', e);
                parsedMeta = {};
            }
        }
        
        // Update quantity if available in item or meta
        const qtyValue = item.quantity || (parsedMeta && parsedMeta.quantity);
        if (qtyValue !== undefined && qtyValue !== null) {
            setQuantity(String(qtyValue));
            console.log('ItemDetailModal - Updated quantity from item:', qtyValue);
        }
        
        // Update smart_alternatives_note if available
        const noteValue = item.smart_alternatives_note || (parsedMeta && parsedMeta.smart_alternatives_note);
        if (noteValue !== undefined) {
            setSmartAlternativesNote(noteValue || '');
            console.log('ItemDetailModal - Updated smart_alternatives_note from item:', noteValue);
        }
        
        // Update dimensions if available in meta
        if (parsedMeta && parsedMeta.dims) {
            if (parsedMeta.dims.w !== undefined) setWidth(String(parsedMeta.dims.w));
            if (parsedMeta.dims.d !== undefined) setDepth(String(parsedMeta.dims.d));
            if (parsedMeta.dims.h !== undefined) setHeight(String(parsedMeta.dims.h));
            if (parsedMeta.dims.unit) setUnit(parsedMeta.dims.unit);
        }
        
        // Update delivery info if available in meta
        if (parsedMeta) {
            if (parsedMeta.delivery_country !== undefined) {
                setDeliveryCountry(parsedMeta.delivery_country || '');
            }
            if (parsedMeta.delivery_postal !== undefined) {
                setDeliveryPostal(parsedMeta.delivery_postal || '');
            }
        }
    }, [item.id, item.quantity, item.meta_json, item.meta, item.smart_alternatives_note]);
    
    // Prevent body scroll when modal is open (K) - FORCEFUL COMPLETE LOCK
    React.useEffect(() => {
        if (isOpen) {
            // Store original values
            const originalBodyOverflow = document.body.style.overflow || '';
            const originalBodyHeight = document.body.style.height || '';
            const originalBodyPosition = document.body.style.position || '';
            const originalBodyTop = document.body.style.top || '';
            const originalBodyWidth = document.body.style.width || '';
            const originalBodyLeft = document.body.style.left || '';
            const originalHtmlOverflow = document.documentElement.style.overflow || '';
            const originalHtmlHeight = document.documentElement.style.height || '';
            const scrollY = window.scrollY;
            const scrollX = window.scrollX;
            
            // FORCEFUL LOCK: Prevent all page scrolling with !important equivalent
            document.body.style.setProperty('overflow', 'hidden', 'important');
            document.body.style.setProperty('height', '100%', 'important');
            document.body.style.setProperty('position', 'fixed', 'important');
            document.body.style.setProperty('top', `-${scrollY}px`, 'important');
            document.body.style.setProperty('left', `-${scrollX}px`, 'important');
            document.body.style.setProperty('width', '100%', 'important');
            document.body.style.setProperty('margin', '0', 'important');
            document.body.style.setProperty('padding', '0', 'important');
            
            document.documentElement.style.setProperty('overflow', 'hidden', 'important');
            document.documentElement.style.setProperty('height', '100%', 'important');
            
            // Add class for CSS targeting
            document.body.classList.add('n88-modal-open');
            document.documentElement.classList.add('n88-modal-open');
            
            // Prevent board container scroll
            const boardContainer = document.getElementById('n88-board-canvas-container');
            if (boardContainer) {
                const originalContainerOverflow = boardContainer.style.overflow || '';
                boardContainer.style.setProperty('overflow', 'hidden', 'important');
                boardContainer.style.setProperty('position', 'fixed', 'important');
                boardContainer.setAttribute('data-original-overflow', originalContainerOverflow || 'auto');
            }
            
            // Prevent wpcontent scroll
            const wpcontent = document.getElementById('wpcontent');
            if (wpcontent) {
                const originalWpcontentOverflow = wpcontent.style.overflow || '';
                wpcontent.style.setProperty('overflow', 'hidden', 'important');
                wpcontent.style.setProperty('position', 'fixed', 'important');
                wpcontent.setAttribute('data-original-overflow', originalWpcontentOverflow || '');
            }
            
            // Prevent wpbody-content scroll
            const wpbodyContent = document.getElementById('wpbody-content');
            if (wpbodyContent) {
                const originalWpbodyOverflow = wpbodyContent.style.overflow || '';
                wpbodyContent.style.setProperty('overflow', 'hidden', 'important');
                wpbodyContent.setAttribute('data-original-overflow', originalWpbodyOverflow || '');
            }
            
            // Prevent wrap scroll
            const wrap = document.querySelector('.wrap');
            if (wrap) {
                const originalWrapOverflow = wrap.style.overflow || '';
                wrap.style.setProperty('overflow', 'hidden', 'important');
                wrap.setAttribute('data-original-overflow', originalWrapOverflow || '');
            }
            
            // Prevent ALL scrolling via event listeners
            const preventScroll = (e) => {
                // Only prevent if scrolling outside modal
                const modal = document.querySelector('[style*="z-index: 1000000"]');
                if (modal && !modal.contains(e.target)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            };
            
            const preventWheel = (e) => {
                const modal = document.querySelector('[style*="z-index: 1000000"]');
                if (modal && !modal.contains(e.target)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            };
            
            const preventTouchMove = (e) => {
                const modal = document.querySelector('[style*="z-index: 1000000"]');
                if (modal && !modal.contains(e.target)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            };
            
            // Add event listeners to prevent all scroll methods
            window.addEventListener('scroll', preventScroll, { passive: false, capture: true });
            window.addEventListener('wheel', preventWheel, { passive: false, capture: true });
            window.addEventListener('touchmove', preventTouchMove, { passive: false, capture: true });
            document.addEventListener('scroll', preventScroll, { passive: false, capture: true });
            document.addEventListener('wheel', preventWheel, { passive: false, capture: true });
            document.addEventListener('touchmove', preventTouchMove, { passive: false, capture: true });
            
            // Store scroll position and event listeners for cleanup
            document.body.setAttribute('data-scroll-y', scrollY.toString());
            document.body.setAttribute('data-scroll-x', scrollX.toString());
            document.body.setAttribute('data-prevent-scroll', 'true');
            
            // Commit 2.3.5.3: Ensure Request Quote button is visible without scrolling
            if (currentState === 'A') {
                setTimeout(() => {
                    const requestQuoteSection = document.getElementById('request-quote-section');
                    if (requestQuoteSection) {
                        requestQuoteSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }, 100);
            }
            
            // Cleanup function
            return () => {
                // Remove event listeners
                window.removeEventListener('scroll', preventScroll, { capture: true });
                window.removeEventListener('wheel', preventWheel, { capture: true });
                window.removeEventListener('touchmove', preventTouchMove, { capture: true });
                document.removeEventListener('scroll', preventScroll, { capture: true });
                document.removeEventListener('wheel', preventWheel, { capture: true });
                document.removeEventListener('touchmove', preventTouchMove, { capture: true });
                
                // Remove classes
                document.body.classList.remove('n88-modal-open');
                document.documentElement.classList.remove('n88-modal-open');
                
                // Restore body scroll
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('height');
                document.body.style.removeProperty('position');
                document.body.style.removeProperty('top');
                document.body.style.removeProperty('left');
                document.body.style.removeProperty('width');
                document.body.style.removeProperty('margin');
                document.body.style.removeProperty('padding');
                
                document.documentElement.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('height');
                
                // Restore scroll position
                const savedScrollY = document.body.getAttribute('data-scroll-y');
                const savedScrollX = document.body.getAttribute('data-scroll-x');
                if (savedScrollY) {
                    window.scrollTo(parseInt(savedScrollX || '0', 10), parseInt(savedScrollY, 10));
                    document.body.removeAttribute('data-scroll-y');
                    document.body.removeAttribute('data-scroll-x');
                }
                document.body.removeAttribute('data-prevent-scroll');
                
                // Restore board container scroll
                if (boardContainer) {
                    boardContainer.style.removeProperty('overflow');
                    boardContainer.style.removeProperty('position');
                    const originalOverflow = boardContainer.getAttribute('data-original-overflow') || 'auto';
                    if (originalOverflow) {
                        boardContainer.style.overflow = originalOverflow;
                    }
                    boardContainer.removeAttribute('data-original-overflow');
                }
                
                // Restore wpcontent scroll
                if (wpcontent) {
                    wpcontent.style.removeProperty('overflow');
                    wpcontent.style.removeProperty('position');
                    const originalOverflow = wpcontent.getAttribute('data-original-overflow') || '';
                    if (originalOverflow) {
                        wpcontent.style.overflow = originalOverflow;
                    }
                    wpcontent.removeAttribute('data-original-overflow');
                }
                
                // Restore wpbody-content scroll
                if (wpbodyContent) {
                    wpbodyContent.style.removeProperty('overflow');
                    const originalOverflow = wpbodyContent.getAttribute('data-original-overflow') || '';
                    if (originalOverflow) {
                        wpbodyContent.style.overflow = originalOverflow;
                    }
                    wpbodyContent.removeAttribute('data-original-overflow');
                }
                
                // Restore wrap scroll
                if (wrap) {
                    wrap.style.removeProperty('overflow');
                    const originalOverflow = wrap.getAttribute('data-original-overflow') || '';
                    if (originalOverflow) {
                        wrap.style.overflow = originalOverflow;
                    }
                    wrap.removeAttribute('data-original-overflow');
                }
            };
        }
    }, [isOpen, currentState]);
    
    // Computed values
    const [computedValues, setComputedValues] = React.useState({
        dimsCm: item.dims_cm || null,
        cbm: item.cbm || null,
        sourcingType: item.sourcing_type || null,
        timelineType: item.timeline_type || null,
    });
    
    // Recompute when dimensions change
    React.useEffect(() => {
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
    
    // Determine current state
    const currentState = React.useMemo(() => {
        if (itemState.loading) return 'loading';
        // Use itemState first, fallback to item prop if itemState not loaded yet
        const has_rfq = itemState.has_rfq || item?.has_rfq || false;
        const has_bids = itemState.has_bids || item?.has_bids || false;
        if (has_bids) return 'C'; // State C: Proposals received
        if (has_rfq) return 'B'; // State B: RFQ sent, no bids
        return 'A'; // State A: Before RFQ
    }, [itemState, item]);
    
    // Check if fields should be editable
    // Dimensions and quantity must remain editable at all times after RFQ submission (State B and C)
    const isEditable = currentState === 'A';
    const isDimsQtyEditable = true; // Always editable, even after RFQ and bids
    
    // Warning banner state for post-RFQ dims/qty changes
    // Show only if: RFQ exists AND bids exist AND dims/qty changed
    const [showWarningBanner, setShowWarningBanner] = React.useState(false);
    
    // Handle save
    const handleSave = async () => {
        if (isUploadingInspiration) {
            alert('Please wait for image uploads to complete before saving.');
            return;
        }
        
        // Dimensions and quantity are always editable (State B and C)
        // No blocking - allow saving in all states
        
        setIsSaving(true);
        
        try {
            const validInspiration = inspiration.filter(insp => {
                if (!insp || typeof insp !== 'object') return false;
                const hasId = insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0;
                const url = insp.url ? String(insp.url).trim() : '';
                const hasValidUrl = url && 
                    url.length > 0 &&
                    (url.startsWith('http://') || url.startsWith('https://')) && 
                    !url.startsWith('data:');
                return hasId || hasValidUrl;
            }).map(insp => {
                const url = insp.url ? String(insp.url).trim() : '';
                const hasValidUrl = url && (url.startsWith('http://') || url.startsWith('https://'));
                
                return {
                    type: insp.type || 'image',
                    id: (insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0) ? Number(insp.id) : null,
                    url: hasValidUrl ? url : '',
                    title: insp.title || insp.filename || 'Reference image',
                };
            });
            
            if (inspiration.length > 0 && validInspiration.length === 0) {
                alert('Warning: All inspiration images were invalid and will not be saved. Please re-upload them.');
                setIsSaving(false);
                return;
            }
            
            const dimsCm = computedValues.dimsCm;
            
            // Process quantity - ensure it's always included if it has a value
            let qtyValue = null;
            if (quantity !== '' && quantity !== null && quantity !== undefined) {
                const parsedQty = parseInt(quantity);
                if (!isNaN(parsedQty) && parsedQty >= 0) {
                    qtyValue = parsedQty;
                }
            }
            
            // Process notes - always include, even if empty
            const notesValue = smartAlternativesNote || '';
            
            const payload = {
                category,
                description,
                keywords: keywords,
                quantity: qtyValue,
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
                inspiration: validInspiration,
                smart_alternatives: smartAlternativesEnabled,
                smart_alternatives_note: notesValue,
                delivery_country: deliveryCountry,
                delivery_postal: deliveryPostal,
            };
            
            // Log payload for debugging
            console.log('ItemDetailModal - Saving item facts (handleSave):', {
                itemId: item.id,
                quantity: qtyValue,
                quantityRaw: quantity,
                smart_alternatives_note: notesValue,
                payload: payload
            });
            
            updateLayout(item.id, payload);
            
            if (onSave) {
                const response = await onSave(item.id, payload);
                // Commit 2.3.5.1: Show warning banner only if: RFQ exists AND bids exist AND dims/qty changed
                if (response && response.has_warning && itemState.has_rfq && itemState.has_bids) {
                    setShowWarningBanner(true);
                    // Auto-hide after 10 seconds
                    setTimeout(() => setShowWarningBanner(false), 10000);
                }
            }
            
            // Commit 2.3.5.3: Close modal after save
            setIsSaving(false);
            if (onClose) {
                onClose();
            }
        } catch (error) {
            console.error('Error saving item facts:', error);
            alert('Failed to save item facts. Please try again.');
            setIsSaving(false);
        }
    };

    // Handler to update only dimensions and quantity (after RFQ is submitted)
    const handleUpdateDimensions = async () => {
        if (isUploadingInspiration) {
            alert('Please wait for image uploads to complete before updating.');
            return;
        }
        
        setIsSaving(true);
        
        try {
            const dimsCm = computedValues.dimsCm;
            
            // Process quantity
            let qtyValue = null;
            if (quantity !== '' && quantity !== null && quantity !== undefined) {
                const parsedQty = parseInt(quantity);
                if (!isNaN(parsedQty) && parsedQty >= 0) {
                    qtyValue = parsedQty;
                }
            }
            
            // Only update dimensions and quantity
            const payload = {
                quantity: qtyValue,
                dims: {
                    w: width ? parseFloat(width) : null,
                    d: depth ? parseFloat(depth) : null,
                    h: height ? parseFloat(height) : null,
                    unit,
                },
                dims_cm: dimsCm,
                cbm: computedValues.cbm,
            };
            
            console.log('ItemDetailModal - Updating dimensions (handleUpdateDimensions):', {
                itemId: item.id,
                quantity: qtyValue,
                payload: payload
            });
            
            updateLayout(item.id, payload);
            
            if (onSave) {
                const response = await onSave(item.id, payload);
                // Show warning banner if dims/qty changed and bids exist
                if (response && response.has_warning && itemState.has_rfq && itemState.has_bids) {
                    setShowWarningBanner(true);
                    setTimeout(() => setShowWarningBanner(false), 10000);
                }
            }
            
            setIsSaving(false);
            alert('Dimensions and quantity updated successfully.');
        } catch (error) {
            console.error('Error updating dimensions:', error);
            alert('Failed to update dimensions. Please try again.');
            setIsSaving(false);
        }
    };
    
    // Handle inspiration image upload
    const handleInspirationFileChange = async (e) => {
        const files = e.target.files;
        if (!files || files.length === 0) return;
        
        // Commit 2.3.5.3: Allow both images and PDFs for inspiration section
        // Added HEIC support - check by MIME type or file extension
        const validFiles = Array.from(files).filter(file => {
            const isImage = file.type.startsWith('image/') || 
                           file.name.toLowerCase().endsWith('.heic') || 
                           file.name.toLowerCase().endsWith('.heif');
            const isPdf = file.type === 'application/pdf' || 
                         file.name.toLowerCase().endsWith('.pdf');
            return isImage || isPdf;
        });
        if (validFiles.length === 0) {
            alert('Please select image or PDF files only.');
            e.target.value = '';
            return;
        }
        
        // Separate images (including HEIC) and PDFs
        const imageFiles = validFiles.filter(file => {
            const isImage = file.type.startsWith('image/') || 
                           file.name.toLowerCase().endsWith('.heic') || 
                           file.name.toLowerCase().endsWith('.heif');
            return isImage;
        });
        const pdfFiles = validFiles.filter(file => file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf'));
        
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        // Try multiple nonce sources
        let nonce = '';
        if (window.n88BoardData && window.n88BoardData.nonce) {
            nonce = window.n88BoardData.nonce;
        } else if (window.n88 && window.n88.nonce) {
            nonce = window.n88.nonce;
        } else if (window.n88BoardNonce && window.n88BoardNonce.nonce) {
            nonce = window.n88BoardNonce.nonce;
        }
        
        if (!nonce) {
            alert('Security token missing. Please refresh the page and try again.');
            e.target.value = '';
            return;
        }
        
        setIsUploadingInspiration(true);
        
        try {
            // Upload images
            const imageUploadPromises = imageFiles.map(async (file) => {
                const formData = new FormData();
                formData.append('action', 'n88_upload_inspiration_image');
                formData.append('inspiration_image', file);
                formData.append('nonce', nonce);
                
                try {
                    const response = await fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData,
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    
                    if (data.success && 
                        data.data && 
                        data.data.id && 
                        Number.isInteger(Number(data.data.id)) && 
                        Number(data.data.id) > 0 &&
                        data.data.url && 
                        typeof data.data.url === 'string' && 
                        data.data.url.trim().length > 0 &&
                        (data.data.url.startsWith('http://') || data.data.url.startsWith('https://'))) {
                        return {
                            type: 'image',
                            url: data.data.url.trim(),
                            id: Number(data.data.id),
                            title: data.data.title || data.data.filename || file.name,
                        };
                    } else {
                        const errorMsg = data.data?.message || 'Upload failed';
                        alert('Failed to upload ' + file.name + ': ' + errorMsg);
                        return null;
                    }
                } catch (error) {
                    console.error('Error uploading image:', error);
                    alert('Error uploading ' + file.name + ': ' + error.message);
                    return null;
                }
            });
            
            // Upload PDFs (Commit 2.3.5.3: PDF support for sketch drawings)
            const pdfUploadPromises = pdfFiles.map(async (file) => {
                const formData = new FormData();
                formData.append('action', 'n88_upload_inspiration_image');
                formData.append('inspiration_image', file);
                formData.append('nonce', nonce);
                
                try {
                    const response = await fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData,
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    
                    if (data.success && 
                        data.data && 
                        data.data.id && 
                        Number.isInteger(Number(data.data.id)) && 
                        Number(data.data.id) > 0 &&
                        data.data.url && 
                        typeof data.data.url === 'string' && 
                        data.data.url.trim().length > 0 &&
                        (data.data.url.startsWith('http://') || data.data.url.startsWith('https://'))) {
                        return {
                            type: 'pdf',
                            url: data.data.url.trim(),
                            id: Number(data.data.id),
                            title: data.data.title || data.data.filename || file.name,
                        };
                    } else {
                        const errorMsg = data.data?.message || 'Upload failed';
                        alert('Failed to upload ' + file.name + ': ' + errorMsg);
                        return null;
                    }
                } catch (error) {
                    console.error('Error uploading PDF:', error);
                    alert('Error uploading ' + file.name + ': ' + error.message);
                    return null;
                }
            });
            
            const uploadedImages = await Promise.all(imageUploadPromises);
            const uploadedPdfs = await Promise.all(pdfUploadPromises);
            const allUploaded = [...uploadedImages, ...uploadedPdfs];
            
            const validFiles = allUploaded.filter(file => {
                if (!file || typeof file !== 'object') return false;
                const hasId = file.id && Number.isInteger(Number(file.id)) && Number(file.id) > 0;
                const url = file.url ? String(file.url).trim() : '';
                const hasUrl = url && 
                    url.length > 0 &&
                    (url.startsWith('http://') || url.startsWith('https://')) && 
                    !url.startsWith('data:');
                return hasId && hasUrl;
            });
            
            if (validFiles.length > 0) {
                setInspiration([...inspiration, ...validFiles]);
            } else if (allUploaded.length > 0) {
                    alert('No files were successfully uploaded. Please try again.');
            }
        } catch (error) {
            console.error('Error during upload process:', error);
            alert('Error uploading files: ' + error.message);
        } finally {
            setIsUploadingInspiration(false);
            e.target.value = '';
        }
    };
    
    // Format dimensions for display
    const formatDimensions = () => {
        if (!width || !depth || !height) return null;
        const w = parseFloat(width);
        const d = parseFloat(depth);
        const h = parseFloat(height);
        if (isNaN(w) || isNaN(d) || isNaN(h)) return null;
        return `${w}${unit === 'in' ? '"' : unit}W × ${d}${unit === 'in' ? '"' : unit}D × ${h}${unit === 'in' ? '"' : unit}H`;
    };
    
    // Format delivery for display
    const formatDelivery = () => {
        if (!deliveryCountry) return null;
        const parts = [deliveryCountry];
        if (deliveryPostal) parts.push(deliveryPostal);
        return parts.join(' ');
    };
    
    // Shipping validation message state
    const [shippingMessage, setShippingMessage] = React.useState('');
    
    // Update shipping message when delivery country changes
    React.useEffect(() => {
        if (!deliveryCountry) {
            setShippingMessage('');
            return;
        }
        
        const country = deliveryCountry.toUpperCase();
        if (country === 'US' || country === 'CA') {
            if (!deliveryPostal) {
                setShippingMessage('ZIP/postal code is required for US and Canada.');
            } else {
                setShippingMessage('');
            }
        } else if (country) {
            setShippingMessage('We are not able to calculate an instant shipping estimate for this delivery location yet, but our team can get back to you with a shipping range within 24 hours.');
        } else {
            setShippingMessage('');
        }
    }, [deliveryCountry, deliveryPostal]);
    
    // Update system invites message
    const updateSystemInvitesMessage = React.useCallback(() => {
        if (allowSystemInvites) {
            if (invitedSuppliers.length > 0) {
                return 'We\'ll invite 2 additional makers in 24 hours.';
            } else {
                return 'We will send your request on your behalf.';
            }
        }
        return '';
    }, [allowSystemInvites, invitedSuppliers.length]);
    
    const [systemInvitesMessage, setSystemInvitesMessage] = React.useState('');
    
    React.useEffect(() => {
        setSystemInvitesMessage(updateSystemInvitesMessage());
    }, [updateSystemInvitesMessage]);
    
    // Add invited supplier chip
    const addInvitedSupplierChip = () => {
        const value = inviteSupplierInput.trim();
        if (!value) return;
        
        if (invitedSuppliers.length >= 5) {
            setRfqError('Maximum 5 invited makers allowed.');
            return;
        }
        
        if (invitedSuppliers.includes(value)) {
            setRfqError('This supplier is already added.');
            return;
        }
        
        setInvitedSuppliers([...invitedSuppliers, value]);
        setInviteSupplierInput('');
        setRfqError('');
        
        // Update system invites message if checkbox is checked
        if (allowSystemInvites) {
            setSystemInvitesMessage('We\'ll invite 2 additional makers in 24 hours.');
        }
    };
    
    // Remove invited supplier chip
    const removeInvitedSupplierChip = (value) => {
        const newSuppliers = invitedSuppliers.filter(s => s !== value);
        setInvitedSuppliers(newSuppliers);
        
        // Update system invites message if checkbox is checked
        if (allowSystemInvites) {
            if (newSuppliers.length > 0) {
                setSystemInvitesMessage('We\'ll invite 2 additional makers in 24 hours.');
            } else {
                setSystemInvitesMessage('We will send your request on your behalf.');
            }
        }
    };
    
    // Add keyword chip
    const addKeywordChip = () => {
        const value = keywordInput.trim();
        if (!value) return;
        
        // Split by comma if multiple keywords entered
        const newKeywords = value.split(',').map(k => k.trim()).filter(k => k.length > 0);
        
        // Add unique keywords only
        const uniqueKeywords = [...new Set([...keywords, ...newKeywords])];
        setKeywords(uniqueKeywords);
        setKeywordInput('');
    };
    
    // Remove keyword chip
    const removeKeywordChip = (keyword) => {
        setKeywords(keywords.filter(k => k !== keyword));
    };
    
    // Handle RFQ submission
    const handleSubmitRfq = async (e) => {
        e.preventDefault();
        
        // Validate
        if (invitedSuppliers.length === 0 && !allowSystemInvites) {
            setRfqError('Invite at least one maker or allow the system to invite makers.');
            return;
        }
        
        // Validate required fields
        if (!quantity || !deliveryCountry) {
            setRfqError('Please fill in all required fields (Quantity, Delivery Country).');
            return;
        }
        
        setIsSubmittingRfq(true);
        setRfqError('');
        
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        let nonce = '';
        if (window.n88BoardData && window.n88BoardData.nonce) {
            nonce = window.n88BoardData.nonce;
        } else if (window.n88 && window.n88.nonce) {
            nonce = window.n88.nonce;
        } else if (window.n88BoardNonce && window.n88BoardNonce.nonce) {
            nonce = window.n88BoardNonce.nonce;
        }
        
        if (!nonce) {
            setRfqError('Security token missing. Please refresh the page and try again.');
            setIsSubmittingRfq(false);
            return;
        }
        
        // Validate item ID
        if (!itemId || isNaN(itemId) || itemId <= 0) {
            setRfqError('Invalid item ID. Please refresh the page and try again.');
            setIsSubmittingRfq(false);
            return;
        }
        
        try {
            const items = [{
                item_id: itemId,
                quantity: parseInt(quantity),
                width: parseFloat(width),
                depth: parseFloat(depth),
                height: parseFloat(height),
                dimension_unit: unit,
                delivery_country: deliveryCountry.toUpperCase().trim(),
                delivery_postal: deliveryPostal.trim(),
            }];
            
            const formData = new FormData();
            formData.append('action', 'n88_submit_rfq');
            formData.append('items', JSON.stringify(items));
            formData.append('invited_suppliers', JSON.stringify(invitedSuppliers));
            formData.append('allow_system_invites', allowSystemInvites ? '1' : '0');
            formData.append('_ajax_nonce', nonce);
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Save item facts (quantity, dimensions, delivery, notes) to item meta_json
                try {
                    const validInspiration = inspiration.filter(insp => {
                        if (!insp || typeof insp !== 'object') return false;
                        const hasId = insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0;
                        const url = insp.url ? String(insp.url).trim() : '';
                        const hasValidUrl = url && 
                            url.length > 0 &&
                            (url.startsWith('http://') || url.startsWith('https://')) && 
                            !url.startsWith('data:');
                        return hasId || hasValidUrl;
                    }).map(insp => {
                        const url = insp.url ? String(insp.url).trim() : '';
                        const hasValidUrl = url && (url.startsWith('http://') || url.startsWith('https://'));
                        
                        return {
                            type: insp.type || 'image',
                            id: (insp.id && Number.isInteger(Number(insp.id)) && Number(insp.id) > 0) ? Number(insp.id) : null,
                            url: hasValidUrl ? url : '',
                            title: insp.title || insp.filename || 'Reference image',
                        };
                    });
                    
                    const dimsCm = computedValues.dimsCm;
                    
                    // Process quantity - ensure it's always included if it has a value
                    let qtyValue = null;
                    if (quantity !== '' && quantity !== null && quantity !== undefined) {
                        const parsedQty = parseInt(quantity);
                        if (!isNaN(parsedQty) && parsedQty >= 0) {
                            qtyValue = parsedQty;
                        }
                    }
                    
                    // Process notes - always include, even if empty
                    const notesValue = smartAlternativesNote || '';
                    
                    const payload = {
                        category,
                        description,
                        quantity: qtyValue,
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
                        inspiration: validInspiration,
                        smart_alternatives: smartAlternativesEnabled,
                        smart_alternatives_note: notesValue,
                        delivery_country: deliveryCountry,
                        delivery_postal: deliveryPostal,
                    };
                    
                    // Log payload for debugging
                    console.log('ItemDetailModal - Saving item facts after RFQ submission:', {
                        itemId: item.id,
                        quantity: qtyValue,
                        quantityRaw: quantity,
                        smart_alternatives_note: notesValue,
                        payload: payload
                    });
                    
                    updateLayout(item.id, payload);
                    
                    if (onSave) {
                        await onSave(item.id, payload);
                    }
                } catch (saveError) {
                    console.error('Error saving item facts after RFQ submission:', saveError);
                    // Don't block RFQ submission if save fails, just log it
                }
                
                alert(data.data.message || 'RFQ submitted successfully!');
                setShowRfqForm(false);
                // Optimistically update state to State B immediately
                setItemState(prev => ({
                    ...prev,
                    has_rfq: true,
                    has_bids: false,
                    loading: false,
                }));
                // Then refresh from server to get actual state
                await fetchItemState();
            } else {
                if (data.data && data.data.errors) {
                    const errorMessages = Object.values(data.data.errors).join(' ');
                    setRfqError(errorMessages);
                } else {
                    setRfqError(data.data && data.data.message ? data.data.message : 'An error occurred. Please try again.');
                }
            }
        } catch (error) {
            console.error('RFQ submission error:', error);
            setRfqError('Network error. Please try again.');
        } finally {
            setIsSubmittingRfq(false);
        }
    };
    
    if (!isOpen) return null;
    
    // Dark theme colors (matching wireframes)
    const darkBg = '#000000';
    const darkText = '#d3d3d3';
    const greenAccent = '#00ff00';
    const darkBorder = '#333333';
    
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
                            // backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            // zIndex: 999999,
                        }}
                    />
                    
                    {/* Modal - 1300px x 700px centered */}
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        exit={{ opacity: 0, scale: 0.95 }}
                        transition={{ type: 'spring', damping: 25, stiffness: 200 }}
                        style={{
                            position: 'fixed',
                            top: '50%',
                            left: '50%',
                            transform: 'translate(-50%, -50%)',
                            width: '1300px',
                            height: '700px',
                            maxWidth: '95vw',
                            maxHeight: '95vh',
                            backgroundColor: darkBg,
                            color: darkText,
                            fontFamily: 'monospace',
                            zIndex: 1000000,
                            display: 'flex',
                            flexDirection: 'column',
                            overflow: 'hidden',
                            border: `1px solid ${darkBorder}`,
                            borderRadius: '8px',
                            margin: 0,
                            padding: 0,
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Header with Close Button (left) and Action Dropdown (right) */}
                        <div style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            padding: '16px 20px',
                            borderBottom: `1px solid ${darkBorder}`,
                            flexShrink: 0,
                        }}>
                            {/* Close Button - Left */}
                            <button
                                onClick={onClose}
                                style={{
                                    background: 'none',
                                    border: 'none',
                                    color: darkText,
                                    fontSize: '24px',
                                    cursor: 'pointer',
                                    padding: '0',
                                    width: '32px',
                                    height: '32px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontFamily: 'monospace',
                                }}
                            >
                                ×
                            </button>
                            
                            {/* Action Dropdown - Right */}
                            <div style={{ position: 'relative' }}>
                                <button
                                    style={{
                                        background: '#111111',
                                        border: `1px solid ${darkBorder}`,
                                        color: darkText,
                                        fontSize: '12px',
                                        padding: '8px 16px',
                                        cursor: 'pointer',
                                        fontFamily: 'monospace',
                                        borderRadius: '4px',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: '8px',
                                    }}
                                    disabled
                                >
                                    Add to Project
                                    <span style={{ fontSize: '10px' }}>▼</span>
                                </button>
                            </div>
                        </div>
                        
                        {/* Main Content - Two Columns */}
                        <div style={{
                            display: 'flex',
                            flex: 1,
                            overflow: 'hidden',
                        }}>
                            {/* Left Column - Images */}
                            <div style={{
                                width: '50%',
                                borderRight: `1px solid ${darkBorder}`,
                                padding: '20px',
                                overflowY: 'auto',
                                scrollbarWidth: 'none',
                                msOverflowStyle: 'none',
                            }}
                            className="n88-modal-scroll-content"
                            >
                        
                                {/* Main Image */}
                                {(item.imageUrl || item.image_url || item.primary_image_url) && (
                                    <div style={{ marginBottom: '16px' }}>
                                        <div style={{
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px', 
                                            padding: '12px',
                                            backgroundColor: '#111111',
                                            minHeight: '200px',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                        }}
                                        onClick={() => {
                                            setLightboxImage(item.imageUrl || item.image_url || item.primary_image_url);
                                        }}
                                        >
                                            <img 
                                                src={item.imageUrl || item.image_url || item.primary_image_url} 
                                                alt="Primary" 
                                                style={{ 
                                                    maxWidth: '100%',
                                                    maxHeight: '300px',
                                                    objectFit: 'contain',
                                                    borderRadius: '4px',
                                                }} 
                                            />
                                        </div>
                                    </div>
                                )}
                                    
                                    {/* Inspiration / References / Sketch Drawings */}
                                    {isEditable && currentState === 'A' && (
                                        <div>
                                            <div style={{ marginBottom: '4px', fontSize: '12px', fontWeight: '600' }}>
                                                Inspiration / References / Sketch Drawings
                                            </div>
                                            <div style={{ marginBottom: '8px', fontSize: '11px', color: '#999' }}>
                                                These images are helpful when you're ready to request a quote. They will be used as reference materials by suppliers to price accurately.
                                            </div>
                                            <div style={{ 
                                                display: 'flex',
                                                gap: '8px',
                                                flexWrap: 'wrap',
                                                marginBottom: '8px',
                                            }}>
                                                {inspiration.map((insp, idx) => (
                                                    <div
                                                        key={idx}
                                                        style={{
                                                            width: '80px',
                                                            height: '80px',
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            backgroundColor: '#111111',
                                                            position: 'relative',
                                                            overflow: 'hidden',
                                                            cursor: 'pointer',
                                                        }}
                                                        onClick={() => {
                                                            if (insp.url) {
                                                                // Commit 2.3.5.4: PDFs open in new tab, images use lightbox
                                                                if (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) {
                                                                    window.open(insp.url, '_blank');
                                                                } else {
                                                                    setLightboxImage(insp.url);
                                                                }
                                                            }
                                                        }}
                                                    >
                                                        {insp.url ? (
                                                            (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) ? (
                                                                <div style={{
                                                                    width: '100%',
                                                                    height: '100%',
                                                                    display: 'flex',
                                                                    alignItems: 'center',
                                                                    justifyContent: 'center',
                                                                    backgroundColor: '#222',
                                                                    borderRadius: '4px',
                                                                    flexDirection: 'column',
                                                                    gap: '4px',
                                                                }}>
                                                                    <div style={{ fontSize: '24px' }}>📄</div>
                                                                    <div style={{ fontSize: '8px', color: '#999' }}>PDF</div>
                                                                </div>
                                                            ) : (
                                                                <img 
                                                                    src={insp.url} 
                                                                    alt={insp.title || 'Reference'} 
                                                                    style={{
                                                                        width: '100%',
                                                                        height: '100%',
                                                                        objectFit: 'cover',
                                                                        borderRadius: '4px',
                                                                    }} 
                                                                />
                                                            )
                                                        ) : (
                                                            <div style={{ fontSize: '10px', color: '#666' }}>[ img ]</div>
                                                        )}
                                                        <button
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                setInspiration(inspiration.filter((_, i) => i !== idx));
                                                            }}
                                                            style={{
                                                                position: 'absolute',
                                                                top: '4px',
                                                                right: '4px',
                                                                background: '#ff0000',
                                                                color: '#fff',
                                                                border: 'none',
                                                                borderRadius: '50%',
                                                                width: '20px',
                                                                height: '20px',
                                                                cursor: 'pointer',
                                                                fontSize: '12px',
                                                                display: 'flex',
                                                                alignItems: 'center',
                                                                justifyContent: 'center',
                                                                padding: 0,
                                                            }}
                                                        >
                                                            ×
                                                        </button>
                                                    </div>
                                                ))}
                                                <button
                                                    onClick={() => {
                                                        const input = document.getElementById('inspiration-file-input-main');
                                                        if (input) input.click();
                                                    }}
                                                    disabled={isUploadingInspiration}
                                                    style={{
                                                        width: '80px',
                                                        height: '80px',
                                                        border: `1px solid ${darkBorder}`,
                                                        borderRadius: '4px',
                                                        backgroundColor: '#111111',
                                                        color: darkText,
                                                        cursor: isUploadingInspiration ? 'not-allowed' : 'pointer',
                                                        fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                    }}
                                                >
                                                    {isUploadingInspiration ? '...' : '[+ Add]'}
                                                </button>
                                            </div>
                                            <input
                                                type="file"
                                                id="inspiration-file-input-main"
                                                accept="image/*,.pdf,application/pdf,.heic,.heif"
                                                multiple
                                                onChange={handleInspirationFileChange}
                                                style={{ display: 'none' }}
                                                disabled={isUploadingInspiration}
                                            />
                                        </div>
                                    )}
                                    
                                    {/* Reference Images (State B only - read-only) */}
                                    {currentState === 'B' && inspiration && inspiration.length > 0 && (
                                        <div>
                                            <div style={{ marginBottom: '12px', fontSize: '12px', color: darkText, opacity: 0.7 }}>
                                                Reference Images
                                            </div>
                                            <div style={{
                                                display: 'flex',
                                                gap: '8px',
                                                flexWrap: 'wrap',
                                            }}>
                                                {inspiration.map((insp, idx) => (
                                                    <div
                                                        key={idx}
                                                        style={{
                                                            width: '80px',
                                                            height: '80px',
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            backgroundColor: '#111111',
                                                            position: 'relative',
                                                            overflow: 'hidden',
                                                            cursor: 'pointer',
                                                        }}
                                                        onClick={() => {
                                                            if (insp.url) {
                                                                // Commit 2.3.5.4: PDFs open in new tab, images use lightbox
                                                                if (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) {
                                                                    window.open(insp.url, '_blank');
                                                                } else {
                                                                    setLightboxImage(insp.url);
                                                                }
                                                            }
                                                        }}
                                                    >
                                                        {insp.url ? (
                                                            (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) ? (
                                                                <div style={{
                                                                    width: '100%',
                                                                    height: '100%',
                                                                    display: 'flex',
                                                                    alignItems: 'center',
                                                                    justifyContent: 'center',
                                                                    backgroundColor: '#222',
                                                                    borderRadius: '4px',
                                                                    flexDirection: 'column',
                                                                    gap: '4px',
                                                                }}>
                                                                    <div style={{ fontSize: '24px' }}>📄</div>
                                                                    <div style={{ fontSize: '8px', color: '#999' }}>PDF</div>
                                                                </div>
                                                            ) : (
                                                                <img 
                                                                    src={insp.url} 
                                                                    alt={insp.title || 'Reference'} 
                                                                    style={{
                                                                        width: '100%',
                                                                        height: '100%',
                                                                        objectFit: 'cover',
                                                                        borderRadius: '4px',
                                                                    }} 
                                                                />
                                                            )
                                                        ) : (
                                                            <div style={{ fontSize: '10px', color: '#666' }}>[ img ]</div>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            
                            {/* Right Column - Tabs */}
                            <div style={{
                                width: '50%',
                                display: 'flex',
                                flexDirection: 'column',
                                overflow: 'hidden',
                            }}>
                                {/* Tabs Header */}
                                <div style={{
                                    display: 'flex',
                                    borderBottom: `1px solid ${darkBorder}`,
                                    flexShrink: 0,
                                }}>
                                    <button
                                        onClick={() => setActiveTab('details')}
                                        style={{
                                            flex: 1,
                                            padding: '12px 16px',
                                            background: activeTab === 'details' ? '#111111' : 'transparent',
                                            border: 'none',
                                            borderBottom: activeTab === 'details' ? `2px solid ${greenAccent}` : 'none',
                                            color: activeTab === 'details' ? greenAccent : darkText,
                                            fontSize: '12px',
                                            fontWeight: activeTab === 'details' ? '600' : '400',
                                            cursor: 'pointer',
                                            fontFamily: 'monospace',
                                        }}
                                    >
                                        The Mission Spec
                                    </button>
                                    <button
                                        onClick={() => setActiveTab('rfq')}
                                        style={{
                                            flex: 1,
                                            padding: '12px 16px',
                                            background: activeTab === 'rfq' ? '#111111' : 'transparent',
                                            border: 'none',
                                            borderBottom: activeTab === 'rfq' ? `2px solid ${greenAccent}` : 'none',
                                            color: activeTab === 'rfq' ? greenAccent : darkText,
                                            fontSize: '12px',
                                            fontWeight: activeTab === 'rfq' ? '600' : '400',
                                            cursor: 'pointer',
                                            fontFamily: 'monospace',
                                        }}
                                    >
                                        Launch Brief
                                    </button>
                                    {itemState.has_bids && itemState.bids && itemState.bids.length > 0 && (
                                        <button
                                            onClick={() => setActiveTab('bids')}
                                            style={{
                                                flex: 1,
                                                padding: '12px 16px',
                                                background: activeTab === 'bids' ? '#111111' : 'transparent',
                                                border: 'none',
                                                borderBottom: activeTab === 'bids' ? `2px solid ${greenAccent}` : 'none',
                                                color: activeTab === 'bids' ? greenAccent : darkText,
                                                fontSize: '12px',
                                                fontWeight: activeTab === 'bids' ? '600' : '400',
                                                cursor: 'pointer',
                                                fontFamily: 'monospace',
                                            }}
                                        >
                                            Proposals Received ({itemState.bids.length})
                                        </button>
                                    )}
                                </div>
                                
                                {/* Tab Content */}
                                <div style={{
                                    flex: 1,
                                    overflowY: 'auto',
                                    padding: '20px',
                                    scrollbarWidth: 'none',
                                    msOverflowStyle: 'none',
                                }}
                                className="n88-modal-scroll-content"
                                >
                                    {/* Tab 1: The Mission Spec */}
                                    {activeTab === 'details' && (
                                        <div>
                                            {/* Action Required Banner - Show when operator has sent messages */}
                                            {itemState.has_unread_operator_messages && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    padding: '16px',
                                                    backgroundColor: '#330000',
                                                    border: '2px solid #ff0000',
                                                    borderRadius: '4px',
                                                }}>
                                                    <div style={{
                                                        fontSize: '14px',
                                                        fontWeight: '600',
                                                        color: '#ff0000',
                                                        marginBottom: '8px',
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        gap: '8px',
                                                    }}>
                                                        <span>⚠️</span>
                                                        <span>Action Required</span>
                                            </div>
                                                    <div style={{
                                                        fontSize: '12px',
                                                        color: '#ff6666',
                                                        lineHeight: '1.5',
                                                    }}>
                                                        You have {itemState.unread_operator_messages || 0} unread message{itemState.unread_operator_messages !== 1 ? 's' : ''} from the operator. Please review and respond.
                                                    </div>
                                                </div>
                                            )}

                                            {/* Commit 2.3.9.2A: CAD Review Actions (Designer) - always show when CAD exists */}
                                            {itemState.has_prototype_payment &&
                                                itemState.prototype_payment_status === 'marked_received' &&
                                                itemState.cad_current_version &&
                                                Number(itemState.cad_current_version) > 0 &&
                                                (itemState.cad_status === 'uploaded' || itemState.cad_status === 'revision_requested' || itemState.cad_status === 'approved') && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    padding: '16px',
                                                    backgroundColor: '#0a0a14',
                                                    border: '1px solid #333',
                                                    borderRadius: '4px',
                                                }}>
                                                    <div style={{ fontSize: '14px', fontWeight: '700', color: '#66aaff', marginBottom: '10px' }}>
                                                        CAD Review
                                                    </div>
                                                    <div style={{ fontSize: '12px', color: '#ccc', marginBottom: '10px', lineHeight: '1.5' }}>
                                                        Current CAD: <span style={{ color: '#fff', fontWeight: 700 }}>v{itemState.cad_current_version}</span>
                                                        {itemState.cad_status === 'approved' && itemState.cad_approved_version ? (
                                                            <span style={{ marginLeft: '10px', color: '#00ff00' }}>
                                                                ✓ Approved (v{itemState.cad_approved_version})
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <div style={{ fontSize: '12px', color: '#ccc', marginBottom: '12px' }}>
                                                        Rounds Used: <span style={{ color: '#fff' }}>{itemState.cad_revision_rounds_used ?? 0}</span>
                                                        {' '}of{' '}
                                                        <span style={{ color: '#fff' }}>{itemState.cad_revision_rounds_included ?? 0}</span>
                                                        {((itemState.cad_revision_rounds_included ?? 0) > 0 && (itemState.cad_revision_rounds_used ?? 0) >= (itemState.cad_revision_rounds_included ?? 0)) ? (
                                                            <span style={{ marginLeft: '10px', color: '#ffaa00' }}>
                                                                Additional fee required (future commit)
                                                            </span>
                                                        ) : null}
                                                    </div>

                                                    {itemState.cad_status !== 'approved' && (
                                                        <div>
                                                            {!showRevisionUpload ? (
                                                                <div style={{ display: 'flex', gap: '10px' }}>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => setShowRevisionUpload(true)}
                                                                        disabled={isCadActionBusy}
                                                                        style={{
                                                                            flex: 1,
                                                                            padding: '10px 12px',
                                                                            backgroundColor: '#111111',
                                                                            border: '1px solid #666',
                                                                            borderRadius: '4px',
                                                                            color: '#fff',
                                                                            fontFamily: 'monospace',
                                                                            fontSize: '12px',
                                                                            cursor: isCadActionBusy ? 'not-allowed' : 'pointer',
                                                                            opacity: isCadActionBusy ? 0.6 : 1,
                                                                        }}
                                                                    >
                                                                        Request Revision
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={approveCad}
                                                                        disabled={isCadActionBusy}
                                                                        style={{
                                                                            flex: 1,
                                                                            padding: '10px 12px',
                                                                            backgroundColor: '#003300',
                                                                            border: '1px solid #00ff00',
                                                                            borderRadius: '4px',
                                                                            color: '#00ff00',
                                                                            fontFamily: 'monospace',
                                                                            fontSize: '12px',
                                                                            fontWeight: 700,
                                                                            cursor: isCadActionBusy ? 'not-allowed' : 'pointer',
                                                                            opacity: isCadActionBusy ? 0.6 : 1,
                                                                        }}
                                                                    >
                                                                        Approve CAD
                                                                    </button>
                                                                </div>
                                                            ) : (
                                                                <div style={{
                                                                    padding: '12px',
                                                                    backgroundColor: '#0a0a0a',
                                                                    border: '1px solid #333',
                                                                    borderRadius: '4px',
                                                                }}>
                                                                    <div style={{ fontSize: '12px', fontWeight: '600', color: '#fff', marginBottom: '8px' }}>
                                                                        Upload Files for Revision Request
                                                                    </div>
                                                                    <div
                                                                        onDrop={(e) => {
                                                                            e.preventDefault();
                                                                            const files = Array.from(e.dataTransfer.files).filter(f => 
                                                                                f.type === 'application/pdf' || 
                                                                                f.type.startsWith('image/')
                                                                            );
                                                                            setRevisionFiles(prev => [...prev, ...files]);
                                                                        }}
                                                                        onDragOver={(e) => e.preventDefault()}
                                                                        style={{
                                                                            border: '2px dashed #666',
                                                                            padding: '20px',
                                                                            textAlign: 'center',
                                                                            marginBottom: '12px',
                                                                            cursor: 'pointer',
                                                                            color: '#999',
                                                                            fontSize: '11px',
                                                                        }}
                                                                        onClick={() => {
                                                                            const input = document.createElement('input');
                                                                            input.type = 'file';
                                                                            input.multiple = true;
                                                                            input.accept = '.pdf,.jpg,.jpeg,.png';
                                                                            input.onchange = (e) => {
                                                                                const files = Array.from(e.target.files || []).filter(f => 
                                                                                    f.type === 'application/pdf' || 
                                                                                    f.type.startsWith('image/')
                                                                                );
                                                                                setRevisionFiles(prev => [...prev, ...files]);
                                                                            };
                                                                            input.click();
                                                                        }}
                                                                    >
                                                                        Drag & Drop Files (PDF, JPG, PNG) or Click to Upload
                                                                    </div>
                                                                    {revisionFiles.length > 0 && (
                                                                        <div style={{ marginBottom: '12px' }}>
                                                                            {revisionFiles.map((file, idx) => (
                                                                                <div key={idx} style={{
                                                                                    display: 'flex',
                                                                                    alignItems: 'center',
                                                                                    justifyContent: 'space-between',
                                                                                    padding: '6px 10px',
                                                                                    backgroundColor: '#1a1a1a',
                                                                                    border: '1px solid #333',
                                                                                    borderRadius: '4px',
                                                                                    marginBottom: '6px',
                                                                                    fontSize: '11px',
                                                                                    color: '#fff',
                                                                                }}>
                                                                                    <span>{file.name}</span>
                                                                                    <button
                                                                                        type="button"
                                                                                        onClick={() => setRevisionFiles(prev => prev.filter((_, i) => i !== idx))}
                                                                                        style={{
                                                                                            background: 'none',
                                                                                            border: 'none',
                                                                                            color: '#ff6666',
                                                                                            cursor: 'pointer',
                                                                                            fontSize: '14px',
                                                                                            padding: '0 4px',
                                                                                        }}
                                                                                    >
                                                                                        ×
                                                                                    </button>
                                                                                </div>
                                                                            ))}
                                                                        </div>
                                                                    )}
                                                                    <div style={{ display: 'flex', gap: '8px' }}>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => {
                                                                                setShowRevisionUpload(false);
                                                                                setRevisionFiles([]);
                                                                            }}
                                                                            style={{
                                                                                flex: 1,
                                                                                padding: '8px 12px',
                                                                                backgroundColor: '#333',
                                                                                border: '1px solid #666',
                                                                                borderRadius: '4px',
                                                                                color: '#fff',
                                                                                fontFamily: 'monospace',
                                                                                fontSize: '11px',
                                                                                cursor: 'pointer',
                                                                            }}
                                                                        >
                                                                            Cancel
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => requestCadRevision(revisionFiles)}
                                                                            disabled={isCadActionBusy || revisionFiles.length === 0}
                                                                            style={{
                                                                                flex: 1,
                                                                                padding: '8px 12px',
                                                                                backgroundColor: revisionFiles.length === 0 ? '#333' : '#111111',
                                                                                border: '1px solid #666',
                                                                                borderRadius: '4px',
                                                                                color: '#fff',
                                                                                fontFamily: 'monospace',
                                                                                fontSize: '11px',
                                                                                cursor: revisionFiles.length === 0 ? 'not-allowed' : 'pointer',
                                                                                opacity: revisionFiles.length === 0 ? 0.5 : 1,
                                                                            }}
                                                                        >
                                                                            {isCadActionBusy ? 'Requesting...' : 'Request Revision'}
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                            
                                            {/* Commit 2.3.9.1C-a: Message Operator Section - allow CAD flow too */}
                                            {(itemState.has_rfq || itemState.has_prototype_payment) && (
                                                    <div style={{
                                                    marginBottom: '24px',
                                                }}>
                                                    {!showDesignerMessageForm ? (
                                                        <button
                                                            onClick={() => {
                                                                setShowDesignerMessageForm(true);
                                                                loadDesignerMessages();
                                                            }}
                                                            style={{
                                                                width: '100%',
                                                                padding: '12px',
                                                                backgroundColor: '#111111',
                                                                border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                                color: darkText,
                                                        fontSize: '14px',
                                                        fontFamily: 'monospace',
                                                                cursor: 'pointer',
                                                                fontWeight: '600',
                                                            }}
                                                            onMouseOver={(e) => e.target.style.backgroundColor = '#1a1a1a'}
                                                            onMouseOut={(e) => e.target.style.backgroundColor = '#111111'}
                                                        >
                                                            Message Operator
                                                        </button>
                                                    ) : (
                                                        <div style={{
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            padding: '16px',
                                                            backgroundColor: '#111111',
                                                        }}>
                                                            <div style={{
                                                                display: 'flex',
                                                                justifyContent: 'space-between',
                                                                alignItems: 'center',
                                                                marginBottom: '16px',
                                                }}>
                                                    <div style={{
                                                        fontSize: '14px',
                                                                    fontWeight: '600',
                                                                    color: darkText,
                                                    }}>
                                                                    Message Operator
                                                    </div>
                                                                <button
                                                                    onClick={() => setShowDesignerMessageForm(false)}
                                                                    style={{
                                                                        background: 'none',
                                                                        border: 'none',
                                                                        color: darkText,
                                                                        fontSize: '20px',
                                                                        cursor: 'pointer',
                                                                        padding: '0',
                                                                        width: '24px',
                                                                        height: '24px',
                                                                        display: 'flex',
                                                                        alignItems: 'center',
                                                                        justifyContent: 'center',
                                                                    }}
                                                                >
                                                                    ×
                                                                </button>
                                                </div>

                                                            {/* WhatsApp-Style Chat Messages */}
                                                <div 
                                                    id="n88-designer-messages-container"
                                                    style={{
                                                        height: '400px',
                                                        overflowY: 'auto',
                                                        padding: '16px',
                                                        backgroundColor: '#0a0a0a',
                                                        borderRadius: '4px',
                                                        marginBottom: '12px',
                                                        border: `1px solid ${darkBorder}`,
                                                        display: 'flex',
                                                        flexDirection: 'column',
                                                    }}
                                                >
                                                    {isLoadingDesignerMessages ? (
                                                        <div style={{
                                                            textAlign: 'center',
                                                            color: '#666',
                                                            fontSize: '12px',
                                                            padding: '20px',
                                                            margin: 'auto',
                                                        }}>
                                                            Loading conversation...
                                                    </div>
                                                    ) : designerMessages.length === 0 ? (
                                                        <div style={{
                                                            textAlign: 'center',
                                                            color: '#666',
                                                            fontSize: '12px',
                                                            padding: '20px',
                                                            margin: 'auto',
                                                        }}>
                                                            No messages yet. Start the conversation!
                                                </div>
                                                    ) : (
                                                        // Sort messages chronologically by created_at
                                                        [...designerMessages].sort((a, b) => {
                                                            return new Date(a.created_at) - new Date(b.created_at);
                                                        }).map((msg, idx) => {
                                                            const isDesigner = msg.sender_role === 'designer';
                                                            const senderName = isDesigner ? 'You' : 'Operator';
                                                            
                                                            const date = new Date(msg.created_at);
                                                            const dateStr = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                                                            
                                                            // Parse CAD upload messages and revision request messages to extract files
                                                            const isCadUploadMessage = !isDesigner && msg.message_text && msg.message_text.includes('CAD v') && msg.message_text.includes('uploaded');
                                                            const isRevisionRequestMessage = isDesigner && msg.message_text && msg.message_text.includes('Revision requested') && msg.message_text.includes('Files:');
                                                            let cadFiles = [];
                                                            let messageText = msg.message_text || '';
                                                            
                                                            if (isCadUploadMessage || isRevisionRequestMessage) {
                                                                // Parse format: "CAD v1 uploaded\nFiles:\n- filename.pdf: https://url.com/file.pdf"
                                                                const lines = messageText.split('\n');
                                                                const filesStartIndex = lines.findIndex(line => line.trim() === 'Files:');
                                                                if (filesStartIndex >= 0) {
                                                                    const headerText = lines.slice(0, filesStartIndex).join('\n');
                                                                    messageText = headerText;
                                                                    const fileLines = lines.slice(filesStartIndex + 1);
                                                                    fileLines.forEach(line => {
                                                                        const match = line.match(/^-\s*(.+?):\s*(https?:\/\/.+)$/);
                                                                        if (match) {
                                                                            const fileName = match[1].trim();
                                                                            const fileUrl = match[2].trim();
                                                                            const fileExt = fileName.split('.').pop()?.toLowerCase() || '';
                                                                            cadFiles.push({ name: fileName, url: fileUrl, ext: fileExt });
                                                                        }
                                                                    });
                                                                }
                                                            }
                                                            
                                                            return (
                                                                <div 
                                                                    key={idx} 
                                                                    style={{
                                                                        marginBottom: '12px',
                                                                        display: 'flex',
                                                                        justifyContent: isDesigner ? 'flex-end' : 'flex-start',
                                                                        width: '100%',
                                                                    }}
                                                                >
                                                                    <div style={{
                                                                        maxWidth: '75%',
                                                                        padding: '10px 14px',
                                                                        backgroundColor: isDesigner ? '#1a1a1a' : '#0a0a0a',
                                                                        border: `1px solid ${isDesigner ? greenAccent : '#333'}`,
                                                                        borderRadius: isDesigner ? '12px 12px 4px 12px' : '12px 12px 12px 4px',
                                                                        fontSize: '12px',
                                                                        color: '#fff',
                                                                        wordWrap: 'break-word',
                                                                        whiteSpace: 'pre-wrap',
                                                                    }}>
                                                                        <div style={{
                                                                            fontSize: '10px',
                                                                            fontWeight: '600',
                                                                            color: isDesigner ? greenAccent : '#00aa00',
                                                                            marginBottom: '4px',
                                                                        }}>
                                                                            {senderName}
                                                                        </div>
                                                                        <div style={{
                                                                            fontSize: '12px',
                                                                            lineHeight: '1.4',
                                                                            marginBottom: '4px',
                                                                        }}>
                                                                            {messageText}
                                                                        </div>
                                                                        {(isCadUploadMessage || isRevisionRequestMessage) && cadFiles.length > 0 && (
                                                                            <div style={{
                                                                                marginTop: '12px',
                                                                                paddingTop: '12px',
                                                                                borderTop: '1px solid #333',
                                                                                display: 'flex',
                                                                                flexDirection: 'column',
                                                                                gap: '8px',
                                                                            }}>
                                                                                {cadFiles.map((file, fileIdx) => {
                                                                                    const isPdf = file.ext === 'pdf';
                                                                                    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(file.ext);
                                                                                    return (
                                                                                        <a
                                                                                            key={fileIdx}
                                                                                            href={file.url}
                                                                                            target="_blank"
                                                                                            rel="noopener noreferrer"
                                                                                            style={{
                                                                                                display: 'flex',
                                                                                                alignItems: 'center',
                                                                                                gap: '10px',
                                                                                                padding: '8px 12px',
                                                                                                backgroundColor: '#1a1a1a',
                                                                                                border: '1px solid #333',
                                                                                                borderRadius: '4px',
                                                                                                textDecoration: 'none',
                                                                                                color: '#fff',
                                                                                                cursor: 'pointer',
                                                                                                transition: 'all 0.2s',
                                                                                            }}
                                                                                            onMouseOver={(e) => {
                                                                                                e.currentTarget.style.backgroundColor = '#222';
                                                                                                e.currentTarget.style.borderColor = greenAccent;
                                                                                            }}
                                                                                            onMouseOut={(e) => {
                                                                                                e.currentTarget.style.backgroundColor = '#1a1a1a';
                                                                                                e.currentTarget.style.borderColor = '#333';
                                                                                            }}
                                                                                        >
                                                                                            <div style={{
                                                                                                width: '32px',
                                                                                                height: '32px',
                                                                                                display: 'flex',
                                                                                                alignItems: 'center',
                                                                                                justifyContent: 'center',
                                                                                                backgroundColor: '#000',
                                                                                                borderRadius: '4px',
                                                                                                flexShrink: 0,
                                                                                            }}>
                                                                                                {isPdf ? (
                                                                                                    <span style={{ fontSize: '20px' }}>📄</span>
                                                                                                ) : isImage ? (
                                                                                                    <span style={{ fontSize: '20px' }}>🖼️</span>
                                                                                                ) : (
                                                                                                    <span style={{ fontSize: '20px' }}>📎</span>
                                                                                                )}
                                                                                            </div>
                                                                                            <div style={{
                                                                                                flex: 1,
                                                                                                minWidth: 0,
                                                                                                overflow: 'hidden',
                                                                                                textOverflow: 'ellipsis',
                                                                                                whiteSpace: 'nowrap',
                                                                                                fontSize: '11px',
                                                                                            }}>
                                                                                                {file.name}
                                                                                            </div>
                                                                                            <div style={{
                                                                                                fontSize: '10px',
                                                                                                color: greenAccent,
                                                                                                flexShrink: 0,
                                                                                            }}>
                                                                                                Open →
                                                                                            </div>
                                                                                        </a>
                                                                                    );
                                                                                })}
                                                                            </div>
                                                                        )}
                                                                        <div style={{
                                                                            fontSize: '9px',
                                                                            color: '#666',
                                                                            textAlign: 'right',
                                                                        }}>
                                                                            {dateStr}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })
                                                    )}
                                                </div>
                                                            
                                                            {/* Message Input at Bottom */}
                                                            <form onSubmit={sendDesignerMessage} style={{
                                                                display: 'flex',
                                                                gap: '8px',
                                                                alignItems: 'flex-end',
                                                            }}>
                                                                <textarea
                                                                    value={designerMessageText}
                                                                    onChange={(e) => setDesignerMessageText(e.target.value)}
                                                                    required
                                                                    rows={2}
                                                                    style={{
                                                                        flex: 1,
                                                                        padding: '10px 12px',
                                                                        backgroundColor: '#000',
                                                                        color: '#fff',
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '20px',
                                                                        fontFamily: 'monospace',
                                                                        fontSize: '12px',
                                                                        resize: 'none',
                                                                        minHeight: '40px',
                                                                        maxHeight: '100px',
                                                                    }}
                                                                    placeholder="Type your message here..."
                                                                    onKeyDown={(e) => {
                                                                        if (e.key === 'Enter' && !e.shiftKey) {
                                                                            e.preventDefault();
                                                                            if (designerMessageText.trim()) {
                                                                                sendDesignerMessage(e);
                                                                            }
                                                                        }
                                                                    }}
                                                                />
                                                                <button
                                                                    type="submit"
                                                                    disabled={isSendingDesignerMessage || !designerMessageText.trim()}
                                                                    style={{
                                                                        padding: '10px 20px',
                                                                        backgroundColor: isSendingDesignerMessage || !designerMessageText.trim() ? '#333' : greenAccent,
                                                                        color: isSendingDesignerMessage || !designerMessageText.trim() ? '#666' : '#000',
                                                                        border: 'none',
                                                                        borderRadius: '20px',
                                                                        fontFamily: 'monospace',
                                                                        fontSize: '12px',
                                                                        fontWeight: '600',
                                                                        cursor: isSendingDesignerMessage || !designerMessageText.trim() ? 'not-allowed' : 'pointer',
                                                                        whiteSpace: 'nowrap',
                                                                    }}
                                                                    onMouseOver={(e) => {
                                                                        if (!isSendingDesignerMessage && designerMessageText.trim()) {
                                                                            e.target.style.backgroundColor = '#00cc00';
                                                                        }
                                                                    }}
                                                                    onMouseOut={(e) => {
                                                                        if (!isSendingDesignerMessage && designerMessageText.trim()) {
                                                                            e.target.style.backgroundColor = greenAccent;
                                                                        }
                                                                    }}
                                                                >
                                                                    {isSendingDesignerMessage ? 'Sending...' : 'Send'}
                                                                </button>
                                                            </form>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                            
                                            {/* Item Title */}
                                            <div style={{ fontSize: '16px', fontWeight: '600', marginBottom: '20px' }}>
                                                {item.title || item.description || 'Untitled Item'}
                                            </div>
                                            
                                            {/* RFQ Sent Status Indicator moved to Launch Brief tab */}
                                            
                                            {/* Editable fields section - State A only */}
                            {currentState !== 'C' && isEditable && currentState === 'A' ? (
                                <>
                                    {/* 3. Description */}
                                    <div style={{ marginBottom: '24px' }}>
                                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                            Description (tell us what you're sourcing)
                                        </label>
                                        <textarea
                                            value={description}
                                            onChange={(e) => setDescription(e.target.value)}
                                            placeholder="Item description"
                                            rows={3}
                                            style={{
                                                width: '100%',
                                                padding: '8px',
                                                backgroundColor: darkBg,
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px',
                                                color: darkText,
                                                fontSize: '12px',
                                                fontFamily: 'monospace',
                                                resize: 'vertical',
                                            }}
                                        />
                                    </div>
                                    
                                    {/* 4. Category */}
                                    <div style={{ marginBottom: '24px' }}>
                                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                            Category
                                        </label>
                                        <select
                                            value={category}
                                            onChange={(e) => setCategory(e.target.value)}
                                            style={{
                                                width: '100%',
                                                padding: '8px',
                                                backgroundColor: darkBg,
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px',
                                                color: darkText,
                                                fontSize: '12px',
                                                fontFamily: 'monospace',
                                            }}
                                        >
                                            <option value="">-- Select Category --</option>
                                                <option value="Indoor Furniture">Indoor Furniture</option>
                                                <option value="Sofas & Seating (Indoor)">Sofas & Seating (Indoor)</option>
                                                <option value="Chairs & Armchairs (Indoor)">Chairs & Armchairs (Indoor)</option>
                                                <option value="Dining Tables (Indoor)">Dining Tables (Indoor)</option>
                                                <option value="Cabinetry / Millwork (Custom)">Cabinetry / Millwork (Custom)</option>
                                                <option value="Casegoods (Beds, Nightstands, Desks, Consoles)">Casegoods (Beds, Nightstands, Desks, Consoles)</option>
                                                <option value="Outdoor Furniture">Outdoor Furniture</option>
                                                <option value="Outdoor Seating">Outdoor Seating</option>
                                                <option value="Outdoor Dining Sets">Outdoor Dining Sets</option>
                                                <option value="Outdoor Loungers & Daybeds">Outdoor Loungers & Daybeds</option>
                                                <option value="Pool Furniture">Pool Furniture</option>
                                                <option value="Lighting">Lighting</option>
                                            <option value="Decorative Lighting">Decorative Lighting</option>
                                            <option value="Architectural Lighting">Architectural Lighting</option>
                                            <option value="Electrical / LED Components">Electrical / LED Components</option>
                                            <option value="Bathroom Fixtures">Bathroom Fixtures</option>
                                            <option value="Kitchen Fixtures">Kitchen Fixtures</option>
                                            <option value="Faucets / Hardware (Plumbing)">Faucets / Hardware (Plumbing)</option>
                                            <option value="Sinks / Basins">Sinks / Basins</option>
                                            <option value="Shower Systems / Accessories">Shower Systems / Accessories</option>
                                            <option value="Marble / Stone">Marble / Stone</option>
                                            <option value="Granite">Granite</option>
                                            <option value="Quartz">Quartz</option>
                                            <option value="Porcelain / Ceramic Slabs">Porcelain / Ceramic Slabs</option>
                                            <option value="Tile (Wall / Floor)">Tile (Wall / Floor)</option>
                                            <option value="Terrazzo">Terrazzo</option>
                                            <option value="Rugs / Carpets">Rugs / Carpets</option>
                                            <option value="Drapery">Drapery</option>
                                            <option value="Window Treatments / Shades">Window Treatments / Shades</option>
                                            <option value="Wallcoverings">Wallcoverings</option>
                                            <option value="Acoustic Panels">Acoustic Panels</option>
                                            <option value="Mirrors">Mirrors</option>
                                            <option value="Artwork">Artwork</option>
                                            <option value="Decorative Accessories">Decorative Accessories</option>
                                            <option value="Planters">Planters</option>
                                            <option value="Sculptural Objects">Sculptural Objects</option>
                                            <option value="Railings">Railings</option>
                                            <option value="Screens / Louvers">Screens / Louvers</option>
                                            <option value="Pergola / Shade Components">Pergola / Shade Components</option>
                                            <option value="Facade Materials">Facade Materials</option>
                                                <option value="Material Sample Kit">Material Sample Kit</option>
                                                <option value="Fabric Sample">Fabric Sample</option>
                                            <option value="Custom Sourcing / Not Listed">Custom Sourcing / Not Listed</option>
                                        </select>
                                    </div>
                                    
                                    {/* 5. Keywords (NEW) */}
                                    <div style={{ marginBottom: '24px' }}>
                                        <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                            Keywords
                                        </label>
                                        <div style={{ display: 'flex', gap: '8px', marginBottom: '8px' }}>
                                            <input
                                                type="text"
                                                value={keywordInput}
                                                onChange={(e) => setKeywordInput(e.target.value)}
                                                onKeyPress={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        addKeywordChip();
                                                    }
                                                }}
                                                placeholder="Enter keywords (comma or Enter to add)"
                                                style={{
                                                    flex: 1,
                                                    padding: '8px',
                                                    backgroundColor: darkBg,
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    color: darkText,
                                                    fontSize: '12px',
                                                    fontFamily: 'monospace',
                                                }}
                                            />
                                            <button
                                                type="button"
                                                onClick={addKeywordChip}
                                                style={{
                                                    padding: '8px 16px',
                                                    backgroundColor: '#111111',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    color: darkText,
                                                    fontSize: '12px',
                                                    fontFamily: 'monospace',
                                                    cursor: 'pointer',
                                                    whiteSpace: 'nowrap',
                                                }}
                                            >
                                                Add
                                            </button>
                                        </div>
                                        <div style={{
                                            display: 'flex',
                                            flexWrap: 'wrap',
                                            gap: '8px',
                                            minHeight: '32px',
                                        }}>
                                            {keywords.map((keyword, idx) => (
                                                <div
                                                    key={idx}
                                                    style={{
                                                        display: 'inline-flex',
                                                        alignItems: 'center',
                                                        gap: '6px',
                                                        padding: '6px 12px',
                                                        backgroundColor: '#111111',
                                                        border: `1px solid ${darkBorder}`,
                                                        borderRadius: '16px',
                                                        fontSize: '12px',
                                                        color: greenAccent,
                                                        fontFamily: 'monospace',
                                                    }}
                                                >
                                                    <span>{keyword}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => removeKeywordChip(keyword)}
                                                        style={{
                                                            background: 'none',
                                                            border: 'none',
                                                            color: darkText,
                                                            cursor: 'pointer',
                                                            fontSize: '16px',
                                                            lineHeight: 1,
                                                            padding: 0,
                                                            marginLeft: '4px',
                                                            fontWeight: 'bold',
                                                        }}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                    
                                    {/* 6. Smart Alternatives */}
                                    <div style={{ marginBottom: '24px' }}>
                                        <div style={{ marginBottom: '12px' }}>
                                            <label style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '12px',
                                                cursor: 'pointer',
                                            }}>
                                                <input
                                                    type="checkbox"
                                                    checked={smartAlternativesEnabled}
                                                    onChange={(e) => setSmartAlternativesEnabled(e.target.checked)}
                                                    style={{
                                                        width: '18px',
                                                        height: '18px',
                                                        cursor: 'pointer',
                                                    }}
                                                />
                                                <span style={{ fontSize: '12px', fontWeight: '600' }}>Smart Alternatives</span>
                                            </label>
                                        </div>
                                        <div style={{ 
                                            marginTop: '8px',
                                            marginLeft: '30px',
                                            fontSize: '11px',
                                            color: '#999',
                                        }}>
                                            <span>Suppliers may suggest equivalent materials or construction methods. No contact info is shared.</span>
                                        </div>
                                    </div>
                                </>
                            ) : currentState === 'C' ? (
                                // State C: Hide all content - only bid tab will be shown
                                null
                            ) : (
                                // State B: Show description above category
                                <>
                                    {description && (
                                        <div style={{ marginBottom: '24px' }}>
                                            <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                Description
                                            </div>
                                            <div style={{ fontSize: '12px', color: darkText, lineHeight: '1.6' }}>
                                                {description}
                                            </div>
                                        </div>
                                    )}
                                    {category && (
                                        <div style={{ marginBottom: '24px' }}>
                                            <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                Category
                                            </div>
                                            <div style={{ fontSize: '12px', color: darkText }}>
                                                {category}
                                            </div>
                                        </div>
                                    )}
                                    
                                    {/* Commit 2.3.9.1C-B: Action Required Banner */}
                                    {showClarificationBanner && (
                                        <div style={{
                                            marginTop: '24px',
                                            marginBottom: '24px',
                                            padding: '16px',
                                            backgroundColor: '#331100',
                                            border: '2px solid #ff9800',
                                            borderRadius: '4px',
                                        }}>
                                            <div style={{
                                                fontSize: '14px',
                                                fontWeight: '600',
                                                color: '#ff9800',
                                                marginBottom: '8px',
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: '8px',
                                            }}>
                                                <span>⚠️</span>
                                                <span>Action Required: Clarification Needed</span>
                                            </div>
                                            <div style={{
                                                fontSize: '12px',
                                                color: '#fff',
                                                marginBottom: '12px',
                                                lineHeight: '1.5',
                                            }}>
                                                Operator has questions from a supplier before work can proceed.
                                            </div>
                                            <button
                                                onClick={() => {
                                                    setShowClarificationBanner(false);
                                                    // Scroll to operator inbox section or open it
                                                }}
                                                style={{
                                                    padding: '8px 16px',
                                                    backgroundColor: '#ff9800',
                                                    color: '#000',
                                                    border: 'none',
                                                    borderRadius: '4px',
                                                    fontSize: '12px',
                                                    fontFamily: 'monospace',
                                                    fontWeight: '600',
                                                    cursor: 'pointer',
                                                }}
                                            >
                                                Review & Respond
                                            </button>
                                        </div>
                                    )}
                                    
                                </>
                            )}
                                        </div>
                                    )}
                                    
                                    {/* Tab 2: Launch Brief */}
                                    {activeTab === 'rfq' && (
                                        <div>
                                            {/* RFQ Sent Status Indicator (State B Only) */}
                                            {currentState === 'B' && (
                                                <div style={{
                                                    marginBottom: '20px',
                                                    padding: '12px 16px',
                                                    backgroundColor: 'rgba(0, 128, 0, 0.08)',
                                                    border: '1px solid rgba(0, 128, 0, 0.2)',
                                                    borderRadius: '4px',
                                                    textAlign: 'center',
                                                    opacity: 0.7,
                                                }}>
                                                    <div style={{
                                                        fontSize: '14px',
                                                        fontWeight: '500',
                                                        color: 'rgba(0, 180, 0, 0.6)',
                                                        fontFamily: 'monospace',
                                                    }}>
                                                        RFQ request sent — waiting to hear back
                                                    </div>
                                                </div>
                                            )}
                                            
                                            {/* State B: Editable Dimensions and Quantity */}
                                            {(currentState === 'B' || currentState === 'C') && (
                                                <>
                                                    <div style={{
                                                        border: `1px solid ${darkBorder}`,
                                                        borderRadius: '4px',
                                                        padding: '16px',
                                                        backgroundColor: '#111111',
                                                        marginBottom: '24px',
                                                    }}>
                                                        {/* Dimensions */}
                                                        <div style={{ marginBottom: '12px' }}>
                                                            <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                                Dimensions (provide ideal dimensions when applicable)
                                                            </label>
                                                            <div style={{ display: 'grid', gridTemplateColumns: '80px 80px 80px auto', gap: '8px' }}>
                                                                <input
                                                                    type="number"
                                                                    value={width}
                                                                    onChange={(e) => setWidth(e.target.value)}
                                                                    placeholder="W"
                                                                    step="0.01"
                                                                    style={{
                                                                        padding: '8px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontSize: '12px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                />
                                                                <input
                                                                    type="number"
                                                                    value={depth}
                                                                    onChange={(e) => setDepth(e.target.value)}
                                                                    placeholder="D"
                                                                    step="0.01"
                                                                    style={{
                                                                        padding: '8px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontSize: '12px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                />
                                                                <input
                                                                    type="number"
                                                                    value={height}
                                                                    onChange={(e) => setHeight(e.target.value)}
                                                                    placeholder="H"
                                                                    step="0.01"
                                                                    style={{
                                                                        padding: '8px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontSize: '12px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                />
                                                                <select
                                                                    value={unit}
                                                                    onChange={(e) => setUnit(e.target.value)}
                                                                    style={{
                                                                        padding: '8px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontSize: '12px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                >
                                                                    <option value="in">in</option>
                                                                    <option value="cm">cm</option>
                                                                    <option value="mm">mm</option>
                                                                    <option value="m">m</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        
                                                        {/* Quantity */}
                                                        <div style={{ marginBottom: '12px' }}>
                                                            <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                                Quantity
                                                            </label>
                                                            <input
                                                                type="number"
                                                                value={quantity}
                                                                onChange={(e) => setQuantity(e.target.value)}
                                                                min="1"
                                                                style={{
                                                                    width: '100%',
                                                                    padding: '8px',
                                                                    backgroundColor: darkBg,
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    color: darkText,
                                                                    fontSize: '12px',
                                                                    fontFamily: 'monospace',
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                    
                                                    {/* Instructional microcopy - Outside the box */}
                                                    <div style={{
                                                        marginTop: '12px',
                                                        fontSize: '11px',
                                                        color: '#999',
                                                        textAlign: 'center',
                                                        fontFamily: 'monospace',
                                                    }}>
                                                        Edit RFQ details and click Update.
                                                    </div>
                                                </>
                                            )}
                                            
                                            {/* Request Quote Button / RFQ Form / Specs Updated Panel */}
                            {currentState === 'A' && (
                                <div id="request-quote-section" style={{ marginBottom: '24px' }}>
                                    {/* D5: Specs Updated Panel - Show when revision_changed=true and has_rfq=true */}
                                    {(itemState.revision_changed === true || item.revision_changed === true) && itemState.has_rfq === true ? (
                                        <div style={{
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                            padding: '16px',
                                            backgroundColor: '#111111',
                                        }}>
                                            {/* Title */}
                                            <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '12px', color: darkText }}>
                                                Specs Updated
                                            </div>
                                            
                                            {/* Message */}
                                            <div style={{ fontSize: '12px', color: darkText, marginBottom: '16px', lineHeight: '1.5' }}>
                                                Suppliers have been notified to update proposals to match the new specs.
                                            </div>
                                            
                                            {/* Current Dimensions and Quantity (read-only) */}
                                            <div style={{ marginBottom: '16px' }}>
                                                <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                    Current Specifications
                                                </div>
                                                
                                                {/* Dimensions */}
                                                <div style={{ marginBottom: '8px' }}>
                                                    <div style={{ fontSize: '11px', color: '#999', marginBottom: '4px' }}>
                                                        Dimensions
                                                    </div>
                                                    <div style={{ fontSize: '12px', color: darkText, fontFamily: 'monospace' }}>
                                                        {width && depth && height ? `${width} × ${depth} × ${height} ${unit}` : 'Not specified'}
                                                    </div>
                                                </div>
                                                
                                                {/* Quantity */}
                                                <div style={{ marginBottom: '8px' }}>
                                                    <div style={{ fontSize: '11px', color: '#999', marginBottom: '4px' }}>
                                                        Quantity
                                                    </div>
                                                    <div style={{ fontSize: '12px', color: darkText, fontFamily: 'monospace' }}>
                                                        {quantity || 'Not specified'}
                                                    </div>
                                                </div>
                                                
                                                {/* Revision Label */}
                                                {(itemState.rfq_revision_current || item.rfq_revision_current || item.meta?.rfq_revision_current) && (
                                                    <div style={{ marginTop: '8px', fontSize: '11px', color: '#999' }}>
                                                        Revision {itemState.rfq_revision_current || item.rfq_revision_current || item.meta?.rfq_revision_current}
                                                    </div>
                                                )}
                                            </div>
                                            
                                            {/* Bids Area */}
                                            <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: `1px solid ${darkBorder}` }}>
                                                {/* Filter bids by revision */}
                                                {(() => {
                                                    const currentRevision = itemState.rfq_revision_current || item.rfq_revision_current || item.meta?.rfq_revision_current || null;
                                                    const currentBids = itemState.bids.filter(bid => {
                                                        if (bid.status !== 'submitted') return false;
                                                        if (currentRevision !== null) {
                                                            const bidRevision = bid.rfq_revision_at_submit || bid.meta?.rfq_revision_at_submit;
                                                            return bidRevision === currentRevision;
                                                        }
                                                        return true; // If no revision tracking, show all submitted bids
                                                    });
                                                    const outdatedBids = itemState.bids.filter(bid => {
                                                        if (bid.status !== 'submitted') return false;
                                                        if (currentRevision !== null) {
                                                            const bidRevision = bid.rfq_revision_at_submit || bid.meta?.rfq_revision_at_submit;
                                                            return bidRevision !== currentRevision && bidRevision !== null;
                                                        }
                                                        return false;
                                                    });
                                                    
                                                    return (
                                                        <>
                                                            {/* Current Revision Bids */}
                                                            {currentBids.length > 0 ? (
                                                                <div style={{ marginBottom: '12px' }}>
                                                                    <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                                        Current Proposals (Revision {currentRevision || 'N/A'})
                                                                    </div>
                                                                    <BidComparisonMatrix 
                                                                        bids={currentBids}
                                                                        darkBorder={darkBorder}
                                                                        greenAccent={greenAccent}
                                                                        darkText={darkText}
                                                                        darkBg={darkBg}
                                                                        onImageClick={setLightboxImage}
                                                                        smartAlternativesEnabled={smartAlternativesEnabled}
                                                                    />
                                                                </div>
                                                            ) : (
                                                                <div style={{ 
                                                                    padding: '12px',
                                                                    backgroundColor: '#1a1a1a',
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    fontSize: '12px',
                                                                    color: darkText,
                                                                    textAlign: 'center',
                                                                }}>
                                                                    Waiting for updated proposals (Revision {currentRevision || 'N/A'})
                                                                </div>
                                                            )}
                                                            
                                                            {/* Outdated Proposals */}
                                                            {outdatedBids.length > 0 && (
                                                                <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: `1px solid ${darkBorder}` }}>
                                                                    <div style={{ 
                                                                        marginBottom: '12px',
                                                                        padding: '10px',
                                                                        backgroundColor: '#331100',
                                                                        border: '1px solid #ff8800',
                                                                        borderRadius: '4px',
                                                                        fontSize: '11px',
                                                                        color: '#ff8800',
                                                                        fontWeight: '500'
                                                                    }}>
                                                                        ⚠️ Old bid - waiting for new bid on updated specs
                                                                    </div>
                                                                    <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px', color: '#999' }}>
                                                                        Outdated Proposals (previous specs)
                                                                    </div>
                                                                    <BidComparisonMatrix 
                                                                        bids={outdatedBids}
                                                                        darkBorder={darkBorder}
                                                                        greenAccent={greenAccent}
                                                                        darkText={darkText}
                                                                        darkBg={darkBg}
                                                                        onImageClick={setLightboxImage}
                                                                        smartAlternativesEnabled={smartAlternativesEnabled}
                                                                    />
                                                                </div>
                                                            )}
                                                        </>
                                                    );
                                                })()}
                                            </div>
                                        </div>
                                    ) : !showRfqForm ? (
                                        <button
                                            onClick={() => setShowRfqForm(true)}
                                        style={{
                                            width: '100%',
                                                padding: '12px',
                                                backgroundColor: '#111111',
                                                border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                                color: darkText,
                                                fontSize: '14px',
                                                fontFamily: 'monospace',
                                            cursor: 'pointer',
                                    fontWeight: '600', 
                                            }}
                                        >
                                            Request Quote
                                        </button>
                                    ) : (
                                        <div style={{
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                            padding: '16px',
                                            backgroundColor: '#111111',
                                        }}>
                                            <div style={{
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                alignItems: 'center',
                                                marginBottom: '16px',
                                            }}>
                                                <div style={{ fontSize: '14px', fontWeight: '600' }}>
                                        Request Quote
                                                </div>
                                    <button
                                                    onClick={() => setShowRfqForm(false)}
                                                    style={{
                                                        background: 'none',
                                                        border: 'none',
                                                        color: darkText,
                                                        fontSize: '20px',
                                                        cursor: 'pointer',
                                                        padding: '0',
                                                        width: '24px',
                                                        height: '24px',
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                    }}
                                                >
                                                    ×
                                </button>
                                </div>
                                
                                            {/* RFQ Form Fields */}
                                {/* Dimensions */}
                                <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                        Dimensions (provide ideal dimensions when applicable)
                                    </label>
                                                <div style={{ display: 'grid', gridTemplateColumns: '80px 80px 80px auto', gap: '8px' }}>
                                            <input
                                                type="number"
                                                value={width}
                                                onChange={(e) => setWidth(e.target.value)}
                                                        placeholder="W"
                                                        step="0.01"
                                                style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                            color: darkText,
                                                    fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                }}
                                            />
                                            <input
                                                type="number"
                                                value={depth}
                                                onChange={(e) => setDepth(e.target.value)}
                                                        placeholder="D"
                                                        step="0.01"
                                                style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                            color: darkText,
                                                    fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                }}
                                            />
                                            <input
                                                type="number"
                                                value={height}
                                                onChange={(e) => setHeight(e.target.value)}
                                                        placeholder="H"
                                                        step="0.01"
                                                style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                            color: darkText,
                                                    fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                }}
                                            />
                                            <select
                                                value={unit}
                                                onChange={(e) => setUnit(e.target.value)}
                                                style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                            color: darkText,
                                                    fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                }}
                                            >
                                                        <option value="in">in</option>
                                                <option value="cm">cm</option>
                                                        <option value="mm">mm</option>
                                                <option value="m">m</option>
                                            </select>
                                        </div>
                            </div>
                            
                                            {/* Quantity */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Quantity
                                    </label>
                                                <input
                                                    type="number"
                                                    value={quantity}
                                                    onChange={(e) => setQuantity(e.target.value)}
                                                    min="1"
                                                    style={{
                                                        width: '100%',
                                                        padding: '8px',
                                                        backgroundColor: darkBg,
                                                        border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px', 
                                                        color: darkText,
                                            fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                    }}
                                                />
                                </div>
                                
                                            {/* Delivery Country */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Delivery Country
                                    </label>
                                                <select
                                                    value={deliveryCountry}
                                                    onChange={(e) => setDeliveryCountry(e.target.value)}
                                                    style={{
                                                        width: '100%',
                                                        padding: '8px',
                                                        backgroundColor: darkBg,
                                                        border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px', 
                                                        color: darkText,
                                            fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                    }}
                                                >
                                                    <option value="">Select Country</option>
                                                    <option value="US">US</option>
                                                    <option value="CA">CA</option>
                                                    <option value="CN">CN</option>
                                                    <option value="VN">VN</option>
                                                    <option value="EU">EU</option>
                                                </select>
                                        </div>
                                            
                                            {/* ZIP/Postal Code */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    ZIP/Postal Code
                                                </label>
                                                <input
                                                    type="text"
                                                    value={deliveryPostal}
                                                    onChange={(e) => setDeliveryPostal(e.target.value)}
                                                    placeholder="Required for US/CA"
                                                    style={{
                                                        width: '100%',
                                    padding: '8px',
                                                        backgroundColor: darkBg,
                                                        border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px', 
                                                        color: darkText,
                                            fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                    }}
                                                />
                                                {shippingMessage && (
                                                    <div style={{ 
                                                        marginTop: '8px',
                                                        fontSize: '11px',
                                                        color: (deliveryCountry?.toUpperCase() === 'US' || deliveryCountry?.toUpperCase() === 'CA') && !deliveryPostal ? '#ff0000' : '#999',
                                        }}>
                                                        {shippingMessage}
                                        </div>
                                    )}
                                </div>
                                            
                                            {/* Inspiration / References / Sketch Drawings (inside RFQ form) */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                                                    Inspiration / References / Sketch Drawings
                                                </div>
                                                <div style={{ marginBottom: '8px', fontSize: '11px', color: '#999' }}>
                                                    These images are helpful when you're ready to request a quote. They will be used as reference materials by suppliers to price accurately.
                                                </div>
                                                <div style={{ 
                                                    display: 'flex',
                                                    gap: '8px',
                                                    flexWrap: 'wrap',
                                                    marginBottom: '8px',
                                                }}>
                                                    {inspiration.map((insp, idx) => (
                                                        <div
                                                            key={idx}
                                                            style={{
                                                                width: '80px',
                                                                height: '80px',
                                                                border: `1px solid ${darkBorder}`,
                                                                borderRadius: '4px',
                                                                backgroundColor: '#111111',
                                                                position: 'relative',
                                                                overflow: 'hidden',
                                                                cursor: 'pointer',
                                                            }}
                                                            onClick={() => {
                                                                if (insp.url) {
                                                                    // Commit 2.3.5.4: PDFs open in new tab, images use lightbox
                                                                    if (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) {
                                                                        window.open(insp.url, '_blank');
                                                                    } else {
                                                                        setLightboxImage(insp.url);
                                                                    }
                                                                }
                                                            }}
                                                        >
                                                            {insp.url ? (
                                                                (insp.type === 'pdf' || insp.url.toLowerCase().endsWith('.pdf')) ? (
                                                                    <div style={{
                                                                        width: '100%',
                                                                        height: '100%',
                                                                        display: 'flex',
                                                                        alignItems: 'center',
                                                                        justifyContent: 'center',
                                                                        backgroundColor: '#222',
                                                                        borderRadius: '4px',
                                                                        flexDirection: 'column',
                                                                        gap: '4px',
                                                                    }}>
                                                                        <div style={{ fontSize: '24px' }}>📄</div>
                                                                        <div style={{ fontSize: '8px', color: '#999' }}>PDF</div>
                                                                    </div>
                                                                ) : (
                                                                    <img 
                                                                        src={insp.url} 
                                                                        alt={insp.title || 'Reference'} 
                                                                        style={{
                                                                            width: '100%',
                                                                            height: '100%',
                                                                            objectFit: 'cover',
                                                                            borderRadius: '4px',
                                                                        }} 
                                                                    />
                                                                )
                                                            ) : (
                                                                <div style={{ fontSize: '10px', color: '#666' }}>[ img ]</div>
                                                            )}
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setInspiration(inspiration.filter((_, i) => i !== idx));
                                                                }}
                                                                style={{
                                                                    position: 'absolute',
                                                                    top: '4px',
                                                                    right: '4px',
                                                                    background: '#ff0000',
                                                                    color: '#fff',
                                                                    border: 'none',
                                                                    borderRadius: '50%',
                                                                    width: '20px',
                                                                    height: '20px',
                                                                    cursor: 'pointer',
                                                                    fontSize: '12px',
                                                                    display: 'flex',
                                                                    alignItems: 'center',
                                                                    justifyContent: 'center',
                                                                    padding: 0,
                                                                }}
                                                            >
                                                                ×
                                                            </button>
                                                        </div>
                                                    ))}
                                                    <button
                                                        onClick={() => {
                                                            const input = document.getElementById('inspiration-file-input-rfq');
                                                            if (input) input.click();
                                                        }}
                                                        disabled={isUploadingInspiration}
                                                        style={{
                                                            width: '80px',
                                                            height: '80px',
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            backgroundColor: '#111111',
                                                            color: darkText,
                                                            cursor: isUploadingInspiration ? 'not-allowed' : 'pointer',
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                        }}
                                                    >
                                                        {isUploadingInspiration ? '...' : '[+ Add]'}
                                                    </button>
                                                </div>
                                                <input
                                                    type="file"
                                                    id="inspiration-file-input-rfq"
                                                    accept="image/*,.pdf,application/pdf,.heic,.heif"
                                                    multiple
                                                    onChange={handleInspirationFileChange}
                                                    style={{ display: 'none' }}
                                                    disabled={isUploadingInspiration}
                                                />
                                            </div>
                                            
                                            {/* Invite Makers */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px' }}>
                                                    Invite Makers
                                                </div>
                                                <div style={{ fontSize: '11px', color: '#999', marginBottom: '8px' }}>
                                                    Enter existing maker username(s) or email address(es). Press Enter or click Add. (1-5 invites)
                                                </div>
                                                <div style={{ display: 'flex', gap: '8px', marginBottom: '8px' }}>
                                                    <input
                                                        type="text"
                                                        value={inviteSupplierInput}
                                                        onChange={(e) => setInviteSupplierInput(e.target.value)}
                                                        onKeyPress={(e) => {
                                                            if (e.key === 'Enter') {
                                                                e.preventDefault();
                                                                addInvitedSupplierChip();
                                            }
                                        }}
                                                        placeholder="Username or email"
                                        style={{
                                            flex: 1,
                                    padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                                            color: darkText,
                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                        }}
                                                    />
                            <button
                                        type="button"
                                                        onClick={addInvitedSupplierChip}
                                style={{
                                                            padding: '8px 16px',
                                                            backgroundColor: '#111111',
                                                            border: `1px solid ${darkBorder}`,
                                    borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                    cursor: 'pointer',
                                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                                        Add
                                    </button>
                                                </div>
                                                <div style={{
                                                    display: 'flex',
                                                    flexWrap: 'wrap',
                                                    gap: '8px',
                                                    marginBottom: '8px',
                                                    minHeight: '32px',
                                                }}>
                                                    {invitedSuppliers.map((supplier, idx) => (
                                                        <div
                                                            key={idx}
                                                            style={{
                                                                display: 'inline-flex',
                                                                alignItems: 'center',
                                                                gap: '6px',
                                                                padding: '6px 12px',
                                                                backgroundColor: '#111111',
                                                                border: `1px solid ${darkBorder}`,
                                                                borderRadius: '16px',
                                    fontSize: '12px',
                                                                color: greenAccent,
                                                                fontFamily: 'monospace',
                                                            }}
                                                        >
                                                            <span>{supplier}</span>
                                    <button
                                        type="button"
                                                                onClick={() => removeInvitedSupplierChip(supplier)}
                                                                style={{
                                                                    background: 'none',
                                                                    border: 'none',
                                                                    color: darkText,
                                                                    cursor: 'pointer',
                                                                    fontSize: '16px',
                                                                    lineHeight: 1,
                                                                    padding: 0,
                                                                    marginLeft: '4px',
                                                                    fontWeight: 'bold',
                                                                }}
                                                            >
                                                                ×
                                </button>
                                </div>
                                                    ))}
                            </div>
                                                {rfqError && invitedSuppliers.length === 0 && (
                                                    <div style={{ fontSize: '11px', color: '#ff0000', marginTop: '4px' }}>
                                                        {rfqError}
                            </div>
                                                )}
                            </div>
                            
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{
                            display: 'flex',
                                                    alignItems: 'center',
                            gap: '8px',
                                                    cursor: 'pointer',
                                                }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={allowSystemInvites}
                                                        onChange={(e) => {
                                                            setAllowSystemInvites(e.target.checked);
                                                            // Update message immediately
                                                            setTimeout(() => {
                                                                if (e.target.checked) {
                                                                    if (invitedSuppliers.length > 0) {
                                                                        setSystemInvitesMessage('We\'ll invite 2 additional makers in 24 hours.');
                                            } else {
                                                                        setSystemInvitesMessage('We will send your request on your behalf.');
                                            }
                                                                } else {
                                                                    setSystemInvitesMessage('');
                                                                }
                                                            }, 0);
                                        }}
                                        style={{
                                                            width: '18px',
                                                            height: '18px',
                                            cursor: 'pointer',
                                                        }}
                                                    />
                                                    <span style={{ fontSize: '12px', fontFamily: 'monospace' }}>
                                                        Let WireFrame (OS) source makers for this request
                                                    </span>
                                                </label>
                                                <div style={{ marginTop: '8px', fontSize: '11px', color: '#999', paddingLeft: '26px' }}>
                                                    If enabled, Wireframe (OS) will find qualified makers based on your item and keywords.
                                                </div>
                                                {systemInvitesMessage && (
                                                    <div style={{ marginTop: '8px', fontSize: '11px', color: '#999', paddingLeft: '26px' }}>
                                                        {systemInvitesMessage}
                                                    </div>
                                                )}
                                            </div>
                                            
                                            {rfqError && (
                                <div style={{ 
                                                    marginBottom: '12px',
                                    padding: '8px',
                                                    backgroundColor: '#330000',
                                                    border: `1px solid #ff0000`,
                                    borderRadius: '4px',
                                                    fontSize: '11px',
                                                    color: '#ff0000',
                                }}>
                                                    {rfqError}
                                </div>
                                            )}
                                            
                            <button
                                                onClick={handleSubmitRfq}
                                                disabled={isSubmittingRfq}
                                style={{
                                                    width: '100%',
                                                    padding: '12px',
                                                    backgroundColor: greenAccent,
                                                    border: 'none',
                                    borderRadius: '4px',
                                                    color: darkBg,
                                                    fontSize: '14px',
                                                    fontFamily: 'monospace',
                                                    cursor: isSubmittingRfq ? 'not-allowed' : 'pointer',
                                                    fontWeight: '600',
                                                    opacity: isSubmittingRfq ? 0.6 : 1,
                                        }}
                                    >
                                                {isSubmittingRfq ? 'Submitting...' : 'Submit RFQ'}
                                </button>
                                            
                                            {/* Instructional microcopy */}
                                            <div style={{
                                                marginTop: '12px',
                                                fontSize: '11px',
                                                color: '#999',
                                                textAlign: 'center',
                                                fontFamily: 'monospace',
                                            }}>
                                                Edit RFQ details and click Update.
                                            </div>
                                </div>
                                    )}
                                        </div>
                                    )}
                                        </div>
                                    )}
                                    
                                    {/* Tab 3: Proposals */}
                                    {activeTab === 'bids' && (
                                        <div>
                                            {/* Commit 2.3.9.1C: Payment Required Banner */}
                                            {(() => {
                                                // Debug logging
                                                if (itemState.has_prototype_payment) {
                                                    console.log('Payment Banner Debug:', {
                                                        has_prototype_payment: itemState.has_prototype_payment,
                                                        prototype_payment_status: itemState.prototype_payment_status,
                                                        prototype_payment_total_due: itemState.prototype_payment_total_due
                                                    });
                                                }
                                                return null;
                                            })()}
                                            {itemState.has_prototype_payment && itemState.prototype_payment_status === 'requested' && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    padding: '20px',
                                                    backgroundColor: '#331100',
                                                    border: '2px solid #ff8800',
                                                    borderRadius: '4px',
                                                }}>
                                                    <div style={{
                                                        fontSize: '16px',
                                                        fontWeight: '600',
                                                        color: '#ff8800',
                                                        marginBottom: '12px',
                                                    }}>
                                                        Payment Required — Prototype & CAD Not Started
                                                    </div>
                                                    <div style={{
                                                        fontSize: '13px',
                                                        color: '#ffaa66',
                                                        marginBottom: '16px',
                                                        lineHeight: '1.5',
                                                    }}>
                                                        Your prototype request has been submitted.
                                                        CAD drafting and prototype work will begin only after payment is received.
                                                    </div>
                                                    <div style={{
                                                        marginBottom: '12px',
                                                        padding: '12px',
                                                        backgroundColor: '#1a0a00',
                                                        borderRadius: '4px',
                                                        border: '1px solid #ff8800',
                                                    }}>
                                                        <div style={{
                                                            fontSize: '14px',
                                                            fontWeight: '600',
                                                            color: '#fff',
                                                            marginBottom: '8px',
                                                        }}>
                                                            Amount Due: ${itemState.prototype_payment_total_due ? itemState.prototype_payment_total_due.toFixed(2) : '0.00'}
                                                        </div>
                                                        <div style={{
                                                            fontSize: '12px',
                                                            color: '#ffaa66',
                                                        }}>
                                                            Payment Methods: Wire / ACH / Zelle
                                                        </div>
                                                    </div>
                                                    <button
                                                        onClick={() => setShowPaymentInstructions(true)}
                                                        style={{
                                                            padding: '10px 20px',
                                                            backgroundColor: '#ff8800',
                                                            color: '#000',
                                                            border: 'none',
                                                            borderRadius: '4px',
                                                            fontFamily: 'monospace',
                                                            fontSize: '12px',
                                                            fontWeight: '600',
                                                            cursor: 'pointer',
                                                        }}
                                                        onMouseOver={(e) => e.target.style.backgroundColor = '#ff9900'}
                                                        onMouseOut={(e) => e.target.style.backgroundColor = '#ff8800'}
                                                    >
                                                        [ View Payment Instructions ]
                                                    </button>
                                                </div>
                                            )}
                                            
                                            {/* Commit 2.3.9.2B-D: Prototype Section (Expandable) */}
                                            {itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    border: '2px solid #00ff00',
                                                    borderRadius: '4px',
                                                    overflow: 'hidden',
                                                }}>
                                                    {/* Header - Always Visible */}
                                                    <div 
                                                        onClick={() => setPrototypeSectionExpanded(!prototypeSectionExpanded)}
                                                        style={{
                                                            padding: '20px',
                                                            backgroundColor: '#003300',
                                                            cursor: 'pointer',
                                                            display: 'flex',
                                                            justifyContent: 'space-between',
                                                            alignItems: 'center',
                                                        }}
                                                    >
                                                        <div>
                                                            <div style={{
                                                                fontSize: '16px',
                                                                fontWeight: '600',
                                                                color: '#00ff00',
                                                                marginBottom: '4px',
                                                            }}>
                                                                Payment Confirmed
                                                            </div>
                                                            <div style={{
                                                                fontSize: '13px',
                                                                color: '#00cc00',
                                                                lineHeight: '1.5',
                                                            }}>
                                                                CAD drafting has begun.
                                                            </div>
                                                        </div>
                                                        <div style={{
                                                            fontSize: '20px',
                                                            color: '#00ff00',
                                                            fontWeight: 'bold',
                                                        }}>
                                                            {prototypeSectionExpanded ? '−' : '+'}
                                                        </div>
                                                    </div>
                                                    
                                                    {/* Expandable Content - Prototype Review */}
                                                    {prototypeSectionExpanded && (
                                                        <div style={{
                                                            padding: '20px',
                                                            backgroundColor: '#001100',
                                                            borderTop: '1px solid #00ff00',
                                                        }}>
                                                            {/* Prototype Video Header */}
                                                            <div style={{
                                                                fontSize: '18px',
                                                                fontWeight: '600',
                                                                color: '#00ff00',
                                                                marginBottom: '16px',
                                                            }}>
                                                                Prototype Video
                                                            </div>
                                                            
                                                            {/* Status Badge */}
                                                            {itemState.prototype_status && (
                                                                <div style={{
                                                                    display: 'inline-block',
                                                                    padding: '6px 12px',
                                                                    backgroundColor: itemState.prototype_status === 'approved' ? '#003300' : 
                                                                                   itemState.prototype_status === 'changes_requested' ? '#331100' : '#001133',
                                                                    border: `1px solid ${itemState.prototype_status === 'approved' ? '#00ff00' : 
                                                                           itemState.prototype_status === 'changes_requested' ? '#ff8800' : '#66aaff'}`,
                                                                    borderRadius: '4px',
                                                                    fontSize: '12px',
                                                                    fontWeight: '600',
                                                                    color: itemState.prototype_status === 'approved' ? '#00ff00' : 
                                                                           itemState.prototype_status === 'changes_requested' ? '#ff8800' : '#66aaff',
                                                                    marginBottom: '16px',
                                                                }}>
                                                                    {itemState.prototype_status === 'approved' ? 'Approved' : 
                                                                     itemState.prototype_status === 'changes_requested' ? 'Changes Requested' : 
                                                                     itemState.prototype_status === 'submitted' ? `Submitted (v${itemState.prototype_current_version || 0})` : 
                                                                     'Not Submitted'}
                                                                </div>
                                                            )}
                                                            
                                                            {/* Video Links */}
                                                            {itemState.prototype_submission && itemState.prototype_submission.links && itemState.prototype_submission.links.length > 0 && (
                                                                <div style={{
                                                                    marginBottom: '16px',
                                                                }}>
                                                                    <div style={{
                                                                        fontSize: '13px',
                                                                        fontWeight: '600',
                                                                        color: '#ccc',
                                                                        marginBottom: '8px',
                                                                    }}>
                                                                        Video Links (v{itemState.prototype_submission.version}):
                                                                    </div>
                                                                    {itemState.prototype_submission.links.map((link, idx) => (
                                                                        <div key={idx} style={{
                                                                            marginBottom: '8px',
                                                                            padding: '10px',
                                                                            backgroundColor: '#000',
                                                                            border: '1px solid #333',
                                                                            borderRadius: '4px',
                                                                        }}>
                                                                            <a 
                                                                                href={link.url} 
                                                                                target="_blank" 
                                                                                rel="noopener noreferrer"
                                                                                style={{
                                                                                    color: '#66aaff',
                                                                                    textDecoration: 'none',
                                                                                    fontSize: '12px',
                                                                                    display: 'flex',
                                                                                    alignItems: 'center',
                                                                                    gap: '8px',
                                                                                }}
                                                                            >
                                                                                <span style={{ fontWeight: '600', textTransform: 'capitalize' }}>{link.provider}:</span>
                                                                                <span style={{ flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{link.url}</span>
                                                                                <span style={{ fontSize: '10px', color: '#888' }}>Open →</span>
                                                                            </a>
                                                                        </div>
                                                                    ))}
                                                                    {itemState.prototype_submission.created_at && (
                                                                        <div style={{
                                                                            fontSize: '11px',
                                                                            color: '#888',
                                                                            marginTop: '8px',
                                                                        }}>
                                                                            Submitted: {new Date(itemState.prototype_submission.created_at).toLocaleString()}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            )}
                                                            
                                                            {/* Action Buttons */}
                                                            {itemState.prototype_status === 'submitted' && (
                                                                <div style={{
                                                                    display: 'flex',
                                                                    gap: '12px',
                                                                    marginTop: '16px',
                                                                }}>
                                                                    <button
                                                                        onClick={async () => {
                                                                            // Fetch keyword names and phrases before opening modal
                                                                            if (itemState.direction_keyword_ids && itemState.direction_keyword_ids.length > 0) {
                                                                                try {
                                                                                    const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                                                    const response = await fetch(ajaxUrl, {
                                                                                        method: 'POST',
                                                                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                                                                        body: new URLSearchParams({
                                                                                            action: 'n88_get_keyword_phrases',
                                                                                            _ajax_nonce: (window.n88BoardNonce && window.n88BoardNonce.nonce_get_keyword_phrases) || 
                                                                                                       (window.n88BoardNonce && window.n88BoardNonce.nonce) || 
                                                                                                       (window.n88BoardData && window.n88BoardData.nonce) || 
                                                                                                       (window.n88 && window.n88.nonce) || '',
                                                                                            keyword_ids: JSON.stringify(itemState.direction_keyword_ids),
                                                                                        }),
                                                                                    });
                                                                                    const data = await response.json();
                                                                                    if (data.success && data.data) {
                                                                                        // Ensure keyword_ids are numbers for consistent access
                                                                                        const phrasesByKeyword = {};
                                                                                        if (data.data.phrases_by_keyword) {
                                                                                            Object.keys(data.data.phrases_by_keyword).forEach(kid => {
                                                                                                phrasesByKeyword[parseInt(kid)] = data.data.phrases_by_keyword[kid];
                                                                                            });
                                                                                        }
                                                                                        setAvailablePhrases(phrasesByKeyword);
                                                                                        
                                                                                        const keywordNamesObj = {};
                                                                                        if (data.data.keyword_names) {
                                                                                            Object.keys(data.data.keyword_names).forEach(kid => {
                                                                                                keywordNamesObj[parseInt(kid)] = data.data.keyword_names[kid];
                                                                                            });
                                                                                        }
                                                                                        setKeywordNames(keywordNamesObj);
                                                                                        // Initialize feedback packet with all keywords set to 'satisfied'
                                                                                        const initialPacket = {};
                                                                                        itemState.direction_keyword_ids.forEach(kid => {
                                                                                            initialPacket[parseInt(kid)] = { status: 'satisfied', severity: null, phrase_ids: [] };
                                                                                        });
                                                                                        setFeedbackPacket(initialPacket);
                                                                                        setTotalPhrasesSelected(0);
                                                                                        setShowRequestChangesModal(true);
                                                                                    } else {
                                                                                        alert('Failed to load keyword data: ' + (data.data?.message || 'Unknown error'));
                                                                                    }
                                                                                } catch (error) {
                                                                                    console.error('Error fetching keyword data:', error);
                                                                                    alert('Error loading keyword data');
                                                                                }
                                                                            } else {
                                                                                setShowRequestChangesModal(true);
                                                                            }
                                                                        }}
                                                                        style={{
                                                                            padding: '10px 20px',
                                                                            backgroundColor: '#331100',
                                                                            border: '1px solid #ff8800',
                                                                            borderRadius: '4px',
                                                                            color: '#ff8800',
                                                                            fontSize: '13px',
                                                                            fontWeight: '600',
                                                                            cursor: 'pointer',
                                                                            fontFamily: 'monospace',
                                                                        }}
                                                                    >
                                                                        Request Changes
                                                                    </button>
                                                                    <button
                                                                        onClick={async () => {
                                                                            // Approve prototype
                                                                            try {
                                                                                const response = await fetch(ajaxurl, {
                                                                                    method: 'POST',
                                                                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                                                                    body: new URLSearchParams({
                                                                                        action: 'n88_approve_prototype',
                                                                                        _ajax_nonce: (window.n88BoardNonce && window.n88BoardNonce.nonce_approve_prototype) || 
                                                                                                   (window.n88BoardNonce && window.n88BoardNonce.nonce) || 
                                                                                                   (window.n88BoardData && window.n88BoardData.nonce) || 
                                                                                                   (window.n88 && window.n88.nonce) || '',
                                                                                        payment_id: itemState.prototype_payment_id,
                                                                                        item_id: item.id,
                                                                                        bid_id: itemState.prototype_payment_bid_id,
                                                                                        version: itemState.prototype_current_version,
                                                                                    }),
                                                                                });
                                                                                const data = await response.json();
                                                                                if (data.success) {
                                                                                    await fetchItemState();
                                                                                    setPrototypeSectionExpanded(true);
                                                                                } else {
                                                                                    alert(data.data?.message || 'Failed to approve prototype');
                                                                                }
                                                                            } catch (error) {
                                                                                console.error('Error approving prototype:', error);
                                                                                alert('Error approving prototype');
                                                                            }
                                                                        }}
                                                                        style={{
                                                                            padding: '10px 20px',
                                                                            backgroundColor: '#003300',
                                                                            border: '1px solid #00ff00',
                                                                            borderRadius: '4px',
                                                                            color: '#00ff00',
                                                                            fontSize: '13px',
                                                                            fontWeight: '600',
                                                                            cursor: 'pointer',
                                                                            fontFamily: 'monospace',
                                                                        }}
                                                                    >
                                                                        Approve Prototype
                                                                    </button>
                                                                </div>
                                                            )}
                                                            
                                                            {itemState.prototype_status === 'changes_requested' && (
                                                                <div style={{
                                                                    fontSize: '12px',
                                                                    color: '#ff8800',
                                                                    marginTop: '12px',
                                                                }}>
                                                                    Changes have been requested. Waiting for supplier to submit updated version.
                                                                </div>
                                                            )}
                                                            
                                                            {itemState.prototype_status === 'approved' && (
                                                                <div style={{
                                                                    fontSize: '12px',
                                                                    color: '#00ff00',
                                                                    marginTop: '12px',
                                                                }}>
                                                                    ✓ Prototype approved (v{itemState.prototype_approved_version || itemState.prototype_current_version})
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                            
                                            {/* Commit 2.3.9.2B-D: Request Changes Modal */}
                                            {showRequestChangesModal && itemState.direction_keyword_ids && itemState.direction_keyword_ids.length > 0 && (
                                                <div style={{
                                                    position: 'fixed',
                                                    top: 0,
                                                    left: 0,
                                                    right: 0,
                                                    bottom: 0,
                                                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                                    zIndex: 10000,
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    padding: '20px',
                                                }}
                                                onClick={() => setShowRequestChangesModal(false)}
                                                >
                                                    <div style={{
                                                        backgroundColor: '#000',
                                                        border: '2px solid #ff8800',
                                                        borderRadius: '8px',
                                                        padding: '24px',
                                                        maxWidth: '800px',
                                                        maxHeight: '90vh',
                                                        overflowY: 'auto',
                                                        width: '100%',
                                                    }}
                                                    onClick={(e) => e.stopPropagation()}
                                                    >
                                                        <div style={{
                                                            display: 'flex',
                                                            justifyContent: 'space-between',
                                                            alignItems: 'center',
                                                            marginBottom: '20px',
                                                        }}>
                                                            <h2 style={{
                                                                fontSize: '18px',
                                                                fontWeight: '600',
                                                                color: '#ff8800',
                                                                margin: 0,
                                                            }}>
                                                                Request Changes - Feedback Packet
                                                            </h2>
                                                            <button
                                                                onClick={() => setShowRequestChangesModal(false)}
                                                                style={{
                                                                    background: 'none',
                                                                    border: 'none',
                                                                    color: '#fff',
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
                                                        
                                                        <div style={{
                                                            fontSize: '12px',
                                                            color: '#aaa',
                                                            marginBottom: '20px',
                                                        }}>
                                                            Review each keyword and select status. For keywords needing adjustment, select up to 3 phrases. Maximum 18 phrases total.
                                                        </div>
                                                        
                                                        {/* Keyword Checklist */}
                                                        <div style={{
                                                            marginBottom: '20px',
                                                        }}>
                                                            {itemState.direction_keyword_ids.map((keywordId) => {
                                                                const keywordData = feedbackPacket[keywordId] || { status: 'satisfied', severity: null, phrase_ids: [] };
                                                                const phrases = availablePhrases[keywordId] || [];
                                                                
                                                                return (
                                                                    <div key={keywordIdNum} style={{
                                                                        marginBottom: '16px',
                                                                        padding: '16px',
                                                                        backgroundColor: '#111',
                                                                        border: '1px solid #333',
                                                                        borderRadius: '4px',
                                                                    }}>
                                                                        <div style={{
                                                                            fontSize: '14px',
                                                                            fontWeight: '600',
                                                                            color: '#fff',
                                                                            marginBottom: '12px',
                                                                        }}>
                                                                            {keywordNames[keywordId] || `Keyword ID: ${keywordId}`}
                                                                        </div>
                                                                        
                                                                        {/* Status Selection */}
                                                                        <div style={{
                                                                            display: 'flex',
                                                                            gap: '12px',
                                                                            marginBottom: '12px',
                                                                        }}>
                                                                            {['satisfied', 'needs_adjustment', 'not_addressed'].map((status) => (
                                                                                <label key={status} style={{
                                                                                    display: 'flex',
                                                                                    alignItems: 'center',
                                                                                    gap: '6px',
                                                                                    cursor: 'pointer',
                                                                                    fontSize: '12px',
                                                                                    color: '#ccc',
                                                                                }}>
                                                                                    <input
                                                                                        type="radio"
                                                                                        name={`keyword_${keywordIdNum}_status`}
                                                                                        value={status}
                                                                                        checked={keywordData.status === status}
                                                                                        onChange={() => {
                                                                                            const newPacket = { ...feedbackPacket };
                                                                                            newPacket[keywordIdNum] = {
                                                                                                ...keywordData,
                                                                                                status: status,
                                                                                                severity: status === 'not_addressed' ? 'must_fix' : status === 'needs_adjustment' ? 'should_fix' : null,
                                                                                                phrase_ids: status === 'satisfied' ? [] : keywordData.phrase_ids,
                                                                                            };
                                                                                            setFeedbackPacket(newPacket);
                                                                                            // Always fetch phrases if not already loaded when status changes to needs_adjustment or not_addressed
                                                                                            if (status !== 'satisfied' && (!availablePhrases[keywordIdNum] || availablePhrases[keywordIdNum].length === 0)) {
                                                                                                const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                                                                fetch(ajaxUrl, {
                                                                                                    method: 'POST',
                                                                                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                                                                                    body: new URLSearchParams({
                                                                                                        action: 'n88_get_keyword_phrases',
                                                                                                        _ajax_nonce: (window.n88BoardNonce && window.n88BoardNonce.nonce_get_keyword_phrases) || 
                                                                                                                   (window.n88BoardNonce && window.n88BoardNonce.nonce) || 
                                                                                                                   (window.n88BoardData && window.n88BoardData.nonce) || 
                                                                                                                   (window.n88 && window.n88.nonce) || '',
                                                                                                        keyword_ids: JSON.stringify([keywordIdNum]),
                                                                                                    }),
                                                                                                })
                                                                                                .then(res => res.json())
                                                                                                .then(data => {
                                                                                                    if (data.success && data.data && data.data.phrases_by_keyword) {
                                                                                                        // Ensure keyword_id is a number for consistent access
                                                                                                        const phrasesByKeyword = {};
                                                                                                        Object.keys(data.data.phrases_by_keyword).forEach(kid => {
                                                                                                            phrasesByKeyword[parseInt(kid)] = data.data.phrases_by_keyword[kid];
                                                                                                        });
                                                                                                        setAvailablePhrases(prev => ({
                                                                                                            ...prev,
                                                                                                            ...phrasesByKeyword,
                                                                                                        }));
                                                                                                        if (data.data.keyword_names) {
                                                                                                            const keywordNamesObj = {};
                                                                                                            Object.keys(data.data.keyword_names).forEach(kid => {
                                                                                                                keywordNamesObj[parseInt(kid)] = data.data.keyword_names[kid];
                                                                                                            });
                                                                                                            setKeywordNames(prev => ({
                                                                                                                ...prev,
                                                                                                                ...keywordNamesObj,
                                                                                                            }));
                                                                                                        }
                                                                                                    } else {
                                                                                                        console.error('Failed to load phrases:', data.data?.message || 'Unknown error', data);
                                                                                                    }
                                                                                                })
                                                                                                .catch(error => {
                                                                                                    console.error('Error fetching phrases:', error);
                                                                                                });
                                                                                            }
                                                                                        }}
                                                                                        style={{ cursor: 'pointer' }}
                                                                                    />
                                                                                    <span>
                                                                                        {status === 'satisfied' ? '✅ Satisfied' : 
                                                                                         status === 'needs_adjustment' ? '⚠️ Needs Adjustment' : 
                                                                                         '❌ Not Addressed'}
                                                                                    </span>
                                                                                </label>
                                                                            ))}
                                                                        </div>
                                                                        
                                                                        {/* Phrase Selection (only if not satisfied) */}
                                                                        {keywordData.status !== 'satisfied' && (
                                                                            phrases && phrases.length > 0 ? (
                                                                            <>
                                                                                <div style={{
                                                                                    fontSize: '12px',
                                                                                    color: '#aaa',
                                                                                    marginBottom: '8px',
                                                                                }}>
                                                                                    Select up to 3 phrases:
                                                                                </div>
                                                                                <div style={{
                                                                                    display: 'flex',
                                                                                    flexDirection: 'column',
                                                                                    gap: '6px',
                                                                                }}>
                                                                                    {phrases.map((phrase) => (
                                                                                        <label key={phrase.phrase_id} style={{
                                                                                            display: 'flex',
                                                                                            alignItems: 'center',
                                                                                            gap: '8px',
                                                                                            cursor: 'pointer',
                                                                                            fontSize: '11px',
                                                                                            color: '#ccc',
                                                                                            padding: '6px',
                                                                                            backgroundColor: keywordData.phrase_ids.includes(phrase.phrase_id) ? '#331100' : 'transparent',
                                                                                            border: `1px solid ${keywordData.phrase_ids.includes(phrase.phrase_id) ? '#ff8800' : '#333'}`,
                                                                                            borderRadius: '4px',
                                                                                        }}>
                                                                                            <input
                                                                                                type="checkbox"
                                                                                                checked={keywordData.phrase_ids.includes(phrase.phrase_id)}
                                                                                                onChange={(e) => {
                                                                                                    const newPacket = { ...feedbackPacket };
                                                                                                    const currentPhraseIds = keywordData.phrase_ids || [];
                                                                                                    if (e.target.checked) {
                                                                                                        if (currentPhraseIds.length < 3) {
                                                                                                            newPacket[keywordIdNum] = {
                                                                                                                ...keywordData,
                                                                                                                phrase_ids: [...currentPhraseIds, phrase.phrase_id],
                                                                                                            };
                                                                                                            setTotalPhrasesSelected(totalPhrasesSelected + 1);
                                                                                                        }
                                                                                                    } else {
                                                                                                        newPacket[keywordIdNum] = {
                                                                                                            ...keywordData,
                                                                                                            phrase_ids: currentPhraseIds.filter(id => id !== phrase.phrase_id),
                                                                                                        };
                                                                                                        setTotalPhrasesSelected(Math.max(0, totalPhrasesSelected - 1));
                                                                                                    }
                                                                                                    setFeedbackPacket(newPacket);
                                                                                                }}
                                                                                                disabled={!keywordData.phrase_ids.includes(phrase.phrase_id) && keywordData.phrase_ids.length >= 3}
                                                                                                style={{ cursor: keywordData.phrase_ids.length < 3 || keywordData.phrase_ids.includes(phrase.phrase_id) ? 'pointer' : 'not-allowed' }}
                                                                                            />
                                                                                            <span>{phrase.phrase_text}</span>
                                                                                        </label>
                                                                                    ))}
                                                                                </div>
                                                                                
                                                                                {/* Severity Selection */}
                                                                                <div style={{
                                                                                    marginTop: '12px',
                                                                                }}>
                                                                                    <div style={{
                                                                                        fontSize: '12px',
                                                                                        color: '#aaa',
                                                                                        marginBottom: '6px',
                                                                                    }}>
                                                                                        Severity:
                                                                                    </div>
                                                                                    <select
                                                                                        value={keywordData.severity || (keywordData.status === 'not_addressed' ? 'must_fix' : 'should_fix')}
                                                                                        onChange={(e) => {
                                                                                            const newPacket = { ...feedbackPacket };
                                                                                            newPacket[keywordIdNum] = {
                                                                                                ...keywordData,
                                                                                                severity: e.target.value,
                                                                                            };
                                                                                            setFeedbackPacket(newPacket);
                                                                                        }}
                                                                                        style={{
                                                                                            padding: '6px 12px',
                                                                                            backgroundColor: '#000',
                                                                                            color: '#fff',
                                                                                            border: '1px solid #333',
                                                                                            borderRadius: '4px',
                                                                                            fontSize: '12px',
                                                                                            fontFamily: 'monospace',
                                                                                        }}
                                                                                    >
                                                                                        <option value="must_fix">Must Fix</option>
                                                                                        <option value="should_fix">Should Fix</option>
                                                                                        <option value="optional">Optional</option>
                                                                                    </select>
                                                                                </div>
                                                                            </>
                                                                            ) : (
                                                                                <div style={{
                                                                                    fontSize: '11px',
                                                                                    color: '#888',
                                                                                    fontStyle: 'italic',
                                                                                    padding: '8px',
                                                                                    backgroundColor: '#0a0a0a',
                                                                                    border: '1px solid #333',
                                                                                    borderRadius: '4px',
                                                                                }}>
                                                                                    Loading phrases...
                                                                                </div>
                                                                            )
                                                                        )}
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                        
                                                        {/* Total Phrases Counter */}
                                                        <div style={{
                                                            fontSize: '12px',
                                                            color: totalPhrasesSelected > 18 ? '#ff6666' : '#aaa',
                                                            marginBottom: '20px',
                                                            textAlign: 'right',
                                                        }}>
                                                            Total Phrases Selected: {totalPhrasesSelected} / 18
                                                        </div>
                                                        
                                                        {/* Submit Button */}
                                                        <div style={{
                                                            display: 'flex',
                                                            gap: '12px',
                                                            justifyContent: 'flex-end',
                                                        }}>
                                                            <button
                                                                onClick={() => {
                                                                    setShowRequestChangesModal(false);
                                                                    setFeedbackPacket({});
                                                                    setTotalPhrasesSelected(0);
                                                                }}
                                                                style={{
                                                                    padding: '10px 20px',
                                                                    backgroundColor: '#333',
                                                                    border: '1px solid #666',
                                                                    borderRadius: '4px',
                                                                    color: '#ccc',
                                                                    fontSize: '13px',
                                                                    fontWeight: '600',
                                                                    cursor: 'pointer',
                                                                    fontFamily: 'monospace',
                                                                }}
                                                            >
                                                                Cancel
                                                            </button>
                                                            <button
                                                                onClick={async () => {
                                                                    if (totalPhrasesSelected > 18) {
                                                                        alert('Maximum 18 phrases allowed.');
                                                                        return;
                                                                    }
                                                                    
                                                                    try {
                                                                        const response = await fetch(ajaxurl, {
                                                                            method: 'POST',
                                                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                                                            body: new URLSearchParams({
                                                                                action: 'n88_request_prototype_changes',
                                                                                _ajax_nonce: (window.n88BoardNonce && window.n88BoardNonce.nonce_request_prototype_changes) || 
                                                                                           (window.n88BoardNonce && window.n88BoardNonce.nonce) || 
                                                                                           (window.n88BoardData && window.n88BoardData.nonce) || 
                                                                                           (window.n88 && window.n88.nonce) || '',
                                                                                payment_id: itemState.prototype_payment_id,
                                                                                item_id: item.id,
                                                                                bid_id: itemState.prototype_payment_bid_id,
                                                                                submission_version: itemState.prototype_current_version,
                                                                                feedback_packet: JSON.stringify(feedbackPacket),
                                                                            }),
                                                                        });
                                                                        const data = await response.json();
                                                                        if (data.success) {
                                                                            setShowRequestChangesModal(false);
                                                                            setFeedbackPacket({});
                                                                            setTotalPhrasesSelected(0);
                                                                            await fetchItemState();
                                                                            setPrototypeSectionExpanded(true);
                                                                        } else {
                                                                            alert(data.data?.message || 'Failed to request changes');
                                                                        }
                                                                    } catch (error) {
                                                                        console.error('Error requesting changes:', error);
                                                                        alert('Error requesting changes');
                                                                    }
                                                                }}
                                                                disabled={totalPhrasesSelected > 18}
                                                                style={{
                                                                    padding: '10px 20px',
                                                                    backgroundColor: totalPhrasesSelected > 18 ? '#333' : '#331100',
                                                                    border: `1px solid ${totalPhrasesSelected > 18 ? '#666' : '#ff8800'}`,
                                                                    borderRadius: '4px',
                                                                    color: totalPhrasesSelected > 18 ? '#666' : '#ff8800',
                                                                    fontSize: '13px',
                                                                    fontWeight: '600',
                                                                    cursor: totalPhrasesSelected > 18 ? 'not-allowed' : 'pointer',
                                                                    fontFamily: 'monospace',
                                                                }}
                                                            >
                                                                Submit Feedback Packet
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                            
                                            {/* Bids Content - Only show if bids exist */}
                                            {itemState.has_bids && itemState.bids && itemState.bids.length > 0 && (
                                                <>
                                            {/* Item Context Header */}
                                            <div style={{ 
                                                marginBottom: '16px',
                                                padding: '12px',
                                                backgroundColor: '#111111',
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px',
                                            }}>
                                                <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                    {item.title || item.description || `Item #${item.id || 'N/A'}`}
                                                </div>
                                                {category && (
                                                    <div style={{ fontSize: '12px', color: darkText }}>
                                                        Category: {category}
                                                    </div>
                                                )}
                                            </div>
                                            
                                            {/* Bids Matrix */}
                                            <BidComparisonMatrix 
                                                bids={itemState.bids}
                                                darkBorder={darkBorder}
                                                greenAccent={greenAccent}
                                                darkText={darkText}
                                                darkBg={darkBg}
                                                onImageClick={setLightboxImage}
                                                smartAlternativesEnabled={smartAlternativesEnabled}
                                            />
                                            
                                            {/* Request CAD + Prototype Video Button/Form (Commit 2.3.9.1B) */}
                                            {!showCadPrototypeForm ? (
                                                <div style={{ marginTop: '20px', textAlign: 'center' }}>
                                                    <button
                                                        onClick={() => {
                                                            // Auto-select first bid if only one bid exists
                                                            if (itemState.bids && itemState.bids.length === 1) {
                                                                setSelectedBidId(itemState.bids[0].bid_id);
                                                            }
                                                            setShowCadPrototypeForm(true);
                                                            setCadPrototypeError('');
                                                            setCadPrototypeSuccess(false);
                                                        }}
                                                        style={{
                                                            padding: '12px 24px',
                                                            backgroundColor: '#111111',
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '14px',
                                                            fontFamily: 'monospace',
                                                            cursor: 'pointer',
                                                            fontWeight: '600',
                                                        }}
                                                    >
                                                        Request CAD + Prototype Video
                                                    </button>
                                                </div>
                                            ) : (
                                                <div style={{
                                                    marginTop: '20px',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    padding: '16px',
                                                    backgroundColor: '#111111',
                                                }}>
                                                    {/* Header with Close Button */}
                                                    <div style={{
                                                        display: 'flex',
                                                        justifyContent: 'space-between',
                                                        alignItems: 'center',
                                                        marginBottom: '16px',
                                                    }}>
                                                        <div style={{ fontSize: '14px', fontWeight: '600', color: darkText }}>
                                                            Request CAD + Prototype Video
                                                        </div>
                                                        <button
                                                            onClick={() => {
                                                                setShowCadPrototypeForm(false);
                                                                setSelectedBidId(null);
                                                                setSelectedKeywords([]);
                                                                setPrototypeNote('');
                                                                setCadPrototypeError('');
                                                                setCadPrototypeSuccess(false);
                                                            }}
                                                            style={{
                                                                background: 'none',
                                                                border: 'none',
                                                                color: darkText,
                                                                fontSize: '20px',
                                                                cursor: 'pointer',
                                                                padding: '0',
                                                                width: '24px',
                                                                height: '24px',
                                                                display: 'flex',
                                                                alignItems: 'center',
                                                                justifyContent: 'center',
                                                            }}
                                                        >
                                                            ×
                                                        </button>
                                                    </div>

                                                    {/* Success Message */}
                                                    {cadPrototypeSuccess && (
                                                        <div style={{
                                                            marginBottom: '16px',
                                                            padding: '12px',
                                                            backgroundColor: '#1a3a1a',
                                                            border: `1px solid ${greenAccent}`,
                                                            borderRadius: '4px',
                                                            fontSize: '12px',
                                                            color: greenAccent,
                                                            lineHeight: '1.5',
                                                        }}>
                                                            Request submitted. Please send payment using the instructions Below. We'll begin CAD drafting once payment is confirmed.
                                                        </div>
                                                    )}

                                                    {/* Error Message */}
                                                    {cadPrototypeError && (
                                                        <div style={{
                                                            marginBottom: '16px',
                                                            padding: '12px',
                                                            backgroundColor: '#3a1a1a',
                                                            border: '1px solid #ff4444',
                                                            borderRadius: '4px',
                                                            fontSize: '12px',
                                                            color: '#ff4444',
                                                        }}>
                                                            {cadPrototypeError}
                                                        </div>
                                                    )}

                                                    {/* Section A: Video Direction */}
                                                    <div style={{ marginBottom: '24px' }}>
                                                        <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '12px', color: darkText }}>
                                                            Video Direction
                                                        </div>
                                                        
                                                        {/* Bid Selection (if multiple bids) */}
                                                        {itemState.bids && itemState.bids.length > 1 && (
                                                            <div style={{ marginBottom: '16px' }}>
                                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '8px', color: darkText }}>
                                                                    Select Bid <span style={{ color: '#ff4444' }}>*</span>
                                                                </label>
                                                                <select
                                                                    value={selectedBidId || ''}
                                                                    onChange={(e) => {
                                                                        setSelectedBidId(parseInt(e.target.value));
                                                                        setSelectedKeywords([]);
                                                                        setAvailableKeywords([]);
                                                                    }}
                                                                    style={{
                                                                        width: '100%',
                                                                        padding: '8px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontSize: '12px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                >
                                                                    <option value="">-- Select a bid --</option>
                                                                    {itemState.bids.map((bid) => (
                                                                        <option key={bid.bid_id} value={bid.bid_id}>
                                                                            Bid #{bid.bid_id} - {bid.supplier_name || `Supplier ${bid.supplier_id}`}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                        )}

                                                        {/* Keyword Selection */}
                                                        <div style={{ marginBottom: '16px' }}>
                                                            <label style={{ display: 'block', fontSize: '12px', marginBottom: '8px', color: darkText }}>
                                                                Select Keywords (3-7 required) <span style={{ color: '#ff4444' }}>*</span>
                                                            </label>
                                                            <div style={{ fontSize: '11px', color: '#999', marginBottom: '8px' }}>
                                                                Select between 3 and 7 keywords that describe what should be shown in the prototype video.
                                                            </div>
                                                            
                                                            {/* Keyword Chips */}
                                                            <div style={{
                                                                display: 'flex',
                                                                flexWrap: 'wrap',
                                                                gap: '8px',
                                                                minHeight: '60px',
                                                                padding: '12px',
                                                                border: `1px solid ${darkBorder}`,
                                                                borderRadius: '4px',
                                                                backgroundColor: darkBg,
                                                            }}>
                                                                {availableKeywords.length === 0 ? (
                                                                    <div style={{ fontSize: '11px', color: '#999', fontStyle: 'italic' }}>
                                                                        {selectedBidId ? 'Loading keywords...' : 'Please select a bid first'}
                                                                    </div>
                                                                ) : (
                                                                    availableKeywords.map((keyword) => {
                                                                        const isSelected = selectedKeywords.includes(keyword.keyword_id);
                                                                        return (
                                                                            <button
                                                                                key={keyword.keyword_id}
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    if (isSelected) {
                                                                                        setSelectedKeywords(selectedKeywords.filter(id => id !== keyword.keyword_id));
                                                                                    } else {
                                                                                        if (selectedKeywords.length < 7) {
                                                                                            setSelectedKeywords([...selectedKeywords, keyword.keyword_id]);
                                                                                        }
                                                                                    }
                                                                                }}
                                                                                disabled={!isSelected && selectedKeywords.length >= 7}
                                                                                style={{
                                                                                    padding: '6px 12px',
                                                                                    backgroundColor: isSelected ? greenAccent : darkBg,
                                                                                    border: `1px solid ${isSelected ? greenAccent : darkBorder}`,
                                                                                    borderRadius: '20px',
                                                                                    color: isSelected ? darkBg : darkText,
                                                                                    fontSize: '11px',
                                                                                    fontFamily: 'monospace',
                                                                                    cursor: (!isSelected && selectedKeywords.length >= 7) ? 'not-allowed' : 'pointer',
                                                                                    opacity: (!isSelected && selectedKeywords.length >= 7) ? 0.5 : 1,
                                                                                }}
                                                                            >
                                                                                {keyword.keyword}
                                                                            </button>
                                                                        );
                                                                    })
                                                                )}
                                                            </div>
                                                            
                                                            {/* Keyword Count Indicator */}
                                                            <div style={{ marginTop: '8px', fontSize: '11px', color: selectedKeywords.length >= 3 && selectedKeywords.length <= 7 ? greenAccent : '#ff8800' }}>
                                                                {selectedKeywords.length} of 3-7 keywords selected
                                                            </div>
                                                        </div>

                                                        {/* Note Field */}
                                                        <div style={{ marginBottom: '16px' }}>
                                                            <label style={{ display: 'block', fontSize: '12px', marginBottom: '8px', color: darkText }}>
                                                                Additional Note (Optional, max 240 characters)
                                                            </label>
                                                            <textarea
                                                                value={prototypeNote}
                                                                onChange={(e) => {
                                                                    if (e.target.value.length <= 240) {
                                                                        setPrototypeNote(e.target.value);
                                                                    }
                                                                }}
                                                                placeholder="Add any additional instructions for the prototype video..."
                                                                style={{
                                                                    width: '100%',
                                                                    minHeight: '80px',
                                                                    padding: '8px',
                                                                    backgroundColor: darkBg,
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    color: darkText,
                                                                    fontSize: '12px',
                                                                    fontFamily: 'monospace',
                                                                    resize: 'vertical',
                                                                }}
                                                            />
                                                            <div style={{ marginTop: '4px', fontSize: '11px', color: '#999', textAlign: 'right' }}>
                                                                {prototypeNote.length}/240 characters
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {/* Section B: Costs & Policy */}
                                                    {selectedBidId && (() => {
                                                        const selectedBid = itemState.bids.find(b => b.bid_id === selectedBidId);
                                                        const prototypeCost = selectedBid?.prototype_cost || null;
                                                        const cadFee = 60.00;
                                                        const totalDue = cadFee + (prototypeCost ? parseFloat(prototypeCost) : 0);
                                                        
                                                        return (
                                                            <div style={{ marginBottom: '24px', padding: '16px', backgroundColor: '#1a1a1a', border: `1px solid ${darkBorder}`, borderRadius: '4px' }}>
                                                                <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '12px', color: darkText }}>
                                                                    Costs & Policy
                                                                </div>
                                                                <div style={{ fontSize: '12px', color: darkText, lineHeight: '1.8' }}>
                                                                    <div style={{ marginBottom: '8px' }}>
                                                                        <strong>CAD Drafting Fee:</strong> $60.00
                                                                    </div>
                                                                    <div style={{ marginBottom: '8px' }}>
                                                                        <strong>CAD Revisions Included:</strong> Up to 3 rounds
                                                                    </div>
                                                                    <div style={{ marginBottom: '8px' }}>
                                                                        <strong>Additional CAD Revisions:</strong> $25.00 per round (round 4+)
                                                                    </div>
                                                                    <div style={{ marginBottom: '8px' }}>
                                                                        <strong>Prototype video cost:</strong> {prototypeCost ? `$${parseFloat(prototypeCost).toFixed(2)}` : 'Estimate not provided'}
                                                                    </div>
                                                                    <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}`, fontSize: '13px', fontWeight: '600', color: greenAccent }}>
                                                                        Total due now: CAD ($60.00) + Prototype Video ({prototypeCost ? `$${parseFloat(prototypeCost).toFixed(2)}` : '$0.00'}) = ${totalDue.toFixed(2)}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        );
                                                    })()}

                                                    {/* Section C: Payment Instructions */}
                                                    {selectedBidId && (
                                                        <div style={{ marginBottom: '24px', padding: '16px', backgroundColor: '#1a1a1a', border: `1px solid ${darkBorder}`, borderRadius: '4px' }}>
                                                            <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '12px', color: darkText }}>
                                                                WireFrameOS Payment Details
                                                            </div>
                                                            <div style={{ fontSize: '12px', color: darkText, lineHeight: '1.8', marginBottom: '12px' }}>
                                                                <div style={{ marginBottom: '8px' }}>
                                                                    <strong>ACH / Wire Instructions:</strong><br />
                                                                    Bank: [Bank Name]<br />
                                                                    Account Number: [Account Number]<br />
                                                                    Routing Number: [Routing Number]<br />
                                                                    Account Name: WireFrameOS
                                                                </div>
                                                                <div style={{ marginBottom: '8px' }}>
                                                                    <strong>Zelle Instructions:</strong><br />
                                                                    Email: payments@wireframeos.com<br />
                                                                    Phone: [Phone Number]
                                                                </div>
                                                                <div style={{ marginTop: '12px', padding: '8px', backgroundColor: '#2a2a2a', borderRadius: '4px', fontSize: '11px', fontFamily: 'monospace', color: greenAccent }}>
                                                                    <strong>Required Reference Line:</strong> Item #{itemId} + Bid #{selectedBidId}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {/* Submit Button */}
                                                    <button
                                                        onClick={async () => {
                                                            // Validation
                                                            if (!selectedBidId) {
                                                                setCadPrototypeError('Please select a bid.');
                                                                return;
                                                            }
                                                            
                                                            if (selectedKeywords.length < 3 || selectedKeywords.length > 7) {
                                                                setCadPrototypeError('Please select between 3 and 7 keywords.');
                                                                return;
                                                            }

                                                            setIsSubmittingCadPrototype(true);
                                                            setCadPrototypeError('');
                                                            setCadPrototypeSuccess(false);

                                                            try {
                                                                const formData = new FormData();
                                                                formData.append('action', 'n88_create_cad_prototype_request');
                                                                formData.append('item_id', itemId);
                                                                formData.append('bid_id', selectedBidId);
                                                                // Send keywords as array (endpoint expects selected_keywords[])
                                                                selectedKeywords.forEach(keywordId => {
                                                                    formData.append('selected_keywords[]', keywordId);
                                                                });
                                                                formData.append('note', prototypeNote);
                                                                // Get nonce for n88-rfq-nonce action
                                                                let submitNonce = '';
                                                                if (window.n88BoardNonce && window.n88BoardNonce.nonce) {
                                                                    submitNonce = window.n88BoardNonce.nonce;
                                                                } else if (window.n88BoardData && window.n88BoardData.nonce) {
                                                                    submitNonce = window.n88BoardData.nonce;
                                                                } else if (window.n88 && window.n88.nonce) {
                                                                    submitNonce = window.n88.nonce;
                                                                }
                                                                
                                                                if (!submitNonce) {
                                                                    setCadPrototypeError('Nonce not found. Please refresh the page and try again.');
                                                                    setIsSubmittingCadPrototype(false);
                                                                    return;
                                                                }
                                                                
                                                                formData.append('nonce', submitNonce);

                                                                const response = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                                                                    method: 'POST',
                                                                    body: formData,
                                                                });

                                                                const data = await response.json();

                                                                if (data.success) {
                                                                    setCadPrototypeSuccess(true);
                                                                    setSelectedKeywords([]);
                                                                    setPrototypeNote('');
                                                                    // Commit 2.3.9.1C: Refresh item state to show payment banner
                                                                    fetchItemState();
                                                                    // Scroll to top of form to show success message
                                                                    setTimeout(() => {
                                                                        const formElement = document.getElementById('cad-prototype-form-container');
                                                                        if (formElement) {
                                                                            formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                                                        }
                                                                    }, 100);
                                                                } else {
                                                                    setCadPrototypeError(data.data?.message || 'Failed to create request. Please try again.');
                                                                    // Scroll to top of form to show error message
                                                                    setTimeout(() => {
                                                                        const formElement = document.getElementById('cad-prototype-form-container');
                                                                        if (formElement) {
                                                                            formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                                                        }
                                                                    }, 100);
                                                                }
                                                            } catch (error) {
                                                                setCadPrototypeError('Error submitting request. Please try again.');
                                                                console.error('CAD Prototype Request Error:', error);
                                                            } finally {
                                                                setIsSubmittingCadPrototype(false);
                                                            }
                                                        }}
                                                        disabled={isSubmittingCadPrototype || !selectedBidId || selectedKeywords.length < 3 || selectedKeywords.length > 7}
                                                        style={{
                                                            width: '100%',
                                                            padding: '12px',
                                                            backgroundColor: (isSubmittingCadPrototype || !selectedBidId || selectedKeywords.length < 3 || selectedKeywords.length > 7) ? '#333' : greenAccent,
                                                            border: 'none',
                                                            borderRadius: '4px',
                                                            color: (isSubmittingCadPrototype || !selectedBidId || selectedKeywords.length < 3 || selectedKeywords.length > 7) ? '#666' : darkBg,
                                                            fontSize: '14px',
                                                            fontFamily: 'monospace',
                                                            fontWeight: '600',
                                                            cursor: (isSubmittingCadPrototype || !selectedBidId || selectedKeywords.length < 3 || selectedKeywords.length > 7) ? 'not-allowed' : 'pointer',
                                                        }}
                                                    >
                                                        {isSubmittingCadPrototype ? 'Submitting...' : 'Submit Request'}
                                                    </button>
                                                </div>
                                            )}
                                            
                                            {/* Concierge + Delivery Banner */}
                                            <div style={{
                                                marginTop: '20px',
                                                padding: '16px',
                                                backgroundColor: '#1a1a1a',
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px',
                                            }}>
                                                <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                    Proposals are in. When you're ready to move to prototypes or orders, concierge oversight is available.
                                                </div>
                                                <div style={{ fontSize: '11px', color: darkText, marginBottom: '12px', lineHeight: '1.5' }}>
                                                    Wireframe OS can also coordinate production follow-through and delivery so everything stays under one roof.
                                                </div>
                                                <button
                                                    style={{
                                                        padding: '8px 16px',
                                                        backgroundColor: greenAccent,
                                                        border: 'none',
                                                        borderRadius: '4px',
                                                        color: darkBg,
                                                        fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                        fontWeight: '600',
                                                        cursor: 'not-allowed',
                                                        opacity: 0.5,
                                                    }}
                                                    title="Concierge support activates later when you request a prototype or proceed to order. This feature is currently in development."
                                                    disabled
                                                >
                                                    Get Concierge Help
                                                </button>
                                            </div>
                                                </>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                        
                        {/* Footer - Save button and Update button - Hidden in State C (when bids exist) */}
                        {(currentState === 'A' || currentState === 'B' || (currentState === 'C' && !itemState.has_bids)) && (
                                <>
                                    {/* Warning Banner - Show if dims/qty changed after RFQ with bids */}
                                    {showWarningBanner && itemState.has_rfq && itemState.has_bids && (
                                <div style={{ 
                                            marginBottom: '16px',
                                            padding: '12px',
                                            backgroundColor: '#330000',
                                            border: '1px solid #ff0000',
                                    borderRadius: '4px',
                                    fontSize: '12px', 
                                            color: '#ff0000',
                                }}>
                                            ⚠️ Dims/Qty changed after RFQ. Existing bids may reflect previous values.
                                </div>
                                    )}
                                    
                                    {/* Redirected warning - Show if item is redirected and bids are available */}
                                    {currentState === 'C' && item.redirected && itemState.bids && itemState.bids.length > 0 && (
                                <div style={{ 
                                            marginBottom: '16px',
                                            padding: '12px',
                                            backgroundColor: '#331100',
                                            border: '1px solid #ff8800',
                                    borderRadius: '4px',
                                    fontSize: '12px',
                                            color: '#ff8800',
                                }}>
                                            ⚠️ This item has been redirected. Existing bids may reflect previous routing.
                                </div>
                                    )}
                                    
                                    {/* Commit 2.3.5.4: Removed duplicate Description heading - already shown above in State B/C section */}
                        
                                    {/* Quantity, Dimensions, and Notes for suppliers - Hidden in State C (when bids exist) */}
                                    {!itemState.has_bids && (
                                    <div style={{ marginBottom: '24px' }}>
                        <div style={{
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                            padding: '12px',
                                            backgroundColor: '#111111',
                                        }}>
                                            {/* Quantity */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Quantity
                                                </label>
                                                <input
                                                    type="number"
                                                    value={quantity}
                                                    onChange={(e) => setQuantity(e.target.value)}
                                                    min="1"
                                                    placeholder="1"
                                style={{
                                                        width: '100%',
                                    padding: '8px',
                                                        backgroundColor: darkBg,
                                                        border: `1px solid ${darkBorder}`,
                                    borderRadius: '4px',
                                                        color: darkText,
                                    fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                    }}
                                                />
                                </div>
                                            
                                            {/* Dimensions */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Dimensions (provide ideal dimensions when applicable)
                                                </label>
                                                <div style={{ display: 'grid', gridTemplateColumns: '80px 80px 80px auto', gap: '8px' }}>
                                                    <input
                                                        type="number"
                                                        value={width}
                                                        onChange={(e) => setWidth(e.target.value)}
                                                        placeholder="W"
                                                        step="0.01"
                                                        style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                        }}
                                                    />
                                                    <input
                                                        type="number"
                                                        value={depth}
                                                        onChange={(e) => setDepth(e.target.value)}
                                                        placeholder="D"
                                                        step="0.01"
                                                        style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                        }}
                                                    />
                                                    <input
                                                        type="number"
                                                        value={height}
                                                        onChange={(e) => setHeight(e.target.value)}
                                                        placeholder="H"
                                                        step="0.01"
                                                        style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                        }}
                                                    />
                                                    <select
                                                        value={unit}
                                                        onChange={(e) => setUnit(e.target.value)}
                                                        style={{
                                                            padding: '8px',
                                                            backgroundColor: darkBg,
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            color: darkText,
                                                            fontSize: '12px',
                                                            fontFamily: 'monospace',
                                                        }}
                                                    >
                                                        <option value="in">in</option>
                                                        <option value="cm">cm</option>
                                                        <option value="mm">mm</option>
                                                        <option value="m">m</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            {/* Notes for suppliers */}
                                            <div style={{ marginBottom: '0' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Notes for suppliers (optional)
                                                </label>
                                                <textarea
                                                    value={smartAlternativesNote}
                                                    onChange={(e) => {
                                                        const val = e.target.value;
                                                        if (val.length <= 240) {
                                                            setSmartAlternativesNote(val);
                                                        }
                                                    }}
                                                    placeholder="[ Add notes for suppliers ]"
                                                    maxLength={240}
                                                    style={{
                                                        width: '100%',
                                                        minHeight: '60px',
                                                        padding: '8px',
                                                        backgroundColor: darkBg,
                                                        border: `1px solid ${darkBorder}`,
                                                        borderRadius: '4px',
                                                        color: darkText,
                                                        fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                        resize: 'vertical',
                                                    }}
                                                />
                                                <div style={{ fontSize: '10px', color: '#666', marginTop: '4px' }}>
                                                    ({smartAlternativesNote.length} chars • filtered)
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    )}
                                </>
                            )}
                        
                        {/* Footer - Save button and Update button - Hidden in State C (when bids exist) */}
                        {(currentState === 'A' || currentState === 'B' || (currentState === 'C' && !itemState.has_bids)) && (
                        <div style={{
                                padding: '16px 20px',
                                borderTop: `1px solid ${darkBorder}`,
                            display: 'flex',
                                gap: '12px',
                            justifyContent: 'flex-end',
                        }}>
                            {/* Show Update button only if RFQ has already been submitted (State B or C) */}
                            {/* Update button: Only updates dimensions and quantity */}
                            {/* Also check item.has_rfq as fallback in case itemState not loaded yet */}
                            {((currentState === 'B' || currentState === 'C') || (item?.has_rfq || itemState.has_rfq)) && (
                                <button
                                    onClick={handleUpdateDimensions}
                                    disabled={isSaving || isUploadingInspiration}
                                    style={{
                                            padding: '10px 20px',
                                            backgroundColor: '#111111',
                                        border: `1px solid ${darkBorder}`,
                                        borderRadius: '4px',
                                            color: darkText,
                                            fontSize: '14px',
                                            fontFamily: 'monospace',
                                        cursor: (isSaving || isUploadingInspiration) ? 'not-allowed' : 'pointer',
                                            fontWeight: '600',
                                        opacity: (isSaving || isUploadingInspiration) ? 0.6 : 1,
                                    }}
                                >
                                        {isUploadingInspiration ? 'Uploading...' : (isSaving ? 'Updating...' : 'Update')}
                                </button>
                            )}
                            <button
                                onClick={handleSave}
                                disabled={isSaving || isUploadingInspiration}
                                style={{
                                        padding: '10px 20px',
                                        backgroundColor: greenAccent,
                                    border: 'none',
                                    borderRadius: '4px',
                                        color: darkBg,
                                        fontSize: '14px',
                                        fontFamily: 'monospace',
                                    cursor: (isSaving || isUploadingInspiration) ? 'not-allowed' : 'pointer',
                                        fontWeight: '600',
                                    opacity: (isSaving || isUploadingInspiration) ? 0.6 : 1,
                                }}
                            >
                                    {isUploadingInspiration ? 'Uploading...' : (isSaving ? 'Saving...' : 'Save for later')}
                            </button>
                        </div>
                        )}
                    </motion.div>
                    
                    {/* Image Lightbox */}
                    {lightboxImage && (
                        <div
                            style={{
                                position: 'fixed',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                backgroundColor: 'rgba(0, 0, 0, 0.95)',
                                zIndex: 1000001,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                padding: '20px',
                            }}
                            onClick={(e) => {
                                // Only close if clicking the backdrop, not the image
                                if (e.target === e.currentTarget) {
                                    setLightboxImage(null);
                                }
                            }}
                        >
                            <img
                                src={lightboxImage}
                                alt="Enlarged"
                                onClick={(e) => e.stopPropagation()}
                                style={{
                                    maxWidth: '90%',
                                    maxHeight: '90%',
                                    objectFit: 'contain',
                                    pointerEvents: 'auto',
                                }}
                            />
                            <button
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setLightboxImage(null);
                                }}
                                style={{
                                    position: 'absolute',
                                    top: '20px',
                                    right: '20px',
                                    background: 'rgba(0, 0, 0, 0.7)',
                                    border: '2px solid #fff',
                                    borderRadius: '50%',
                                    color: '#fff',
                                    fontSize: '28px',
                                    fontWeight: 'bold',
                                    cursor: 'pointer',
                                    padding: '0',
                                    width: '44px',
                                    height: '44px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    lineHeight: '1',
                                    transition: 'all 0.2s',
                                    zIndex: 1000002,
                                }}
                                onMouseOver={(e) => {
                                    e.currentTarget.style.backgroundColor = 'rgba(255, 0, 0, 0.8)';
                                    e.currentTarget.style.transform = 'scale(1.1)';
                                }}
                                onMouseOut={(e) => {
                                    e.currentTarget.style.backgroundColor = 'rgba(0, 0, 0, 0.7)';
                                    e.currentTarget.style.transform = 'scale(1)';
                                }}
                            >
                                ×
                            </button>
                        </div>
                    )}
                    
                    {/* Commit 2.3.9.1C: Payment Instructions Modal */}
                    {showPaymentInstructions && (
                        <div
                            style={{
                                position: 'fixed',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                zIndex: 1000002,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                padding: '20px',
                            }}
                            onClick={() => setShowPaymentInstructions(false)}
                        >
                            <div
                                style={{
                                    maxWidth: '600px',
                                    width: '100%',
                                    backgroundColor: darkBg,
                                    border: `2px solid ${greenAccent}`,
                                    borderRadius: '8px',
                                    padding: '24px',
                                    fontFamily: 'monospace',
                                }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <div style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                    marginBottom: '20px',
                                    borderBottom: `1px solid ${darkBorder}`,
                                    paddingBottom: '12px',
                                }}>
                                    <h2 style={{
                                        margin: 0,
                                        fontSize: '18px',
                                        fontWeight: '600',
                                        color: greenAccent,
                                    }}>
                                        Prototype & CAD Payment Instructions
                                    </h2>
                                    <button
                                        onClick={() => setShowPaymentInstructions(false)}
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            color: darkText,
                                            fontSize: '24px',
                                            cursor: 'pointer',
                                            padding: '0',
                                            width: '32px',
                                            height: '32px',
                                            display: 'flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                        }}
                                    >
                                        ×
                                    </button>
                                </div>
                                
                                <div style={{
                                    fontSize: '13px',
                                    color: darkText,
                                    lineHeight: '1.6',
                                }}>
                                    <div style={{
                                        marginBottom: '16px',
                                        padding: '12px',
                                        backgroundColor: '#111111',
                                        borderRadius: '4px',
                                        border: `1px solid ${darkBorder}`,
                                    }}>
                                        <div style={{
                                            fontSize: '14px',
                                            fontWeight: '600',
                                            color: greenAccent,
                                            marginBottom: '8px',
                                        }}>
                                            Amount Due: ${itemState.prototype_payment_total_due ? itemState.prototype_payment_total_due.toFixed(2) : '0.00'}
                                        </div>
                                    </div>
                                    
                                    <div style={{
                                        marginBottom: '16px',
                                    }}>
                                        <div style={{
                                            fontSize: '14px',
                                            fontWeight: '600',
                                            color: greenAccent,
                                            marginBottom: '8px',
                                        }}>
                                            What this covers:
                                        </div>
                                        <ul style={{
                                            margin: 0,
                                            paddingLeft: '20px',
                                            color: darkText,
                                        }}>
                                            <li>CAD drawings</li>
                                            <li>Video prototype</li>
                                        </ul>
                                    </div>
                                    
                                    <div style={{
                                        marginBottom: '16px',
                                    }}>
                                        <div style={{
                                            fontSize: '14px',
                                            fontWeight: '600',
                                            color: greenAccent,
                                            marginBottom: '8px',
                                        }}>
                                            Payment Methods Accepted:
                                        </div>
                                        <ul style={{
                                            margin: 0,
                                            paddingLeft: '20px',
                                            color: darkText,
                                        }}>
                                            <li>Wire</li>
                                            <li>ACH</li>
                                            <li>Zelle</li>
                                        </ul>
                                    </div>
                                    
                                    <div style={{
                                        marginBottom: '16px',
                                        padding: '12px',
                                        backgroundColor: '#111111',
                                        borderRadius: '4px',
                                        border: `1px solid ${darkBorder}`,
                                    }}>
                                        <div style={{
                                            fontSize: '14px',
                                            fontWeight: '600',
                                            color: greenAccent,
                                            marginBottom: '8px',
                                        }}>
                                            Instructions:
                                        </div>
                                        <ol style={{
                                            margin: 0,
                                            paddingLeft: '20px',
                                            color: darkText,
                                        }}>
                                            <li>Send payment using one of the methods above</li>
                                            <li>Include Item #{itemId} in the memo</li>
                                            <li>Once received, CAD drafting will begin automatically</li>
                                        </ol>
                                    </div>
                                    
                                    <div style={{
                                        padding: '12px',
                                        backgroundColor: '#331100',
                                        borderRadius: '4px',
                                        border: '1px solid #ff8800',
                                    }}>
                                        <div style={{
                                            fontSize: '12px',
                                            fontWeight: '600',
                                            color: '#ff8800',
                                        }}>
                                            Important:
                                        </div>
                                        <div style={{
                                            fontSize: '12px',
                                            color: '#ffaa66',
                                            marginTop: '4px',
                                        }}>
                                            Work does not begin until payment is confirmed by our team.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}
            
        </AnimatePresence>
    );
};

export default ItemDetailModal;
