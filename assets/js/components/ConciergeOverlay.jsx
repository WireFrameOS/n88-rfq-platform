/**
 * Concierge Overlay Component (K) - Support (headphone icon + label)
 * 
 * Milestone 1.3.7: Read-only overlay showing sourcing agent info
 * 
 * Non-blocking overlay that displays Support label and headphone icon avatar.
 * Must not interfere with board interactions (pointer-events: none).
 */

import React from 'react';

/**
 * ConciergeOverlay - Persistent overlay showing Support with headphone icon
 * 
 * @param {Object} props
 * @param {Object} props.concierge - Sourcing agent data { name: string, avatarUrl: string }
 */
const ConciergeOverlay = ({ concierge }) => {
    const conciergeData = concierge || {
        name: 'Message System Operator',
        avatarUrl: '',
    };

    return (
        <div
            id="n88-concierge-overlay"
            style={{
                position: 'absolute',
                top: '20px',
                left: '20px',
                zIndex: 10000,
                pointerEvents: 'none',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '12px',
                    backgroundColor: '#2d2d2d',
                    padding: '12px 16px',
                    borderRadius: '6px',
                    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.3)',
                    border: '1px solid rgba(255, 255, 255, 0.1)',
                    fontFamily: 'ui-monospace, monospace',
                }}
            >
                <div
                    style={{
                        width: '36px',
                        height: '36px',
                        borderRadius: '50%',
                        backgroundColor: conciergeData.avatarUrl ? 'transparent' : '#e91e8c',
                        backgroundImage: conciergeData.avatarUrl ? `url(${conciergeData.avatarUrl})` : 'none',
                        backgroundSize: 'cover',
                        backgroundPosition: 'center',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: '#fff',
                        fontSize: '16px',
                        flexShrink: 0,
                    }}
                >
                    {!conciergeData.avatarUrl && (
                        <span aria-hidden="true">🎧</span>
                    )}
                </div>
                <div
                    style={{
                        fontSize: '13px',
                        fontWeight: '600',
                        color: '#e0e0e0',
                    }}
                >
                    {conciergeData.name}
                </div>
            </div>
        </div>
    );
};

export default ConciergeOverlay;

