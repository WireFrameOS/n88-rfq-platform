/**
 * ItemDetailModal Component
 * 
 * Designer Item Modal with Three States:
 * - State A: Before RFQ (editable)
 * - State B: RFQ Sent, Awaiting Bids (read-only)
 * - State C: Bids Received (comparison view)
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
    
    // 6-Step Timeline categories
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
        return '6-Step Timeline';
    }
    
    // Check if category matches 4-step
    if (fourStepCategories.some(cat => categoryLower.includes(cat.toLowerCase()))) {
        return '4-Step Timeline';
    }
    
    // Default to 6-step for furniture-related categories
    if (categoryLower.includes('furniture') || categoryLower.includes('sofa') || 
        categoryLower.includes('chair') || categoryLower.includes('table') ||
        categoryLower.includes('bed') || categoryLower.includes('cabinet')) {
        return '6-Step Timeline';
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
                                <span style={{ color: greenAccent }}>${bid.unit_price}</span>
                            ) : (
                                <span style={{ color: darkText }}>—</span>
                            )}
                        </div>
                    ))}
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
    });
    
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
    
    // Auto-expand bids in State C
    React.useEffect(() => {
        if (currentState === 'C' && itemState.has_bids && itemState.bids && itemState.bids.length > 0) {
            setBidsExpanded(true);
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
    
    // Prevent body scroll when modal is open
    React.useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
            // Commit 2.3.5.3: Ensure Request Quote button is visible without scrolling
            // Scroll to Request Quote section when modal opens in State A
            if (currentState === 'A') {
                setTimeout(() => {
                    const requestQuoteSection = document.getElementById('request-quote-section');
                    if (requestQuoteSection) {
                        requestQuoteSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }, 100);
            }
        } else {
            document.body.style.overflow = '';
        }
        return () => {
            document.body.style.overflow = '';
        };
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
        if (itemState.has_bids) return 'C'; // State C: Bids received
        if (itemState.has_rfq) return 'B'; // State B: RFQ sent, no bids
        return 'A'; // State A: Before RFQ
    }, [itemState]);
    
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
    
    // Handle inspiration image upload
    const handleInspirationFileChange = async (e) => {
        const files = e.target.files;
        if (!files || files.length === 0) return;
        
        // Commit 2.3.5.3: Allow both images and PDFs for inspiration section
        const validFiles = Array.from(files).filter(file => 
            file.type.startsWith('image/') || file.type === 'application/pdf'
        );
        if (validFiles.length === 0) {
            alert('Please select image or PDF files only.');
            e.target.value = '';
            return;
        }
        
        const imageFiles = validFiles.filter(file => file.type.startsWith('image/'));
        const pdfFiles = validFiles.filter(file => file.type === 'application/pdf');
        
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
                    
                    {/* Modal */}
                    <motion.div
                        initial={{ x: '100%' }}
                        animate={{ x: 0 }}
                        exit={{ x: '100%' }}
                        transition={{ type: 'spring', damping: 25, stiffness: 200 }}
                        style={{
                            position: 'fixed',
                            top: 0,
                            right: 0,
                            width: '600px',
                            maxWidth: '90vw',
                            height: '100vh',
                            backgroundColor: darkBg,
                            color: darkText,
                            fontFamily: 'monospace',
                            zIndex: 1000000,
                            display: 'flex',
                            flexDirection: 'column',
                            overflow: 'hidden',
                            borderLeft: `1px solid ${darkBorder}`,
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Scrollable Content */}
                        <div style={{
                            flex: 1,
                            overflowY: 'auto',
                            padding: '20px',
                        }}>
                            {/* Header with Title and Close Button */}
                            <div style={{
                                marginBottom: '24px',
                            }}>
                                {/* Header Row: Item Detail (left) and Close Button (right) */}
                                <div style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                                    marginBottom: '12px',
                        }}>
                                    <div style={{ 
                                        fontSize: '16px', 
                                        fontWeight: '600',
                                        color: darkText,
                                        fontFamily: 'monospace',
                                    }}>
                                        Item Detail
                                    </div>
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
                        </div>
                        
                                {/* RFQ Sent Status Indicator (State B Only) */}
                                {currentState === 'B' && (
                                <div style={{
                                    marginBottom: '24px',
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
                        
                                {/* Commit 2.3.5.3: Field Order - 1. Item Title */}
                                {/* Item title - Hidden in State C (shown above bid matrix instead) */}
                                {currentState !== 'C' && (
                                <div style={{ fontSize: '16px', fontWeight: '600', marginBottom: '24px' }}>
                                    {item.title || item.description || 'Untitled Item'}
                                </div>
                                )}
                                
                                {/* Commit 2.3.5.3: Field Order - 2. Images (Main + Inspiration/References) */}
                                {/* Images section - Hidden in State C (only bid tab shown) */}
                                {currentState !== 'C' && (
                                <div style={{ marginBottom: '24px' }}>
                                    {/* Main Image */}
                                    {(item.imageUrl || item.image_url || item.primary_image_url) && (
                                        <div style={{ marginBottom: '16px' }}>
                                            <div style={{
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px', 
                                                padding: '12px',
                                                backgroundColor: '#111111',
                                                minHeight: '150px',
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
                                                        maxHeight: '250px',
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
                                                accept="image/*,.pdf,application/pdf"
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
                                )}
                                
                            </div>
                                
                            {/* Commit 2.3.5.3: Field Order - Editable fields (State A only) */}
                            {/* Editable fields section - Hidden in State C (only bid tab shown) */}
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
                                </>
                            )}
                            
                            {/* Removed SECTION: Item Facts - all fields moved to Request Quote box */}
                                
                            {/* IMAGES Section - Removed in State C (images already shown at top) */}
                            
                            {/* Commit 2.3.6: BIDS Section - Read-only Matrix View */}
                            {/* In State C, show only bid tab - hide all other content */}
                            {itemState.has_bids && itemState.bids && itemState.bids.length > 0 && (
                            <div 
                                style={{ marginBottom: '24px' }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                {/* Item Context Header - Show above matrix in State C */}
                                {currentState === 'C' && (
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
                                )}
                                
                                <div
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        cursor: 'pointer',
                                        marginBottom: bidsExpanded ? '12px' : '0',
                                    }}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        setBidsExpanded(!bidsExpanded);
                                    }}
                                >
                                    <div style={{ fontSize: '14px', fontWeight: '600' }}>
                                        BIDS
                                    </div>
                                    <span 
                                        style={{ fontSize: '12px', color: darkText, cursor: 'pointer' }}
                                        onClick={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setBidsExpanded(!bidsExpanded);
                                        }}
                                    >
                                        {bidsExpanded ? '▼' : '▶'}
                                    </span>
                                </div>
                            
                                {!bidsExpanded && (
                                    <div style={{ fontSize: '12px', color: darkText, marginTop: '8px' }}>
                                        {`${itemState.bids.length} bid${itemState.bids.length !== 1 ? 's' : ''} received`}
                                    </div>
                                )}
                                
                                {/* Commit 2.3.6: Expanded BIDS Matrix View */}
                                {bidsExpanded && (
                                    <>
                                        <BidComparisonMatrix 
                                            bids={itemState.bids}
                                            darkBorder={darkBorder}
                                            greenAccent={greenAccent}
                                            darkText={darkText}
                                            darkBg={darkBg}
                                            onImageClick={setLightboxImage}
                                            smartAlternativesEnabled={smartAlternativesEnabled}
                                        />
                                        
                                        {/* Commit 2.3.6: Concierge + Delivery Banner */}
                                        <div style={{
                                            marginTop: '20px',
                                            padding: '16px',
                                            backgroundColor: '#1a1a1a',
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                        }}>
                                            <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '8px', color: darkText }}>
                                                Bids are in. When you're ready to move to prototypes or orders, concierge oversight is available.
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
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    e.stopPropagation();
                                                    // Commit 2.3.6: Visual only - no action (non-functional in this commit)
                                                }}
                                                disabled
                                            >
                                                Get Concierge Help
                                            </button>
                                        </div>
                                    </>
                                )}
                            </div>
                            )}
                            
                            {/* Request Quote Button / RFQ Form / Specs Updated Panel */}
                            {/* Commit 2.3.5.3: Ensure Request Quote is visible without scrolling */}
                            {/* Request Quote section - Hidden in State C (only bid tab shown) */}
                            {/* D5: Show Specs Updated panel if revision_changed and has_rfq, otherwise show RFQ form */}
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
                                                Suppliers have been notified to update bids to match the new specs.
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
                                                                        Current Bids (Revision {currentRevision || 'N/A'})
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
                                                                    Waiting for updated bids (Revision {currentRevision || 'N/A'})
                                                                </div>
                                                            )}
                                                            
                                                            {/* Outdated Bids */}
                                                            {outdatedBids.length > 0 && (
                                                                <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: `1px solid ${darkBorder}` }}>
                                                                    <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px', color: '#999' }}>
                                                                        Outdated Bids (previous specs)
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
                                                    accept="image/*,.pdf,application/pdf"
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
                                </div>
                                    )}
                            </div>
                            )}
                            
                            {/* State B and C: Description and editable fields (images shown at top) */}
                            {/* State B/C section - Hidden in State C (only bid tab shown) */}
                            {(currentState === 'B' || (currentState === 'C' && !itemState.has_bids)) && (
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
                                                    </div>
                        
                        {/* Footer - Save button - Hidden in State C (when bids exist) */}
                        {(currentState === 'A' || currentState === 'B' || (currentState === 'C' && !itemState.has_bids)) && (
                        <div style={{
                                padding: '16px 20px',
                                borderTop: `1px solid ${darkBorder}`,
                            display: 'flex',
                                gap: '12px',
                            justifyContent: 'flex-end',
                        }}>
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
                </>
            )}
        </AnimatePresence>
    );
};

export default ItemDetailModal;
