/**
 * Welcome Modal Component
 * 
 * Milestone 1.3.7: Welcome modal shown once per user
 * 
 * Shows welcome video modal on first board entry only.
 * Uses localStorage to track if already shown.
 */

import React, { useState, useEffect } from 'react';

/**
 * WelcomeModal - Modal shown once per user on first board entry
 * 
 * @param {Object} props
 * @param {number} props.userId - Current user ID
 * @param {Function} props.onClose - Callback when modal is closed
 */
const WelcomeModal = ({ userId, onClose }) => {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        // Check if modal should be shown
        if (!userId) {
            // No user ID - don't show modal
            return;
        }

        const storageKey = `n88_welcome_modal_shown_v1_user_${userId}`;
        
        // Check localStorage
        try {
            const alreadyShown = localStorage.getItem(storageKey);
            if (alreadyShown === 'true') {
                // Already shown - don't display
                return;
            }
        } catch (e) {
            console.warn('Failed to check welcome modal flag:', e);
            return;
        }

        // Show modal (first time only)
        setIsVisible(true);

        // Set flag immediately when modal is shown (Option A)
        try {
            localStorage.setItem(storageKey, 'true');
        } catch (e) {
            console.warn('Failed to set welcome modal flag:', e);
        }
    }, [userId]);

    const handleClose = () => {
        setIsVisible(false);
        if (onClose) {
            onClose();
        }
    };

    // Don't render if not visible
    if (!isVisible) {
        return null;
    }

    return (
        <div
            style={{
                position: 'fixed',
                top: 0,
                left: 0,
                right: 0,
                bottom: 0,
                backgroundColor: 'rgba(0, 0, 0, 0.6)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 20000, // Above everything including concierge overlay
                pointerEvents: 'auto', // Modal itself is clickable
            }}
            onClick={(e) => {
                // Close on backdrop click
                if (e.target === e.currentTarget) {
                    handleClose();
                }
            }}
        >
            <div
                style={{
                    backgroundColor: '#fff',
                    borderRadius: '8px',
                    padding: '24px',
                    maxWidth: '600px',
                    width: '90%',
                    maxHeight: '90vh',
                    overflow: 'auto',
                    boxShadow: '0 4px 20px rgba(0, 0, 0, 0.3)',
                    pointerEvents: 'auto', // Modal content is clickable
                }}
                onClick={(e) => {
                    // Prevent backdrop close when clicking modal content
                    e.stopPropagation();
                }}
            >
                {/* Close button */}
                <button
                    onClick={handleClose}
                    style={{
                        position: 'absolute',
                        top: '12px',
                        right: '12px',
                        background: 'transparent',
                        border: 'none',
                        fontSize: '24px',
                        cursor: 'pointer',
                        color: '#666',
                        padding: '4px 8px',
                        lineHeight: 1,
                    }}
                    aria-label="Close"
                >
                    ×
                </button>

                {/* Title */}
                <h2
                    style={{
                        marginTop: 0,
                        marginBottom: '16px',
                        fontSize: '24px',
                        fontWeight: 'bold',
                        color: '#333',
                    }}
                >
                    Welcome to Your Board
                </h2>

                {/* Text */}
                <p
                    style={{
                        marginBottom: '20px',
                        fontSize: '16px',
                        color: '#666',
                        lineHeight: 1.6,
                    }}
                >
                    Get started by exploring your board. Drag items to organize them, change sizes, and customize your layout.
                </p>

                {/* Video embed placeholder */}
                <div
                    style={{
                        width: '100%',
                        paddingBottom: '56.25%', // 16:9 aspect ratio
                        position: 'relative',
                        backgroundColor: '#f0f0f0',
                        borderRadius: '4px',
                        marginBottom: '20px',
                        overflow: 'hidden',
                    }}
                >
                    <div
                        style={{
                            position: 'absolute',
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: '#999',
                            fontSize: '14px',
                        }}
                    >
                        Video placeholder (embed video here)
                    </div>
                </div>

                {/* Close button at bottom */}
                <button
                    onClick={handleClose}
                    style={{
                        width: '100%',
                        padding: '12px 24px',
                        backgroundColor: '#0073aa',
                        color: '#fff',
                        border: 'none',
                        borderRadius: '4px',
                        fontSize: '16px',
                        fontWeight: '600',
                        cursor: 'pointer',
                        transition: 'background-color 0.2s',
                    }}
                    onMouseEnter={(e) => {
                        e.target.style.backgroundColor = '#005a87';
                    }}
                    onMouseLeave={(e) => {
                        e.target.style.backgroundColor = '#0073aa';
                    }}
                >
                    Get Started
                </button>
            </div>
        </div>
    );
};

export default WelcomeModal;

