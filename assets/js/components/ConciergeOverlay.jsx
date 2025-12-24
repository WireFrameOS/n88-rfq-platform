/**
 * Concierge Overlay Component
 * 
 * Milestone 1.3.7: Read-only overlay showing concierge info
 * 
 * Non-blocking overlay that displays concierge name and avatar.
 * Must not interfere with board interactions (pointer-events: none).
 */

import React from 'react';

/**
 * ConciergeOverlay - Persistent overlay showing concierge information
 * 
 * @param {Object} props
 * @param {Object} props.concierge - Concierge data { name: string, avatarUrl: string }
 */
const ConciergeOverlay = ({ concierge }) => {
    // Default placeholder if concierge data not provided
    const conciergeData = concierge || {
        name: 'Your Concierge',
        avatarUrl: '',
    };

    return (
        <div
            style={{
                position: 'absolute',
                top: '20px',
                left: '20px',
                zIndex: 10000, // Above tiles (tiles use z-index up to ~1000)
                pointerEvents: 'none', // Non-blocking - allows clicks to pass through
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '12px',
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    padding: '12px 16px',
                    borderRadius: '8px',
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.15)',
                    border: '1px solid rgba(0, 0, 0, 0.1)',
                }}
            >
                {/* Avatar */}
                <div
                    style={{
                        width: '40px',
                        height: '40px',
                        borderRadius: '50%',
                        backgroundColor: conciergeData.avatarUrl ? 'transparent' : '#0073aa',
                        backgroundImage: conciergeData.avatarUrl ? `url(${conciergeData.avatarUrl})` : 'none',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: '#fff',
                        fontSize: '18px',
                        fontWeight: 'bold',
                        flexShrink: 0,
                    }}
                >
                    {!conciergeData.avatarUrl && (
                        <span>{conciergeData.name.charAt(0).toUpperCase()}</span>
                    )}
                </div>
                {/* Name */}
                <div
                    style={{
                        fontSize: '14px',
                        fontWeight: '600',
                        color: '#333',
                    }}
                >
                    {conciergeData.name}
                </div>
            </div>
        </div>
    );
};

export default ConciergeOverlay;

