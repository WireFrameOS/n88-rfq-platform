<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Timeline Management Class
 * 
 * Handles timeline assignment, structure generation, and timeline operations.
 * Phase 3 - Milestone 3.1
 */
class N88_RFQ_Timeline {

    /**
     * Timeline type constants
     */
    const TIMELINE_TYPE_6STEP_FURNITURE = '6step_furniture';
    const TIMELINE_TYPE_4STEP_SOURCING = '4step_sourcing';
    const TIMELINE_TYPE_NONE = 'none';

    /**
     * Indoor Furniture Keywords (→ 6-Step Furniture Timeline)
     */
    private static $indoor_furniture_keywords = array(
        'Indoor Furniture',
        'Indoor Sofa',
        'Indoor Sectional',
        'Indoor Lounge Chair',
        'Indoor Dining Chair',
        'Indoor Dining Table',
        'Casegoods',
        'Beds',
        'Consoles',
        'Desks',
        'Cabinets',
        'Nightstands',
        'Upholstered Furniture',
        'Millwork / Cabinetry',
        'Millwork',
        'Cabinetry',
        'Fully Upholstered Pieces',
    );

    /**
     * Outdoor Furniture Keywords (→ 6-Step Furniture Timeline)
     */
    private static $outdoor_furniture_keywords = array(
        'Outdoor Furniture',
        'Outdoor Sofa',
        'Outdoor Sectional',
        'Outdoor Lounge Chair',
        'Outdoor Dining',
        'Outdoor Dining Chair',
        'Outdoor Dining Table',
        'Daybed',
        'Chaise Lounge',
        'Pool Furniture',
        'Sun Lounger',
        'Outdoor Seating Sets',
    );

    /**
     * Global Sourcing Keywords (→ 4-Step Sourcing Timeline)
     */
    private static $sourcing_keywords = array(
        'Lighting',
        'Flooring',
        'Marble / Stone',
        'Marble',
        'Stone',
        'Granite',
        'Carpets',
        'Drapery',
        'Window Treatments',
        'Accessories',
        'Hardware',
        'Metalwork',
    );

    /**
     * Determine timeline type based on product category
     * 
     * @param string $product_category Product category string
     * @param string $sourcing_category Fallback sourcing category (project-level)
     * @return string Timeline type constant
     */
    public static function assign_timeline_type( $product_category = '', $sourcing_category = '' ) {
        $category = ! empty( $product_category ) ? $product_category : $sourcing_category;
        
        if ( empty( $category ) ) {
            return self::TIMELINE_TYPE_NONE;
        }

        // Normalize category for comparison
        $category_normalized = trim( $category );

        // Check for Material Sample Kit
        if ( stripos( $category_normalized, 'Sample Kit' ) !== false || 
             stripos( $category_normalized, 'Material Sample' ) !== false ) {
            return self::TIMELINE_TYPE_NONE;
        }

        // Check Indoor Furniture keywords
        foreach ( self::$indoor_furniture_keywords as $keyword ) {
            if ( stripos( $category_normalized, $keyword ) !== false ) {
                return self::TIMELINE_TYPE_6STEP_FURNITURE;
            }
        }

        // Check Outdoor Furniture keywords
        foreach ( self::$outdoor_furniture_keywords as $keyword ) {
            if ( stripos( $category_normalized, $keyword ) !== false ) {
                return self::TIMELINE_TYPE_6STEP_FURNITURE;
            }
        }

        // Check Sourcing keywords
        foreach ( self::$sourcing_keywords as $keyword ) {
            if ( stripos( $category_normalized, $keyword ) !== false ) {
                return self::TIMELINE_TYPE_4STEP_SOURCING;
            }
        }

        // Default: if it's not furniture fabrication, assume sourcing
        // This handles "Any product sourced from external factories"
        return self::TIMELINE_TYPE_4STEP_SOURCING;
    }

    /**
     * Generate timeline structure for an item
     * 
     * @param string $timeline_type Timeline type constant
     * @param string $assigned_by_category Category that triggered assignment
     * @return array Timeline structure array
     */
    public static function generate_timeline_structure( $timeline_type, $assigned_by_category = '' ) {
        $now = current_time( 'mysql' );
        $iso_date = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $now ) );

        switch ( $timeline_type ) {
            case self::TIMELINE_TYPE_6STEP_FURNITURE:
                return self::generate_6step_furniture_timeline( $assigned_by_category, $iso_date );

            case self::TIMELINE_TYPE_4STEP_SOURCING:
                return self::generate_4step_sourcing_timeline( $assigned_by_category, $iso_date );

            default:
                return array(
                    'timeline_type' => self::TIMELINE_TYPE_NONE,
                    'assigned_at' => $iso_date,
                    'assigned_by_category' => $assigned_by_category,
                    'steps' => array(),
                    'total_estimated_days' => 0,
                    'total_actual_days' => null,
                    'started_at' => null,
                    'completed_at' => null,
                );
        }
    }

    /**
     * Generate 6-Step Furniture Production Timeline structure
     * 
     * @param string $assigned_by_category Category that triggered assignment
     * @param string $iso_date ISO 8601 datetime string
     * @return array Timeline structure
     */
    private static function generate_6step_furniture_timeline( $assigned_by_category, $iso_date ) {
        $steps = array(
            array(
                'step_key' => 'prototype',
                'label' => 'Prototype',
                'order' => 1,
                'description' => 'Initial prototype development and approval',
                'icon' => 'prototype-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 7,
                'actual_days' => null,
                'is_locked' => false,
                'locked_reason' => null,
            ),
            array(
                'step_key' => 'frame_structure',
                'label' => 'Frame / Structure',
                'order' => 2,
                'description' => 'Frame construction and structural assembly',
                'icon' => 'frame-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 10,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 1 (Prototype) before starting this step',
            ),
            array(
                'step_key' => 'surface_treatment',
                'label' => 'Surface Treatment',
                'order' => 3,
                'description' => 'Sanding, staining, finishing',
                'icon' => 'surface-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 5,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 2 (Frame / Structure) before starting this step',
            ),
            array(
                'step_key' => 'upholstery_fabrication',
                'label' => 'Upholstery / Fabrication',
                'order' => 4,
                'description' => 'Fabric cutting, sewing, and upholstery work',
                'icon' => 'upholstery-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 8,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 3 (Surface Treatment) before starting this step',
            ),
            array(
                'step_key' => 'final_qc',
                'label' => 'Final QC',
                'order' => 5,
                'description' => 'Quality control inspection and final approval',
                'icon' => 'qc-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 2,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 4 (Upholstery / Fabrication) before starting this step',
            ),
            array(
                'step_key' => 'packing_delivery',
                'label' => 'Packing & Delivery',
                'order' => 6,
                'description' => 'Final packaging and shipping preparation',
                'icon' => 'packing-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 3,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 5 (Final QC) before starting this step',
            ),
        );

        $total_estimated_days = array_sum( array_column( $steps, 'estimated_days' ) );

        return array(
            'timeline_type' => self::TIMELINE_TYPE_6STEP_FURNITURE,
            'assigned_at' => $iso_date,
            'assigned_by_category' => $assigned_by_category,
            'steps' => $steps,
            'total_estimated_days' => $total_estimated_days,
            'total_actual_days' => null,
            'started_at' => null,
            'completed_at' => null,
        );
    }

    /**
     * Generate 4-Step Global Sourcing Timeline structure
     * 
     * @param string $assigned_by_category Category that triggered assignment
     * @param string $iso_date ISO 8601 datetime string
     * @return array Timeline structure
     */
    private static function generate_4step_sourcing_timeline( $assigned_by_category, $iso_date ) {
        $steps = array(
            array(
                'step_key' => 'sourcing',
                'label' => 'Sourcing',
                'order' => 1,
                'description' => 'Vendor identification and material sourcing',
                'icon' => 'sourcing-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 14,
                'actual_days' => null,
                'is_locked' => false,
                'locked_reason' => null,
            ),
            array(
                'step_key' => 'production_procurement',
                'label' => 'Production / Procurement',
                'order' => 2,
                'description' => 'Manufacturing or procurement process',
                'icon' => 'production-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 21,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 1 (Sourcing) before starting this step',
            ),
            array(
                'step_key' => 'quality_check',
                'label' => 'Quality Check',
                'order' => 3,
                'description' => 'Quality inspection and verification',
                'icon' => 'qc-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 3,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 2 (Production / Procurement) before starting this step',
            ),
            array(
                'step_key' => 'packing_delivery',
                'label' => 'Packing & Delivery',
                'order' => 4,
                'description' => 'Final packaging and shipping preparation',
                'icon' => 'packing-icon',
                'current_status' => 'pending',
                'started_at' => null,
                'completed_at' => null,
                'completed_by' => null,
                'admin_notes' => '',
                'estimated_days' => 5,
                'actual_days' => null,
                'is_locked' => true,
                'locked_reason' => 'Complete Step 3 (Quality Check) before starting this step',
            ),
        );

        $total_estimated_days = array_sum( array_column( $steps, 'estimated_days' ) );

        return array(
            'timeline_type' => self::TIMELINE_TYPE_4STEP_SOURCING,
            'assigned_at' => $iso_date,
            'assigned_by_category' => $assigned_by_category,
            'steps' => $steps,
            'total_estimated_days' => $total_estimated_days,
            'total_actual_days' => null,
            'started_at' => null,
            'completed_at' => null,
        );
    }

    /**
     * Ensure item has timeline structure (auto-assign if missing)
     * 
     * @param array $item Item data array
     * @param string $sourcing_category Project-level sourcing category (fallback)
     * @return array Item with timeline_structure added/updated
     */
    public static function ensure_item_timeline( $item, $sourcing_category = '' ) {
        // Check if timeline_structure already exists
        if ( ! empty( $item['timeline_structure'] ) && is_array( $item['timeline_structure'] ) ) {
            return $item;
        }

        // Get product category
        $product_category = isset( $item['product_category'] ) ? $item['product_category'] : '';

        // Assign timeline type
        $timeline_type = self::assign_timeline_type( $product_category, $sourcing_category );

        // Generate timeline structure
        $assigned_by = ! empty( $product_category ) ? $product_category : $sourcing_category;
        $timeline_structure = self::generate_timeline_structure( $timeline_type, $assigned_by );

        // Add to item
        $item['timeline_structure'] = $timeline_structure;

        return $item;
    }
}

