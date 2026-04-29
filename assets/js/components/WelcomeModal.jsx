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
                zIndex: 99999, // Very high z-index to ensure it's above all items (even L/XL with +1000 boost)
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
                    Welcome to Wireframe OS
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
                    How would you like to get started?
                </p>
                <button
                    onClick={() => {
                        localStorage.setItem('n88_workflow_preferred_entry_mode', 'full_process');
                        handleClose();
                    }}
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
                    Continue with Full Workflow →
                </button>
                <button
                    onClick={() => {
                        localStorage.setItem('n88_workflow_preferred_entry_mode', 'production_only');
                        handleClose();
                    }}
                    style={{
                        width: '100%',
                        padding: '10px 24px',
                        backgroundColor: '#f5f5f5',
                        color: '#333',
                        border: '1px solid #d0d0d0',
                        borderRadius: '4px',
                        fontSize: '14px',
                        fontWeight: '600',
                        cursor: 'pointer',
                        marginTop: '8px',
                    }}
                >
                    Production Tracking Only
                </button>
            </div>
        </div>
    );
};

export default WelcomeModal;

