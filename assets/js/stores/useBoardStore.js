/**
 * Board Layout Store (Zustand)
 * 
 * Milestone 1.3.3: Frontend State Spine
 * 
 * Pure state management for board layout. No side effects, no API calls,
 * no rendering logic. Fully serializable and deterministic.
 * 
 * Architecture (Milestone 1.3):
 * - Backend API: WordPress (PHP) wp_ajax endpoints (layout-only, verify_ajax_nonce() + ownership enforced)
 * - Data persistence: MySQL LONGTEXT (single latest snapshot JSON) + immutable events for audit / rewind later
 * 
 * @requires zustand - Must be loaded as UMD/global: window.zustand.create
 * 
 * @typedef {Object} BoardItemLayout
 * @property {string} id - Item identifier
 * @property {number} x - X position in pixels
 * @property {number} y - Y position in pixels
 * @property {number} z - Z-index (stacking order)
 * @property {number} width - Item width in pixels
 * @property {number} height - Item height in pixels
 * @property {'S'|'D'|'L'|'XL'|string} sizeKey - Size preset key (S/D/L/XL) for forward compatibility
 * @property {'photo_only'|'full'} displayMode - Display mode
 */

// Hard fail if Zustand is not available
// Zustand 3.x UMD exposes as window.zustand.default or window.zustand
// Dependency order is handled via WordPress enqueue (zustand loads before useBoardStore)
if (typeof window === 'undefined' || !window.zustand) {
    throw new Error('useBoardStore: Zustand is required. Please load zustand UMD bundle before this script (window.zustand must exist).');
}

var zustandModule = window.zustand;

// Handle both UMD formats: window.zustand.default (ESM) or window.zustand.create (CJS)
// Zustand 3.7.2 UMD exports as: {__esModule: true, default: createFunction}
var create;
if (typeof zustandModule.create === 'function') {
    // CJS format: window.zustand.create
    create = zustandModule.create;
} else if (zustandModule.default) {
    if (typeof zustandModule.default.create === 'function') {
        // ESM format: window.zustand.default.create
        create = zustandModule.default.create;
    } else if (typeof zustandModule.default === 'function') {
        // ESM format: window.zustand.default IS the create function
        create = zustandModule.default;
    } else {
        console.error('useBoardStore: Zustand default is not a function:', zustandModule.default);
        throw new Error('useBoardStore: Zustand default export is not a function');
    }
} else if (typeof zustandModule === 'function') {
    // Direct function export
    create = zustandModule;
} else {
    console.error('useBoardStore: Zustand module structure:', zustandModule);
    console.error('useBoardStore: Available properties:', Object.keys(zustandModule));
    throw new Error('useBoardStore: Zustand create function not found. Expected window.zustand.create or window.zustand.default');
}

// Initialize namespace if it doesn't exist
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
 * Usage in React:
 * ```jsx
 * const { items, setItems, bringToFront, updateLayout } = window.N88StudioOS.useBoardStore();
 * ```
 */
window.N88StudioOS.useBoardStore = create((set, get) => ({
    // Initial state: empty items array
    items: [],

    /**
     * Replace the entire layout state.
     * Used for hydration from backend (Commit 1.3.2).
     * 
     * Must NOT mutate or derive values.
     * Array order must be preserved exactly as provided.
     * 
     * @param {BoardItemLayout[]} items - Complete array of board items
     */
    setItems: (items) => {
        // Validate items array
        if (!Array.isArray(items)) {
            console.warn('useBoardStore.setItems: items must be an array');
            return;
        }

        // Replace state exactly as provided (no mutation, no derivation)
        // Deep copy items to prevent external mutation
        set({ items: items.map(i => ({ ...i })) });
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
    bringToFront: (id) => {
        set((state) => {
            // Find item by ID
            const itemIndex = state.items.findIndex((item) => item.id === id);
            if (itemIndex === -1) {
                console.warn(`useBoardStore.bringToFront: item with id "${id}" not found`);
                return state; // No-op: item not found
            }

            // Find current max z-index
            const maxZ = state.items.reduce((max, item) => {
                return Math.max(max, (typeof item.z === 'number' ? item.z : 0));
            }, 0);

            // Check if item is already at max z (no-op)
            if (state.items[itemIndex].z === maxZ) {
                return state; // No-op: already at max
            }

            // Create new items array with updated z-index
            const newItems = [...state.items];
            newItems[itemIndex] = {
                ...newItems[itemIndex],
                z: maxZ + 1,
            };

            return { items: newItems };
        });
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
    updateLayout: (id, partialChanges) => {
        set((state) => {
            // Find item by ID
            const itemIndex = state.items.findIndex((item) => item.id === id);
            if (itemIndex === -1) {
                console.warn('useBoardStore.updateLayout: item with id ' + id + ' not found');
                return state; // No-op: item not found
            }

            // Validate partialChanges: layout properties + status used to refresh card after approve/request-changes (not id)
            const allowedKeys = [
                'x',
                'y',
                'z',
                'width',
                'height',
                'sizeKey',
                'displayMode',
                'prototype_status',
                'action_required',
            ];

            const filteredChanges = {};
            for (const key in partialChanges) {
                if (allowedKeys.includes(key)) {
                    filteredChanges[key] = partialChanges[key];
                }
            }

            // If no valid changes, no-op
            if (Object.keys(filteredChanges).length === 0) {
                return state;
            }

            // Create new items array with updated item
            const newItems = [...state.items];
            newItems[itemIndex] = {
                ...newItems[itemIndex],
                ...filteredChanges,
            };

            return { items: newItems };
        });
    },
}));
