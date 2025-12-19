/**
 * Board Layout Store (Vanilla JavaScript)
 * 
 * Milestone 1.3.3: Frontend State Spine
 * 
 * Pure state management for board layout. No side effects, no API calls,
 * no rendering logic. Fully serializable and deterministic.
 * 
 * Follows N88 Studio OS development standards:
 * - Namespace: window.N88StudioOS.BoardStore
 * - One source of truth per feature/module
 * - Pure state management only (no side effects)
 * 
 * @typedef {Object} BoardItemLayout
 * @property {string} id - Item identifier
 * @property {number} x - X position in pixels
 * @property {number} y - Y position in pixels
 * @property {number} z - Z-index (stacking order)
 * @property {number} width - Item width in pixels
 * @property {number} height - Item height in pixels
 * @property {'photo_only'|'full'} displayMode - Display mode
 */

(function() {
    'use strict';

    // Initialize N88StudioOS namespace if it doesn't exist
    if (typeof window.N88StudioOS === 'undefined') {
        window.N88StudioOS = {};
    }

    /**
     * Board layout store
     * 
     * Pure state management with no side effects.
     * All state is fully serializable JSON.
     * All actions are deterministic and idempotent.
     * 
     * State structure:
     * {
     *   items: BoardItemLayout[]
     * }
     */
    const self = {
        /**
         * Internal state: items array
         * @type {BoardItemLayout[]}
         */
        _state: {
            items: []
        },

        /**
         * Get current state (returns a copy to prevent external mutation)
         * @returns {Object} Current state snapshot
         */
        getState: function() {
            return {
                items: this._state.items.map(item => ({ ...item }))
            };
        },

        /**
         * Get current items array (returns a copy to prevent external mutation)
         * @returns {BoardItemLayout[]} Current items array
         */
        getItems: function() {
            return this._state.items.map(item => ({ ...item }));
        },

        /**
         * Replace the entire layout state.
         * Used for hydration from backend (Commit 1.3.2).
         * 
         * Must NOT mutate or derive values.
         * Array order must be preserved exactly as provided.
         * 
         * @param {BoardItemLayout[]} items - Complete array of board items
         */
        setItems: function(items) {
            // Validate items array
            if (!Array.isArray(items)) {
                console.warn('BoardStore.setItems: items must be an array');
                return;
            }

            // Replace state exactly as provided (no mutation, no derivation)
            // Create deep copy to prevent external mutation
            this._state.items = items.map(item => ({ ...item }));
        },

        /**
         * Bring an item to the front by setting its z-index to max + 1.
         * 
         * Finds current max z-index across all items.
         * Sets target item to maxZ + 1.
         * Must NOT renumber or normalize other items.
         * 
         * Preferred behavior: if item is already at max z, no-op.
         * 
         * @param {string} id - Item ID to bring to front
         */
        bringToFront: function(id) {
            // Find item by ID
            const itemIndex = this._state.items.findIndex((item) => item.id === id);
            if (itemIndex === -1) {
                console.warn(`BoardStore.bringToFront: item with id "${id}" not found`);
                return; // No-op: item not found
            }

            // Find current max z-index
            const maxZ = this._state.items.reduce((max, item) => {
                return Math.max(max, (typeof item.z === 'number' ? item.z : 0));
            }, 0);

            // Check if item is already at max z (no-op)
            if (this._state.items[itemIndex].z === maxZ) {
                return; // No-op: already at max
            }

            // Create new items array with updated z-index
            const newItems = this._state.items.map((item, index) => {
                if (index === itemIndex) {
                    return {
                        ...item,
                        z: maxZ + 1,
                    };
                }
                return { ...item };
            });

            this._state.items = newItems;
        },

        /**
         * Optimistically update local state for a single item.
         * 
         * Accepts partial changes: { x, y, z, width, height, displayMode }
         * Does NOT call APIs.
         * Does NOT debounce.
         * Does NOT derive or normalize values.
         * 
         * @param {string} id - Item ID to update
         * @param {Partial<BoardItemLayout>} partialChanges - Partial item properties to update
         */
        updateLayout: function(id, partialChanges) {
            // Find item by ID
            const itemIndex = this._state.items.findIndex((item) => item.id === id);
            if (itemIndex === -1) {
                console.warn(`BoardStore.updateLayout: item with id "${id}" not found`);
                return; // No-op: item not found
            }

            // Validate partialChanges: only allow layout properties (not id)
            const allowedKeys = [
                'x',
                'y',
                'z',
                'width',
                'height',
                'displayMode',
            ];

            const filteredChanges = {};
            for (const key in partialChanges) {
                if (allowedKeys.includes(key)) {
                    filteredChanges[key] = partialChanges[key];
                }
            }

            // If no valid changes, no-op
            if (Object.keys(filteredChanges).length === 0) {
                return;
            }

            // Create new items array with updated item
            const newItems = this._state.items.map((item, index) => {
                if (index === itemIndex) {
                    return {
                        ...item,
                        ...filteredChanges,
                    };
                }
                return { ...item };
            });

            this._state.items = newItems;
        },

        /**
         * Reset store to initial empty state
         */
        reset: function() {
            this._state.items = [];
        },

        /**
         * Get serializable state (for persistence, undo/redo, etc.)
         * Returns a deep copy that can be safely JSON.stringify'd
         * 
         * @returns {Object} Serializable state object
         */
        toJSON: function() {
            return {
                items: this._state.items.map(item => ({ ...item }))
            };
        },

        /**
         * Check if store is empty
         * @returns {boolean} True if items array is empty
         */
        isEmpty: function() {
            return this._state.items.length === 0;
        },

        /**
         * Get item by ID
         * @param {string} id - Item ID
         * @returns {BoardItemLayout|undefined} Item or undefined if not found
         */
        getItem: function(id) {
            const item = this._state.items.find(item => item.id === id);
            return item ? { ...item } : undefined;
        }
    };

    // Export to namespace
    window.N88StudioOS.BoardStore = self;
})();
