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

const itemTimelineCache = {};
const itemMessagesCache = {};
const paymentReceiptsCache = {};
const boardProjectsCache = {};
const projectRoomsCache = {};

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
 * USA door-to-door delivery cost from CBM (mirrors N88_RFQ_Pricing::calculate_usa_delivery_cost)
 * LCL (<=14.99 CBM): max(cbm, 1.5) * 390 + 350
 * FCL 20' (15–24 CBM): $4,850
 * FCL 40' HQ (>24 CBM): $5,750
 */
const calculateUsaDeliveryCostFromCbm = (totalCbm) => {
    if (totalCbm == null || totalCbm === '' || isNaN(parseFloat(totalCbm))) return null;
    const cbm = parseFloat(totalCbm);
    if (cbm <= 0) return null;
    if (cbm <= 14.99) {
        const billable = Math.max(cbm, 1.5);
        return Math.round((billable * 390 + 350) * 100) / 100;
    }
    if (cbm >= 15 && cbm <= 24) return 4850;
    return 5750;
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
    
    // Project Workflow categories
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
        return 'Project Workflow';
    }
    
    // Check if category matches 4-step
    if (fourStepCategories.some(cat => categoryLower.includes(cat.toLowerCase()))) {
        return '4-Step Timeline';
    }
    
    // Default to 6-step for furniture-related categories
    if (categoryLower.includes('furniture') || categoryLower.includes('sofa') || 
        categoryLower.includes('chair') || categoryLower.includes('table') ||
        categoryLower.includes('bed') || categoryLower.includes('cabinet')) {
        return 'Project Workflow';
    }
    
    // Default to 4-step for sourcing categories
    return '4-Step Timeline';
};

/**
 * Convert video watch URL to embed URL for YouTube, Vimeo, Loom (for on-screen inline play)
 */
const getVideoEmbedUrl = (url, provider) => {
    if (!url || typeof url !== 'string') return null;
    const u = url.trim();
    try {
        const prov = (provider || '').toLowerCase();
        if (prov.includes('youtube') || u.includes('youtube.com') || u.includes('youtu.be')) {
            const re = new RegExp('(?:youtube\\.com/watch\\?v=|youtu\\.be/)([^&\\s?]+)');
            const m = u.match(re);
            if (m && m[1]) return 'https://www.youtube.com/embed/' + m[1];
        }
        if (prov.includes('vimeo') || u.includes('vimeo.com')) {
            const re = new RegExp('vimeo\\.com/(?:video/)?(\\d+)');
            const m = u.match(re);
            if (m && m[1]) return 'https://player.vimeo.com/video/' + m[1];
        }
        if (prov.includes('loom') || u.includes('loom.com')) {
            const re = new RegExp('loom\\.com/share/([^?\\s]+)');
            const m = u.match(re);
            if (m && m[1]) return 'https://www.loom.com/embed/' + m[1];
            if (u.includes('/embed/')) return u;
        }
    } catch (_) {}
    return null;
};

/**
 * Detect phone numbers, emails, or personal-contact keywords in Request Changes feedback text.
 * Returns { blocked: true, message: string } if detected, else { blocked: false }.
 */
const detectPersonalInfoInFeedback = (text) => {
    if (!text || typeof text !== 'string') return { blocked: false };
    const t = text.trim();
    if (!t.length) return { blocked: false };
    try {
        // Email
        const emailRe = new RegExp('[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}');
        if (emailRe.test(t)) return { blocked: true, message: 'Please remove email addresses. Do not share personal contact info in feedback.' };
        // Phone: (123) 456-7890, 123-456-7890, 123.456.7890, 10+ digits, +1 234 567 8900
        const phoneRe = new RegExp('(?:\\+?\\d{1,3}[-.\\s]?)?\\(?\\d{3}\\)?[-.\\s]?\\d{3}[-.\\s]?\\d{4}|\\d{10,}');
        if (phoneRe.test(t)) return { blocked: true, message: 'Please remove phone numbers. Do not share personal contact info in feedback.' };
        // Keywords suggesting personal contact
        const lower = t.toLowerCase();
        const keywords = ['email me', 'call me', 'text me', 'whatsapp', 'wechat', 'skype', 'my number', 'my email', 'reach me at', 'contact me at', 'send to @', 'dm me', 'message me at'];
        for (let i = 0; i < keywords.length; i++) {
            if (lower.indexOf(keywords[i]) !== -1) {
                return { blocked: true, message: 'Please remove personal contact phrases (e.g. "email me", "call me"). Do not share contact info in feedback.' };
            }
        }
    } catch (_) {}
    return { blocked: false };
};

/**
 * Format workflow date/time for display: "February 26th 2026 1:53:43 PM"
 */
const formatWorkflowDateTime = (dateStr) => {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return String(dateStr);
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const day = d.getDate();
    const ord = (day % 10 === 1 && day % 100 !== 11) ? 'st' : (day % 10 === 2 && day % 100 !== 12) ? 'nd' : (day % 10 === 3 && day % 100 !== 13) ? 'rd' : 'th';
    const month = months[d.getMonth()];
    const year = d.getFullYear();
    let h = d.getHours();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const min = String(d.getMinutes()).padStart(2, '0');
    const sec = String(d.getSeconds()).padStart(2, '0');
    return `${month} ${day}${ord} ${year} ${h}:${min}:${sec} ${ampm}`;
};

/**
 * Bid Comparison Component - Shows single bid box or comparison table
 * Single bid: Compact box with all details (no CAD)
 * Multiple bids: Comparison table with 3 columns (Supplier A, B, C)
 */
const BidComparisonMatrix = ({ bids, darkBorder, greenAccent, darkText, darkBg, onImageClick, smartAlternativesEnabled = false, itemQuantity: propItemQuantity = null, itemCbm = null, itemDimsCm = null }) => {
    const prototypeHelperText = 'For your convenience and to save time and money we offer you the option to have a prototype video of the piece you are asking about. If you still need to see a physical product you can choose to do so after the video of the prototype.';
    // Compute CBM from dimensions when not available (per-unit CBM for prototype delivery)
    const effectiveItemCbm = React.useMemo(() => {
        if (itemCbm != null && itemCbm !== '' && !isNaN(parseFloat(itemCbm))) return parseFloat(itemCbm);
        const dims = itemDimsCm || (bids && bids[0] && bids[0].item_dims_cm) || null;
        if (!dims) return null;
        const w = dims.w_cm ?? dims.w;
        const d = dims.d_cm ?? dims.d;
        const h = dims.h_cm ?? dims.h;
        if (w != null && d != null && h != null && !isNaN(w) && !isNaN(d) && !isNaN(h)) return calculateCBM(w, d, h);
        return null;
    }, [itemCbm, itemDimsCm, bids]);
    // Per-unit CBM for Prototype Delivery (1 unit). When we have only itemCbm and qty > 1, backend may send total CBM so we divide.
    const perUnitCbmForPrototype = React.useMemo(() => {
        if (effectiveItemCbm == null || isNaN(effectiveItemCbm)) return null;
        const qty = Math.max(1, Number(propItemQuantity) || 1);
        const dims = itemDimsCm || (bids && bids[0] && bids[0].item_dims_cm) || null;
        const fromDims = dims && (dims.w_cm ?? dims.w) != null && (dims.d_cm ?? dims.d) != null && (dims.h_cm ?? dims.h) != null;
        if (fromDims) return effectiveItemCbm;
        return effectiveItemCbm / qty;
    }, [effectiveItemCbm, propItemQuantity, itemDimsCm, bids]);
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

    // Helper to render only video links for "Media links" row
    const renderMediaLinks = (bid, compact = false) => {
        const videoLinksByProvider = bid.video_links_by_provider || { youtube: [], vimeo: [], loom: [] };
        const allVideos = [
            ...(videoLinksByProvider.youtube || []).map(u => ({ provider: 'YouTube', url: u })),
            ...(videoLinksByProvider.vimeo || []).map(u => ({ provider: 'Vimeo', url: u })),
            ...(videoLinksByProvider.loom || []).map(u => ({ provider: 'Loom', url: u })),
        ].slice(0, 3);
        if (allVideos.length === 0) return null;
        return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: compact ? '2px' : '4px' }}>
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
        );
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
        const mediaLinks = renderMediaLinks(bid, true);
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
        
        const labelWidth = '260px';
        const sectionHeaderStyle = {
            padding: '10px 12px',
            borderBottom: `1px solid ${darkBorder}`,
            fontSize: '14px',
            fontWeight: '600',
            color: darkText,
            backgroundColor: '#0a0a0a',
            textTransform: 'uppercase',
            letterSpacing: '0.5px',
        };
        const rowLabelStyle = {
            padding: '8px 12px',
            borderBottom: `1px solid ${darkBorder}`,
            fontSize: '14px',
            color: darkText,
            backgroundColor: '#111111',
            opacity: 0.95,
            borderRight: `1px solid ${darkBorder}`,
            width: labelWidth,
            whiteSpace: 'nowrap',
        };
        const rowValueStyle = {
            padding: '8px 12px',
            borderBottom: `1px solid ${darkBorder}`,
            fontSize: '16px',
            color: greenAccent,
        };
        const hasDelivery = bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)));
        const prototypeDeliveryAmount = (perUnitCbmForPrototype != null && !isNaN(perUnitCbmForPrototype)) ? calculateUsaDeliveryCostFromCbm(perUnitCbmForPrototype) : null;
        const hasPrototypeCredit = bid.prototype_cost != null && bid.prototype_cost !== '' && (parseFloat(bid.prototype_cost) > 0);
        const prototypeCreditAmount = hasPrototypeCredit ? parseFloat(bid.prototype_cost) * 0.5 : 0;
        const physicalPrototypeCost = (bid.prototype_cost != null && bid.prototype_cost !== '' && !isNaN(parseFloat(bid.prototype_cost))) ? parseFloat(bid.prototype_cost) * 1.5 : null;
        const qty = bid.item_quantity || propItemQuantity || 1;
        const unitPrice = bid.unit_price != null ? (typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)) : null;
        const totalProductionCost = unitPrice != null ? (bid.total_price != null ? parseFloat(bid.total_price) : unitPrice * qty) : null;
        const deliveryCost = hasDelivery ? parseFloat(bid.delivery_cost_usd) : 0;
        const totalDeliveredCost = totalProductionCost != null ? totalProductionCost + deliveryCost : null;
        const finalOrderTotal = totalDeliveredCost != null && hasPrototypeCredit ? totalDeliveredCost - prototypeCreditAmount : totalDeliveredCost;
        
        return (
            <div style={{
                border: `1px solid ${darkBorder}`,
                borderRadius: '4px',
                backgroundColor: '#111111',
                overflow: 'hidden',
            }}>
                <div style={{ padding: '12px', borderBottom: `1px solid ${darkBorder}` }}>
                    <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '4px', color: darkText }}>Supplier A</div>
                    <div style={{ fontSize: '10px', color: '#FF0065', lineHeight: 1.4 }}>{prototypeHelperText}</div>
                </div>
                <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
                    <colgroup><col style={{ width: labelWidth }} /><col style={{ width: 'auto' }} /></colgroup>
                    <tbody>
                        {/* CAPABILITY */}
                        <tr>
                            <td colSpan={2} style={{ ...sectionHeaderStyle }}>Capability</td>
                        </tr>
                        {mediaLinks && (
                            <tr>
                                <td style={rowLabelStyle}>Reference Video</td>
                                <td style={rowValueStyle}>{mediaLinks}</td>
                            </tr>
                        )}
                        {(bid.photo_urls && bid.photo_urls.length > 0) && (
                            <tr>
                                <td style={rowLabelStyle}>Similar Project Photos</td>
                                <td style={rowValueStyle}>
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                                        {bid.photo_urls.slice(0, 6).map((url, i) => (
                                            <img key={i} src={url} alt="" onClick={(e) => { e.stopPropagation(); window.open(url, '_blank'); }}
                                                style={{ width: '40px', height: '40px', objectFit: 'cover', cursor: 'pointer', border: `1px solid ${darkBorder}`, borderRadius: '2px' }} />
                                        ))}
                                    </div>
                                </td>
                            </tr>
                        )}
                        {/* EVALUATION OPTIONS */}
                        <tr>
                            <td colSpan={2} style={{ ...sectionHeaderStyle }}>Evaluation Options</td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Evaluation Video Cost</td>
                            <td style={rowValueStyle}>
                                {bid.prototype_cost != null && bid.prototype_cost !== '' ? `$${parseFloat(bid.prototype_cost).toFixed(2)}` : '—'}
                            </td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Evaluation Video Timeline</td>
                            <td style={rowValueStyle}>{bid.prototype_timeline || '—'}</td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Physical Prototype Cost</td>
                            <td style={rowValueStyle}>
                                {physicalPrototypeCost != null ? `$${physicalPrototypeCost.toFixed(2)}` : '—'}
                            </td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Prototype Delivery</td>
                            <td style={rowValueStyle}>
                                {prototypeDeliveryAmount != null ? `$${prototypeDeliveryAmount.toFixed(2)}` : '—'}
                            </td>
                        </tr>
                        {/* PRODUCTION */}
                        <tr>
                            <td colSpan={2} style={{ ...sectionHeaderStyle }}>Production</td>
                        </tr>
                        {bid.production_lead_time && (
                            <tr>
                                <td style={rowLabelStyle}>Production Lead Time</td>
                                <td style={rowValueStyle}>{bid.production_lead_time}</td>
                            </tr>
                        )}
                        {bid.unit_price !== null && (
                            <tr>
                                <td style={rowLabelStyle}>Unit Price</td>
                                <td style={rowValueStyle}>
                                    ${(typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)).toFixed(2)}
                                    {bid.total_price && bid.item_quantity > 1 && (
                                        <span style={{ fontSize: '14px', color: darkText, marginLeft: '8px', opacity: 0.8 }}>
                                            (Total: ${parseFloat(bid.total_price).toFixed(2)})
                                        </span>
                                    )}
                                </td>
                            </tr>
                        )}
                        {/* LOGISTICS */}
                        <tr>
                            <td colSpan={2} style={{ ...sectionHeaderStyle }}>Logistics</td>
                        </tr>
                        {hasDelivery && (
                            <tr>
                                <td style={rowLabelStyle}>Door-to-Door Delivery</td>
                                <td style={rowValueStyle}>
                                    {qty > 1
                                        ? `$${(parseFloat(bid.delivery_cost_usd) / qty).toFixed(2)} per unit (${qty} × $${(parseFloat(bid.delivery_cost_usd) / qty).toFixed(2)} = $${parseFloat(bid.delivery_cost_usd).toFixed(2)})`
                                        : `$${parseFloat(bid.delivery_cost_usd).toFixed(2)}`}
                                    {bid.delivery_shipping_mode && (
                                        <span style={{ fontSize: '14px', color: darkText, marginLeft: '8px', opacity: 0.7 }}>
                                            {bid.delivery_shipping_mode === 'LCL' ? 'LCL' : bid.delivery_shipping_mode === 'FCL_20' ? '20\' Container' : bid.delivery_shipping_mode === 'FCL_40HQ' ? '40\' HQ' : bid.delivery_shipping_mode}
                                        </span>
                                    )}
                                </td>
                            </tr>
                        )}
                        {/* ORDER TOTAL */}
                        <tr>
                            <td colSpan={2} style={{ ...sectionHeaderStyle }}>Order Total</td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Total Production Cost</td>
                            <td style={rowValueStyle}>{totalProductionCost != null ? `$${totalProductionCost.toFixed(2)}` : '—'}</td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Total Delivered Cost</td>
                            <td style={rowValueStyle}>{totalDeliveredCost != null ? `$${totalDeliveredCost.toFixed(2)}` : '—'}</td>
                        </tr>
                        <tr>
                            <td style={rowLabelStyle}>Prototype Credit Applied</td>
                            <td style={rowValueStyle}>{hasPrototypeCredit ? `$${prototypeCreditAmount.toFixed(2)}` : '—'}</td>
                        </tr>
                        <tr>
                            <td style={{ ...rowLabelStyle, fontWeight: '600' }}>Final Order Total</td>
                            <td style={{ ...rowValueStyle, fontWeight: '600' }}>{finalOrderTotal != null ? `$${finalOrderTotal.toFixed(2)}` : '—'}</td>
                        </tr>
                    </tbody>
                </table>
                {hasSmartAltContent && (
                        <div style={{ padding: '12px', borderTop: `1px solid ${darkBorder}` }}>
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
        );
    }

    // Multiple bids: Show comparison table with 3 columns
    const maxBids = Math.min(orderedBids.length, 3);
    const displayBids = orderedBids.slice(0, maxBids);
    const labelWidth = '260px';
    const valueCols = `repeat(${maxBids}, minmax(100px, 140px))`;

    return (
        <div style={{
            border: `1px solid ${darkBorder}`,
            borderRadius: '4px',
            overflow: 'hidden',
            backgroundColor: '#111111',
        }}>
            {/* Helper text about prototype video */}
            <div style={{
                padding: '8px 10px 0',
                fontSize: '10px',
                color: '#FF0065',
                lineHeight: 1.4,
            }}>
                {prototypeHelperText}
            </div>

            {/* Table Header */}
            <div style={{
                display: 'grid',
                gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                {/* CAPABILITY section header */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        whiteSpace: 'nowrap',
                    }}>
                        Capability
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div key={`cap-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', backgroundColor: '#0a0a0a' }} />
                    ))}
                </div>
                {/* Reference Video Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Reference Video
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`media-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                display: 'flex',
                                justifyContent: 'center',
                                alignItems: 'center',
                            }}
                        >
                            {renderMediaLinks(bid, true) || <span style={{ color: darkText }}>—</span>}
                        </div>
                    ))}
                </div>

                {/* Similar Project Photos Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Similar Project Photos
                    </div>
                    {displayBids.map((bid, idx) => {
                        const photos = bid.photo_urls || [];
                        return (
                            <div
                                key={`photos-${bid.bid_id}`}
                                style={{
                                    padding: '6px 10px',
                                    borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                    fontSize: '10px',
                                    display: 'flex',
                                    justifyContent: 'center',
                                    alignItems: 'center',
                                }}
                            >
                                {photos.length > 0 ? (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                                        {photos.slice(0, 3).map((url, i) => (
                                            <img
                                                key={i}
                                                src={url}
                                                alt=""
                                                onClick={(e) => { e.stopPropagation(); window.open(url, '_blank'); }}
                                                style={{ width: '28px', height: '28px', objectFit: 'cover', cursor: 'pointer', border: `1px solid ${darkBorder}`, borderRadius: '2px' }}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <span style={{ color: darkText }}>—</span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* EVALUATION OPTIONS section header */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        whiteSpace: 'nowrap',
                    }}>
                        Evaluation Options
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div key={`eval-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', backgroundColor: '#0a0a0a' }} />
                    ))}
                </div>
                {/* Evaluation Video Cost Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Evaluation Video Cost
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`prototype-cost-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                display: 'flex',
                                justifyContent: 'center',
                                alignItems: 'center',
                                textAlign: 'center',
                            }}
                        >
                            {bid.prototype_cost != null && bid.prototype_cost !== '' ? (
                                <span style={{ color: greenAccent }}>${(typeof bid.prototype_cost === 'number' ? bid.prototype_cost : parseFloat(bid.prototype_cost)).toFixed(2)}</span>
                            ) : (
                                <span style={{ color: darkText }}>—</span>
                            )}
                        </div>
                    ))}
                </div>

                {/* Evaluation Video Timeline Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Evaluation Video Timeline
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div
                            key={`prototype-timeline-${bid.bid_id}`}
                            style={{
                                padding: '6px 10px',
                                borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                fontSize: '10px',
                                display: 'flex',
                                justifyContent: 'center',
                                alignItems: 'center',
                                textAlign: 'center',
                            }}
                        >
                            {bid.prototype_timeline ? (
                                <span style={{ color: greenAccent }}>{bid.prototype_timeline}</span>
                            ) : (
                                <span style={{ color: darkText }}>—</span>
                            )}
                        </div>
                    ))}
                </div>

                {/* Physical Prototype Cost Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Physical Prototype Cost
                    </div>
                    {displayBids.map((bid, idx) => {
                        const physCost = (bid.prototype_cost != null && bid.prototype_cost !== '' && !isNaN(parseFloat(bid.prototype_cost))) ? parseFloat(bid.prototype_cost) * 1.5 : null;
                        return (
                            <div
                                key={`proto-cost-${bid.bid_id}`}
                                style={{
                                    padding: '6px 10px',
                                    borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                    fontSize: '10px',
                                    textAlign: 'center',
                                }}
                            >
                                {physCost != null ? <span style={{ color: greenAccent }}>${physCost.toFixed(2)}</span> : <span style={{ color: darkText }}>—</span>}
                            </div>
                        );
                    })}
                </div>

                {/* Prototype Delivery Row (via CBM - 1 unit when itemCbm available, else full order delivery) */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Prototype Delivery
                    </div>
                    {displayBids.map((bid, idx) => {
                        const protoDelivery = (perUnitCbmForPrototype != null && !isNaN(perUnitCbmForPrototype)) ? calculateUsaDeliveryCostFromCbm(perUnitCbmForPrototype) : null;
                        return (
                            <div
                                key={`proto-delivery-${bid.bid_id}`}
                                style={{
                                    padding: '6px 10px',
                                    borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none',
                                    fontSize: '10px',
                                    textAlign: 'center',
                                }}
                            >
                                <span style={{ color: protoDelivery != null ? greenAccent : darkText }}>
                                    {protoDelivery != null ? `$${protoDelivery.toFixed(2)}` : '—'}
                                </span>
                            </div>
                        );
                    })}
                </div>

                {/* PRODUCTION section header */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        whiteSpace: 'nowrap',
                    }}>
                        Production
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div key={`prod-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', backgroundColor: '#0a0a0a' }} />
                    ))}
                </div>
                {/* Production Lead Time Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Production Lead Time
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
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
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
                                    <span style={{ color: greenAccent }}>${(typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)).toFixed(2)}</span>
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

                {/* LOGISTICS section header */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        whiteSpace: 'nowrap',
                    }}>
                        Logistics
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div key={`log-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', backgroundColor: '#0a0a0a' }} />
                    ))}
                </div>
                {/* Door-to-Door Delivery Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Door-to-Door Delivery
                    </div>
                    {displayBids.map((bid, idx) => {
                        const hasDelivery = bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)));
                        const totalDeliv = hasDelivery ? parseFloat(bid.delivery_cost_usd) : null;
                        const qtyD = bid.item_quantity || 1;
                        const perUnitD = totalDeliv != null && qtyD > 0 ? totalDeliv / qtyD : totalDeliv;
                        const doorText = totalDeliv != null
                            ? (qtyD > 1
                                ? `$${perUnitD.toFixed(2)} per unit (${qtyD} × $${perUnitD.toFixed(2)} = $${totalDeliv.toFixed(2)})`
                                : `$${totalDeliv.toFixed(2)}`)
                            : '—';
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
                                        <span style={{ color: greenAccent }}>{doorText}</span>
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

                {/* ORDER TOTAL section header */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        whiteSpace: 'nowrap',
                    }}>
                        Order Total
                    </div>
                    {displayBids.map((bid, idx) => (
                        <div key={`order-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', backgroundColor: '#0a0a0a' }} />
                    ))}
                </div>
                {/* Total Production Cost Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Total Production Cost
                    </div>
                    {displayBids.map((bid, idx) => {
                        const qty = bid.item_quantity || propItemQuantity || 1;
                        const unitPrice = bid.unit_price != null ? (typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)) : null;
                        const totalProductionCost = unitPrice != null ? (bid.total_price != null ? parseFloat(bid.total_price) : unitPrice * qty) : null;
                        return (
                            <div key={`total-prod-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', fontSize: '10px', textAlign: 'center' }}>
                                {totalProductionCost != null ? <span style={{ color: greenAccent }}>${totalProductionCost.toFixed(2)}</span> : <span style={{ color: darkText }}>System calculated</span>}
                            </div>
                        );
                    })}
                </div>
                {/* Total Delivered Cost Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Total Delivered Cost
                    </div>
                    {displayBids.map((bid, idx) => {
                        const qty = bid.item_quantity || propItemQuantity || 1;
                        const unitPrice = bid.unit_price != null ? (typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)) : null;
                        const totalProductionCost = unitPrice != null ? (bid.total_price != null ? parseFloat(bid.total_price) : unitPrice * qty) : null;
                        const deliveryCost = (bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)))) ? parseFloat(bid.delivery_cost_usd) : 0;
                        const totalDeliveredCost = totalProductionCost != null ? totalProductionCost + deliveryCost : null;
                        return (
                            <div key={`total-deliv-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', fontSize: '10px', textAlign: 'center' }}>
                                {totalDeliveredCost != null ? <span style={{ color: greenAccent }}>${totalDeliveredCost.toFixed(2)}</span> : <span style={{ color: darkText }}>System calculated</span>}
                            </div>
                        );
                    })}
                </div>
                {/* Prototype Credit Applied Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
                    }}>
                        Prototype Credit Applied
                    </div>
                    {displayBids.map((bid, idx) => {
                        const hasPrototypeCredit = bid.prototype_cost != null && bid.prototype_cost !== '' && (parseFloat(bid.prototype_cost) > 0);
                        const creditAmount = hasPrototypeCredit ? parseFloat(bid.prototype_cost) * 0.5 : 0;
                        return (
                            <div key={`credit-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', fontSize: '10px', textAlign: 'center' }}>
                                {hasPrototypeCredit ? <span style={{ color: greenAccent }}>${creditAmount.toFixed(2)}</span> : <span style={{ color: darkText }}>—</span>}
                            </div>
                        );
                    })}
                </div>
                {/* Final Order Total Row */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
                    borderBottom: `1px solid ${darkBorder}`,
                }}>
                    <div style={{
                        padding: '6px 10px',
                        borderRight: `1px solid ${darkBorder}`,
                        fontSize: '10px',
                        fontWeight: '600',
                        color: darkText,
                        backgroundColor: '#0a0a0a',
                        display: 'flex',
                        alignItems: 'center',
                        whiteSpace: 'nowrap',
                    }}>
                        Final Order Total
                    </div>
                    {displayBids.map((bid, idx) => {
                        const qty = bid.item_quantity || propItemQuantity || 1;
                        const unitPrice = bid.unit_price != null ? (typeof bid.unit_price === 'number' ? bid.unit_price : parseFloat(bid.unit_price)) : null;
                        const totalProductionCost = unitPrice != null ? (bid.total_price != null ? parseFloat(bid.total_price) : unitPrice * qty) : null;
                        const deliveryCost = (bid.delivery_cost_usd != null && bid.delivery_cost_usd !== '' && (typeof bid.delivery_cost_usd === 'number' || !isNaN(parseFloat(bid.delivery_cost_usd)))) ? parseFloat(bid.delivery_cost_usd) : 0;
                        const totalDeliveredCost = totalProductionCost != null ? totalProductionCost + deliveryCost : null;
                        const hasPrototypeCredit = bid.prototype_cost != null && bid.prototype_cost !== '' && (parseFloat(bid.prototype_cost) > 0);
                        const prototypeCreditAmount = hasPrototypeCredit ? parseFloat(bid.prototype_cost) * 0.5 : 0;
                        const finalOrderTotal = totalDeliveredCost != null && hasPrototypeCredit ? totalDeliveredCost - prototypeCreditAmount : totalDeliveredCost;
                        return (
                            <div key={`final-${bid.bid_id}`} style={{ padding: '6px 10px', borderRight: idx < maxBids - 1 ? `1px solid ${darkBorder}` : 'none', fontSize: '10px', textAlign: 'center', fontWeight: '600' }}>
                                {finalOrderTotal != null ? <span style={{ color: greenAccent }}>${finalOrderTotal.toFixed(2)}</span> : <span style={{ color: darkText }}>System calculated</span>}
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
                            gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                            gridTemplateColumns: `${labelWidth} ${valueCols}`,
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

                {/* Commit 2.4.1: Award Bid Row (moved to bottom) */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: `${labelWidth} ${valueCols}`,
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
                        whiteSpace: 'nowrap',
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
                                        backgroundColor: 'rgba(255,0,101,0.15)',
                                        border: '1px solid #FF0065',
                                        borderRadius: '4px',
                                        color: '#FF0065',
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
                                            backgroundColor: '#FF0065',
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
                                            e.target.style.backgroundColor = '#FF0065';
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
            {/* Commit 2.6.1: Hide Award Bid button for view-only team members */}
            {!isViewOnly && bid.can_award && !bid.is_awarded && !bid.is_declined && (
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
                            backgroundColor: '#FF0065',
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
                            e.target.style.backgroundColor = '#FF0065';
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
                    backgroundColor: 'rgba(255,0,101,0.15)',
                    border: '1px solid #FF0065',
                    borderRadius: '4px',
                    fontSize: '12px',
                    color: '#FF0065',
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
const ItemDetailModal = ({ item, isOpen, onClose, onSave, boardId = null, priceRequested = false, onPriceRequest, openToDetailsAndSupport = false }) => {
    // Commit 2.6.1: Check if user is view-only team member
    const isViewOnly = window.n88BoardData?.isViewOnly || false;
    const updateLayout = useBoardStore((state) => state.updateLayout);
    const initialItemState = React.useMemo(() => ({
        has_rfq: false,
        has_bids: false,
        has_awarded_bid: false,
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
        cad_status: null,
        cad_revision_rounds_included: null,
        cad_revision_rounds_used: null,
        cad_approved_at: null,
        cad_approved_version: null,
        cad_released_to_supplier_at: null,
        cad_current_version: null,
        prototype_status: null,
        prototype_current_version: null,
        prototype_approved_version: null,
        prototype_submission: null,
        direction_keyword_ids: null,
        direction_keyword_names: null,
        workflow_milestones: null,
        payment_milestones_summary: null,
        rfq_revision_current: null,
        revision_changed: false,
        deposit_status: '',
        deposit_amount: null,
        deposit_calculated_at: null,
        deposit_received_at: null,
        deposit_receipt_url: '',
        deposit_sent_note: '',
        deposit_sent_at: null,
    }), []);
    
    // Item state (RFQ and bids)
    const [itemState, setItemState] = React.useState(initialItemState);
    const [loadedSections, setLoadedSections] = React.useState({ summary: false, bids: false, workflow: false });
    const itemStateRequestRef = React.useRef({});
    const itemStateCacheRef = React.useRef({});
    
    // Payment Instructions Modal State
    const [showPaymentInstructions, setShowPaymentInstructions] = React.useState(false);
    const [paymentReceipts, setPaymentReceipts] = React.useState([]);
    const [paymentReceiptsLoading, setPaymentReceiptsLoading] = React.useState(false);
    const [paymentReceiptUploading, setPaymentReceiptUploading] = React.useState(false);
    const [paymentReceiptSelectedFile, setPaymentReceiptSelectedFile] = React.useState(null);
    const [paymentReceiptMessage, setPaymentReceiptMessage] = React.useState('');
    const [showResubmitReceiptForm, setShowResubmitReceiptForm] = React.useState(false);
    const paymentReceiptInputRef = React.useRef(null);

    // Deposit proof modal (designer: send payment proof; same style as Payment Instructions)
    const [showDepositProofModal, setShowDepositProofModal] = React.useState(false);
    const [depositProofFile, setDepositProofFile] = React.useState(null);
    const [depositProofMessage, setDepositProofMessage] = React.useState('');
    const [depositProofUploading, setDepositProofUploading] = React.useState(false);
    const depositProofInputRef = React.useRef(null);

    // Project / Room assignment (Board Projects)
    const [projectMenuOpen, setProjectMenuOpen] = React.useState(false);
    const [boardProjects, setBoardProjects] = React.useState([]);
    const [projectRooms, setProjectRooms] = React.useState([]);
    const [projectsLoading, setProjectsLoading] = React.useState(false);
    const [roomsLoading, setRoomsLoading] = React.useState(false);
    const [assignmentSaving, setAssignmentSaving] = React.useState(false);
    const [selectedProjectId, setSelectedProjectId] = React.useState(0);
    const [selectedRoomId, setSelectedRoomId] = React.useState(0);
    const roomsFetchProjectIdRef = React.useRef(0);

    // Commit 2.3.9.2B-D: Prototype section state
    const [prototypeSectionExpanded, setPrototypeSectionExpanded] = React.useState(false);
    const [showRequestChangesModal, setShowRequestChangesModal] = React.useState(false);
    const [inlineVideoPlayer, setInlineVideoPlayer] = React.useState(null); // { url, provider } for Step 3 prototype video on-screen play
    const [hoverVideoPlayIdx, setHoverVideoPlayIdx] = React.useState(null);
    const [hoverVideoLinkIdx, setHoverVideoLinkIdx] = React.useState(null);
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
    const [preferredDeliveryDate, setPreferredDeliveryDate] = React.useState(
        item.preferred_delivery_date || item.meta?.preferred_delivery_date || ''
    );
    
    // Keywords state - load from item meta
    const [keywords, setKeywords] = React.useState(
        item.keywords || item.meta?.keywords || []
    );
    const [keywordInput, setKeywordInput] = React.useState('');
    
    // Smart Alternatives state - load from item meta (default false / no for designer request a quote)
    const [smartAlternativesEnabled, setSmartAlternativesEnabled] = React.useState(
        item.smart_alternatives !== undefined ? item.smart_alternatives : 
        (item.meta?.smart_alternatives !== undefined ? item.meta.smart_alternatives : false)
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
    
    // COMMIT 3.C.3: RFQ evidence – overall notes, fabric supplied, fabric notes
    const [rfqOverallNotes, setRfqOverallNotes] = React.useState(
        item.rfq_overall_notes ?? item.meta?.rfq_overall_notes ?? ''
    );
    const [fabricSupplied, setFabricSupplied] = React.useState(
        item.rfq_fabric_supplied_flag ?? item.meta?.rfq_fabric_supplied_flag ?? 'yes'
    );
    const [fabricNotes, setFabricNotes] = React.useState(
        item.rfq_fabric_notes ?? item.meta?.rfq_fabric_notes ?? ''
    );
    
    // RFQ form always expanded (no expand/collapse button)
    const [showRfqForm, setShowRfqForm] = React.useState(true);
    
    // COMMIT 3.C.3: Add Link URL input for RFQ Inspiration (link-only, no upload)
    const [inspirationLinkInput, setInspirationLinkInput] = React.useState('');
    
    // Invite Makers state - allow Add Item draft invites to pre-fill RFQ form
    const [invitedSuppliers, setInvitedSuppliers] = React.useState(
        Array.isArray(item.meta?.rfq_draft_invited_suppliers)
            ? item.meta.rfq_draft_invited_suppliers
            : []
    );
    const [inviteSupplierInput, setInviteSupplierInput] = React.useState('');
    const [allowSystemInvites, setAllowSystemInvites] = React.useState(
        item.meta?.rfq_draft_allow_system_invites === true ||
        item.meta?.rfq_draft_allow_system_invites === '1'
    );
    const [isSubmittingRfq, setIsSubmittingRfq] = React.useState(false);
    const [rfqError, setRfqError] = React.useState('');
    
    // BIDS section expansion state - start expanded when item has bids (Proposals received)
    const [bidsExpanded, setBidsExpanded] = React.useState(!!(item.bids && item.bids.length > 0));
    
    // CAD Prototype Request form state (Commit 2.3.9.1B)
    const [showCadPrototypeForm, setShowCadPrototypeForm] = React.useState(false);
    const [selectedBidId, setSelectedBidId] = React.useState(null);
    const [selectedKeywords, setSelectedKeywords] = React.useState([]);
    const [prototypeNote, setPrototypeNote] = React.useState('');
    const [availableKeywords, setAvailableKeywords] = React.useState([]);
    const [hasRequestedCategoryKeywords, setHasRequestedCategoryKeywords] = React.useState(false);
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
    // Commit 3.A.1: Item timeline (read-only for designer)
    const [timelineData, setTimelineData] = React.useState(null);
    const [timelineLoading, setTimelineLoading] = React.useState(false);
    const [timelineError, setTimelineError] = React.useState(null);
    const [selectedStepIndex, setSelectedStepIndex] = React.useState(0);
    const [milestoneSummaryLoading, setMilestoneSummaryLoading] = React.useState(false);
    const [milestoneDetailsByStage, setMilestoneDetailsByStage] = React.useState({});
    const [expandedMilestoneStage, setExpandedMilestoneStage] = React.useState('');
    const [milestoneSetupDraft, setMilestoneSetupDraft] = React.useState([]);
    const [milestoneSaving, setMilestoneSaving] = React.useState(false);
    const [milestoneSetupPromptOpen, setMilestoneSetupPromptOpen] = React.useState(true);
    const [milestoneSetupChoice, setMilestoneSetupChoice] = React.useState('none');
    const [milestoneEditOpen, setMilestoneEditOpen] = React.useState(true);
    const [milestoneUiNotice, setMilestoneUiNotice] = React.useState('');
    const [paymentProofFilesByStage, setPaymentProofFilesByStage] = React.useState({});
    const [paymentNotesByStage, setPaymentNotesByStage] = React.useState({});
    const [paymentMethodsByStage, setPaymentMethodsByStage] = React.useState({});
    const [revisionNotesByStage, setRevisionNotesByStage] = React.useState({});
    const [milestoneActionBusyByStage, setMilestoneActionBusyByStage] = React.useState({});
    const [milestoneActionTypeByStage, setMilestoneActionTypeByStage] = React.useState({});
    // Commit 3.A.2S: Designer view of supplier step evidence (View Step Evidence)
    const [supplierStepEvidenceView, setSupplierStepEvidenceView] = React.useState(null);
    const [supplierStepEvidenceLoading, setSupplierStepEvidenceLoading] = React.useState(false);
    // Commit 3.A.3: Evidence comment drafts and submit state
    const [evidenceCommentDrafts, setEvidenceCommentDrafts] = React.useState({});
    const [designerStep456CommentDraft, setDesignerStep456CommentDraft] = React.useState({});
    const [designerStep456CommentSubmitting, setDesignerStep456CommentSubmitting] = React.useState(false);
    const [evidenceCommentSubmitting, setEvidenceCommentSubmitting] = React.useState(false);
    // When designer requests CAD revision or approves CAD, keep tab on Mission Spec (details) instead of switching to Proposals
    const skipNextTabSwitchFromCadActionRef = React.useRef(false);
    
    // Auto-select first bid when form opens with single bid (Commit 2.3.9.1B)
    React.useEffect(() => {
        if (showCadPrototypeForm && !selectedBidId && itemState.bids && itemState.bids.length === 1) {
            setSelectedBidId(itemState.bids[0].bid_id);
        }
    }, [showCadPrototypeForm, itemState.bids, selectedBidId]);
    
    // Auto-expand bids and set tab: CAD/messages → Step 2; CAD released or video submitted → Step 3; payment approved → Step 1 (synced with admin.php)
    React.useEffect(() => {
        if (isProductionOnly) {
            setActiveTab('workflow');
            setSelectedStepIndex(2);
            return;
        }
        if (itemState.loading) return;
        if (skipNextTabSwitchFromCadActionRef.current) {
            skipNextTabSwitchFromCadActionRef.current = false;
            return;
        }
        const hasUnread = !!(itemState.has_unread_operator_messages || item?.action_required === true || item?.action_required === 'true' || item?.action_required === 1 || item?.has_unread_operator_messages === true || item?.has_unread_operator_messages === 'true' || item?.has_unread_operator_messages === 1);
        const cadSubmitted = (Number(itemState.cad_current_version) || 0) > 0;
        const cadPendingDesignerReview = !!(itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && cadSubmitted && ['uploaded', 'revision_requested'].includes(String(itemState.cad_status || '')));
        const hasActionRequired = hasUnread || cadPendingDesignerReview;
        if (cadPendingDesignerReview) {
            setActiveTab('workflow');
            setSelectedStepIndex(1); // Step 2: CAD submitted, show message box
            setShowDesignerMessageForm(true);
            loadDesignerMessages();
            const scrollToCad = () => {
                const el = document.getElementById('n88-designer-messages-container-workflow');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };
            setTimeout(scrollToCad, 400);
            return;
        }
        if (hasUnread && !cadSubmitted) {
            setActiveTab('workflow');
            setSelectedStepIndex(0); // Step 1: Clarifications before CAD submitted
            setShowDesignerMessageForm(true);
            loadDesignerMessages();
            setTimeout(() => {
                const el = document.getElementById('n88-designer-messages-container-workflow');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 400);
            return;
        }
        const cadReleasedToSupplier = !!(itemState.cad_released_to_supplier_at && String(itemState.cad_released_to_supplier_at).trim());
        const hasPrototypeVideoSubmitted = !!(itemState.prototype_submission?.links?.length) || itemState.prototype_status === 'submitted';
        if (cadReleasedToSupplier || hasPrototypeVideoSubmitted) {
            setActiveTab('workflow');
            setSelectedStepIndex(2); // Step 3: Prototype Video (operator sent CAD to supplier, or supplier submitted video)
            return;
        }
        const paymentApproved = !!(itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received');
        if (paymentApproved) {
            setActiveTab('workflow');
            setSelectedStepIndex(0); // Step 1: Payment Confirmed / CAD drafting
            return;
        }
        if (currentState === 'C' && itemState.has_bids) {
            setBidsExpanded(true);
            setActiveTab('bids');
        } else {
            setActiveTab('details');
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- loadDesignerMessages is defined later; effect correctly runs when action_required/unread/CAD-pending or itemState change
    }, [isProductionOnly, itemState.loading, currentState, itemState.has_bids, itemState.bids, itemState.has_unread_operator_messages, itemState.has_prototype_payment, itemState.prototype_payment_status, itemState.cad_current_version, itemState.cad_status, itemState.cad_released_to_supplier_at, itemState.prototype_submission, itemState.prototype_status, item?.action_required, item?.has_unread_operator_messages]);

    // When opened from card Support link: switch to Item Spec tab and open Support (messages) box
    React.useEffect(() => {
        if (isOpen && openToDetailsAndSupport) {
            setActiveTab('details');
            setShowDesignerMessageForm(true);
            loadDesignerMessages();
            const scrollToSupport = () => {
                const el = document.getElementById('n88-item-spec-support-box');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };
            setTimeout(scrollToSupport, 300);
        }
    }, [isOpen, openToDetailsAndSupport]);
    
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
    const entryModeRaw = (
        itemState?.entry_mode ||
        item?.entry_mode ||
        itemState?.meta?.entry_mode ||
        item?.meta?.entry_mode ||
        ''
    );
    const isProductionOnly = String(entryModeRaw).toLowerCase() === 'production_only';

    React.useEffect(() => {
        if (!isOpen) return;
        itemStateRequestRef.current = {};
        const cachedEntry = itemId ? itemStateCacheRef.current[itemId] : null;
        if (cachedEntry && cachedEntry.itemState) {
            setItemState(cachedEntry.itemState);
            setLoadedSections(cachedEntry.loadedSections || { summary: false, bids: false, workflow: false });
            return;
        }
        setItemState(initialItemState);
        setLoadedSections({ summary: false, bids: false, workflow: false });
    }, [isOpen, itemId, initialItemState]);
    
    // Fetch item RFQ/bid state when modal opens. When Action Required (operator sent CAD/message), do NOT collapse Review and Message so the tab effect can auto-expand it.
    React.useEffect(() => {
        if (isOpen && itemId && itemId > 0) {
            const cachedSummaryLoaded = !!itemStateCacheRef.current[itemId]?.loadedSections?.summary;
            const hasActionRequired = !!(
                item?.action_required === true || item?.action_required === 'true' || item?.action_required === 1 ||
                item?.has_unread_operator_messages === true || item?.has_unread_operator_messages === 'true' || item?.has_unread_operator_messages === 1
            );
            if (!hasActionRequired) {
                setShowDesignerMessageForm(false); // Collapse when not Action Required (CAD-pending is only known after fetch)
            }
            if (!loadedSections.summary && !cachedSummaryLoaded) {
                fetchItemState('summary');
            }
        }
    }, [isOpen, itemId, item?.action_required, item?.has_unread_operator_messages, loadedSections.summary, fetchItemState]);
    
    // Auto-expand "Payment Confirmed" / "View Prototype Videos" when supplier has submitted videos
    React.useEffect(() => {
        if (itemState.prototype_submission?.links?.length > 0) {
            setPrototypeSectionExpanded(true);
        }
    }, [itemState.prototype_submission]);
    
    // Fetch item state from server
    const fetchItemState = React.useCallback(async (requestedSection) => {
        const section = isProductionOnly
            ? 'workflow'
            : (requestedSection || (activeTab === 'workflow' ? 'workflow' : (activeTab === 'bids' ? 'bids' : 'summary')));
        if (itemStateRequestRef.current[section]) {
            return;
        }
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
            itemStateRequestRef.current[section] = true;
            const formData = new FormData();
            formData.append('action', 'n88_get_item_rfq_state');
            formData.append('item_id', String(itemId));
            formData.append('section', section);
            formData.append('_ajax_nonce', nonce);
            
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            });
            
            const data = await response.json();
            
            if (data.success) {
                const sectionFlags = data.data?.loaded_sections || {};
                const summaryLoaded = !!sectionFlags.summary || section === 'summary' || section === 'bids' || section === 'workflow';
                const bidsLoaded = !!sectionFlags.bids || section === 'bids';
                const workflowLoaded = !!sectionFlags.workflow || section === 'workflow';
                let nextLoadedSections = null;
                let nextItemState = null;

                setLoadedSections((prev) => {
                    nextLoadedSections = {
                        summary: prev.summary || summaryLoaded,
                        bids: prev.bids || bidsLoaded,
                        workflow: prev.workflow || workflowLoaded,
                    };
                    return nextLoadedSections;
                });

                setItemState((prev) => {
                    nextItemState = {
                        ...prev,
                        entry_mode: data.data.entry_mode || prev.entry_mode || (item?.meta?.entry_mode || item?.entry_mode || ''),
                        has_rfq: isProductionOnly ? false : (data.data.has_rfq || false),
                        has_bids: isProductionOnly ? false : (data.data.has_bids || false),
                        has_awarded_bid: !!data.data.has_awarded_bid,
                        bids: isProductionOnly ? [] : (bidsLoaded ? (data.data.bids || []) : prev.bids),
                        rfq_revision_current: workflowLoaded ? (data.data.rfq_revision_current || null) : prev.rfq_revision_current,
                        revision_changed: workflowLoaded ? (data.data.revision_changed || false) : prev.revision_changed,
                        has_unread_operator_messages: data.data.has_unread_operator_messages || false,
                        unread_operator_messages: data.data.unread_operator_messages || 0,
                        has_prototype_payment: data.data.has_prototype_payment || false,
                        prototype_payment_id: data.data.prototype_payment_id || null,
                        prototype_payment_bid_id: data.data.prototype_payment_bid_id || null,
                        prototype_payment_supplier_id: data.data.prototype_payment_supplier_id || null,
                        prototype_payment_status: data.data.prototype_payment_status || null,
                        prototype_payment_total_due: data.data.prototype_payment_total_due || null,
                        cad_status: data.data.cad_status || null,
                        cad_revision_rounds_included: (data.data.cad_revision_rounds_included !== undefined && data.data.cad_revision_rounds_included !== null) ? data.data.cad_revision_rounds_included : null,
                        cad_revision_rounds_used: (data.data.cad_revision_rounds_used !== undefined && data.data.cad_revision_rounds_used !== null) ? data.data.cad_revision_rounds_used : null,
                        cad_approved_at: data.data.cad_approved_at || null,
                        cad_approved_version: (data.data.cad_approved_version !== undefined && data.data.cad_approved_version !== null) ? data.data.cad_approved_version : null,
                        cad_released_to_supplier_at: data.data.cad_released_to_supplier_at || null,
                        cad_current_version: (data.data.cad_current_version !== undefined && data.data.cad_current_version !== null) ? data.data.cad_current_version : null,
                        prototype_status: data.data.prototype_status || null,
                        prototype_current_version: (data.data.prototype_current_version !== undefined && data.data.prototype_current_version !== null) ? data.data.prototype_current_version : null,
                        prototype_approved_version: (data.data.prototype_approved_version !== undefined && data.data.prototype_approved_version !== null) ? data.data.prototype_approved_version : null,
                        prototype_submission: workflowLoaded ? (data.data.prototype_submission || null) : prev.prototype_submission,
                        direction_keyword_ids: workflowLoaded ? (data.data.direction_keyword_ids || null) : prev.direction_keyword_ids,
                        direction_keyword_names: workflowLoaded ? (data.data.direction_keyword_names || null) : prev.direction_keyword_names,
                        workflow_milestones: workflowLoaded ? (data.data.workflow_milestones || null) : prev.workflow_milestones,
                        payment_milestones_summary: workflowLoaded ? (data.data?.validation_state?.payment_milestones || null) : prev.payment_milestones_summary,
                        deposit_status: data.data.deposit_status || '',
                        deposit_amount: data.data.deposit_amount != null ? data.data.deposit_amount : null,
                        deposit_calculated_at: data.data.deposit_calculated_at || null,
                        deposit_received_at: data.data.deposit_received_at || null,
                        deposit_receipt_url: data.data.deposit_receipt_url || '',
                        deposit_sent_note: data.data.deposit_sent_note || '',
                        deposit_sent_at: data.data.deposit_sent_at || null,
                        loading: section === 'summary' ? false : prev.loading,
                    };
                    return nextItemState;
                });

                if (itemId && nextItemState && nextLoadedSections) {
                    itemStateCacheRef.current[itemId] = {
                        itemState: nextItemState,
                        loadedSections: nextLoadedSections,
                    };
                }
                // Update board card so status progresses (CAD Requested, Awaiting payment, Payment received, Review CAD, Pending Prototype Video, etc.)
                const cardUpdates = {};
                if (data.data.cad_status !== undefined && data.data.cad_status !== null) cardUpdates.cad_status = data.data.cad_status;
                if (data.data.cad_current_version !== undefined && data.data.cad_current_version !== null) cardUpdates.cad_current_version = data.data.cad_current_version;
                if (data.data.prototype_payment_status !== undefined && data.data.prototype_payment_status !== null) cardUpdates.prototype_payment_status = data.data.prototype_payment_status;
                if (data.data.prototype_status !== undefined && data.data.prototype_status !== null) cardUpdates.prototype_status = data.data.prototype_status;
                if (data.data.has_prototype_payment !== undefined) cardUpdates.has_prototype_payment = !!data.data.has_prototype_payment;
                if (data.data.has_awarded_bid !== undefined) cardUpdates.has_awarded_bid = !!data.data.has_awarded_bid;
                if (data.data.deposit_status !== undefined) cardUpdates.deposit_status = data.data.deposit_status;
                if (data.data.has_unread_operator_messages !== undefined) cardUpdates.has_unread_operator_messages = !!data.data.has_unread_operator_messages;
                if (data.data.has_prototype_video_submitted !== undefined) cardUpdates.has_prototype_video_submitted = !!data.data.has_prototype_video_submitted;
                if (data.data.has_payment_receipt_uploaded !== undefined) cardUpdates.has_payment_receipt_uploaded = !!data.data.has_payment_receipt_uploaded;
                if (data.data.action_required !== undefined) cardUpdates.action_required = !!data.data.action_required;
                if (Object.keys(cardUpdates).length > 0 && typeof updateLayout === 'function') updateLayout(item.id, cardUpdates);
                // When all bids withdrawn: item is State A; reset Launch Brief to show "Request Quote" (fresh form)
                if (!isProductionOnly && (data.data.has_rfq === false || !data.data.has_rfq) && (data.data.has_bids === false || !data.data.has_bids)) {
                    setShowRfqForm(false);
                }
            } else {
                console.error('Failed to fetch item state:', data.message);
                if (section === 'summary') {
                    setItemState(prev => ({ ...prev, loading: false }));
                }
            }
        } catch (error) {
            console.error('Error fetching item state:', error);
            if (section === 'summary') {
                setItemState(prev => ({ ...prev, loading: false }));
            }
        } finally {
            delete itemStateRequestRef.current[section];
        }
    }, [activeTab, isProductionOnly, itemId, item?.id, updateLayout]);

    React.useEffect(() => {
        if (!isOpen || !itemId || itemId <= 0) return;
        const cachedBidsLoaded = !!itemStateCacheRef.current[itemId]?.loadedSections?.bids;
        if (activeTab === 'bids' && itemState.has_bids && !loadedSections.bids && !cachedBidsLoaded) {
            fetchItemState('bids');
        }
    }, [isOpen, itemId, activeTab, itemState.has_bids, loadedSections.bids, fetchItemState]);

    React.useEffect(() => {
        if (!isOpen || !itemId || itemId <= 0) return;
        const cachedWorkflowLoaded = !!itemStateCacheRef.current[itemId]?.loadedSections?.workflow;
        if (activeTab === 'workflow' && !loadedSections.workflow && !cachedWorkflowLoaded) {
            fetchItemState('workflow');
        }
    }, [isOpen, itemId, activeTab, loadedSections.workflow, fetchItemState]);

    // Commit 3.A.1: Fetch item timeline when Workflow tab is selected
    const fetchTimeline = React.useCallback(async (forceRefresh = false) => {
        const id = getItemId();
        if (!id) return;
        if (!forceRefresh && itemTimelineCache[id]) {
            setTimelineData(itemTimelineCache[id]);
            setTimelineError(null);
            return;
        }
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        // Prefer timeline/RFQ state nonce so designer and admin both get timeline + evidence_by_step
        let nonce = '';
        if (window.n88BoardNonce && window.n88BoardNonce.nonce_get_item_rfq_state) nonce = window.n88BoardNonce.nonce_get_item_rfq_state;
        else if (window.n88BoardData && window.n88BoardData.nonce) nonce = window.n88BoardData.nonce;
        else if (window.n88 && window.n88.nonce) nonce = window.n88.nonce;
        else if (window.n88BoardNonce && window.n88BoardNonce.nonce) nonce = window.n88BoardNonce.nonce;
        if (!nonce) {
            setTimelineError('Session expired. Please refresh.');
            return;
        }
        setTimelineLoading(true);
        setTimelineError(null);
        try {
            const formData = new FormData();
            formData.append('action', 'n88_get_item_timeline');
            formData.append('item_id', String(id));
            formData.append('_ajax_nonce', nonce);
            const response = await fetch(ajaxUrl, { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success && data.data && data.data.timeline) {
                const nextTimelineData = {
                    ...data.data.timeline,
                    evidence_by_step: data.data.evidence_by_step || {},
                    can_add_evidence_comment: !!data.data.can_add_evidence_comment,
                    steps_with_supplier_evidence: data.data.steps_with_supplier_evidence || {},
                    step_456_videos: data.data.step_456_videos || { 4: [], 5: [], 6: [] },
                    step_456_comments: data.data.step_456_comments || { 4: [], 5: [], 6: [] },
                };
                itemTimelineCache[id] = nextTimelineData;
                setTimelineData(nextTimelineData);
                setTimelineError(null);
            } else {
                setTimelineData(null);
                setTimelineError(data.message || 'Failed to load timeline.');
            }
        } catch (err) {
            console.error('Timeline fetch error:', err);
            setTimelineData(null);
            setTimelineError('Failed to load timeline.');
        } finally {
            setTimelineLoading(false);
        }
    }, []);
    React.useEffect(() => {
        if (activeTab === 'workflow' && itemId && !timelineData && !timelineLoading && !timelineError) {
            fetchTimeline();
        }
    }, [activeTab, itemId, timelineData, timelineLoading, timelineError, fetchTimeline]);
    const fetchMilestoneSummary = React.useCallback(async () => {
        if (!itemId) return null;
        const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return null;
        setMilestoneSummaryLoading(true);
        try {
            const fd = new FormData();
            fd.append('action', 'n88_get_payment_milestone_summary');
            fd.append('item_id', String(itemId));
            fd.append('_ajax_nonce', nonce);
            const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data?.success) {
                const nextSummary = data.data || null;
                setItemState((prev) => ({ ...prev, payment_milestones_summary: nextSummary }));
                return nextSummary;
            }
        } catch (err) {
            console.error('Failed to fetch milestone summary:', err);
        } finally {
            setMilestoneSummaryLoading(false);
        }
        return null;
    }, [itemId]);
    const saveMilestoneSetup = React.useCallback(async (enabled, rows = []) => {
        if (!itemId) return false;
        const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return false;
        setMilestoneSaving(true);
        try {
            const fd = new FormData();
            fd.append('action', 'n88_save_payment_milestones');
            fd.append('item_id', String(itemId));
            fd.append('milestones_enabled', enabled ? '1' : '0');
            if (enabled && Array.isArray(rows) && rows.length) {
                fd.append('milestones', JSON.stringify(rows));
            }
            fd.append('_ajax_nonce', nonce);
            const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data?.success) {
                alert(data?.data?.message || 'Could not save milestones.');
                return false;
            }
            const nextSummary = data.data || null;
            setItemState((prev) => ({ ...prev, payment_milestones_summary: nextSummary }));
            if (enabled) {
                setMilestoneUiNotice('Milestones saved. Stage 4.1 is ready to start.');
                const firstStage = Array.isArray(nextSummary?.stages) && nextSummary.stages.length ? nextSummary.stages[0] : null;
                if (firstStage?.stage_key) setExpandedMilestoneStage(firstStage.stage_key);
                setMilestoneEditOpen(false);
            } else {
                setMilestoneUiNotice('Milestones disabled. Normal flow continues.');
                setExpandedMilestoneStage('');
                setMilestoneEditOpen(true);
            }
            return true;
        } catch (err) {
            console.error(err);
            alert('Could not save milestones.');
            return false;
        } finally {
            setMilestoneSaving(false);
        }
    }, [itemId]);
    const fetchMilestoneStageDetails = React.useCallback(async (stageKey) => {
        if (!itemId || !stageKey) return null;
        if (milestoneDetailsByStage[stageKey]) return milestoneDetailsByStage[stageKey];
        const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return null;
        try {
            const fd = new FormData();
            fd.append('action', 'n88_get_payment_milestone_stage_details');
            fd.append('item_id', String(itemId));
            fd.append('stage_key', String(stageKey));
            fd.append('_ajax_nonce', nonce);
            const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data?.success) {
                setMilestoneDetailsByStage((prev) => ({ ...prev, [stageKey]: data.data || {} }));
                return data.data || {};
            }
        } catch (err) {
            console.error(err);
        }
        return null;
    }, [itemId, milestoneDetailsByStage]);
    const runMilestoneAction = React.useCallback(async (stageKey, actionName, appendFields = null) => {
        if (!itemId || !stageKey || !actionName) return false;
        const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return false;
        setMilestoneActionBusyByStage((prev) => ({ ...prev, [stageKey]: true }));
        setMilestoneActionTypeByStage((prev) => ({ ...prev, [stageKey]: actionName }));
        try {
            const fd = new FormData();
            fd.append('action', actionName);
            fd.append('item_id', String(itemId));
            fd.append('stage_key', String(stageKey));
            if (typeof appendFields === 'function') appendFields(fd);
            fd.append('_ajax_nonce', nonce);
            const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data?.success) {
                alert(data?.data?.message || 'Milestone action failed.');
                return false;
            }
            setItemState((prev) => ({ ...prev, payment_milestones_summary: data.data || null }));
            setMilestoneDetailsByStage((prev) => {
                const next = { ...prev };
                delete next[stageKey];
                return next;
            });
            await fetchMilestoneSummary();
            if (typeof fetchItemState === 'function') await fetchItemState();
            if (typeof onSave === 'function') await onSave(itemId, {});
            try { window.dispatchEvent(new CustomEvent('n88-board-refresh-status')); } catch (e) {}
            return true;
        } catch (err) {
            console.error(err);
            alert('Milestone action failed.');
            return false;
        } finally {
            setMilestoneActionBusyByStage((prev) => ({ ...prev, [stageKey]: false }));
            setMilestoneActionTypeByStage((prev) => ({ ...prev, [stageKey]: '' }));
        }
    }, [itemId, fetchMilestoneSummary]);
    React.useEffect(() => {
        if (!isOpen || activeTab !== 'workflow' || !itemId || !itemState.has_awarded_bid || milestoneSummaryLoading) return;
        if (itemState.payment_milestones_summary && typeof itemState.payment_milestones_summary === 'object') return;
        const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return;
        setMilestoneSummaryLoading(true);
        const fd = new FormData();
        fd.append('action', 'n88_get_payment_milestone_summary');
        fd.append('item_id', String(itemId));
        fd.append('_ajax_nonce', nonce);
        fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd })
            .then((r) => r.json())
            .then((res) => {
                if (res?.success) {
                    setItemState((prev) => ({ ...prev, payment_milestones_summary: res.data || null }));
                }
            })
            .finally(() => setMilestoneSummaryLoading(false));
    }, [isOpen, activeTab, itemId, itemState.has_awarded_bid, itemState.payment_milestones_summary, milestoneSummaryLoading]);
    React.useEffect(() => {
        const stages = itemState?.payment_milestones_summary?.stages;
        if (!Array.isArray(stages) || !stages.length) {
            setMilestoneSetupDraft([]);
            setMilestoneEditOpen(true);
            return;
        }
        setMilestoneEditOpen(!(itemState?.payment_milestones_summary?.enabled));
        setMilestoneSetupDraft(stages.map((s) => ({
            stage_key: s.stage_key,
            stage_label: s.stage_label,
            percent_alloc: Number(s.percent_alloc || 0),
            amount_alloc: s.amount_alloc == null ? '' : String(s.amount_alloc),
            stage_enabled: !!s.stage_enabled,
            payment_required: !!s.payment_required,
        })));
    }, [itemState?.payment_milestones_summary?.stages]);
    React.useEffect(() => {
        const summary = itemState?.payment_milestones_summary;
        if (!summary?.enabled) return;
        const nextKey = summary?.next_stage_key || '';
        if (nextKey) setExpandedMilestoneStage(nextKey);
    }, [itemState?.payment_milestones_summary?.next_stage_key, itemState?.payment_milestones_summary?.enabled]);
    React.useEffect(() => {
        if (!milestoneUiNotice) return;
        const t = setTimeout(() => setMilestoneUiNotice(''), 3000);
        return () => clearTimeout(t);
    }, [milestoneUiNotice]);
    // Reset timeline when item changes so next open fetches fresh
    React.useEffect(() => {
        setTimelineData(null);
        setTimelineError(null);
        setSelectedStepIndex(0);
        setSupplierStepEvidenceView(null);
        setDesignerStep456CommentDraft({});
        setMilestoneSetupPromptOpen(true);
        setMilestoneSetupChoice('none');
    }, [itemId]);
    
    // Load keywords when bid is selected (Commit 2.3.9.1B)
    React.useEffect(() => {
        if (!showCadPrototypeForm || !hasRequestedCategoryKeywords || !selectedBidId || !category) {
            return;
        }

        const loadKeywordsForCategory = async () => {
            setIsLoadingKeywords(true);
            setAvailableKeywords([]);

            try {
                const keywordLoader = typeof window !== 'undefined' ? window.n88LoadCategoryKeywords : null;
                const keywords = typeof keywordLoader === 'function'
                    ? await keywordLoader(category)
                    : [];
                setAvailableKeywords(Array.isArray(keywords) ? keywords : []);
            } catch (error) {
                console.error('Error loading keywords:', error);
                setAvailableKeywords([]);
            } finally {
                setIsLoadingKeywords(false);
            }
        };

        loadKeywordsForCategory();
    }, [showCadPrototypeForm, hasRequestedCategoryKeywords, selectedBidId, category]);
    
    // Commit 2.3.9.1C-a: Load Designer Messages
    const loadDesignerMessages = React.useCallback(async (forceRefresh = false) => {
        if (!itemId) return;
        if (!forceRefresh && itemMessagesCache[itemId]) {
            setDesignerMessages(itemMessagesCache[itemId]);
            return;
        }
        
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
                itemMessagesCache[itemId] = data.data.messages;
            } else {
                setDesignerMessages([]);
                itemMessagesCache[itemId] = [];
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
                loadDesignerMessages(true);
                window.setTimeout(() => { if (typeof fetchItemState === 'function') fetchItemState(); }, 0);
            } else {
                alert('Error: ' + (data.data?.message || 'Failed to send message. Please try again.'));
            }
        } catch (error) {
            console.error('Error sending designer message:', error);
            alert('An error occurred. Please try again.');
        } finally {
            setIsSendingDesignerMessage(false);
        }
    }, [itemId, designerMessageText, designerMessageCategory, loadDesignerMessages, fetchItemState]);

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
                await loadDesignerMessages(true);
                skipNextTabSwitchFromCadActionRef.current = true; // stay on Workflow Step 2 after revision request
                setActiveTab('workflow');
                setSelectedStepIndex(1); // Step 2
                setShowDesignerMessageForm(true); // keep message box open
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
                await loadDesignerMessages(true);
                skipNextTabSwitchFromCadActionRef.current = true; // stay on Workflow Step 2 after approve CAD
                setActiveTab('workflow');
                setSelectedStepIndex(1); // Step 2
                setShowDesignerMessageForm(true); // keep message box open
                updateLayout(item.id, { cad_status: 'approved' }); // so item card shows Pending Prototype Video
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
    }, [itemId, item.id, itemState.prototype_payment_id, loadDesignerMessages, fetchItemState, updateLayout]);

    // Fetch payment receipts when Payment Instructions modal opens
    const fetchPaymentReceipts = React.useCallback(async (forceRefresh = false) => {
        const pid = itemState.prototype_payment_id;
        if (!pid) return;
        if (!forceRefresh && paymentReceiptsCache[pid]) {
            setPaymentReceipts(paymentReceiptsCache[pid]);
            return;
        }
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardNonce?.nonce_get_payment_receipts || '';
        if (!nonce) return;
        setPaymentReceiptsLoading(true);
        try {
            const fd = new FormData();
            fd.append('action', 'n88_get_payment_receipts');
            fd.append('payment_id', String(pid));
            fd.append('_ajax_nonce', nonce);
            const r = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success && Array.isArray(d.data.receipts)) {
                setPaymentReceipts(d.data.receipts);
                paymentReceiptsCache[pid] = d.data.receipts;
            } else {
                setPaymentReceipts([]);
                paymentReceiptsCache[pid] = [];
            }
        } catch (e) {
            setPaymentReceipts([]);
        } finally {
            setPaymentReceiptsLoading(false);
        }
    }, [itemState.prototype_payment_id]);

    React.useEffect(() => {
        if (showPaymentInstructions && itemState.prototype_payment_id) fetchPaymentReceipts();
        else if (!showPaymentInstructions) {
            setPaymentReceiptSelectedFile(null);
            setPaymentReceiptMessage('');
            setShowResubmitReceiptForm(false);
        }
    }, [showPaymentInstructions, itemState.prototype_payment_id, fetchPaymentReceipts]);

    // Initialize selected project/room from item when modal opens / item changes
    React.useEffect(() => {
        if (!isOpen) return;
        const pid = Number(item.project_id || item.projectId || item.meta?.project_id || 0) || 0;
        const rid = Number(item.room_id || item.roomId || item.meta?.room_id || 0) || 0;
        setSelectedProjectId(pid);
        setSelectedRoomId(rid);
    }, [isOpen, item.id, item.project_id, item.room_id]);

    // Fetch board projects when modal opens (use n88BoardNonce.nonce - projects/rooms endpoints expect 'n88-rfq-nonce')
    React.useEffect(() => {
        if (!isOpen || !projectMenuOpen) return;
        if (!boardId || Number(boardId) <= 0) return;
        const boardCacheKey = String(boardId);
        if (boardProjectsCache[boardCacheKey]) {
            setBoardProjects(boardProjectsCache[boardCacheKey]);
            return;
        }
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardNonce?.nonce || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) return;
        setProjectsLoading(true);
        fetch(`${ajaxUrl}?action=n88_get_board_projects&board_id=${encodeURIComponent(String(boardId))}&nonce=${encodeURIComponent(String(nonce))}`, {
            method: 'GET',
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.success && Array.isArray(d.data?.projects)) {
                    setBoardProjects(d.data.projects);
                    boardProjectsCache[boardCacheKey] = d.data.projects;
                } else if (d && d.success && Array.isArray(d.projects)) {
                    setBoardProjects(d.projects);
                    boardProjectsCache[boardCacheKey] = d.projects;
                } else {
                    setBoardProjects([]);
                    boardProjectsCache[boardCacheKey] = [];
                }
            })
            .catch(() => setBoardProjects([]))
            .finally(() => setProjectsLoading(false));
    }, [isOpen, projectMenuOpen, boardId]);

    // Fetch rooms whenever selected project changes (use n88BoardNonce.nonce - rooms endpoint expects 'n88-rfq-nonce')
    React.useEffect(() => {
        if (!isOpen || !projectMenuOpen) return;
        if (!selectedProjectId || selectedProjectId <= 0) {
            setProjectRooms([]);
            setRoomsLoading(false);
            return;
        }
        const roomsCacheKey = String(selectedProjectId);
        if (projectRoomsCache[roomsCacheKey]) {
            setProjectRooms(projectRoomsCache[roomsCacheKey]);
            return;
        }
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardNonce?.nonce || window.n88BoardData?.nonce || window.n88?.nonce || '';
        if (!nonce) {
            setProjectRooms([]);
            return;
        }
        const pid = Number(selectedProjectId);
        roomsFetchProjectIdRef.current = pid;
        setProjectRooms([]);
        setRoomsLoading(true);
        const url = `${ajaxUrl}?action=n88_get_project_rooms&project_id=${encodeURIComponent(String(pid))}&nonce=${encodeURIComponent(String(nonce))}`;
        fetch(url, { method: 'GET', credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d) => {
                const roomsRaw = (d && d.success && (d.data?.rooms ?? d.data?.data?.rooms ?? d.rooms));
                const list = Array.isArray(roomsRaw)
                    ? roomsRaw.map((r) => ({ id: r.id ?? r.room_id, name: r.name ?? r.room_name ?? String(r.id ?? r.room_id ?? '') }))
                    : [];
                projectRoomsCache[roomsCacheKey] = list;
                if (roomsFetchProjectIdRef.current === pid) setProjectRooms(list);
            })
            .catch(() => { if (roomsFetchProjectIdRef.current === pid) setProjectRooms([]); })
            .finally(() => setRoomsLoading(false));
    }, [isOpen, projectMenuOpen, selectedProjectId]);

    const getSelectedProjectName = React.useCallback(() => {
        const pid = Number(selectedProjectId || 0);
        if (!pid) return '';
        // Use item.project_name / item.room_name when available (e.g. from server) so label shows immediately
        const itemProjectName = item.project_name || item.projectName || '';
        const itemRoomName = item.room_name || item.roomName || '';
        if (itemProjectName && pid === Number(item.project_id || item.projectId || 0)) {
            const roomId = Number(selectedRoomId || 0);
            if (roomId && itemRoomName && roomId === Number(item.room_id || item.roomId || 0)) {
                return `${String(itemProjectName)} / ${String(itemRoomName)}`;
            }
            return String(itemProjectName);
        }
        const p = (boardProjects || []).find((x) => Number(x.id) === pid);
        const projectName = p?.name ? String(p.name) : '';
        const rid = Number(selectedRoomId || 0);
        if (rid && projectName) {
            const r = (projectRooms || []).find((x) => Number(x.id) === rid);
            const roomName = r?.name ? String(r.name) : '';
            if (roomName) return `${projectName} / ${roomName}`;
        }
        return projectName;
    }, [selectedProjectId, selectedRoomId, boardProjects, projectRooms, item.project_id, item.projectId, item.room_id, item.roomId, item.project_name, item.projectName, item.room_name, item.roomName]);

    const saveProjectRoomAssignment = React.useCallback(async (projectId, roomId) => {
        if (!boardId || Number(boardId) <= 0) return;
        const itemId = Number(item.id);
        if (!itemId || itemId <= 0) return;
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardData?.nonce || window.n88?.nonce || window.n88BoardNonce?.nonce || '';
        if (!nonce) { alert('Security token missing. Please refresh the page and try again.'); return; }

        setAssignmentSaving(true);
        try {
            const params = new URLSearchParams();
            params.set('action', 'n88_save_item_facts');
            params.set('board_id', String(boardId));
            params.set('item_id', String(itemId));
            params.set('nonce', String(nonce));
            params.set('payload', JSON.stringify({})); // keep item facts unchanged
            params.set('project_id', String(projectId || 0));
            params.set('room_id', String(roomId || 0));

            const r = await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
            });
            const d = await r.json();
            if (!d.success) throw new Error(d.data?.message || 'Failed to update project/room');

            // Update store item immediately (so UI reflects without reload)
            const store = window.N88StudioOS?.useBoardStore?.getState?.();
            if (store && Array.isArray(store.items) && typeof store.setItems === 'function') {
                const updated = store.items.map((it) => {
                    const itId = typeof it.id === 'string' && it.id.startsWith('item-') ? Number(it.id.replace('item-', '')) : Number(it.id);
                    if (itId === itemId) {
                        return { ...it, project_id: Number(projectId || 0) || null, room_id: Number(roomId || 0) || null };
                    }
                    return it;
                });
                store.setItems(updated);
            }

            // Reload page to show item in filtered view (project/room)
            // Preserve current project_id and room_id in URL so item appears in correct filter
            const urlParams = new URLSearchParams(window.location.search);
            const currentProjectId = urlParams.get('project_id') || '';
            const currentRoomId = urlParams.get('room_id') || '';
            const boardIdParam = urlParams.get('board_id') || '';
            
            // Build reload URL with same filters
            const reloadUrl = new URL(window.location.href);
            reloadUrl.searchParams.set('board_id', boardIdParam || String(boardId));
            if (projectId > 0) {
                reloadUrl.searchParams.set('project_id', String(projectId));
                if (roomId > 0) {
                    reloadUrl.searchParams.set('room_id', String(roomId));
                } else {
                    reloadUrl.searchParams.delete('room_id');
                }
            } else {
                reloadUrl.searchParams.delete('project_id');
                reloadUrl.searchParams.delete('room_id');
            }
            
            // Reload after a short delay to show success
            setTimeout(() => {
                window.location.href = reloadUrl.toString();
            }, 300);
        } catch (e) {
            alert(e?.message || 'Failed to update project/room.');
        } finally {
            setAssignmentSaving(false);
        }
    }, [boardId, item.id]);

    const handleSelectProject = React.useCallback((e) => {
        const newProjectId = Number(e.target.value || 0) || 0;
        setSelectedProjectId(newProjectId);
        setSelectedRoomId(0);
    }, []);

    const handleSelectRoom = React.useCallback((e) => {
        const newRoomId = Number(e.target.value || 0) || 0;
        setSelectedRoomId(newRoomId);
    }, []);

    const handleUpdateProjectRoom = React.useCallback(async () => {
        await saveProjectRoomAssignment(selectedProjectId, selectedRoomId);
        setProjectMenuOpen(false);
    }, [saveProjectRoomAssignment, selectedProjectId, selectedRoomId]);

    const handlePaymentReceiptFileSelect = React.useCallback((e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const ok = /\.(jpe?g|pdf)$/i.test(file.name) || ['image/jpeg','image/jpg','application/pdf'].includes(file.type);
        if (!ok) { alert('Only JPG and PDF are allowed.'); e.target.value = ''; return; }
        setPaymentReceiptSelectedFile(file);
        e.target.value = '';
    }, []);

    const submitPaymentReceiptUpload = React.useCallback(async () => {
        const file = paymentReceiptSelectedFile;
        if (!file) return;
        const pid = itemState.prototype_payment_id;
        if (!pid) return;
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardNonce?.nonce_upload_payment_receipt || '';
        if (!nonce) { alert('Upload not available.'); return; }
        setPaymentReceiptUploading(true);
        try {
            const fd = new FormData();
            fd.append('action', 'n88_upload_payment_receipt');
            fd.append('payment_id', String(pid));
            fd.append('receipt_file', file);
            if (paymentReceiptMessage && paymentReceiptMessage.trim()) fd.append('receipt_message', paymentReceiptMessage.trim());
            fd.append('_ajax_nonce', nonce);
            const r = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                setPaymentReceiptSelectedFile(null);
                setPaymentReceiptMessage('');
                setShowResubmitReceiptForm(false);
                setShowPaymentInstructions(false);
                await fetchPaymentReceipts(true);
                if (paymentReceiptInputRef.current) paymentReceiptInputRef.current.value = '';
                // Update item card status to "Awaiting payment confirmation"
                updateLayout(item.id, { has_payment_receipt_uploaded: true });
                if (typeof fetchItemState === 'function') fetchItemState();
                if (onClose) onClose();
            } else {
                alert(d.data?.message || 'Upload failed.');
            }
        } catch (err) {
            alert('Upload failed.');
        } finally {
            setPaymentReceiptUploading(false);
        }
    }, [paymentReceiptSelectedFile, paymentReceiptMessage, itemState.prototype_payment_id, fetchPaymentReceipts, onClose, item.id, updateLayout]);

    const handleDepositProofFileSelect = React.useCallback((e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const ok = /\.(jpe?g|png|pdf)$/i.test(file.name) || ['image/jpeg','image/jpg','image/png','application/pdf'].includes(file.type);
        if (!ok) { alert('Only images (JPG, PNG) and PDF are allowed.'); if (e.target) e.target.value = ''; return; }
        setDepositProofFile(file);
        if (e.target) e.target.value = '';
    }, []);

    const submitDepositProof = React.useCallback(async () => {
        const file = depositProofFile;
        if (!file) { alert('Please select a file (screenshot or PDF).'); return; }
        const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
        const nonce = window.n88BoardNonce?.nonce_mark_deposit_sent || window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || '';
        if (!nonce) { alert('Security token missing. Please refresh the page.'); return; }
        setDepositProofUploading(true);
        try {
            const fd = new FormData();
            fd.append('action', 'n88_mark_deposit_sent');
            fd.append('item_id', String(getItemId()));
            fd.append('deposit_receipt', file);
            if (depositProofMessage && depositProofMessage.trim()) fd.append('note', depositProofMessage.trim());
            fd.append('_ajax_nonce', nonce);
            const r = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                setShowDepositProofModal(false);
                setDepositProofFile(null);
                setDepositProofMessage('');
                if (depositProofInputRef.current) depositProofInputRef.current.value = '';
                if (typeof fetchItemState === 'function') fetchItemState();
                if (typeof fetchTimeline === 'function') fetchTimeline(true);
                if (typeof updateLayout === 'function') updateLayout(item.id, { deposit_status: 'sent_by_designer' });
            } else {
                alert(d.data?.message || d.message || 'Failed to submit.');
            }
        } catch (err) {
            console.error(err);
            alert('Error. Please try again.');
        } finally {
            setDepositProofUploading(false);
        }
    }, [depositProofFile, depositProofMessage, getItemId, item.id, updateLayout]);
    
    // Auto-scroll to bottom when messages load or new message is sent (Workflow Step 2 container)
    React.useEffect(() => {
        if (showDesignerMessageForm && designerMessages.length > 0) {
            const container = document.getElementById('n88-designer-messages-container-workflow');
            if (container) {
                setTimeout(() => {
                    container.scrollTop = container.scrollHeight;
                }, 100);
            }
        }
    }, [showDesignerMessageForm, designerMessages]);
    
    // Update inspiration when item changes
    React.useEffect(() => {
        const validInspiration = (item.inspiration || []).filter(validateInspirationItem).map(insp => ({
            ...insp,
            caption: (insp && typeof insp.caption === 'string') ? insp.caption.slice(0, 100) : '',
        }));
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
            if (parsedMeta.preferred_delivery_date !== undefined) {
                setPreferredDeliveryDate(parsedMeta.preferred_delivery_date || '');
            }
            // COMMIT 3.C.3: RFQ overall notes, fabric supplied, fabric notes
            if (parsedMeta.rfq_overall_notes !== undefined) setRfqOverallNotes(parsedMeta.rfq_overall_notes || '');
            if (parsedMeta.rfq_fabric_supplied_flag === 'yes' || parsedMeta.rfq_fabric_supplied_flag === 'no') setFabricSupplied(parsedMeta.rfq_fabric_supplied_flag);
            if (parsedMeta.rfq_fabric_notes !== undefined) setFabricNotes(parsedMeta.rfq_fabric_notes || '');
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
    
    // Fallback dims in cm from item.dims when dims_cm/cbm not set (so Prototype Delivery can use per-unit CBM)
    const fallbackDimsCm = React.useMemo(() => {
        if (computedValues?.dimsCm) return computedValues.dimsCm;
        if (item?.dims_cm && (item.dims_cm.w_cm != null || item.dims_cm.w != null)) return item.dims_cm;
        const d = item?.dims;
        if (d && d.w != null && d.d != null && d.h != null) {
            const u = d.unit || unit || 'in';
            return {
                w_cm: normalizeToCm(Number(d.w), u),
                d_cm: normalizeToCm(Number(d.d), u),
                h_cm: normalizeToCm(Number(d.h), u),
            };
        }
        return null;
    }, [computedValues?.dimsCm, item?.dims_cm, item?.dims, unit]);
    
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
    // Commit 2.6.1: View-only team members cannot edit anything
    // Lock after CAD/Prototype request submitted (permanent): lock Brief/RFQ and hide Update/Save
    const isLockedAwaitingPayment = !!itemState.has_prototype_payment;
    const isEditable = !isViewOnly && currentState === 'A' && !isLockedAwaitingPayment;
    const isDimsQtyEditable = !isViewOnly && !isLockedAwaitingPayment;
    
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
                    caption: (insp.caption && typeof insp.caption === 'string') ? insp.caption.slice(0, 100) : '',
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
                preferred_delivery_date: preferredDeliveryDate,
                rfq_overall_notes: rfqOverallNotes.slice(0, 100),
                rfq_fabric_supplied_flag: fabricSupplied,
                rfq_fabric_notes: fabricSupplied === 'yes' ? fabricNotes : '',
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
    
    // COMMIT 3.C.3: Add link URL to inspiration (RFQ tab – no upload)
    const addInspirationLink = () => {
        const url = inspirationLinkInput.trim();
        if (!url) return;
        if (!url.startsWith('http://') && !url.startsWith('https://')) {
            alert('Please enter a valid URL (e.g. https://…)');
            return;
        }
        setInspiration([...inspiration, { type: 'link', url, title: 'Reference link', caption: '' }]);
        setInspirationLinkInput('');
    };
    
    // COMMIT 3.C.3: Update per-image caption (max 100 chars)
    const setInspirationCaption = (idx, value) => {
        setInspiration(prev => prev.map((insp, i) => i === idx ? { ...insp, caption: (value || '').slice(0, 100) } : insp));
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
        if (preferredDeliveryDate) parts.push(`Preferred: ${preferredDeliveryDate.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$2/$3/$1')}`);
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
                return '';
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
            setSystemInvitesMessage('');
        }
    };
    
    // Remove invited supplier chip
    const removeInvitedSupplierChip = (value) => {
        const newSuppliers = invitedSuppliers.filter(s => s !== value);
        setInvitedSuppliers(newSuppliers);
        
        // Update system invites message if checkbox is checked
        if (allowSystemInvites) {
            if (newSuppliers.length > 0) {
                setSystemInvitesMessage('');
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
                preferred_delivery_date: preferredDeliveryDate.trim(),
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
                            caption: (insp.caption && typeof insp.caption === 'string') ? insp.caption.slice(0, 100) : '',
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
                        preferred_delivery_date: preferredDeliveryDate,
                        rfq_overall_notes: rfqOverallNotes.slice(0, 100),
                        rfq_fabric_supplied_flag: fabricSupplied,
                        rfq_fabric_notes: fabricSupplied === 'yes' ? fabricNotes : '',
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
    const greenAccent = '#FF0065'; /* pink accent - same as supplier queue */
    const darkBorder = '#333333';
    
    return (
        <AnimatePresence>
            {isOpen && (
                <>
                    {/* Backdrop - 50px margin area, blur so board visible behind */}
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
                            backgroundColor: 'rgba(0, 0, 0, 0.2)',
                            backdropFilter: 'blur(6px)',
                            WebkitBackdropFilter: 'blur(1px)',
                            zIndex: 999999,
                        }}
                    />
                    
                    {/* Modal - inset top 30px, left/right 150px, bottom 30px */}
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        exit={{ opacity: 0, scale: 0.95 }}
                        transition={{ type: 'spring', damping: 25, stiffness: 200 }}
                        style={{
                            position: 'fixed',
                            top: '30px',
                            left: '150px',
                            right: '150px',
                            bottom: '30px',
                            width: 'auto',
                            height: 'auto',
                            transform: 'none',
                            boxSizing: 'border-box',
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
                        {/* Header: Close + Wireframe(OS) + Board/Item (left), Action Dropdown (right) */}
                        <div style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            padding: '16px 20px',
                            borderBottom: `1px solid ${darkBorder}`,
                            flexShrink: 0,
                        }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
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
                                    aria-label="Close"
                                >
                                    ×
                                </button>
                                <div style={{
                                    fontSize: '12px',
                                    color: darkText,
                                    fontFamily: 'monospace',
                                    display: 'flex',
                                    alignItems: 'center',
                                }}>
                                    <span style={{ color: greenAccent, fontWeight: '600', marginRight: '12px' }}>Wireframe(OS)</span>
                                    <span>Board : </span>
                                    <span style={{ color: greenAccent }}>{(typeof window !== 'undefined' && window.n88BoardData && window.n88BoardData.boardName) || 'Demo Board'}</span>
                                    <span style={{ margin: '0 8px' }}> / </span>
                                    <span>Item </span>
                                    <span style={{ color: greenAccent }}>{itemId ? String(itemId) : (item.id ? (typeof item.id === 'string' && item.id.indexOf('item-') === 0 ? item.id.replace('item-', '') : String(item.id)) : 'N/A')}</span>
                                </div>
                            </div>
                            
                            {/* Action Dropdown - Right (Add to Project / Room) */}
                            <div style={{ position: 'relative' }}>
                                <button
                                    onClick={() => setProjectMenuOpen((v) => !v)}
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
                                        opacity: boardId && Number(boardId) > 0 ? 1 : 0.5,
                                    }}
                                    disabled={!boardId || Number(boardId) <= 0}
                                >
                                    {getSelectedProjectName() ? `Project: ${getSelectedProjectName()}` : 'Add to Project'}
                                    <span style={{ fontSize: '10px' }}>▼</span>
                                </button>

                                {projectMenuOpen && (
                                    <div
                                        style={{
                                            position: 'absolute',
                                            top: '110%',
                                            right: 0,
                                            width: '320px',
                                            backgroundColor: '#0b0b0b',
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '6px',
                                            padding: '12px',
                                            zIndex: 1000003,
                                            boxShadow: '0 8px 20px rgba(0,0,0,0.45)',
                                        }}
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <div style={{ fontSize: '11px', color: '#aaa', marginBottom: '6px' }}>
                                            {projectsLoading ? 'Loading projects…' : 'Select a project'}
                                        </div>
                                        <select
                                            value={String(selectedProjectId || 0)}
                                            onChange={handleSelectProject}
                                            disabled={projectsLoading || assignmentSaving}
                                            style={{
                                                width: '100%',
                                                padding: '8px 10px',
                                                borderRadius: '4px',
                                                backgroundColor: '#111',
                                                color: '#fff',
                                                border: `1px solid ${darkBorder}`,
                                                fontFamily: 'monospace',
                                                fontSize: '12px',
                                                marginBottom: '10px',
                                            }}
                                        >
                                            <option value="0">— Not in a project —</option>
                                            {(boardProjects || []).map((p) => (
                                                <option key={p.id} value={String(p.id)}>{p.name}</option>
                                            ))}
                                        </select>

                                        {selectedProjectId > 0 && (
                                            <>
                                                <div style={{ fontSize: '11px', color: '#aaa', marginBottom: '6px' }}>
                                                    {roomsLoading ? 'Loading rooms…' : 'Select a room (optional)'}
                                                </div>
                                                <select
                                                    value={String(selectedRoomId || 0)}
                                                    onChange={handleSelectRoom}
                                                    disabled={roomsLoading || assignmentSaving}
                                                    style={{
                                                        width: '100%',
                                                        padding: '8px 10px',
                                                        borderRadius: '4px',
                                                        backgroundColor: '#111',
                                                        color: '#fff',
                                                        border: `1px solid ${darkBorder}`,
                                                        fontFamily: 'monospace',
                                                        fontSize: '12px',
                                                    }}
                                                >
                                                    <option value="0">— No room —</option>
                                                    {(projectRooms || []).map((r) => (
                                                        <option key={r.id} value={String(r.id)}>{r.name}</option>
                                                    ))}
                                                </select>
                                            </>
                                        )}

                                        {assignmentSaving && (
                                            <div style={{ marginTop: '10px', fontSize: '11px', color: '#FF0065' }}>
                                                Saving…
                                            </div>
                                        )}

                                        <div style={{ marginTop: '12px', display: 'flex', gap: '8px' }}>
                                            <button
                                                type="button"
                                                onClick={handleUpdateProjectRoom}
                                                disabled={assignmentSaving}
                                                style={{
                                                    flex: 1,
                                                    padding: '8px 10px',
                                                    backgroundColor: '#1a1a1a',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    color: '#fff',
                                                    cursor: assignmentSaving ? 'not-allowed' : 'pointer',
                                                    fontFamily: 'monospace',
                                                    fontSize: '12px',
                                                }}
                                            >
                                                Update
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setProjectMenuOpen(false)}
                                                style={{
                                                    flex: 1,
                                                    padding: '8px 10px',
                                                    backgroundColor: '#111',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    color: '#aaa',
                                                    cursor: 'pointer',
                                                    fontFamily: 'monospace',
                                                    fontSize: '12px',
                                                }}
                                            >
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                        
                        {/* Main Content - Two Columns */}
                        <div style={{
                            display: 'flex',
                            flex: 1,
                            overflow: 'hidden',
                        }}>
                            {/* Left Column - Images (30%) */}
                            <div style={{
                                width: '30%',
                                minWidth: 0,
                                borderRight: `1px solid ${darkBorder}`,
                                padding: '0px 10px',
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
                            
                            {/* Right Column - Tabs (remaining) */}
                            <div style={{
                                flex: 1,
                                minWidth: 0,
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
                                    {!isProductionOnly && <button
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
                                    </button>}
                                    <button
                                        onClick={() => setActiveTab('workflow')}
                                        style={{
                                            flex: 1,
                                            padding: '12px 16px',
                                            background: activeTab === 'workflow' ? '#111111' : 'transparent',
                                            border: 'none',
                                            borderBottom: activeTab === 'workflow' ? `2px solid ${greenAccent}` : 'none',
                                            color: activeTab === 'workflow' ? greenAccent : darkText,
                                            fontSize: '12px',
                                            fontWeight: activeTab === 'workflow' ? '600' : '400',
                                            cursor: 'pointer',
                                            fontFamily: 'monospace',
                                        }}
                                    >
                                        {isProductionOnly ? 'Production Tracking Mode' : 'Project Workflow'}
                                    </button>
                                    {!isProductionOnly && itemState.has_bids && (
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
                                            Proposals Received{itemState.bids && itemState.bids.length > 0 ? ` (${itemState.bids.length})` : ''}
                                        </button>
                                    )}
                                </div>
                                {isProductionOnly && (
                                    <div style={{ margin: '12px 20px 0', padding: '10px 12px', border: `1px solid ${darkBorder}`, borderRadius: '4px', background: '#111' }}>
                                        <div style={{ fontSize: '13px', fontWeight: '600', color: greenAccent, marginBottom: '4px' }}>Production Tracking Mode</div>
                                        <div style={{ fontSize: '12px', color: darkText }}>Full visibility and structured oversight into every stage of production with your supplier.</div>
                                    </div>
                                )}
                                
                                {/* Tab Content */}
                                <div style={{
                                    flex: 1,
                                    minHeight: 0,
                                    overflow: activeTab === 'details' ? 'hidden' : 'auto',
                                    overflowY: activeTab === 'details' ? 'hidden' : 'auto',
                                    padding: '0px 20px',
                                    scrollbarWidth: 'none',
                                    msOverflowStyle: 'none',
                                    display: activeTab === 'details' ? 'flex' : 'block',
                                }}
                                className="n88-modal-scroll-content"
                                >
                                    {/* Tab 1: Item Specification — 60% left (scrollable) / 40% right (fixed Support) */}
                                    {activeTab === 'details' && (
                                        <>
                                        <div style={{ display: 'flex', gap: '16px', alignItems: 'stretch', flex: 1, minHeight: 0, overflow: 'hidden', width: '100%' }}>
                                            {/* Left: 60% — scrollable: Item Specification box + Request Quote form */}
                                            <div style={{ flex: '0 0 60%', maxWidth: '60%', overflowY: 'auto', minHeight: 0, paddingRight: '8px' }}>
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

                                            {/* Unified Item Specification summary (all states) */}
                                            <div
                                                style={{
                                                    marginBottom: '24px',
                                                    padding: '16px',
                                                    backgroundColor: '#111111',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        fontSize: '14px',
                                                        fontWeight: 600,
                                                        marginBottom: '16px',
                                                        color: darkText,
                                                        borderBottom: `1px solid ${darkBorder}`,
                                                        paddingBottom: '8px',
                                                    }}
                                                >
                                                    Item Specification
                                                </div>
                                                {(item.title || description) && (
                                                    <div style={{ marginBottom: '12px' }}>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                marginBottom: '4px',
                                                                color: darkText,
                                                                opacity: 0.7,
                                                            }}
                                                        >
                                                            Name:
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: '13px',
                                                                color: greenAccent,
                                                                fontWeight: 500,
                                                            }}
                                                        >
                                                            {item.title || description || 'Untitled Item'}
                                                        </div>
                                                    </div>
                                                )}
                                                {description && (
                                                    <div style={{ marginBottom: '12px' }}>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                marginBottom: '4px',
                                                                color: darkText,
                                                                opacity: 0.7,
                                                            }}
                                                        >
                                                            Description:
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                color: darkText,
                                                                lineHeight: 1.6,
                                                            }}
                                                        >
                                                            {description}
                                                        </div>
                                                    </div>
                                                )}
                                                {category && (
                                                    <div style={{ marginBottom: '12px' }}>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                marginBottom: '4px',
                                                                color: darkText,
                                                                opacity: 0.7,
                                                            }}
                                                        >
                                                            Category:
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                color: greenAccent,
                                                            }}
                                                        >
                                                            {category}
                                                        </div>
                                                    </div>
                                                )}
                                                {(() => {
                                                    const timelineType = getTimelineTypeFromCategory(category);
                                                    if (!timelineType) return null;
                                                    return (
                                                        <div style={{ marginBottom: '12px' }}>
                                                            <div
                                                                style={{
                                                                    fontSize: '12px',
                                                                    marginBottom: '4px',
                                                                    color: darkText,
                                                                    opacity: 0.7,
                                                                }}
                                                            >
                                                                Timeline Type:
                                                            </div>
                                                            <div
                                                                style={{
                                                                    fontSize: '12px',
                                                                    color: greenAccent,
                                                                }}
                                                            >
                                                                {timelineType}
                                                            </div>
                                                        </div>
                                                    );
                                                })()}
                                                {quantity && (
                                                    <div style={{ marginBottom: '12px' }}>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                marginBottom: '4px',
                                                                color: darkText,
                                                                opacity: 0.7,
                                                            }}
                                                        >
                                                            Quantity:
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                color: greenAccent,
                                                            }}
                                                        >
                                                            {quantity}
                                                        </div>
                                                    </div>
                                                )}
                                                {width && depth && height && (
                                                    <div style={{ marginBottom: '0' }}>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                marginBottom: '4px',
                                                                color: darkText,
                                                                opacity: 0.7,
                                                            }}
                                                        >
                                                            Dimensions:
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: '12px',
                                                                color: greenAccent,
                                                            }}
                                                        >
                                                            {`${width} × ${depth} × ${height} ${unit}`}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Review and Message (CAD) redirect notice removed from Item Specification tab per UX request */}
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
                                            <option value="UPHOLSTERY">UPHOLSTERY</option>
                                            <option value="INDOOR FURNITURE (CASEGOODS)">INDOOR FURNITURE (CASEGOODS)</option>
                                            <option value="OUTDOOR FURNITURE">OUTDOOR FURNITURE</option>
                                            <option value="LIGHTING">LIGHTING</option>
                                            <option value="STONE (MARBLE / GRANITE / QUARTZ)">STONE (MARBLE / GRANITE / QUARTZ)</option>
                                            <option value="METALWORK">METALWORK</option>
                                            <option value="MILLWORK / CABINETRY">MILLWORK / CABINETRY</option>
                                            <option value="FLOORING">FLOORING</option>
                                            <option value="DRAPERY / WINDOW TREATMENTS">DRAPERY / WINDOW TREATMENTS</option>
                                            <option value="GLASS / MIRRORS">GLASS / MIRRORS</option>
                                            <option value="HARDWARE / ACCESSORIES">HARDWARE / ACCESSORIES</option>
                                            <option value="RUGS / CARPETS">RUGS / CARPETS</option>
                                            <option value="WALLCOVERINGS / FINISHES">WALLCOVERINGS / FINISHES</option>
                                            <option value="APPLIANCES">APPLIANCES</option>
                                            <option value="OTHER">OTHER</option>
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
                                    
                                    {/* Smart Alternatives removed from designer request-a-quote form; default is no (false) */}
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
                                                <span></span>
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

                                    {/* Launch Brief content (integrated into Mission Spec tab) */}
                                    {activeTab === 'details' && (
                                        <div>
                                            {/* Helper text before Request RFQ form — show always */}
                                            <div style={{
                                                marginBottom: '20px',
                                                padding: '12px 16px',
                                                backgroundColor: 'rgba(0,0,0,0.2)',
                                                border: `1px solid ${darkBorder}`,
                                                borderRadius: '4px',
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                gap: '10px',
                                            }}>
                                                <span style={{ fontSize: '18px', lineHeight: 1, opacity: 0.9 }} title="Helper">ℹ️</span>
                                                <div style={{ flex: 1, fontSize: '12px', color: darkText, lineHeight: 1.5 }}>
                                                    When you're ready, you can request quotes now or return later after refining your specifications.
                                                </div>
                                            </div>
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
                                            
                                            {/* State B: Editable Dimensions and Quantity - locked when awaiting payment (Lock Designer Editing While Awaiting Payment) */}
                                            {(currentState === 'B' || currentState === 'C') && (
                                                <>
                                                    <div style={{
                                                        border: `1px solid ${darkBorder}`,
                                                        borderRadius: '4px',
                                                        padding: '16px',
                                                        backgroundColor: '#111111',
                                                        marginBottom: '24px',
                                                        opacity: isLockedAwaitingPayment ? 0.7 : 1,
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
                                                                    disabled={isLockedAwaitingPayment}
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
                                                                    disabled={isLockedAwaitingPayment}
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
                                                                    disabled={isLockedAwaitingPayment}
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
                                                                    disabled={isLockedAwaitingPayment}
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
                                                                disabled={isLockedAwaitingPayment}
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

                                                        {/* Inline Update button for dimensions & quantity (always visible on Item Specification tab) */}
                                                        <div style={{ marginTop: '4px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px' }}>
                                                            <span style={{ fontSize: '11px', color: '#999' }}>
                                                                Edit RFQ details and click <strong>Update</strong>.
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={handleUpdateDimensions}
                                                                disabled={isSaving || isUploadingInspiration || isLockedAwaitingPayment}
                                                                style={{
                                                                    padding: '8px 16px',
                                                                    backgroundColor: '#111111',
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    color: darkText,
                                                                    fontSize: '12px',
                                                                    fontFamily: 'monospace',
                                                                    cursor: (isSaving || isUploadingInspiration || isLockedAwaitingPayment) ? 'not-allowed' : 'pointer',
                                                                    fontWeight: '600',
                                                                    opacity: (isSaving || isUploadingInspiration || isLockedAwaitingPayment) ? 0.6 : 1,
                                                                }}
                                                            >
                                                                {isUploadingInspiration ? 'Uploading...' : (isSaving ? 'Updating...' : 'Update')}
                                                            </button>
                                                        </div>
                                                    </div>
                                                    
                                                </>
                                            )}
                                            
                                            {/* Request Quote Button / RFQ Form / Specs Updated Panel */}
                            {/* Commit 2.6.1: Hide RFQ submission for view-only team members */}
                            {currentState === 'A' && !isViewOnly && (
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
                                                                        itemQuantity={item?.quantity ?? item?.meta?.quantity}
                                                                        itemCbm={computedValues?.cbm ?? item?.cbm}
                                                                        itemDimsCm={fallbackDimsCm}
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
                                                                        itemQuantity={item?.quantity ?? item?.meta?.quantity}
                                                                        itemCbm={computedValues?.cbm ?? item?.cbm}
                                                                        itemDimsCm={fallbackDimsCm}
                                                                    />
                                                                </div>
                                                            )}
                                                        </>
                                                    );
                                                })()}
                                            </div>
                                        </div>
                                    ) : (
                                        <div style={{
                                            border: `1px solid ${darkBorder}`,
                                            borderRadius: '4px',
                                            padding: '16px',
                                            backgroundColor: '#111111',
                                        }}>
                                            <div style={{ fontSize: '14px', fontWeight: '600', marginBottom: '16px' }}>
                                                Request Quote
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
                                                    Final Delivery ZIP Code
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

                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px' }}>
                                                    Preferred Delivery Date
                                                </label>
                                                <input
                                                    type="date"
                                                    value={preferredDeliveryDate}
                                                    onChange={(e) => setPreferredDeliveryDate(e.target.value)}
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
                                            
                                            {/* Inspiration / References / Sketch Drawings (inside RFQ form) – COMMIT 3.C.3 */}
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
                                                            key={insp.id || insp.url || idx}
                                                            style={{
                                                                width: '80px',
                                                                flexShrink: 0,
                                                            }}
                                                        >
                                                            <div
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
                                                                        if (insp.type === 'pdf' || insp.type === 'link' || (insp.url || '').toLowerCase().endsWith('.pdf')) {
                                                                            window.open(insp.url, '_blank');
                                                                        } else {
                                                                            setLightboxImage(insp.url);
                                                                        }
                                                                    }
                                                                }}
                                                            >
                                                                {insp.url ? (
                                                                    (insp.type === 'pdf' || (insp.url || '').toLowerCase().endsWith('.pdf')) ? (
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
                                                                    ) : insp.type === 'link' ? (
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
                                                                            <div style={{ fontSize: '24px' }}>🔗</div>
                                                                            <div style={{ fontSize: '8px', color: '#999' }}>Link</div>
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
                                                                    type="button"
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
                                                            <input
                                                                type="text"
                                                                value={(insp.caption || '').slice(0, 100)}
                                                                onChange={(e) => setInspirationCaption(idx, e.target.value)}
                                                                placeholder="Caption (max 100)"
                                                                maxLength={100}
                                                                style={{
                                                                    width: '100%',
                                                                    marginTop: '4px',
                                                                    padding: '4px 6px',
                                                                    fontSize: '10px',
                                                                    backgroundColor: darkBg,
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    color: darkText,
                                                                    fontFamily: 'monospace',
                                                                    boxSizing: 'border-box',
                                                                }}
                                                            />
                                                        </div>
                                                    ))}
                                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', alignItems: 'flex-start' }}>
                                                        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' }}>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    const input = document.getElementById('inspiration-file-input-rfq-images');
                                                                    if (input) input.click();
                                                                }}
                                                                disabled={isUploadingInspiration}
                                                                style={{
                                                                    padding: '6px 10px',
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    backgroundColor: '#111111',
                                                                    color: darkText,
                                                                    cursor: isUploadingInspiration ? 'not-allowed' : 'pointer',
                                                                    fontSize: '11px',
                                                                    fontFamily: 'monospace',
                                                                }}
                                                            >
                                                                {isUploadingInspiration ? '...' : 'Add Photos'}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    const input = document.getElementById('inspiration-file-input-rfq-pdf');
                                                                    if (input) input.click();
                                                                }}
                                                                disabled={isUploadingInspiration}
                                                                style={{
                                                                    padding: '6px 10px',
                                                                    border: `1px solid ${darkBorder}`,
                                                                    borderRadius: '4px',
                                                                    backgroundColor: '#111111',
                                                                    color: darkText,
                                                                    cursor: isUploadingInspiration ? 'not-allowed' : 'pointer',
                                                                    fontSize: '11px',
                                                                    fontFamily: 'monospace',
                                                                }}
                                                            >
                                                                {isUploadingInspiration ? '...' : 'Add PDF'}
                                                            </button>
                                                            <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                                                                <input
                                                                    type="text"
                                                                    value={inspirationLinkInput}
                                                                    onChange={(e) => setInspirationLinkInput(e.target.value)}
                                                                    onKeyPress={(e) => { if (e.key === 'Enter') { e.preventDefault(); addInspirationLink(); } }}
                                                                    placeholder="Paste URL"
                                                                    style={{
                                                                        width: '140px',
                                                                        padding: '6px 8px',
                                                                        fontSize: '11px',
                                                                        backgroundColor: darkBg,
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        color: darkText,
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={addInspirationLink}
                                                                    style={{
                                                                        padding: '6px 10px',
                                                                        border: `1px solid ${darkBorder}`,
                                                                        borderRadius: '4px',
                                                                        backgroundColor: '#111111',
                                                                        color: darkText,
                                                                        cursor: 'pointer',
                                                                        fontSize: '11px',
                                                                        fontFamily: 'monospace',
                                                                    }}
                                                                >
                                                                    Add Link
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <input
                                                    type="file"
                                                    id="inspiration-file-input-rfq-images"
                                                    accept="image/*,.heic,.heif"
                                                    multiple
                                                    onChange={handleInspirationFileChange}
                                                    style={{ display: 'none' }}
                                                    disabled={isUploadingInspiration}
                                                />
                                                <input
                                                    type="file"
                                                    id="inspiration-file-input-rfq-pdf"
                                                    accept=".pdf,application/pdf"
                                                    multiple
                                                    onChange={handleInspirationFileChange}
                                                    style={{ display: 'none' }}
                                                    disabled={isUploadingInspiration}
                                                />
                                            </div>
                                            
                                            {/* Global RFQ Notes (Overall Notes) – COMMIT 3.C.3 */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                                                    Overall Notes
                                                </label>
                                                <div style={{ fontSize: '11px', color: '#999', marginBottom: '6px' }}>
                                                    Overall notes for these references (dimensions, finish, proportions, performance requirements).
                                                </div>
                                                <textarea
                                                    value={rfqOverallNotes}
                                                    onChange={(e) => setRfqOverallNotes(e.target.value.slice(0, 100))}
                                                    maxLength={100}
                                                    placeholder="Overall notes for these references…"
                                                    rows={2}
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
                                                        boxSizing: 'border-box',
                                                    }}
                                                />
                                                <div style={{ fontSize: '10px', color: '#666', marginTop: '4px' }}>
                                                    {rfqOverallNotes.length}/100
                                                </div>
                                            </div>
                                            
                                            {/* Fabric Supplied? – COMMIT 3.C.3 */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                                                    Where applicable, will you supply fabric?
                                                </label>
                                                <div style={{ fontSize: '11px', color: '#999', marginBottom: '8px' }}>
                                                    Use Yes if you will supply fabric (COM). Use No if the supplier should quote using in-house fabric/material where applicable.
                                                </div>
                                                <div style={{ display: 'flex', gap: '16px', alignItems: 'center', marginBottom: '8px' }}>
                                                    <label style={{ display: 'flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}>
                                                        <input
                                                            type="radio"
                                                            name="fabric-supplied-rfq"
                                                            checked={fabricSupplied === 'yes'}
                                                            onChange={() => setFabricSupplied('yes')}
                                                            style={{ width: '16px', height: '16px', cursor: 'pointer' }}
                                                        />
                                                        <span style={{ fontSize: '12px' }}>Yes</span>
                                                    </label>
                                                    <label style={{ display: 'flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}>
                                                        <input
                                                            type="radio"
                                                            name="fabric-supplied-rfq"
                                                            checked={fabricSupplied === 'no'}
                                                            onChange={() => setFabricSupplied('no')}
                                                            style={{ width: '16px', height: '16px', cursor: 'pointer' }}
                                                        />
                                                        <span style={{ fontSize: '12px' }}>No</span>
                                                    </label>
                                                </div>
                                                {fabricSupplied === 'yes' && (
                                                    <div style={{ marginTop: '8px' }}>
                                                        <label style={{ display: 'block', fontSize: '11px', marginBottom: '4px', color: '#999' }}>
                                                            Fabric Notes (optional)
                                                        </label>
                                                        <textarea
                                                            value={fabricNotes}
                                                            onChange={(e) => setFabricNotes(e.target.value)}
                                                            placeholder="Fabric type, yardage assumptions, testing requirements, shipping timing."
                                                            rows={2}
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
                                                                boxSizing: 'border-box',
                                                            }}
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                            
                                            {/* Invite Suppliers */}
                                            <div style={{ marginBottom: '12px' }}>
                                                <div style={{ fontSize: '12px', fontWeight: '600', marginBottom: '8px' }}>
                                                    Invite Suppliers
                                                </div>
                                                <div style={{ fontSize: '11px', color: '#999', marginBottom: '8px' }}>
                                                    Enter existing supplier username(s) or email address(es). Press Enter or click Add. (1-5 invites)
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
                                                                        setSystemInvitesMessage('');
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
                                                <div
                                                    id="n88-step1-payment-required"
                                                    style={{
                                                        marginBottom: '24px',
                                                        padding: '20px',
                                                        backgroundColor: '#331100',
                                                        border: '2px solid #ff8800',
                                                        borderRadius: '4px',
                                                    }}>
                                                    <div
                                                        id="n88-step1-payment-required-heading"
                                                        style={{
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
                                            {/* Payment Confirmed & Prototype Video moved to Workflow → Step 1 & Step 3 (aligned with admin) */}
                                            {itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    padding: '16px',
                                                    border: `1px solid ${darkBorder}`,
                                                    borderRadius: '4px',
                                                    backgroundColor: 'rgba(0,0,0,0.2)',
                                                }}>
                                                    <div style={{ fontSize: '12px', color: darkText, marginBottom: '8px' }}>
                                                        Payment Confirmed and Prototype Video are in Workflow tab → Step 1 & Step 3.
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setActiveTab('workflow');
                                                            setSelectedStepIndex((itemState.prototype_submission?.links?.length) ? 2 : 0);
                                                        }}
                                                        style={{
                                                            padding: '8px 16px',
                                                            background: greenAccent,
                                                            color: '#000',
                                                            border: 'none',
                                                            borderRadius: '4px',
                                                            cursor: 'pointer',
                                                            fontWeight: '600',
                                                            fontSize: '12px',
                                                        }}
                                                    >
                                                        Open Workflow
                                                    </button>
                                                </div>
                                            )}
                                            {/* Prototype approved — Award project (Proposals tab) */}
                                            {!isViewOnly && itemState.prototype_status === 'approved' && !itemState.has_awarded_bid && itemState.prototype_payment_bid_id && (
                                                <div style={{
                                                    marginBottom: '24px',
                                                    padding: '16px',
                                                    border: `1px solid ${greenAccent}`,
                                                    borderRadius: '4px',
                                                    backgroundColor: 'rgba(0,51,0,0.15)',
                                                }}>
                                                    <div style={{ fontSize: '13px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Prototype approved</div>
                                                    <div style={{ fontSize: '12px', color: darkText, marginBottom: '12px' }}>Award the project when ready.</div>
                                                    <button
                                                        type="button"
                                                        onClick={async () => {
                                                            if (!window.confirm('Award this project to the supplier? All other bids will be declined.')) return;
                                                            const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                            const nonce = window.n88BoardNonce?.nonce_award_bid || window.n88BoardData?.nonce || window.n88?.nonce;
                                                            if (!nonce) { alert('Security token missing.'); return; }
                                                            const fd = new FormData();
                                                            fd.append('action', 'n88_award_bid');
                                                            fd.append('item_id', String(getItemId()));
                                                            fd.append('bid_id', String(itemState.prototype_payment_bid_id));
                                                            fd.append('_ajax_nonce', nonce);
                                                            try {
                                                                const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                                                                const data = await res.json();
                                                                if (data.success) { if (onSave) await onSave(getItemId(), {}); fetchItemState(); onClose(); }
                                                                else alert(data.data?.message || 'Failed to award.');
                                                            } catch (e) { console.error(e); alert('Error awarding. Please try again.'); }
                                                        }}
                                                        style={{ padding: '10px 20px', background: '#FF0065', color: '#000', border: 'none', borderRadius: '4px', fontSize: '12px', fontWeight: '600', cursor: 'pointer', fontFamily: 'monospace' }}
                                                    >
                                                        Award Bid
                                                    </button>
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
                                                                const kid = typeof keywordId === 'number' ? keywordId : Number(keywordId);
                                                                const keywordData = feedbackPacket[kid] || { status: 'satisfied', severity: null, phrase_ids: [], revision_detail: '' };
                                                                const phrases = availablePhrases[keywordId] || [];
                                                                
                                                                return (
                                                                    <div key={kid} style={{
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
                                                                                        name={`keyword_${kid}_status`}
                                                                                        value={status}
                                                                                        checked={keywordData.status === status}
                                                                                        onChange={() => {
                                                                                            const newPacket = { ...feedbackPacket };
                                                                                            newPacket[kid] = {
                                                                                                ...keywordData,
                                                                                                status: status,
                                                                                                severity: status === 'not_addressed' ? 'must_fix' : status === 'needs_adjustment' ? 'should_fix' : null,
                                                                                                phrase_ids: status === 'satisfied' ? [] : keywordData.phrase_ids,
                                                                                                revision_detail: status === 'satisfied' ? '' : (keywordData.revision_detail || ''),
                                                                                            };
                                                                                            setFeedbackPacket(newPacket);
                                                                                            // Always fetch phrases if not already loaded when status changes to needs_adjustment or not_addressed
                                                                                            if (status !== 'satisfied' && (!availablePhrases[keywordId] || availablePhrases[keywordId].length === 0)) {
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
                                                                                                        keyword_ids: JSON.stringify([kid]),
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
                                                                        
                                                                        {/* Phrase Selection (only if not satisfied; show when phrases loaded) */}
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
                                                                                                                newPacket[kid] = {
                                                                                                                    ...keywordData,
                                                                                                                    phrase_ids: [...currentPhraseIds, phrase.phrase_id],
                                                                                                                };
                                                                                                                setTotalPhrasesSelected(totalPhrasesSelected + 1);
                                                                                                            }
                                                                                                        } else {
                                                                                                            newPacket[kid] = {
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
                                                                                    {availablePhrases[keywordId] ? 'No phrases for this keyword.' : 'Loading phrases...'}
                                                                                </div>
                                                                            )
                                                                        )}
                                                                        {/* Severity + Revision Detail: always show when keyword is not satisfied (Commit 3.B.5A) */}
                                                                        {keywordData.status !== 'satisfied' && (
                                                                            <>
                                                                                <div style={{ marginTop: '12px' }}>
                                                                                    <div style={{ fontSize: '12px', color: '#aaa', marginBottom: '6px' }}>Severity:</div>
                                                                                    <select
                                                                                        value={keywordData.severity || (keywordData.status === 'not_addressed' ? 'must_fix' : 'should_fix')}
                                                                                        onChange={(e) => {
                                                                                            const newPacket = { ...feedbackPacket };
                                                                                            newPacket[kid] = { ...keywordData, severity: e.target.value };
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
                                                                                <div style={{ marginTop: '12px' }}>
                                                                                    <div style={{ fontSize: '12px', color: '#aaa', marginBottom: '6px' }}>
                                                                                        Additional Revision Detail (Optional)
                                                                                    </div>
                                                                                    <div style={{ fontSize: '11px', color: '#666', marginBottom: '4px' }}>
                                                                                        Do not include phone numbers, emails, or personal contact info.
                                                                                    </div>
                                                                                    <textarea
                                                                                        placeholder="Describe exactly what should be different in the revised video…"
                                                                                        maxLength={200}
                                                                                        value={keywordData.revision_detail || ''}
                                                                                        onChange={(e) => {
                                                                                            const newPacket = { ...feedbackPacket };
                                                                                            newPacket[kid] = { ...keywordData, revision_detail: e.target.value };
                                                                                            setFeedbackPacket(newPacket);
                                                                                        }}
                                                                                        style={{
                                                                                            width: '100%',
                                                                                            minHeight: '60px',
                                                                                            padding: '8px 12px',
                                                                                            backgroundColor: '#000',
                                                                                            color: '#fff',
                                                                                            border: '1px solid #333',
                                                                                            borderRadius: '4px',
                                                                                            fontSize: '12px',
                                                                                            fontFamily: 'monospace',
                                                                                            resize: 'vertical',
                                                                                        }}
                                                                                    />
                                                                                    <div style={{ fontSize: '11px', color: '#666', marginTop: '4px' }}>
                                                                                        {(keywordData.revision_detail || '').length}/200
                                                                                    </div>
                                                                                </div>
                                                                            </>
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
                                                                    // Check all revision_detail fields for phone/email/personal info
                                                                    const allRevisionText = Object.keys(feedbackPacket || {}).map((k) => {
                                                                        const d = feedbackPacket[k];
                                                                        return (d && typeof d.revision_detail === 'string') ? d.revision_detail : '';
                                                                    }).join(' ');
                                                                    const personalCheck = detectPersonalInfoInFeedback(allRevisionText);
                                                                    if (personalCheck.blocked) {
                                                                        alert(personalCheck.message);
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
                                                                            // Redirect to project main board (same project/room as this item) for easy return
                                                                            const urlParams = new URLSearchParams(window.location.search);
                                                                            const boardId = urlParams.get('board_id');
                                                                            const pid = item.project_id ?? item.projectId ?? item.meta?.project_id;
                                                                            const rid = item.room_id ?? item.roomId ?? item.meta?.room_id;
                                                                            const params = new URLSearchParams();
                                                                            params.set('page', 'n88-rfq-board-demo');
                                                                            if (boardId) params.set('board_id', String(boardId));
                                                                            if (pid) params.set('project_id', String(pid));
                                                                            if (rid) params.set('room_id', String(rid));
                                                                            window.location.href = window.location.pathname + '?' + params.toString();
                                                                            return;
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
                                                itemQuantity={item?.quantity ?? item?.meta?.quantity}
                                                itemCbm={computedValues?.cbm ?? item?.cbm}
                                                itemDimsCm={fallbackDimsCm}
                                            />
                                            
                                            {/* Request Prototype Video Button/Form (Commit 2.3.9.1B) - hide when CAD request already submitted */}
                                            {!itemState.has_prototype_payment && ( !showCadPrototypeForm ? (
                                                <div style={{ marginTop: '20px', textAlign: 'center' }}>
                                                    <button
                                                        onClick={() => {
                                                            // Auto-select first bid if only one bid exists
                                                            if (itemState.bids && itemState.bids.length === 1) {
                                                                setSelectedBidId(itemState.bids[0].bid_id);
                                                            }
                                                            setHasRequestedCategoryKeywords(true);
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
                                                        Request Prototype Video
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
                                                            Request Prototype Video
                                                        </div>
                                                        <button
                                                            onClick={() => {
                                                                setShowCadPrototypeForm(false);
                                                                setHasRequestedCategoryKeywords(false);
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
                                                        <div style={{ fontSize: '13px', fontWeight: '600', marginBottom: '4px', color: darkText }}>
                                                            Video Direction
                                                        </div>
                                                        <div style={{ fontSize: '11px', color: '#ff4444', marginBottom: '8px' }}>
                                                            Select up to 7 keywords that are most important for the supplier to focus on when filming the prototype video.
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
                                                                        setHasRequestedCategoryKeywords(true);
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
                                                            <label style={{ display: 'block', fontSize: '12px', marginBottom: '4px', color: darkText }}>
                                                                Additional Note (Optional, max 240 characters)
                                                            </label>
                                                            <div style={{ fontSize: '11px', color: '#ff4444', marginBottom: '8px' }}>
                                                                Add additional notes for clear description of what you are looking for
                                                            </div>
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
                                                                    // Lock Launch Brief immediately when CAD request is submitted
                                                                    setItemState(prev => ({ ...prev, has_prototype_payment: true, prototype_payment_status: 'requested' }));
                                                                    // Commit 2.3.9.1C: Refresh item state to show payment banner
                                                                    fetchItemState();
                                                                    // Scroll to payment required box so it's visible at top (Step 1)
                                                                    setTimeout(() => {
                                                                        const paymentEl = document.getElementById('n88-step1-payment-required-heading');
                                                                        if (paymentEl) {
                                                                            paymentEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                                                        } else {
                                                                            const formElement = document.getElementById('cad-prototype-form-container');
                                                                            if (formElement) formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                                                        }
                                                                    }, 350);
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
                                            ) )}
                                            
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
                                            {/* Right: 40% — fixed Support / message box (no scroll with left) */}
                                            <div
                                                id="n88-item-spec-support-box"
                                                style={{
                                                    flex: '0 0 40%',
                                                    maxWidth: '40%',
                                                    overflow: 'hidden',
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    minHeight: 0,
                                                }}
                                            >
                                                <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '12px' }}>Support</div>
                                                <div style={{ border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '12px', backgroundColor: '#111' }}>
                                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                                                            <span style={{ fontSize: '12px', color: darkText }}>Check files for CAD · Check messages below</span>
                                                            {/* No close button in Item Spec; support is always visible */}
                                                        </div>
                                                        <div id="n88-designer-messages-container-details" style={{ height: '320px', overflowY: 'auto', padding: '12px', backgroundColor: '#0a0a0a', borderRadius: '4px', marginBottom: '12px', border: `1px solid ${darkBorder}` }}>
                                                            {isLoadingDesignerMessages ? <div style={{ textAlign: 'center', color: '#888', padding: '20px' }}>Loading conversation…</div> : designerMessages.length === 0 ? <div style={{ textAlign: 'center', color: '#666', fontSize: '12px' }}>No messages yet.</div> : (
                                                                [...designerMessages].sort((a, b) => new Date(a.created_at) - new Date(b.created_at)).map((msg, idx) => {
                                                                    const isDesigner = msg.sender_role === 'designer';
                                                                    const isOperatorView = !!(window.n88BoardData && window.n88BoardData.isOperator);
                                                                    const senderName = isDesigner ? (isOperatorView ? 'Comment by designer' : 'You') : 'Operator';
                                                                    const rawText = (msg.message_text || '').substring(0, 2000);
                                                                    const hasFilesBlock = rawText.indexOf('CAD Files:') !== -1 || rawText.indexOf('Files:') !== -1;
                                                                    let displayText = rawText;
                                                                    const fileList = [];
                                                                    if (hasFilesBlock) {
                                                                        const lines = rawText.split('\n');
                                                                        let filesStart = -1;
                                                                        for (let li = 0; li < lines.length; li++) {
                                                                            const trimmed = (lines[li] || '').trim();
                                                                            if (trimmed === 'CAD Files:' || trimmed === 'Files:') { filesStart = li; break; }
                                                                        }
                                                                        if (filesStart >= 0) {
                                                                            let filesEnd = lines.length;
                                                                            for (let li = filesStart + 1; li < lines.length; li++) {
                                                                                const t = (lines[li] || '').trim();
                                                                                if (t.indexOf('Direction Keywords') === 0 || t === '') { filesEnd = li; break; }
                                                                            }
                                                                            displayText = lines.slice(0, filesStart).join('\n').trim();
                                                                            for (let fi = filesStart + 1; fi < filesEnd; fi++) {
                                                                                const line = (lines[fi] || '').trim();
                                                                                if (line.indexOf('- ') === 0) {
                                                                                    const withoutDash = line.slice(2);
                                                                                    const sepIdx = withoutDash.indexOf(': ');
                                                                                    if (sepIdx > 0) {
                                                                                        const fileName = withoutDash.slice(0, sepIdx).trim();
                                                                                        const fileUrl = withoutDash.slice(sepIdx + 2).trim();
                                                                                        if (fileUrl.startsWith('http://') || fileUrl.startsWith('https://')) fileList.push({ name: fileName, url: fileUrl });
                                                                                    }
                                                                                }
                                                                            }
                                                                        }
                                                                    }
                                                                    const urlRe = /(https?:\/\/[^\s<>"']+)/gi;
                                                                    const parts = [];
                                                                    let urlM;
                                                                    let last = 0;
                                                                    while ((urlM = urlRe.exec(displayText)) !== null) {
                                                                        if (urlM.index > last) parts.push({ t: 'text', v: displayText.slice(last, urlM.index) });
                                                                        const url = urlM[0];
                                                                        const isImg = /\.(jpe?g|png|gif|webp)(\?|$)/i.test(url);
                                                                        parts.push({ t: isImg ? 'image' : 'file', v: url });
                                                                        last = urlRe.lastIndex;
                                                                    }
                                                                    if (last < displayText.length) parts.push({ t: 'text', v: displayText.slice(last) });
                                                                    const content = parts.length === 0 ? displayText : parts.map((p, i) => {
                                                                        if (p.t === 'text') return <React.Fragment key={i}>{p.v}</React.Fragment>;
                                                                        if (p.t === 'image') return (
                                                                            <span key={i} style={{ display: 'inline-block', marginTop: 6, marginBottom: 4 }}>
                                                                                <img src={p.v} alt="" style={{ maxWidth: 120, maxHeight: 80, objectFit: 'contain', display: 'block', borderRadius: 4, border: `1px solid ${darkBorder}` }} onError={(e) => { e.target.style.display = 'none'; const n = e.target.nextSibling; if (n) n.style.display = 'block'; }} />
                                                                                <a href={p.v} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent, fontSize: '11px', marginTop: 4, display: 'none' }}>View image →</a>
                                                                            </span>
                                                                        );
                                                                        return <a key={i} href={p.v} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent, fontSize: '11px', display: 'inline-block', marginTop: 4, marginRight: 8 }}>View file →</a>;
                                                                    });
                                                                    return (
                                                                        <div key={idx} style={{ marginBottom: '10px', textAlign: isDesigner ? 'right' : 'left' }}>
                                                                            <div style={{ display: 'inline-block', maxWidth: '85%', padding: '8px 12px', backgroundColor: isDesigner ? '#1a1a1a' : '#0a0a0a', border: `1px solid ${isDesigner ? greenAccent : '#333'}`, borderRadius: '8px', fontSize: '11px', color: '#fff', whiteSpace: 'pre-wrap' }}>
                                                                                <div style={{ fontSize: '10px', fontWeight: 600, color: isDesigner ? greenAccent : '#00aa00', marginBottom: 4 }}>{senderName}</div>
                                                                                {content}
                                                                                {fileList.length > 0 && (
                                                                                    <div style={{ marginTop: 10, paddingTop: 8, borderTop: `1px solid ${darkBorder}`, display: 'flex', flexDirection: 'column', gap: 6 }}>
                                                                                        {fileList.map((file, fi) => {
                                                                                            const isImageFile = /\.(jpe?g|png|gif|webp)(\?|$)/i.test(file.name);
                                                                                            return (
                                                                                                <a key={fi} href={file.url} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 10px', backgroundColor: '#0a0a0a', border: '1px solid #333', borderRadius: 4, color: '#fff', textDecoration: 'none', fontSize: '11px', cursor: 'pointer' }}>
                                                                                                    {isImageFile ? (
                                                                                                        <span style={{ width: 40, height: 40, flexShrink: 0, borderRadius: 4, overflow: 'hidden', backgroundColor: '#222', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                                                                                            <img src={file.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={(e) => { e.target.style.display = 'none'; if (e.target.parentNode) { const ph = e.target.parentNode; ph.style.fontSize = '18px'; ph.textContent = '🖼️'; } }} />
                                                                                                        </span>
                                                                                                    ) : (
                                                                                                        <span style={{ fontSize: '14px' }}>{file.name.toLowerCase().endsWith('.pdf') ? '📄' : '📎'}</span>
                                                                                                    )}
                                                                                                    <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={file.name}>{file.name}</span>
                                                                                                    <span style={{ color: greenAccent, fontSize: '10px', flexShrink: 0 }}>Open in new tab →</span>
                                                                                                </a>
                                                                                            );
                                                                                        })}
                                                                                    </div>
                                                                                )}
                                                                                <div style={{ fontSize: '9px', color: '#666', textAlign: 'right', marginTop: 4 }}>{msg.created_at ? new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</div>
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                })
                                                            )}
                                                        </div>
                                                        <form onSubmit={sendDesignerMessage} style={{ display: 'flex', gap: '8px', alignItems: 'flex-end' }}>
                                                            <textarea
                                                                value={designerMessageText}
                                                                onChange={(e) => setDesignerMessageText(e.target.value)}
                                                                required
                                                                rows={2}
                                                                style={{ flex: 1, padding: '10px 12px', backgroundColor: '#000', color: '#fff', border: `1px solid ${darkBorder}`, borderRadius: '20px', fontFamily: 'monospace', fontSize: '12px', resize: 'none', minHeight: '40px', maxHeight: '100px' }}
                                                                placeholder="Type your message here..."
                                                                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (designerMessageText.trim()) sendDesignerMessage(e); } }}
                                                            />
                                                            <button
                                                                type="submit"
                                                                disabled={isSendingDesignerMessage || !designerMessageText.trim()}
                                                                style={{ padding: '10px 20px', backgroundColor: (isSendingDesignerMessage || !designerMessageText.trim()) ? '#333' : greenAccent, color: (isSendingDesignerMessage || !designerMessageText.trim()) ? '#666' : '#000', border: 'none', borderRadius: '20px', fontFamily: 'monospace', fontSize: '12px', fontWeight: '600', cursor: (isSendingDesignerMessage || !designerMessageText.trim()) ? 'not-allowed' : 'pointer', whiteSpace: 'nowrap' }}
                                                            >{isSendingDesignerMessage ? 'Sending...' : 'Send'}</button>
                                                        </form>
                                                    </div>
                                            </div>
                                        </div>
                                        </>
                                    )}

                                    {/* Tab: The Workflow (Commit 3.A.1 — read-only 6-step timeline) */}
                                    {activeTab === 'workflow' && (
                                        <div style={{ fontFamily: 'monospace' }}>
                                            <div style={{ marginBottom: '16px', fontSize: '11px', color: darkText }}>
                                            </div>
                                            {timelineLoading && (
                                                <div style={{ padding: '24px', textAlign: 'center', color: darkText }}>Loading timeline…</div>
                                            )}
                                            {timelineError && (
                                                <div style={{ padding: '16px', border: `1px solid ${darkBorder}`, borderRadius: '4px', color: '#cc6666', marginBottom: '16px' }}>{timelineError}</div>
                                            )}
                                            {/* Operator: standalone Step 4 Deposit block — always visible when item has awarded bid, even before timeline loads or when timeline has < 6 steps */}
                                            {false && !timelineLoading && itemState && itemState.has_awarded_bid && window.n88BoardData && window.n88BoardData.isOperator && (
                                                <div style={{ marginBottom: '20px', padding: '16px', border: '1px solid #FF0065', borderRadius: '4px', backgroundColor: 'rgba(0,0,0,0.2)' }}>
                                                    <div style={{ fontSize: '13px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Step 4 — Sent Deposit to start production</div>
                                                    {itemState.deposit_status === 'received' ? (
                                                        <div style={{ fontSize: '11px', color: greenAccent }}>
                                                            ✓ Deposit received {itemState.deposit_received_at ? new Date(itemState.deposit_received_at).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : ''}. Production (Step 4) can be started.
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <div style={{ fontSize: '11px', color: darkText, marginBottom: '8px' }}>
                                                                {itemState.deposit_amount != null ? `$${Number(itemState.deposit_amount).toFixed(2)} pending.` : 'Deposit pending.'}{' '}
                                                                {itemState.deposit_status === 'sent_by_designer' ? 'Designer has submitted payment proof — review and approve below.' : 'Waiting for designer to submit deposit payment proof.'}
                                                            </div>
                                                            {itemState.deposit_status === 'sent_by_designer' && (
                                                                <div style={{ marginTop: '12px', padding: '12px', background: 'rgba(0,0,0,0.3)', border: '1px solid #555', borderRadius: '4px' }}>
                                                                    <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Review payment proof</div>
                                                                    {itemState.deposit_receipt_url && (
                                                                        <a href={itemState.deposit_receipt_url} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-block', padding: '6px 12px', fontSize: '11px', background: '#003300', color: greenAccent, border: `1px solid ${greenAccent}`, borderRadius: '4px', textDecoration: 'none', fontFamily: 'monospace', fontWeight: '600', marginBottom: '8px' }}>
                                                                            View payment receipt
                                                                        </a>
                                                                    )}
                                                                    {(itemState.deposit_sent_note && String(itemState.deposit_sent_note).trim()) && (
                                                                        <div style={{ marginBottom: '10px' }}>
                                                                            <div style={{ fontSize: '11px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Designer comment</div>
                                                                            <div style={{ fontSize: '11px', color: '#ccc', whiteSpace: 'pre-wrap', padding: '8px', background: '#111', borderRadius: '4px', border: '1px solid #333' }}>{itemState.deposit_sent_note}</div>
                                                                            {itemState.deposit_sent_at && <div style={{ fontSize: '10px', color: '#888', marginTop: '4px' }}>Sent {new Date(itemState.deposit_sent_at).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}</div>}
                                                                        </div>
                                                                    )}
                                                                    <button
                                                                        type="button"
                                                                        onClick={async () => {
                                                                            if (!window.confirm('Mark deposit as received for this item? Production (Step 4) will then be allowed to start.')) return;
                                                                            const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce;
                                                                            if (!nonce) { alert('Security token missing.'); return; }
                                                                            const fd = new FormData();
                                                                            fd.append('action', 'n88_mark_deposit_received');
                                                                            fd.append('item_id', String(getItemId()));
                                                                            fd.append('_ajax_nonce', nonce);
                                                                            try {
                                                                                const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
                                                                                const data = await res.json();
                                                                                if (data.success) { fetchItemState(); fetchTimeline(); }
                                                                                else alert(data.data?.message || 'Failed.');
                                                                            } catch (e) { console.error(e); alert('Error. Please try again.'); }
                                                                        }}
                                                                        style={{ padding: '8px 12px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontFamily: 'monospace', fontWeight: '600' }}
                                                                    >
                                                                        Approve (mark deposit received)
                                                                    </button>
                                                                </div>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            )}
                                            {!timelineLoading && !timelineError && timelineData && timelineData.steps && timelineData.steps.length >= 6 && (
                                                <>
                                                    {/* Horizontal 6-step row with connector lines between steps — sticky */}
                                                    <div style={{
                                                        position: 'sticky',
                                                        top: 0,
                                                        zIndex: 10,
                                                        backgroundColor: '#0a0a0a',
                                                        marginTop: '-20px',
                                                        marginBottom: '24px',
                                                        paddingTop: '20px',
                                                        paddingBottom: '12px',
                                                        borderBottom: `1px solid ${darkBorder}`,
                                                        display: 'flex',
                                                        alignItems: 'flex-start',
                                                        justifyContent: 'space-between',
                                                        gap: 0,
                                                    }}>
                                                        {timelineData.steps.flatMap((step, idx) => {
                                                            const isSelected = selectedStepIndex === idx;
                                                            const stepEl = (
                                                                <div
                                                                    key={step.step_id ?? idx}
                                                                    onClick={() => { setSelectedStepIndex(idx); setSupplierStepEvidenceView(null); }}
                                                                    style={{
                                                                        flex: 1,
                                                                        display: 'flex',
                                                                        flexDirection: 'column',
                                                                        alignItems: 'center',
                                                                        cursor: 'pointer',
                                                                        minWidth: 0,
                                                                    }}
                                                                >
                                                                    <div style={{
                                                                        width: '28px',
                                                                        height: '28px',
                                                                        borderRadius: '50%',
                                                                        border: `2px solid ${isSelected ? greenAccent : darkBorder}`,
                                                                        background: isSelected ? greenAccent : '#333',
                                                                        color: isSelected ? '#0a0a0a' : '#888',
                                                                        fontSize: '12px',
                                                                        fontWeight: '600',
                                                                        display: 'flex',
                                                                        alignItems: 'center',
                                                                        justifyContent: 'center',
                                                                        marginBottom: '6px',
                                                                    }}>
                                                                        {step.step_number}
                                                                    </div>
                                                                    <div style={{
                                                                        fontSize: '10px',
                                                                        color: isSelected ? greenAccent : '#888',
                                                                        textAlign: 'center',
                                                                        lineHeight: 1.2,
                                                                        overflow: 'hidden',
                                                                        textOverflow: 'ellipsis',
                                                                        display: '-webkit-box',
                                                                        WebkitLineClamp: 2,
                                                                        WebkitBoxOrient: 'vertical',
                                                                    }}>
                                                                        {step.label}
                                                                    </div>
                                                                    {step.is_delayed && (
                                                                        <span style={{ fontSize: '9px', color: '#ff6666', marginTop: '2px' }}>[ ! Delayed ]</span>
                                                                    )}
                                                                </div>
                                                            );
                                                            const connector = idx < timelineData.steps.length - 1 ? (
                                                                <div
                                                                    key={`conn-${idx}`}
                                                                    style={{ flex: '0 0 20px', alignSelf: 'center', height: '2px', background: darkBorder, marginBottom: '20px' }}
                                                                    aria-hidden={true}
                                                                />
                                                            ) : null;
                                                            return [stepEl, connector].filter(Boolean);
                                                        })}
                                                    </div>
                                                    {/* Selected step detail */}
                                                    {timelineData.steps[selectedStepIndex] && (() => {
                                                        const s = timelineData.steps[selectedStepIndex];
                                                        const cadSubmittedStep = (Number(itemState.cad_current_version) || 0) > 0;
                                                        const statusLabel = s.display_status === 'delayed' ? 'Delayed' : s.display_status === 'in_progress' ? 'In Progress' : s.display_status === 'completed' ? 'Completed' : 'Pending';
                                                        return (
                                                            <div style={{
                                                                padding: '16px',
                                                                border: `1px solid ${darkBorder}`,
                                                                borderRadius: '4px',
                                                                backgroundColor: 'rgba(0,0,0,0.2)',
                                                            }}>
                                                                <div style={{ fontSize: '13px', fontWeight: '600', color: greenAccent, marginBottom: '4px' }}>
                                                                    {s.step_number}. {s.label}
                                                                </div>
                                                                <div style={{ fontSize: '12px', color: darkText, marginBottom: '12px', lineHeight: 1.4 }}>
                                                                    {({ 1: 'Details are confirmed and the quote is finalized.', 2: 'You review and approve drawings, samples, and technical details.', 3: 'You review the prototype and approve it before production begins.', 4: 'The item is produced and progress is documented.', 5: 'Final quality checks and packing are completed.', 6: 'Shipping details are uploaded and delivery is tracked.' })[s.step_number] || ''}
                                                                </div>
                                                                {/* Step 1: Design & Specifications — dot + CAD requested, Payment sent, Payment approved with dates (no generic status dot) */}
                                                                {s.step_number === 1 && itemState.workflow_milestones && itemState.workflow_milestones.step1 && (
                                                                    <div style={{ marginBottom: '12px', paddingBottom: '12px', borderBottom: `1px solid ${darkBorder}` }}>
                                                                        <div style={{ fontSize: '11px', color: darkText, display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                                                            {itemState.workflow_milestones.step1.cad_requested_at && (
                                                                                <div>· CAD requested — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step1.cad_requested_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step1.payment_sent_at && (
                                                                                <div>· Payment sent — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step1.payment_sent_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step1.payment_approved_at && (
                                                                                <div>· Payment approved — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step1.payment_approved_at)}</span></div>
                                                                            )}
                                                                            {!itemState.workflow_milestones.step1.cad_requested_at && !itemState.workflow_milestones.step1.payment_sent_at && !itemState.workflow_milestones.step1.payment_approved_at && itemState.has_prototype_payment && (
                                                                                <div style={{ color: '#888' }}>No milestone dates yet.</div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                                {/* Step 1: CAD requested — when CAD request submitted */}
                                                                {s.step_number === 1 && itemState.has_prototype_payment && itemState.prototype_payment_status === 'requested' && !itemState.workflow_milestones?.step1?.payment_sent_at && (
                                                                    <div style={{ marginTop: '12px', padding: '16px', backgroundColor: 'rgba(0,51,51,0.4)', border: `1px solid #66aaff`, borderRadius: '4px' }}>
                                                                        <div style={{ fontSize: '14px', fontWeight: '600', color: '#66aaff', marginBottom: '4px' }}>CAD Requested</div>
                                                                        <div style={{ fontSize: '12px', color: darkText, lineHeight: 1.5 }}>Designer has requested CAD with selected keywords.</div>
                                                                        {itemState.direction_keyword_ids?.length > 0 && itemState.direction_keyword_names && (
                                                                            <div style={{ marginTop: '8px', fontSize: '11px', color: darkText }}>
                                                                                Keywords: {itemState.direction_keyword_ids.map(id => itemState.direction_keyword_names[id] || `#${id}`).join(', ')}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                                {/* Step 1: Payment sent — pending approval */}
                                                                {s.step_number === 1 && itemState.has_prototype_payment && itemState.prototype_payment_status === 'requested' && itemState.workflow_milestones?.step1?.payment_sent_at && (
                                                                    <div style={{ marginTop: '12px', padding: '16px', backgroundColor: 'rgba(51,33,0,0.4)', border: `1px solid #ff8800`, borderRadius: '4px' }}>
                                                                        <div style={{ fontSize: '14px', fontWeight: '600', color: '#ff8800', marginBottom: '4px' }}>Payment Sent — Pending Approval</div>
                                                                        <div style={{ fontSize: '12px', color: darkText, lineHeight: 1.5 }}>Receipt uploaded. CAD drafting will begin once payment is confirmed.</div>
                                                                        {itemState.direction_keyword_ids?.length > 0 && itemState.direction_keyword_names && (
                                                                            <div style={{ marginTop: '8px', fontSize: '11px', color: darkText }}>
                                                                                Keywords: {itemState.direction_keyword_ids.map(id => itemState.direction_keyword_names[id] || `#${id}`).join(', ')}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                                {/* Step 1: Payment Confirmed (prototype video is in Step 3) */}
                                                                {s.step_number === 1 && itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && (
                                                                    <div style={{ marginTop: '12px', padding: '16px', backgroundColor: 'rgba(0,17,0,0.4)', border: `1px solid ${greenAccent}`, borderRadius: '4px' }}>
                                                                        <div style={{ fontSize: '14px', fontWeight: '600', color: greenAccent, marginBottom: '4px' }}>Payment Confirmed</div>
                                                                        <div style={{ fontSize: '12px', color: darkText, lineHeight: 1.5 }}>CAD drafting has begun.</div>
                                                                        {itemState.direction_keyword_ids?.length > 0 && itemState.direction_keyword_names && (
                                                                            <div style={{ marginTop: '8px', fontSize: '11px', color: darkText }}>
                                                                                Keywords: {itemState.direction_keyword_ids.map(id => itemState.direction_keyword_names[id] || `#${id}`).join(', ')}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                                {/* Step 1: Award project — when prototype approved but not yet awarded (button removed per requirements) */}
                                                                {/* Step 1: Clarifications / Message Operator (before CAD submitted) — moved to Item Spec Support box; hide in Workflow tab */}
                                                                {null}
                                                                {/* Generic State line: show for steps 2–6 only (Step 1 shows only milestone dots) */}
                                                                {s.step_number !== 1 && (
                                                                    <div style={{ fontSize: '12px', color: darkText, marginBottom: '4px' }}>
                                                                        · State: <span style={{ color: s.display_status === 'delayed' ? '#ff6666' : s.display_status === 'completed' ? greenAccent : darkText }}>{statusLabel}</span>
                                                                    </div>
                                                                )}
                                                                {s.started_at && (
                                                                    <div style={{ fontSize: '11px', color: darkText, marginBottom: '2px' }}>Started: {formatWorkflowDateTime(s.started_at)}</div>
                                                                )}
                                                                {s.completed_at && (
                                                                    <div style={{ fontSize: '11px', color: darkText, marginBottom: '2px' }}>Completed: {formatWorkflowDateTime(s.completed_at)}</div>
                                                                )}
                                                                {s.expected_by && (
                                                                    <div style={{ fontSize: '11px', color: darkText }}>Expected by: {formatWorkflowDateTime(s.expected_by)}</div>
                                                                )}
                                                                {/* Step 3: Pre-Production Approval — prototype video timeline: Video submitted, Changes requested, Video resubmitted, Approved with dates */}
                                                                {s.step_number === 3 && itemState.workflow_milestones && itemState.workflow_milestones.step3 && (
                                                                    <div style={{ marginTop: '12px', marginBottom: '12px', paddingBottom: '12px', borderBottom: `1px solid ${darkBorder}` }}>
                                                                        <div style={{ fontSize: '11px', color: darkText, display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                                                            {(itemState.workflow_milestones.step3.video_submitted_at || (itemState.prototype_submission && itemState.prototype_submission.created_at)) && (
                                                                                <div>· Video submitted — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step3.video_submitted_at || itemState.prototype_submission.created_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step3.changes_requested_at && (
                                                                                <div>· Changes requested — <span style={{ color: '#ffaa00' }}>{formatWorkflowDateTime(itemState.workflow_milestones.step3.changes_requested_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step3.video_resubmitted_at && (
                                                                                <div>· Video resubmitted — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step3.video_resubmitted_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step3.video_approved_at && (
                                                                                <div>· Approved — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step3.video_approved_at)}</span></div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                                {/* Step 3: Prototype Video box (moved from Proposals tab) — status, links, Approve / Request Changes */}
                                                                {s.step_number === 3 && itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && (
                                                                    <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                                                                        <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Prototype Video</div>
                                                                        {itemState.prototype_status && (
                                                                            <div style={{
                                                                                display: 'inline-block', padding: '6px 10px', marginBottom: '8px', borderRadius: '4px', fontSize: '11px', fontWeight: '600',
                                                                                backgroundColor: itemState.prototype_status === 'approved' ? 'rgba(255,0,101,0.15)' : itemState.prototype_status === 'changes_requested' ? '#331100' : '#001133',
                                                                                border: `1px solid ${itemState.prototype_status === 'approved' ? '#FF0065' : itemState.prototype_status === 'changes_requested' ? '#ff8800' : '#66aaff'}`,
                                                                                color: itemState.prototype_status === 'approved' ? '#FF0065' : itemState.prototype_status === 'changes_requested' ? '#ff8800' : '#66aaff',
                                                                            }}>
                                                                                {itemState.prototype_status === 'approved' ? 'Prototype Approved' : itemState.prototype_status === 'changes_requested' ? 'Changes Requested' : itemState.prototype_status === 'submitted' ? `Submitted (v${itemState.prototype_current_version || 0})` : 'Not Submitted'}
                                                                            </div>
                                                                        )}
                                                                        {itemState.prototype_submission?.links?.length > 0 && (
                                                                            <div style={{ marginBottom: '8px' }}>
                                                                                <div style={{ fontSize: '11px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Video Links (v{itemState.prototype_submission.version}):</div>
                                                                                {itemState.prototype_submission.links.map((link, idx) => (
                                                                                    <div key={idx} style={{ marginBottom: '6px', display: 'flex', alignItems: 'center', gap: '12px' }}>
                                                                                        <button
                                                                                            type="button"
                                                                                            title="Play on screen"
                                                                                            onClick={(e) => { e.preventDefault(); setInlineVideoPlayer({ url: link.url, provider: link.provider || 'Link' }); }}
                                                                                            onMouseEnter={() => setHoverVideoPlayIdx(idx)}
                                                                                            onMouseLeave={() => setHoverVideoPlayIdx(null)}
                                                                                            style={{
                                                                                                padding: '8px 12px',
                                                                                                background: 'rgba(0,255,0,0.15)',
                                                                                                color: greenAccent,
                                                                                                border: `1px solid ${greenAccent}`,
                                                                                                borderRadius: '4px',
                                                                                                fontSize: '16px',
                                                                                                cursor: 'pointer',
                                                                                                transform: hoverVideoPlayIdx === idx ? 'scale(1.2)' : 'scale(1)',
                                                                                                transition: 'transform 0.2s ease',
                                                                                            }}
                                                                                        >
                                                                                            ▶
                                                                                        </button>
                                                                                        <a
                                                                                            href={link.url}
                                                                                            target="_blank"
                                                                                            rel="noopener noreferrer"
                                                                                            title="Open in new tab"
                                                                                            onMouseEnter={() => setHoverVideoLinkIdx(idx)}
                                                                                            onMouseLeave={() => setHoverVideoLinkIdx(null)}
                                                                                            style={{
                                                                                                color: greenAccent,
                                                                                                fontSize: '18px',
                                                                                                textDecoration: 'none',
                                                                                                transform: hoverVideoLinkIdx === idx ? 'scale(1.2)' : 'scale(1)',
                                                                                                transition: 'transform 0.2s ease',
                                                                                                display: 'inline-flex',
                                                                                                alignItems: 'center',
                                                                                                gap: '4px',
                                                                                            }}
                                                                                        >
                                                                                            {link.provider || 'Video'} →
                                                                                        </a>
                                                                                    </div>
                                                                                ))}
                                                                                {itemState.prototype_submission.created_at && (
                                                                                    <div style={{ fontSize: '10px', color: darkText, marginTop: '4px' }}>Submitted: {formatWorkflowDateTime(itemState.prototype_submission.created_at)}</div>
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                        {itemState.prototype_status === 'submitted' && (
                                                                            <div style={{ display: 'flex', gap: '8px', marginTop: '8px' }}>
                                                                                <button type="button" onClick={async () => {
                                                                                    if (!window.confirm(`Approve prototype v${itemState.prototype_current_version}?`)) return;
                                                                                    const fd = new FormData();
                                                                                    fd.append('action', 'n88_approve_prototype');
                                                                                    fd.append('item_id', String(getItemId()));
                                                                                    fd.append('payment_id', String(itemState.prototype_payment_id));
                                                                                    fd.append('bid_id', String(itemState.prototype_payment_bid_id));
                                                                                    fd.append('version', String(itemState.prototype_current_version));
                                                                                    fd.append('_ajax_nonce', window.n88BoardNonce?.nonce_approve_prototype || '');
                                                                                    const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
                                                                                    const data = await res.json();
                                                                                    if (data.success) {
                                                                                        fetchItemState();
                                                                                        if (data.data && data.data.message) alert(data.data.message);
                                                                                    } else alert(data.message || data.data?.message || 'Failed');
                                                                                }} style={{ padding: '8px 12px', background: 'rgba(255,0,101,0.2)', color: '#FF0065', border: '1px solid #FF0065', borderRadius: '4px', fontSize: '11px', cursor: 'pointer' }}>Approve</button>
                                                                                <button type="button" onClick={() => setShowRequestChangesModal(true)} style={{ padding: '8px 12px', background: '#331100', color: '#ff8800', border: '1px solid #ff8800', borderRadius: '4px', fontSize: '11px', cursor: 'pointer' }}>Request Changes</button>
                                                                            </div>
                                                                        )}
                {itemState.prototype_status === 'approved' && (
                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap', marginTop: '8px' }}>
                        <div style={{ fontSize: '11px', color: greenAccent }}>✓ Prototype approved (v{itemState.prototype_approved_version || itemState.prototype_current_version})</div>
                        {!isViewOnly && !itemState.has_awarded_bid && itemState.prototype_payment_bid_id && (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!window.confirm('Award this project to the supplier? All other bids will be declined.')) return;
                                    const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                    const nonce = window.n88BoardNonce?.nonce_award_bid || window.n88BoardData?.nonce || window.n88?.nonce;
                                    if (!nonce) { alert('Security token missing.'); return; }
                                    const fd = new FormData();
                                    fd.append('action', 'n88_award_bid');
                                    fd.append('item_id', String(getItemId()));
                                    fd.append('bid_id', String(itemState.prototype_payment_bid_id));
                                    fd.append('_ajax_nonce', nonce);
                                    try {
                                        const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                                        const data = await res.json();
                                        if (data.success) { if (onSave) await onSave(getItemId(), {}); fetchItemState(); onClose(); }
                                        else alert(data.data?.message || 'Failed to award.');
                                    } catch (e) { console.error(e); alert('Error awarding. Please try again.'); }
                                }}
                                style={{ padding: '8px 12px', background: '#FF0065', color: '#000', border: 'none', borderRadius: '4px', fontSize: '11px', fontWeight: '600', cursor: 'pointer', fontFamily: 'monospace' }}
                            >
                                Award Bid
                            </button>
                        )}
                        {itemState.has_awarded_bid && (
                            <div style={{ fontSize: '11px', color: greenAccent }}>Bid Awarded</div>
                        )}
                    </div>
                )}
                                                                    </div>
                                                                )}
                                                                {/* Step 2: Technical Review & Documentation — CAD received, Revision submitted, Revision sent, CAD approved, CAD released with dates */}
                                                                {s.step_number === 2 && itemState.workflow_milestones && itemState.workflow_milestones.step2 && (
                                                                    <div style={{ marginTop: '12px', marginBottom: '12px', paddingBottom: '12px', borderBottom: `1px solid ${darkBorder}` }}>
                                                                        <div style={{ fontSize: '11px', color: darkText, display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                                                            {itemState.workflow_milestones.step2.cad_received_at && (
                                                                                <div>· CAD file received — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step2.cad_received_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step2.revision_submitted_at && (
                                                                                <div>· Revision submitted — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step2.revision_submitted_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step2.revision_sent_at && (
                                                                                <div>· Revision sent (operator) — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step2.revision_sent_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step2.cad_approved_at && (
                                                                                <div>· CAD approved — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step2.cad_approved_at)}</span></div>
                                                                            )}
                                                                            {itemState.workflow_milestones.step2.cad_released_to_supplier_at && (
                                                                                <div>· Final CAD file submitted to supplier — <span style={{ color: greenAccent }}>{formatWorkflowDateTime(itemState.workflow_milestones.step2.cad_released_to_supplier_at)}</span></div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                )}
                                                                {/* Step 2: Review and Message + CAD Review (after CAD submitted) – hidden in designer Project Workflow (moved to Item Spec Support box) */}
                                                                {false && s.step_number === 2 && !isViewOnly && (itemState.has_rfq || itemState.has_prototype_payment) && cadSubmittedStep && (
                                                                    <div style={{ marginTop: '16px', marginBottom: '16px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                                                                        <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '12px' }}>🎧 Review and Message</div>
                                                                        {!showDesignerMessageForm ? (
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => { setShowDesignerMessageForm(true); loadDesignerMessages(); }}
                                                                                style={{ width: '100%', padding: '12px', backgroundColor: '#111', border: `1px solid ${darkBorder}`, borderRadius: '4px', color: darkText, fontSize: '12px', fontFamily: 'monospace', cursor: 'pointer', fontWeight: '600' }}
                                                                            >
                                                                                Open Review and Message (CAD · Messages · Support)
                                                                            </button>
                                                                        ) : (
                                                                            <div style={{ border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '12px', backgroundColor: '#111' }}>
                                                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                                                                                    <span style={{ fontSize: '12px', color: darkText }}>Check files for CAD · Check messages below</span>
                                                                                    <button type="button" onClick={() => setShowDesignerMessageForm(false)} style={{ background: 'none', border: 'none', color: darkText, fontSize: '18px', cursor: 'pointer', padding: '0 6px' }}>×</button>
                                                                                </div>
                                                                                <div id="n88-designer-messages-container-workflow" style={{ height: '320px', overflowY: 'auto', padding: '12px', backgroundColor: '#0a0a0a', borderRadius: '4px', marginBottom: '12px', border: `1px solid ${darkBorder}` }}>
                                                                                    {isLoadingDesignerMessages ? <div style={{ textAlign: 'center', color: '#888', padding: '20px' }}>Loading conversation…</div> : designerMessages.length === 0 ? <div style={{ textAlign: 'center', color: '#666', fontSize: '12px' }}>No messages yet.</div> : (
                                                                                        [...designerMessages].sort((a, b) => new Date(a.created_at) - new Date(b.created_at)).map((msg, idx) => {
                                                                                            const isDesigner = msg.sender_role === 'designer';
                                                                                            const isOperatorView = !!(window.n88BoardData && window.n88BoardData.isOperator);
                                                                                            const senderName = isDesigner ? (isOperatorView ? 'Comment by designer' : 'You') : 'Operator';
                                                                                            const rawText = (msg.message_text || '').substring(0, 2000);
                                                                                            // Parse "CAD Files:" / "Files:" block so operator files are clickable and open in new tab
                                                                                            const hasFilesBlock = rawText.indexOf('CAD Files:') !== -1 || rawText.indexOf('Files:') !== -1;
                                                                                            let displayText = rawText;
                                                                                            const fileList = [];
                                                                                            if (hasFilesBlock) {
                                                                                                const lines = rawText.split('\n');
                                                                                                let filesStart = -1;
                                                                                                for (let li = 0; li < lines.length; li++) {
                                                                                                    const trimmed = (lines[li] || '').trim();
                                                                                                    if (trimmed === 'CAD Files:' || trimmed === 'Files:') {
                                                                                                        filesStart = li;
                                                                                                        break;
                                                                                                    }
                                                                                                }
                                                                                                if (filesStart >= 0) {
                                                                                                    let filesEnd = lines.length;
                                                                                                    for (let li = filesStart + 1; li < lines.length; li++) {
                                                                                                        const t = (lines[li] || '').trim();
                                                                                                        if (t.indexOf('Direction Keywords') === 0 || t === '') {
                                                                                                            filesEnd = li;
                                                                                                            break;
                                                                                                        }
                                                                                                    }
                                                                                                    displayText = lines.slice(0, filesStart).join('\n').trim();
                                                                                                    for (let fi = filesStart + 1; fi < filesEnd; fi++) {
                                                                                                        const line = (lines[fi] || '').trim();
                                                                                                        if (line.indexOf('- ') === 0) {
                                                                                                            const withoutDash = line.slice(2);
                                                                                                            const sepIdx = withoutDash.indexOf(': ');
                                                                                                            if (sepIdx > 0) {
                                                                                                                const fileName = withoutDash.slice(0, sepIdx).trim();
                                                                                                                const fileUrl = withoutDash.slice(sepIdx + 2).trim();
                                                                                                                if (fileUrl.startsWith('http://') || fileUrl.startsWith('https://')) {
                                                                                                                    fileList.push({ name: fileName, url: fileUrl });
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                            const urlRe = /(https?:\/\/[^\s<>"']+)/gi;
                                                                                            const parts = [];
                                                                                            let urlM;
                                                                                            let last = 0;
                                                                                            while ((urlM = urlRe.exec(displayText)) !== null) {
                                                                                                if (urlM.index > last) parts.push({ t: 'text', v: displayText.slice(last, urlM.index) });
                                                                                                const url = urlM[0];
                                                                                                const isImg = /\.(jpe?g|png|gif|webp)(\?|$)/i.test(url);
                                                                                                parts.push({ t: isImg ? 'image' : 'file', v: url });
                                                                                                last = urlRe.lastIndex;
                                                                                            }
                                                                                            if (last < displayText.length) parts.push({ t: 'text', v: displayText.slice(last) });
                                                                                            const content = parts.length === 0 ? displayText : parts.map((p, i) => {
                                                                                                if (p.t === 'text') return <React.Fragment key={i}>{p.v}</React.Fragment>;
                                                                                                if (p.t === 'image') return (
                                                                                                    <span key={i} style={{ display: 'inline-block', marginTop: 6, marginBottom: 4 }}>
                                                                                                        <img src={p.v} alt="" style={{ maxWidth: 120, maxHeight: 80, objectFit: 'contain', display: 'block', borderRadius: 4, border: `1px solid ${darkBorder}` }} onError={(e) => { e.target.style.display = 'none'; const n = e.target.nextSibling; if (n) n.style.display = 'block'; }} />
                                                                                                        <a href={p.v} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent, fontSize: '11px', marginTop: 4, display: 'none' }}>View image →</a>
                                                                                                    </span>
                                                                                                );
                                                                                                return <a key={i} href={p.v} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent, fontSize: '11px', display: 'inline-block', marginTop: 4, marginRight: 8 }}>View file →</a>;
                                                                                            });
                                                                                            return (
                                                                                                <div key={idx} style={{ marginBottom: '10px', textAlign: isDesigner ? 'right' : 'left' }}>
                                                                                                    <div style={{ display: 'inline-block', maxWidth: '85%', padding: '8px 12px', backgroundColor: isDesigner ? '#1a1a1a' : '#0a0a0a', border: `1px solid ${isDesigner ? greenAccent : '#333'}`, borderRadius: '8px', fontSize: '11px', color: '#fff', whiteSpace: 'pre-wrap' }}>
                                                                                                        <div style={{ fontSize: '10px', fontWeight: 600, color: isDesigner ? greenAccent : '#00aa00', marginBottom: 4 }}>{senderName}</div>
                                                                                                        {content}
                                                                                                        {fileList.length > 0 && (
                                                                                                            <div style={{ marginTop: 10, paddingTop: 8, borderTop: `1px solid ${darkBorder}`, display: 'flex', flexDirection: 'column', gap: 6 }}>
                                                                                                                {fileList.map((file, fi) => {
                                                                                                                    const isImageFile = /\.(jpe?g|png|gif|webp)(\?|$)/i.test(file.name);
                                                                                                                    return (
                                                                                                                    <a key={fi} href={file.url} target="_blank" rel="noopener noreferrer" style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 10px', backgroundColor: '#0a0a0a', border: '1px solid #333', borderRadius: 4, color: '#fff', textDecoration: 'none', fontSize: '11px', cursor: 'pointer' }}>
                                                                                                                        {isImageFile ? (
                                                                                                                            <span style={{ width: 40, height: 40, flexShrink: 0, borderRadius: 4, overflow: 'hidden', backgroundColor: '#222', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                                                                                                                <img src={file.url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} onError={(e) => { e.target.style.display = 'none'; if (e.target.parentNode) { const ph = e.target.parentNode; ph.style.fontSize = '18px'; ph.textContent = '🖼️'; } }} />
                                                                                                                            </span>
                                                                                                                        ) : (
                                                                                                                            <span style={{ fontSize: '14px' }}>{file.name.toLowerCase().endsWith('.pdf') ? '📄' : '📎'}</span>
                                                                                                                        )}
                                                                                                                        <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={file.name}>{file.name}</span>
                                                                                                                        <span style={{ color: greenAccent, fontSize: '10px', flexShrink: 0 }}>Open in new tab →</span>
                                                                                                                    </a>
                                                                                                                    );
                                                                                                                })}
                                                                                                            </div>
                                                                                                        )}
                                                                                                        <div style={{ fontSize: '9px', color: '#666', marginTop: 4 }}>{msg.created_at ? new Date(msg.created_at).toLocaleString() : ''}</div>
                                                                                                    </div>
                                                                                                </div>
                                                                                            );
                                                                                        })
                                                                                    )}
                                                                                </div>
                                                                                {itemState.has_prototype_payment && itemState.prototype_payment_status === 'marked_received' && itemState.cad_current_version && Number(itemState.cad_current_version) > 0 && (itemState.cad_status === 'uploaded' || itemState.cad_status === 'revision_requested' || itemState.cad_status === 'approved') && (
                                                                                    <div style={{ marginTop: '12px', marginBottom: '12px', padding: '12px', backgroundColor: '#0a0a14', border: '1px solid #333', borderRadius: '4px', flexShrink: 0 }}>
                                                                                        <div style={{ fontSize: '12px', fontWeight: '700', color: '#66aaff', marginBottom: '8px' }}>CAD Review</div>
                                                                                        <div style={{ fontSize: '11px', color: '#ccc', marginBottom: '8px' }}>Current CAD: v{itemState.cad_current_version} {itemState.cad_status === 'approved' && itemState.cad_approved_version ? <span style={{ color: greenAccent }}>· Approved v{itemState.cad_approved_version}</span> : null}</div>
                                                                                        {!isViewOnly && itemState.cad_status !== 'approved' && (
                                                                                            <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                                                                                {!showRevisionUpload ? (
                                                                                                    <>
                                                                                                        <button type="button" onClick={() => setShowRevisionUpload(true)} disabled={isCadActionBusy} style={{ padding: '8px 12px', backgroundColor: '#111', border: '1px solid #666', borderRadius: '4px', color: '#fff', fontSize: '11px', cursor: 'pointer' }}>Submit revised CAD</button>
                                                                                                        <button type="button" onClick={approveCad} disabled={isCadActionBusy} style={{ padding: '8px 12px', backgroundColor: 'rgba(255,0,101,0.2)', border: '1px solid #FF0065', borderRadius: '4px', color: '#FF0065', fontSize: '11px', cursor: 'pointer' }}>Approve CAD</button>
                                                                                                    </>
                                                                                                ) : (
                                                                                                    <div style={{ width: '100%' }}>
                                                                                                        <div style={{ fontSize: '11px', marginBottom: '8px' }}>Upload files for revision</div>
                                                                                                        <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setRevisionFiles(prev => [...prev, ...Array.from(e.target.files || [])])} style={{ marginBottom: '8px', fontSize: '11px' }} />
                                                                                                        {revisionFiles.length > 0 && <div style={{ marginBottom: '8px' }}>{revisionFiles.map((f, i) => <span key={i} style={{ marginRight: '8px', fontSize: '11px' }}>{f.name} <button type="button" onClick={() => setRevisionFiles(prev => prev.filter((_, idx) => idx !== i))} style={{ color: '#ff6666', background: 'none', border: 'none', cursor: 'pointer' }}>×</button></span>)}</div>}
                                                                                                        <div style={{ display: 'flex', gap: '8px' }}>
                                                                                                            <button type="button" onClick={() => { requestCadRevision(revisionFiles); setShowRevisionUpload(false); setRevisionFiles([]); }} disabled={isCadActionBusy} style={{ padding: '6px 12px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', fontSize: '11px', cursor: 'pointer' }}>Send revision request</button>
                                                                                                            <button type="button" onClick={() => { setShowRevisionUpload(false); setRevisionFiles([]); }} style={{ padding: '6px 12px', background: 'transparent', color: darkText, border: `1px solid ${darkBorder}`, borderRadius: '4px', fontSize: '11px', cursor: 'pointer' }}>Cancel</button>
                                                                                                        </div>
                                                                                                    </div>
                                                                                                )}
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                )}
                                                                                <form onSubmit={(e) => { e.preventDefault(); if (designerMessageText.trim()) sendDesignerMessage(e); }} style={{ display: 'flex', gap: '8px', alignItems: 'flex-end' }}>
                                                                                    <textarea value={designerMessageText} onChange={(e) => setDesignerMessageText(e.target.value)} required rows={2} placeholder="Type your message…" style={{ flex: 1, padding: '8px 12px', backgroundColor: '#000', color: '#fff', border: `1px solid ${darkBorder}`, borderRadius: '4px', fontSize: '12px', minHeight: '40px' }} />
                                                                                    <button type="submit" disabled={isSendingDesignerMessage || !designerMessageText.trim()} style={{ padding: '10px 16px', backgroundColor: (isSendingDesignerMessage || !designerMessageText.trim()) ? '#333' : greenAccent, color: (isSendingDesignerMessage || !designerMessageText.trim()) ? '#666' : '#000', border: 'none', borderRadius: '4px', fontWeight: '600', cursor: 'pointer', fontSize: '12px' }}>{isSendingDesignerMessage ? 'Sending…' : 'Send'}</button>
                                                                                </form>
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                )}
                                                                {/* Commit 3.A.2 + 3.A.3: Evidence (watermarked) + immutable comments beneath each */}
                                                                {(() => {
                                                                    const byStep = timelineData.evidence_by_step || {};
                                                                    const stepKey = s.step_id;
                                                                    const evidenceList = byStep[stepKey] || byStep[String(stepKey)] || [];
                                                                    const canAddComment = !!timelineData.can_add_evidence_comment;
                                                                    const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                                    const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
                                                                    if (!evidenceList.length) return null;
                                                                    return (
                                                                        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                                                                            <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Evidence</div>
                                                                            <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                                                                {evidenceList.map((ev) => (
                                                                                    <div key={ev.id || ev.view_url} style={{ fontSize: '11px', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '10px', backgroundColor: 'rgba(0,0,0,0.2)' }}>
                                                                                        {ev.media_type === 'youtube' ? (
                                                                                            <a href={ev.view_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>YouTube</a>
                                                                                        ) : ev.media_type === 'image' ? (
                                                                                            <a href={ev.view_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>
                                                                                                <img src={ev.view_url} alt="" style={{ maxWidth: '120px', maxHeight: '80px', objectFit: 'contain', display: 'block', marginBottom: '4px' }} />
                                                                                            </a>
                                                                                        ) : (
                                                                                            <a href={ev.view_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>{ev.media_type || 'File'}</a>
                                                                                        )}
                                                                                        {ev.created_at && <div style={{ fontSize: '10px', color: darkText }}>{ev.created_at}</div>}
                                                                                        {/* 3.A.3: Comments (immutable) beneath this media */}
                                                                                        {(ev.comments && ev.comments.length) ? (
                                                                                            <div style={{ marginTop: '8px', paddingTop: '8px', borderTop: `1px solid ${darkBorder}` }}>
                                                                                                <div style={{ fontSize: '10px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Comments</div>
                                                                                                {ev.comments.map((c) => (
                                                                                                    <div key={c.id} style={{ fontSize: '11px', color: darkText, marginBottom: '6px', whiteSpace: 'pre-wrap' }}>
                                                                                                        {c.comment_text}
                                                                                                        {c.created_at && <div style={{ fontSize: '10px', opacity: 0.8 }}>{c.created_at}</div>}
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>
                                                                                        ) : null}
                                                                                        {canAddComment && (
                                                                                            <div style={{ marginTop: '8px' }}>
                                                                                                <textarea
                                                                                                    placeholder="Add comment (immutable, anchored to this media)"
                                                                                                    value={evidenceCommentDrafts[ev.id] ?? ''}
                                                                                                    onChange={(e) => setEvidenceCommentDrafts((prev) => ({ ...prev, [ev.id]: e.target.value }))}
                                                                                                    style={{ width: '100%', minHeight: '48px', padding: '6px', fontSize: '11px', background: '#111', color: '#ccc', border: `1px solid ${darkBorder}`, borderRadius: '4px', resize: 'vertical' }}
                                                                                                />
                                                                                                <button
                                                                                                    type="button"
                                                                                                    disabled={evidenceCommentSubmitting || !(evidenceCommentDrafts[ev.id] || '').trim()}
                                                                                                    onClick={async () => {
                                                                                                        const text = (evidenceCommentDrafts[ev.id] || '').trim();
                                                                                                        if (!text || !nonce) return;
                                                                                                        setEvidenceCommentSubmitting(true);
                                                                                                        try {
                                                                                                            const fd = new FormData();
                                                                                                            fd.append('action', 'n88_add_evidence_comment');
                                                                                                            fd.append('evidence_id', String(ev.id));
                                                                                                            fd.append('comment_text', text);
                                                                                                            fd.append('_ajax_nonce', nonce);
                                                                                                            const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                                                                                                            const data = await res.json();
                                                                                                            if (data.success) {
                                                                                                                setEvidenceCommentDrafts((prev) => { const n = { ...prev }; delete n[ev.id]; return n; });
                                                                                                                fetchTimeline();
                                                                                                            } else {
                                                                                                                alert(data.data?.message || 'Failed to add comment.');
                                                                                                            }
                                                                                                        } finally {
                                                                                                            setEvidenceCommentSubmitting(false);
                                                                                                        }
                                                                                                    }}
                                                                                                    style={{ marginTop: '4px', padding: '4px 10px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                                                                                                >
                                                                                                    Add comment
                                                                                                </button>
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                })()}
                                                                {s.step_number === 4 && itemState.has_awarded_bid && (() => {
                                                                    const isOperator = !!(window.n88BoardData && window.n88BoardData.isOperator);
                                                                    const summary = itemState.payment_milestones_summary;
                                                                    const stages = Array.isArray(summary?.stages) ? summary.stages : [];
                                                                    const paidPercent = Number(summary?.paid_percent || 0).toFixed(2).replace(/\.00$/, '');
                                                                    return (
                                                                        <div style={{ marginTop: '12px', marginBottom: '12px', padding: '12px', border: `1px solid ${darkBorder}`, borderRadius: '4px', backgroundColor: 'rgba(0,0,0,0.2)' }}>
                                                                            <div style={{ fontSize: '12px', fontWeight: '700', color: greenAccent, marginBottom: '8px' }}>Payment Milestones (Step 4)</div>
                                                                            {milestoneUiNotice && <div style={{ fontSize: '11px', color: greenAccent, marginBottom: '8px' }}>{milestoneUiNotice}</div>}
                                                                            {milestoneSummaryLoading && <div style={{ fontSize: '11px', color: darkText, marginBottom: '8px' }}>Loading milestones…</div>}
                                                                            {!summary?.enabled && (
                                                                                <div style={{ fontSize: '11px', color: darkText }}>
                                                                                    {milestoneSetupPromptOpen ? (
                                                                                        <div style={{ border: `1px solid ${darkBorder}`, borderRadius: '4px', background: '#0b0b0b' }}>
                                                                                            <div style={{ padding: '10px 12px', borderBottom: `1px solid ${darkBorder}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                                                                <div style={{ color: '#fff', fontSize: '12px', fontWeight: '700' }}>SET PAYMENT MILESTONES</div>
                                                                                                <button type="button" onClick={() => setMilestoneSetupPromptOpen(false)} style={{ padding: '4px 8px', fontSize: '11px', background: 'transparent', color: '#bbb', border: `1px solid ${darkBorder}`, borderRadius: '4px', cursor: 'pointer' }}>[ Close ]</button>
                                                                                            </div>
                                                                                            <div style={{ padding: '12px' }}>
                                                                                                <div style={{ marginBottom: '10px' }}>How do you want to handle production payments?</div>
                                                                                                <label style={{ display: 'block', marginBottom: '8px', cursor: 'pointer' }}>
                                                                                                    <input type="radio" name="milestone-choice" checked={milestoneSetupChoice === 'none'} onChange={() => setMilestoneSetupChoice('none')} style={{ marginRight: '8px' }} />
                                                                                                    No milestones
                                                                                                    <div style={{ marginLeft: '22px', color: '#aaa' }}>Deposit + final payment only</div>
                                                                                                </label>
                                                                                                <label style={{ display: 'block', marginBottom: '12px', cursor: 'pointer' }}>
                                                                                                    <input type="radio" name="milestone-choice" checked={milestoneSetupChoice === 'yes'} onChange={() => setMilestoneSetupChoice('yes')} style={{ marginRight: '8px' }} />
                                                                                                    Yes — Create Payment Milestones
                                                                                                    <div style={{ marginLeft: '22px', color: '#aaa' }}>Tie payments to approved production stages</div>
                                                                                                </label>
                                                                                                <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                                                                    <button
                                                                                                        type="button"
                                                                                                        disabled={milestoneSaving}
                                                                                                        onClick={async () => {
                                                                                                            if (milestoneSetupChoice === 'yes') {
                                                                                                                const ok = await saveMilestoneSetup(true);
                                                                                                                if (ok) setMilestoneSetupPromptOpen(false);
                                                                                                            } else {
                                                                                                                await saveMilestoneSetup(false);
                                                                                                                setMilestoneSetupPromptOpen(false);
                                                                                                            }
                                                                                                        }}
                                                                                                        style={{ padding: '6px 12px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: '600' }}
                                                                                                    >
                                                                                                        [ Continue ]
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    ) : (
                                                                                        <button type="button" onClick={() => setMilestoneSetupPromptOpen(true)} style={{ padding: '6px 10px', fontSize: '11px', background: '#111', color: '#ccc', border: `1px solid ${darkBorder}`, borderRadius: '4px', cursor: 'pointer' }}>
                                                                                            Set Payment Milestones
                                                                                        </button>
                                                                                    )}
                                                                                </div>
                                                                            )}
                                                                            {summary?.enabled && (
                                                                                <>
                                                                                    <div style={{ fontSize: '11px', color: darkText, marginBottom: '10px' }}>
                                                                                        <span style={{ color: greenAccent, fontWeight: '600' }}>{paidPercent}% Paid</span>
                                                                                        {' '}· Next: <span style={{ color: '#fff' }}>{summary?.next_stage_label || 'Completed'}</span>
                                                                                    </div>
                                                                                    {!isOperator && stages.length > 0 && milestoneEditOpen && (
                                                                                        <div style={{ marginBottom: '12px', padding: '10px', border: `1px solid ${darkBorder}`, borderRadius: '4px', background: '#0f0f0f' }}>
                                                                                            <div style={{ fontSize: '11px', color: '#ddd', marginBottom: '8px' }}>Edit future milestones (total must be 100%)</div>
                                                                                            {milestoneSetupDraft.map((row, idx) => (
                                                                                                <div key={row.stage_key || idx} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: '8px', marginBottom: '6px' }}>
                                                                                                    <input
                                                                                                        type="text"
                                                                                                        value={row.stage_label}
                                                                                                        readOnly
                                                                                                        disabled
                                                                                                        style={{ background: '#0b0b0b', color: '#888', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', fontSize: '11px', cursor: 'not-allowed' }}
                                                                                                    />
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        min="0"
                                                                                                        max="100"
                                                                                                        step="0.01"
                                                                                                        value={row.percent_alloc}
                                                                                                        readOnly
                                                                                                        disabled
                                                                                                        title="Percent allocation is locked for this setup."
                                                                                                        style={{ background: '#0b0b0b', color: '#888', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', fontSize: '11px', cursor: 'not-allowed' }}
                                                                                                    />
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        min="0"
                                                                                                        step="0.01"
                                                                                                        value={row.amount_alloc}
                                                                                                        onChange={(e) => setMilestoneSetupDraft((prev) => prev.map((r, i) => i === idx ? { ...r, amount_alloc: e.target.value } : r))}
                                                                                                        style={{ background: '#111', color: '#ddd', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', fontSize: '11px' }}
                                                                                                    />
                                                                                                    <label style={{ display: 'flex', alignItems: 'center', gap: '4px', color: '#bbb', fontSize: '11px' }}>
                                                                                                        <input
                                                                                                            type="checkbox"
                                                                                                            checked={!!row.stage_enabled}
                                                                                                            onChange={(e) => setMilestoneSetupDraft((prev) => prev.map((r, i) => i === idx ? { ...r, stage_enabled: !!e.target.checked } : r))}
                                                                                                        />
                                                                                                        On
                                                                                                    </label>
                                                                                                </div>
                                                                                            ))}
                                                                                            <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginTop: '8px' }}>
                                                                                                <button
                                                                                                    type="button"
                                                                                                    disabled={milestoneSaving}
                                                                                                    onClick={() => {
                                                                                                        const total = milestoneSetupDraft.reduce((sum, row) => sum + (row.stage_enabled ? Number(row.percent_alloc || 0) : 0), 0);
                                                                                                        if (Math.abs(total - 100) > 0.01) {
                                                                                                            alert('Milestone total must equal 100%.');
                                                                                                            return;
                                                                                                        }
                                                                                                        saveMilestoneSetup(true, milestoneSetupDraft);
                                                                                                    }}
                                                                                                    style={{ padding: '6px 10px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: '600' }}
                                                                                                >
                                                                                                    Save Milestones
                                                                                                </button>
                                                                                                <button
                                                                                                    type="button"
                                                                                                    disabled={milestoneSaving}
                                                                                                    onClick={() => saveMilestoneSetup(false)}
                                                                                                    style={{ padding: '6px 10px', fontSize: '11px', background: 'transparent', color: '#bbb', border: `1px solid ${darkBorder}`, borderRadius: '4px', cursor: 'pointer' }}
                                                                                                >
                                                                                                    Disable milestones
                                                                                                </button>
                                                                                            </div>
                                                                                        </div>
                                                                                    )}
                                                                                    {!isOperator && stages.length > 0 && !milestoneEditOpen && (
                                                                                        <div style={{ marginBottom: '12px' }}>
                                                                                            <button type="button" onClick={() => setMilestoneEditOpen(true)} style={{ padding: '6px 10px', fontSize: '11px', background: '#111', color: '#ccc', border: `1px solid ${darkBorder}`, borderRadius: '4px', cursor: 'pointer' }}>
                                                                                                Edit Milestones
                                                                                            </button>
                                                                                        </div>
                                                                                    )}
                                                                                    {stages.map((stage) => {
                                                                                        const stageKey = stage.stage_key;
                                                                                        const isExpanded = expandedMilestoneStage === stageKey;
                                                                                        const details = milestoneDetailsByStage[stageKey] || stage;
                                                                                        const isBusy = !!milestoneActionBusyByStage[stageKey];
                                                                                        const queueState = !details.stage_submitted_at
                                                                                            ? 'Awaiting Supplier Submission'
                                                                                            : !details.stage_approved_at
                                                                                                ? (details.stage_revision_requested_at ? 'Waiting for Supplier Revision' : 'Awaiting Approval')
                                                                                                : !details.payment_confirmed_at
                                                                                                    ? 'Payment Pending'
                                                                                                    : 'Payment Received → Proceed';
                                                                                        return (
                                                                                            <div key={stageKey} style={{ border: `1px solid ${darkBorder}`, borderRadius: '4px', marginBottom: '8px', overflow: 'hidden' }}>
                                                                                                <button
                                                                                                    type="button"
                                                                                                    onClick={async () => {
                                                                                                        if (isExpanded) {
                                                                                                            setExpandedMilestoneStage('');
                                                                                                            return;
                                                                                                        }
                                                                                                        setExpandedMilestoneStage(stageKey);
                                                                                                        await fetchMilestoneStageDetails(stageKey);
                                                                                                    }}
                                                                                                    style={{ width: '100%', textAlign: 'left', padding: '10px', background: '#111', color: '#ddd', border: 'none', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', gap: '8px' }}
                                                                                                >
                                                                                                    <span>{stage.stage_label} ({stage.percent_alloc}%)</span>
                                                                                                    <span style={{ color: greenAccent }}>{queueState}</span>
                                                                                                </button>
                                                                                                {isExpanded && (
                                                                                                    <div style={{ padding: '10px', background: '#0b0b0b', fontSize: '11px', color: darkText }}>
                                                                                                        {details.stage_proof_attachment_url && <div style={{ marginBottom: '6px' }}><a href={details.stage_proof_attachment_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>View supplier proof</a></div>}
                                                                                                        {details.stage_proof_note && <div style={{ marginBottom: '6px' }}>Supplier note: {details.stage_proof_note}</div>}
                                                                                                        {details.stage_meeting_link && <div style={{ marginBottom: '6px' }}><a href={details.stage_meeting_link} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>Open meeting link</a></div>}
                                                                                                        {Array.isArray(details.stage_submission_history) && details.stage_submission_history.length > 0 && (
                                                                                                            <div style={{ marginBottom: '8px', padding: '8px', border: `1px solid ${darkBorder}`, borderRadius: '4px', background: '#101010' }}>
                                                                                                                <div style={{ marginBottom: '6px', color: '#ddd' }}>Submission history</div>
                                                                                                                {details.stage_submission_history.map((h, idx) => (
                                                                                                                    <div key={`hist-${stageKey}-${idx}`} style={{ marginBottom: '6px', paddingBottom: '6px', borderBottom: '1px dashed #2a2a2a' }}>
                                                                                                                        <div style={{ color: '#fff' }}>#{h.submission_no || idx + 1} - {h.submitted_at || ''}</div>
                                                                                                                        {h.attachment_url && <div><a href={h.attachment_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>View submitted file</a></div>}
                                                                                                                        {h.meeting_link && <div><a href={h.meeting_link} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>Meeting link</a></div>}
                                                                                                                        {h.supplier_note && <div>Supplier note: {h.supplier_note}</div>}
                                                                                                                        {h.review_status === 'approved' && <div style={{ color: '#4caf50', fontWeight: 600 }}>Approved {h.reviewed_at || ''}</div>}
                                                                                                                        {h.review_status === 'revision_requested' && <div style={{ color: '#ffb347', fontWeight: 600 }}>Revision requested {h.reviewed_at || ''}{h.reviewer_note ? ` - ${h.reviewer_note}` : ''}</div>}
                                                                                                                    </div>
                                                                                                                ))}
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {details.stage_revision_requested_at && <div style={{ marginBottom: '6px', color: '#ffb347' }}>Revision requested {new Date(details.stage_revision_requested_at).toLocaleString()}</div>}
                                                                                                        {details.stage_revision_note && <div style={{ marginBottom: '6px' }}>Revision note: {details.stage_revision_note}</div>}
                                                                                                        {details.stage_submitted_at && !details.stage_approved_at && !!details.stage_revision_requested_at && !isOperator && (
                                                                                                            <div style={{ marginBottom: '8px', color: '#ffb347' }}>
                                                                                                                Waiting for supplier resubmission. Approval/Revision actions will reappear after new submission.
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {details.stage_submitted_at && !details.stage_approved_at && !details.stage_revision_requested_at && !isOperator && (
                                                                                                            <div style={{ marginBottom: '8px' }}>
                                                                                                                <textarea
                                                                                                                    rows={2}
                                                                                                                    placeholder="Add revision note (optional)"
                                                                                                                    value={revisionNotesByStage[stageKey] || ''}
                                                                                                                    onChange={(e) => setRevisionNotesByStage((prev) => ({ ...prev, [stageKey]: e.target.value }))}
                                                                                                                    style={{ width: '100%', background: '#111', color: '#ddd', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', marginBottom: '6px', fontSize: '11px' }}
                                                                                                                />
                                                                                                                <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                                                                                                    <button
                                                                                                                        type="button"
                                                                                                                        disabled={isBusy}
                                                                                                                        onClick={() => runMilestoneAction(stageKey, 'n88_approve_stage_progress')}
                                                                                                                        style={{ padding: '6px 10px', fontSize: '11px', background: '#1e3a8a', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                                                                                                                    >
                                                                                                                        {isBusy && milestoneActionTypeByStage[stageKey] === 'n88_approve_stage_progress' ? 'Approving...' : 'Approve Stage Progress'}
                                                                                                                    </button>
                                                                                                                    <button
                                                                                                                        type="button"
                                                                                                                        disabled={isBusy}
                                                                                                                        onClick={() => runMilestoneAction(stageKey, 'n88_request_stage_progress_revision', (fd) => {
                                                                                                                            if (revisionNotesByStage[stageKey]) fd.append('note', revisionNotesByStage[stageKey]);
                                                                                                                        })}
                                                                                                                        style={{ padding: '6px 10px', fontSize: '11px', background: '#3a1f1f', color: '#ffb347', border: '1px solid #ffb347', borderRadius: '4px', cursor: 'pointer' }}
                                                                                                                    >
                                                                                                                        {isBusy && milestoneActionTypeByStage[stageKey] === 'n88_request_stage_progress_revision' ? 'Requesting Revision...' : 'Request Revision'}
                                                                                                                    </button>
                                                                                                                </div>
                                                                                                            </div>
                                                                                                        )}
                                                                                                        {details.stage_approved_at && !details.payment_confirmed_at && (
                                                                                                            <div style={{ borderTop: `1px solid ${darkBorder}`, paddingTop: '8px', marginTop: '8px' }}>
                                                                                                                <div style={{ marginBottom: '6px', color: '#ddd' }}>Payment required for this stage.</div>
                                                                                                                {details.payment_attachment_url && <div style={{ marginBottom: '6px' }}><a href={details.payment_attachment_url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>View payment proof</a></div>}
                                                                                                                {details.payment_note && <div style={{ marginBottom: '6px' }}>Payment note: {details.payment_note}</div>}
                                                                                                                {!!details.payment_submitted_at && !details.payment_confirmed_at && (
                                                                                                                    <div style={{ marginBottom: '8px', color: '#ffb347' }}>
                                                                                                                        Payment proof submitted on {new Date(details.payment_submitted_at).toLocaleString()}. Awaiting approval.
                                                                                                                    </div>
                                                                                                                )}
                                                                                                                {!isOperator && (
                                                                                                                    <>
                                                                                                                        {!details.payment_submitted_at && (
                                                                                                                            <>
                                                                                                                        <input
                                                                                                                            type="text"
                                                                                                                            placeholder="Payment method (optional)"
                                                                                                                            value={paymentMethodsByStage[stageKey] || ''}
                                                                                                                            onChange={(e) => setPaymentMethodsByStage((prev) => ({ ...prev, [stageKey]: e.target.value }))}
                                                                                                                            style={{ width: '100%', background: '#111', color: '#ddd', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', marginBottom: '6px', fontSize: '11px' }}
                                                                                                                        />
                                                                                                                        <textarea
                                                                                                                            rows={2}
                                                                                                                            placeholder="Add payment note"
                                                                                                                            value={paymentNotesByStage[stageKey] || ''}
                                                                                                                            onChange={(e) => setPaymentNotesByStage((prev) => ({ ...prev, [stageKey]: e.target.value }))}
                                                                                                                            style={{ width: '100%', background: '#111', color: '#ddd', border: `1px solid ${darkBorder}`, borderRadius: '4px', padding: '6px', marginBottom: '6px', fontSize: '11px' }}
                                                                                                                        />
                                                                                                                        <input
                                                                                                                            type="file"
                                                                                                                            onChange={(e) => setPaymentProofFilesByStage((prev) => ({ ...prev, [stageKey]: e.target.files?.[0] || null }))}
                                                                                                                            style={{ marginBottom: '6px', fontSize: '11px', color: '#ccc' }}
                                                                                                                        />
                                                                                                                        <button
                                                                                                                            type="button"
                                                                                                                            disabled={isBusy}
                                                                                                                            onClick={() => runMilestoneAction(stageKey, 'n88_submit_stage_payment_proof', (fd) => {
                                                                                                                                if (paymentMethodsByStage[stageKey]) fd.append('payment_method', paymentMethodsByStage[stageKey]);
                                                                                                                                if (paymentNotesByStage[stageKey]) fd.append('note', paymentNotesByStage[stageKey]);
                                                                                                                                const file = paymentProofFilesByStage[stageKey];
                                                                                                                                if (file) fd.append('payment_proof', file);
                                                                                                                            })}
                                                                                                                            style={{ padding: '6px 10px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: '600' }}
                                                                                                                        >
                                                                                                                            Submit Payment Proof
                                                                                                                        </button>
                                                                                                                            </>
                                                                                                                        )}
                                                                                                                    </>
                                                                                                                )}
                                                                                                                {isOperator && (
                                                                                                                    <button
                                                                                                                        type="button"
                                                                                                                        disabled={isBusy}
                                                                                                                        onClick={() => runMilestoneAction(stageKey, 'n88_approve_stage_payment')}
                                                                                                                        style={{ padding: '6px 10px', fontSize: '11px', background: '#0f5132', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}
                                                                                                                    >
                                                                                                                        Confirm Payment
                                                                                                                    </button>
                                                                                                                )}
                                                                                                            </div>
                                                                                                        )}
                                                                                                    </div>
                                                                                                )}
                                                                                            </div>
                                                                                        );
                                                                                    })}
                                                                                </>
                                                                            )}
                                                                        </div>
                                                                    );
                                                                })()}
                                                                {/* Commit 28: Deposit — show when Step 4 selected and item awarded; operator marks received before production can start */}
                                                                {false && s.step_number === 4 && itemState.has_awarded_bid && (
                                                                    <div style={{ marginTop: '12px', marginBottom: '12px', padding: '12px', border: '1px solid #FF0065', borderRadius: '4px', backgroundColor: 'rgba(0,0,0,0.2)' }}>
                                                                        <div style={{ fontSize: '12px', fontWeight: '600', color: darkText, marginBottom: '6px' }}>Sent Deposit to start production</div>
                                                                        {itemState.deposit_status === 'received' ? (
                                                                            <div style={{ fontSize: '11px', color: greenAccent }}>
                                                                                ✓ Received {itemState.deposit_received_at ? new Date(itemState.deposit_received_at).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : ''}. Production (Step 4) can be started.
                                                                            </div>
                                                                        ) : (
                                                                            <>
                                                                                <div style={{ fontSize: '11px', color: darkText, marginBottom: '8px' }}>
                                                                                    {itemState.deposit_amount != null ? `$${Number(itemState.deposit_amount).toFixed(2)} pending.` : 'Deposit pending.'}{' '}
                                                                                    {itemState.deposit_status === 'sent_by_designer'
                                                                                        ? 'Status: Awaiting Deposit Confirmation. Operator will review your proof and mark received.'
                                                                                        : 'Production can start only after the operator marks deposit received.'}
                                                                                </div>
                                                                                {/* Designer: open modal to send payment proof (screenshot/file) */}
                                                                                {(!window.n88BoardData || !window.n88BoardData.isOperator) && itemState.deposit_status !== 'sent_by_designer' && (
                                                                                    <button
                                                                                        type="button"
                                                                                        onClick={() => setShowDepositProofModal(true)}
                                                                                        style={{ padding: '8px 12px', fontSize: '11px', background: '#FF0065', color: '#000', border: '1px solid #FF0065', borderRadius: '4px', cursor: 'pointer', fontFamily: 'monospace', fontWeight: '600', marginRight: '8px' }}
                                                                                    >
                                                                                        [ Send Deposit Payment Proof ]
                                                                                    </button>
                                                                                )}
                                                                                {/* Operator: view payment receipt, designer comments, approve */}
                                                                                {window.n88BoardData && window.n88BoardData.isOperator && (
                                                                                    <div style={{ marginTop: '12px', padding: '12px', background: 'rgba(0,0,0,0.3)', border: '1px solid #555', borderRadius: '4px' }}>
                                                                                        <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Review payment proof</div>
                                                                                        {itemState.deposit_receipt_url && (
                                                                                            <a href={itemState.deposit_receipt_url} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-block', padding: '6px 12px', fontSize: '11px', background: '#003300', color: greenAccent, border: `1px solid ${greenAccent}`, borderRadius: '4px', textDecoration: 'none', fontFamily: 'monospace', fontWeight: '600', marginBottom: '8px' }}>
                                                                                                View payment receipt
                                                                                            </a>
                                                                                        )}
                                                                                        {(itemState.deposit_sent_note && String(itemState.deposit_sent_note).trim()) && (
                                                                                            <div style={{ marginBottom: '10px' }}>
                                                                                                <div style={{ fontSize: '11px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Designer comment</div>
                                                                                                <div style={{ fontSize: '11px', color: '#ccc', whiteSpace: 'pre-wrap', padding: '8px', background: '#111', borderRadius: '4px', border: '1px solid #333' }}>{itemState.deposit_sent_note}</div>
                                                                                                {itemState.deposit_sent_at && <div style={{ fontSize: '10px', color: '#888', marginTop: '4px' }}>Sent {new Date(itemState.deposit_sent_at).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })}</div>}
                                                                                            </div>
                                                                                        )}
                                                                                        <button
                                                                                            type="button"
                                                                                            onClick={async () => {
                                                                                                if (!window.confirm('Mark deposit as received for this item? Production (Step 4) will then be allowed to start.')) return;
                                                                                                const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce;
                                                                                                if (!nonce) { alert('Security token missing.'); return; }
                                                                                                const fd = new FormData();
                                                                                                fd.append('action', 'n88_mark_deposit_received');
                                                                                                fd.append('item_id', String(getItemId()));
                                                                                                fd.append('_ajax_nonce', nonce);
                                                                                                try {
                                                                                                    const res = await fetch(window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd });
                                                                                                    const data = await res.json();
                                                                                                    if (data.success) { fetchItemState(); fetchTimeline(); }
                                                                                                    else alert(data.data?.message || 'Failed.');
                                                                                                } catch (e) { console.error(e); alert('Error. Please try again.'); }
                                                                                            }}
                                                                                            style={{ padding: '8px 12px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontFamily: 'monospace', fontWeight: '600' }}
                                                                                        >
                                                                                            Approve (mark deposit received)
                                                                                        </button>
                                                                                    </div>
                                                                                )}
                                                                            </>
                                                                        )}
                                                                    </div>
                                                                )}
                                                                {/* Commit 3.B.5.A1: Step 4–6 — Video evidence + designer step comments */}
                                                                {s.step_number >= 4 && s.step_number <= 6 && (() => {
                                                                    const videos = (timelineData.step_456_videos || {})[s.step_number] || [];
                                                                    const comments = (timelineData.step_456_comments || {})[s.step_number] || [];
                                                                    const canAddComment = !!timelineData.can_add_evidence_comment;
                                                                    const draft = designerStep456CommentDraft[s.step_number] ?? '';
                                                                    const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
                                                                    const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                                    return (
                                                                        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                                                                            <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Video Evidence</div>
                                                                            {videos.length > 0 ? videos.map((sub, i) => (
                                                                                <div key={i} style={{ marginBottom: '12px' }}>
                                                                                    <div style={{ fontSize: '11px', color: darkText, marginBottom: '4px' }}>Video Evidence v{sub.version || ''}</div>
                                                                                    {(sub.links || []).map((lk, j) => (
                                                                                        <div key={j} style={{ marginBottom: '6px' }}>
                                                                                            <a href={lk.url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent, fontSize: '11px' }}>{lk.provider || 'Link'} — Open video</a>
                                                                                        </div>
                                                                                    ))}
                                                                                    {sub.optional_note && (
                                                                                        <div style={{ fontSize: '11px', color: darkText, fontStyle: 'italic', marginTop: '6px' }}>Optional Supplier Note: {sub.optional_note}</div>
                                                                                    )}
                                                                                </div>
                                                                            )) : <div style={{ fontSize: '11px', color: darkText, marginBottom: '10px' }}>No video evidence yet.</div>}
                                                                            <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginTop: '12px', marginBottom: '8px' }}>Designer Comments</div>
                                                                            {comments.length > 0 ? (
                                                                                <ul style={{ margin: 0, paddingLeft: '18px', fontSize: '11px', color: darkText }}>
                                                                                    {comments.map((c, i) => (
                                                                                        <li key={i} style={{ marginBottom: '6px' }}>{c.created_at?.split(' ')[0] || ''} {c.designer_name || ''}: &quot;{c.comment_text}&quot;</li>
                                                                                    ))}
                                                                                </ul>
                                                                            ) : <div style={{ fontSize: '11px', color: darkText, marginBottom: '10px' }}>No comments yet.</div>}
                                                                            {/* Acceptance: Designer sees comment option under same step (4–6); form when owner, note when not */}
                                                                            <div style={{ marginTop: '12px' }}>
                                                                                <div style={{ fontSize: '11px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Review This Step</div>
                                                                                {canAddComment ? (
                                                                                    <>
                                                                                        <textarea
                                                                                            placeholder="Add feedback or approval note for this step…"
                                                                                            value={draft}
                                                                                            onChange={(e) => setDesignerStep456CommentDraft((prev) => ({ ...prev, [s.step_number]: e.target.value }))}
                                                                                            rows={3}
                                                                                            style={{ width: '100%', padding: '8px', fontSize: '11px', background: '#111', color: '#ccc', border: `1px solid ${darkBorder}`, borderRadius: '4px', resize: 'vertical' }}
                                                                                        />
                                                                                        <button
                                                                                            type="button"
                                                                                            disabled={designerStep456CommentSubmitting || !draft.trim()}
                                                                                            onClick={async () => {
                                                                                                if (!draft.trim() || !nonce) return;
                                                                                                setDesignerStep456CommentSubmitting(true);
                                                                                                try {
                                                                                                    const fd = new FormData();
                                                                                                    fd.append('action', 'n88_designer_submit_step_comment');
                                                                                                    fd.append('item_id', String(getItemId()));
                                                                                                    fd.append('step_number', String(s.step_number));
                                                                                                    fd.append('comment_text', draft.trim());
                                                                                                    fd.append('_ajax_nonce', nonce);
                                                                                                    const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                                                                                                    const data = await res.json();
                                                                                                    if (data.success) {
                                                                                                        setDesignerStep456CommentDraft((prev) => { const n = { ...prev }; n[s.step_number] = ''; return n; });
                                                                                                        fetchTimeline();
                                                                                                        try { window.dispatchEvent(new CustomEvent('n88-board-refresh-status')); } catch (e) {}
                                                                                                    } else {
                                                                                                        alert(data.data?.message || 'Failed to submit comment.');
                                                                                                    }
                                                                                                } finally {
                                                                                                    setDesignerStep456CommentSubmitting(false);
                                                                                                }
                                                                                            }}
                                                                                            style={{ marginTop: '6px', padding: '6px 12px', fontSize: '11px', background: greenAccent, color: '#000', border: 'none', borderRadius: '4px', cursor: 'pointer', fontFamily: 'monospace' }}
                                                                                        >
                                                                                            Submit Comment
                                                                                        </button>
                                                                                    </>
                                                                                ) : (
                                                                                    <div style={{ fontSize: '11px', color: '#888' }}>Only the item owner (designer) can add step comments here.</div>
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                })()}
                                                                {/* Commit 3.A.2S: Supplier step evidence (designer read-only — Evidence Received + View Step Evidence) */}
                                                                {(() => {
                                                                    const stepsWithEvidence = timelineData.steps_with_supplier_evidence || {};
                                                                    const hasSupplierEvidence = stepsWithEvidence[s.step_id] || stepsWithEvidence[String(s.step_id)];
                                                                    if (!hasSupplierEvidence) return null;
                                                                    const viewingThis = supplierStepEvidenceView && supplierStepEvidenceView.stepId === s.step_id;
                                                                    const ajaxUrl = window.n88BoardData?.ajaxUrl || window.n88?.ajaxUrl || '/wp-admin/admin-ajax.php';
                                                                    const nonce = window.n88BoardNonce?.nonce_get_item_rfq_state || window.n88BoardData?.nonce || window.n88?.nonce || '';
                                                                    return (
                                                                        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${darkBorder}` }}>
                                                                            <div style={{ fontSize: '12px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Supplier Evidence Received</div>
                                                                            {!viewingThis ? (
                                                                                <button
                                                                                    type="button"
                                                                                    disabled={supplierStepEvidenceLoading}
                                                                                    onClick={async () => {
                                                                                        if (!nonce || !getItemId()) return;
                                                                                        setSupplierStepEvidenceLoading(true);
                                                                                        try {
                                                                                            const fd = new FormData();
                                                                                            fd.append('action', 'n88_get_step_evidence');
                                                                                            fd.append('item_id', String(getItemId()));
                                                                                            fd.append('step_id', String(s.step_id));
                                                                                            fd.append('_ajax_nonce', nonce);
                                                                                            const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                                                                                            const data = await res.json();
                                                                                            if (data.success && data.data && data.data.for_step) {
                                                                                                setSupplierStepEvidenceView({ stepId: s.step_id, data: data.data.for_step });
                                                                                            }
                                                                                        } finally {
                                                                                            setSupplierStepEvidenceLoading(false);
                                                                                        }
                                                                                    }}
                                                                                    style={{ padding: '6px 12px', fontSize: '11px', background: '#111', color: greenAccent, border: `1px solid ${greenAccent}`, borderRadius: '4px', cursor: 'pointer', fontFamily: 'monospace' }}
                                                                                >
                                                                                    {supplierStepEvidenceLoading ? 'Loading…' : '[ View Step Evidence ]'}
                                                                                </button>
                                                                            ) : (
                                                                                <div>
                                                                                    {supplierStepEvidenceView.data.submissions && supplierStepEvidenceView.data.submissions.length > 0 ? (
                                                                                        <ul style={{ margin: '8px 0 0 18px', padding: 0, fontSize: '11px', color: darkText }}>
                                                                                            {supplierStepEvidenceView.data.submissions.flatMap((sub, i) => (sub.links || []).map((link, j) => (
                                                                                                <li key={`${i}-${j}`} style={{ marginBottom: '4px' }}>
                                                                                                    <a href={link.url} target="_blank" rel="noopener noreferrer" style={{ color: greenAccent }}>{link.provider || 'Link'}</a>
                                                                                                </li>
                                                                                            )))}
                                                                                        </ul>
                                                                                    ) : (
                                                                                        <div style={{ fontSize: '11px', color: darkText }}>No links.</div>
                                                                                    )}
                                                                                    <button type="button" onClick={() => setSupplierStepEvidenceView(null)} style={{ marginTop: '8px', padding: '4px 10px', fontSize: '11px', background: 'transparent', color: darkText, border: `1px solid ${darkBorder}`, borderRadius: '4px', cursor: 'pointer' }}>Close</button>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    );
                                                                })()}
                                                            </div>
                                                        );
                                                    })()}
                                                    {/* Prototype mini-timeline under Step 3 */}
                                                    {timelineData.show_prototype_mini && (
                                                        <div style={{
                                                            marginTop: '20px',
                                                            padding: '12px',
                                                            border: `1px solid ${darkBorder}`,
                                                            borderRadius: '4px',
                                                            fontSize: '11px',
                                                            color: darkText,
                                                        }}>
                                                            <div style={{ marginBottom: '8px', color: greenAccent }}>Prototype Mini-Timeline (visual only)</div>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                                <span>Requested</span>
                                                                <span>→</span>
                                                                <span>Paid</span>
                                                                <span>→</span>
                                                                <span>CAD Approved</span>
                                                                <span>→</span>
                                                                <span>Prototype Submitted</span>
                                                                <span>→</span>
                                                                <span>Approved</span>
                                                            </div>
                                                            <div style={{ marginTop: '6px', opacity: 0.8, fontSize: '10px' }}>Appears after prototype payment evidence is cleared.</div>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    )}


                                </div>
                            </div>
                        </div>
                        {/* Footer - Save button and Update button - Hidden in State C (when bids exist); Lock Designer: hidden when awaiting payment */}
                        {(currentState === 'A' || currentState === 'B' || (currentState === 'C' && !itemState.has_bids)) && !isLockedAwaitingPayment && (
                        <div style={{
                                padding: '16px 20px',
                                borderTop: `1px solid ${darkBorder}`,
                            display: 'flex',
                                gap: '12px',
                            justifyContent: 'flex-end',
                        }}>
                            {/* Show Update button only if RFQ has already been submitted (State B or C) */}
                            {/* Commit 2.6.1: Hide Update button for view-only team members */}
                            {/* Update button: Only updates dimensions and quantity */}
                            {/* Also check item.has_rfq as fallback in case itemState not loaded yet */}
                            {!isViewOnly && ((currentState === 'B' || currentState === 'C') || (item?.has_rfq || itemState.has_rfq)) && (
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
                            {/* Commit 2.6.1: Hide Save button for view-only team members */}
                            {!isViewOnly && (
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
                            )}
                        </div>
                        )}
                    </motion.div>
                    
                    {/* Inline video player overlay — Step 3 prototype video (play on screen, no new tab) */}
                    {inlineVideoPlayer && (
                        <div
                            style={{
                                position: 'fixed',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                backgroundColor: 'rgba(0, 0, 0, 0.92)',
                                zIndex: 1000002,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                padding: '24px',
                                boxSizing: 'border-box',
                            }}
                            onClick={(e) => { if (e.target === e.currentTarget) setInlineVideoPlayer(null); }}
                        >
                            <div
                                style={{
                                    position: 'relative',
                                    width: '100%',
                                    maxWidth: '900px',
                                    maxHeight: '90vh',
                                    aspectRatio: '16/9',
                                    backgroundColor: '#111',
                                    borderRadius: '8px',
                                    overflow: 'hidden',
                                    border: '1px solid #333',
                                    boxShadow: '0 8px 32px rgba(0,0,0,0.5)',
                                }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <button
                                    type="button"
                                    onClick={() => setInlineVideoPlayer(null)}
                                    style={{
                                        position: 'absolute',
                                        top: '12px',
                                        right: '12px',
                                        zIndex: 10,
                                        width: '36px',
                                        height: '36px',
                                        borderRadius: '50%',
                                        border: '1px solid #333',
                                        background: 'rgba(0,0,0,0.8)',
                                        color: '#fff',
                                        fontSize: '20px',
                                        cursor: 'pointer',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        lineHeight: 1,
                                    }}
                                    aria-label="Close video"
                                >
                                    ×
                                </button>
                                {getVideoEmbedUrl(inlineVideoPlayer.url, inlineVideoPlayer.provider) ? (
                                    <iframe
                                        title={`Prototype video — ${inlineVideoPlayer.provider}`}
                                        src={getVideoEmbedUrl(inlineVideoPlayer.url, inlineVideoPlayer.provider)}
                                        style={{
                                            position: 'absolute',
                                            top: 0,
                                            left: 0,
                                            width: '100%',
                                            height: '100%',
                                            border: 'none',
                                        }}
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowFullScreen
                                    />
                                ) : (
                                    <div style={{ padding: '24px', color: '#ccc', fontSize: '14px', textAlign: 'center' }}>
                                        <p>This video link cannot be embedded. You can open it in a new tab:</p>
                                        <a href={inlineVideoPlayer.url} target="_blank" rel="noopener noreferrer" style={{ color: '#FF0065', marginTop: '8px', display: 'inline-block' }}>Open {inlineVideoPlayer.provider} →</a>
                                        <button type="button" onClick={() => setInlineVideoPlayer(null)} style={{ display: 'block', margin: '16px auto 0', padding: '8px 16px', background: '#333', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' }}>Close</button>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                    
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
                                    maxHeight: '70vh',
                                    overflowY: 'auto',
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

                                    {/* Upload Payment Receipt (JPG/PDF) — operator sees these when marking received */}
                                    <div style={{ marginTop: '20px', padding: '12px', backgroundColor: '#1a0a14', borderRadius: '4px', border: '1px solid #FF0065' }}>
                                        {(() => {
                                            const paymentApproved = itemState.prototype_payment_status === 'marked_received';
                                            const hasReceipts = paymentReceipts.length > 0;
                                            const showForm = !hasReceipts || (hasReceipts && !paymentApproved && showResubmitReceiptForm);
                                            // After submit: hide message box; show Resubmit only when payment not approved
                                            if (showForm) {
                                                return (
                                                    <>
                                                        {/* Message (optional) only when showing form (first time or resubmit) */}
                                                        <div style={{ marginBottom: '14px' }}>
                                                            <label style={{ display: 'block', fontSize: '13px', fontWeight: '600', color: '#FF0065', marginBottom: '6px' }}>Message (optional):</label>
                                                            <textarea
                                                                value={paymentReceiptMessage}
                                                                onChange={(e) => setPaymentReceiptMessage(e.target.value)}
                                                                placeholder="e.g. Paid via Zelle, ref #123 or bank transfer confirmation"
                                                                rows={2}
                                                                maxLength={500}
                                                                style={{ width: '100%', padding: '8px 10px', fontSize: '12px', color: '#fff', backgroundColor: '#1a0d14', border: '1px solid #33001a', borderRadius: '4px', fontFamily: 'monospace', resize: 'vertical', boxSizing: 'border-box' }}
                                                            />
                                                        </div>
                                                        <div style={{ fontSize: '14px', fontWeight: '600', color: '#FF0065', marginBottom: '8px' }}>{hasReceipts ? 'Resubmit payment proof' : 'Upload Payment Receipt'}</div>
                                                        <p style={{ fontSize: '12px', color: '#aaa', marginBottom: '10px' }}>JPG or PDF. Operator will review before confirming payment.</p>
                                                        <input
                                                            ref={paymentReceiptInputRef}
                                                            type="file"
                                                            accept=".jpg,.jpeg,.pdf"
                                                            onChange={handlePaymentReceiptFileSelect}
                                                            disabled={paymentReceiptUploading}
                                                            style={{ display: 'block', marginBottom: '10px', fontSize: '12px', color: '#fff' }}
                                                        />
                                                        {paymentReceiptSelectedFile && (
                                                            <div style={{ fontSize: '12px', color: '#FF0065', marginBottom: '8px', padding: '6px 10px', backgroundColor: '#1a0d14', borderRadius: '4px', border: '1px solid #33001a' }}>
                                                                Selected: {paymentReceiptSelectedFile.name}
                                                            </div>
                                                        )}
                                                        <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                                                            <button
                                                                type="button"
                                                                onClick={submitPaymentReceiptUpload}
                                                                disabled={!paymentReceiptSelectedFile || paymentReceiptUploading}
                                                                style={{ padding: '6px 14px', backgroundColor: '#FF0065', color: '#1a0a14', border: 'none', borderRadius: '4px', cursor: 'pointer', fontSize: '12px', fontWeight: '600', fontFamily: 'monospace' }}
                                                            >
                                                                {hasReceipts ? 'Submit' : 'Submit'}
                                                            </button>
                                                            {hasReceipts && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => { setShowResubmitReceiptForm(false); setPaymentReceiptMessage(''); setPaymentReceiptSelectedFile(null); if (paymentReceiptInputRef.current) paymentReceiptInputRef.current.value = ''; }}
                                                                    style={{ padding: '6px 14px', backgroundColor: 'transparent', color: '#FF0065', border: '1px solid #FF0065', borderRadius: '4px', cursor: 'pointer', fontSize: '12px', fontFamily: 'monospace' }}
                                                                >
                                                                    Cancel
                                                                </button>
                                                            )}
                                                            {paymentReceiptUploading && <span style={{ fontSize: '11px', color: '#FF0065' }}>Uploading…</span>}
                                                        </div>
                                                        {paymentReceiptsLoading && <div style={{ fontSize: '12px', color: '#888', marginTop: '8px' }}>Loading receipts…</div>}
                                                    </>
                                                );
                                            }
                                            // Has receipts: show list; if payment not approved, show Resubmit button (no message box)
                                            return (
                                                <>
                                                    <div style={{ fontSize: '14px', fontWeight: '600', color: '#FF0065', marginBottom: '8px' }}>Payment proof attachment: Uploaded</div>
                                                    <ul style={{ margin: 0, paddingLeft: '18px', color: '#ccc', fontSize: '12px' }}>
                                                        {paymentReceipts.map((r, index) => {
                                                            const isResubmitted = paymentReceipts.length > 1 && index < paymentReceipts.length - 1;
                                                            return (
                                                                <li key={r.id} style={{ marginBottom: '6px' }}>
                                                                    {isResubmitted && (
                                                                        <span style={{ display: 'inline-block', marginRight: '8px', padding: '2px 6px', fontSize: '10px', fontWeight: '600', backgroundColor: '#331100', color: '#ff8800', border: '1px solid #ff8800', borderRadius: '2px' }}>Resubmitted</span>
                                                                    )}
                                                                    <a href={r.url} target="_blank" rel="noopener noreferrer" style={{ color: '#FF0065' }}>{r.file_name}</a>
                                                                    {r.message && <span style={{ display: 'block', marginTop: '4px', color: '#aaa', fontStyle: 'italic' }}>— {r.message}</span>}
                                                                </li>
                                                            );
                                                        })}
                                                    </ul>
                                                    {!paymentApproved && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowResubmitReceiptForm(true)}
                                                            style={{ marginTop: '12px', padding: '8px 16px', backgroundColor: '#003300', color: '#FF0065', border: '1px solid #FF0065', borderRadius: '4px', cursor: 'pointer', fontSize: '12px', fontWeight: '600', fontFamily: 'monospace' }}
                                                        >
                                                            Resubmit
                                                        </button>
                                                    )}
                                                </>
                                            );
                                        })()}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                    {/* Deposit Proof Modal — designer sends payment proof (screenshot/PDF); same style as Payment Instructions */}
                    {showDepositProofModal && (
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
                            onClick={() => !depositProofUploading && setShowDepositProofModal(false)}
                        >
                            <div
                                style={{
                                    maxWidth: '560px',
                                    width: '100%',
                                    backgroundColor: darkBg,
                                    border: `2px solid ${greenAccent}`,
                                    borderRadius: '8px',
                                    padding: '24px',
                                    fontFamily: 'monospace',
                                }}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px', borderBottom: `1px solid ${darkBorder}`, paddingBottom: '12px' }}>
                                    <h2 style={{ margin: 0, fontSize: '18px', fontWeight: '600', color: greenAccent }}>Sent Deposit to start production</h2>
                                    <button type="button" onClick={() => !depositProofUploading && setShowDepositProofModal(false)} style={{ background: 'none', border: 'none', color: darkText, fontSize: '24px', cursor: depositProofUploading ? 'not-allowed' : 'pointer', padding: 0, width: '32px', height: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>×</button>
                                </div>
                                <div style={{ fontSize: '13px', color: darkText, lineHeight: '1.6', marginBottom: '16px' }}>
                                    {itemState.deposit_amount != null && (
                                        <div style={{ marginBottom: '12px', padding: '12px', backgroundColor: '#111111', borderRadius: '4px', border: `1px solid ${darkBorder}` }}>
                                            <span style={{ fontSize: '14px', fontWeight: '600', color: greenAccent }}>Amount due: </span>
                                            <span style={{ color: '#fff' }}>${Number(itemState.deposit_amount).toFixed(2)}</span>
                                        </div>
                                    )}
                                    <p style={{ marginBottom: '12px' }}>Upload proof of payment (screenshot or PDF). The operator will review and mark deposit received so production can start.</p>
                                    <div style={{ marginTop: '16px', padding: '12px', backgroundColor: '#1a0a14', borderRadius: '4px', border: `1px solid ${greenAccent}` }}>
                                        <div style={{ fontSize: '14px', fontWeight: '600', color: greenAccent, marginBottom: '8px' }}>Upload payment proof</div>
                                        <p style={{ fontSize: '12px', color: '#aaa', marginBottom: '10px' }}>Image (JPG, PNG) or PDF. Operator will review before confirming.</p>
                                        <div style={{ marginBottom: '10px' }}>
                                            <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', color: darkText, marginBottom: '4px' }}>Message (optional):</label>
                                            <textarea
                                                value={depositProofMessage}
                                                onChange={(e) => setDepositProofMessage(e.target.value)}
                                                placeholder="e.g. Paid via Zelle, ref #123"
                                                rows={2}
                                                maxLength={500}
                                                style={{ width: '100%', padding: '8px 10px', fontSize: '12px', color: '#fff', backgroundColor: '#1a0d14', border: '1px solid #33001a', borderRadius: '4px', fontFamily: 'monospace', resize: 'vertical', boxSizing: 'border-box' }}
                                            />
                                        </div>
                                        <input
                                            ref={depositProofInputRef}
                                            type="file"
                                            accept="image/*,.pdf,.png,.jpg,.jpeg"
                                            onChange={handleDepositProofFileSelect}
                                            disabled={depositProofUploading}
                                            style={{ display: 'block', marginBottom: '10px', fontSize: '12px', color: '#fff' }}
                                        />
                                        {depositProofFile && (
                                            <div style={{ fontSize: '12px', color: greenAccent, marginBottom: '8px', padding: '6px 10px', backgroundColor: '#1a0d14', borderRadius: '4px', border: '1px solid #33001a' }}>
                                                Selected: {depositProofFile.name}
                                            </div>
                                        )}
                                        <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                                            <button
                                                type="button"
                                                onClick={submitDepositProof}
                                                disabled={!depositProofFile || depositProofUploading}
                                                style={{ padding: '8px 16px', backgroundColor: depositProofFile && !depositProofUploading ? greenAccent : '#333', color: depositProofFile && !depositProofUploading ? '#000' : '#666', border: 'none', borderRadius: '4px', cursor: depositProofFile && !depositProofUploading ? 'pointer' : 'not-allowed', fontSize: '12px', fontWeight: '600', fontFamily: 'monospace' }}
                                            >
                                                {depositProofUploading ? 'Submitting…' : 'Submit'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => { setShowDepositProofModal(false); setDepositProofFile(null); setDepositProofMessage(''); if (depositProofInputRef.current) depositProofInputRef.current.value = ''; }}
                                                disabled={depositProofUploading}
                                                style={{ padding: '8px 16px', backgroundColor: 'transparent', color: greenAccent, border: `1px solid ${greenAccent}`, borderRadius: '4px', cursor: depositProofUploading ? 'not-allowed' : 'pointer', fontSize: '12px', fontFamily: 'monospace' }}
                                            >
                                                Cancel
                                            </button>
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
