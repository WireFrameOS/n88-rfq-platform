<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Intelligence Engine - Unit Normalization, CBM Calculation, Timeline Derivation
 * 
 * Phase 1.2: Core Intelligence
 * 
 * This class provides intelligence functions for items:
 * - Unit normalization (mm, cm, m, in → cm)
 * - CBM calculation
 * - Timeline type derivation from sourcing type
 */
class N88_Intelligence {

    /**
     * Supported dimension units (explicit list)
     * 
     * @var array
     */
    private static $supported_units = array(
        'mm',
        'cm',
        'm',
        'in',
    );

    /**
     * Allowed sourcing types
     * 
     * @var array
     */
    private static $allowed_sourcing_types = array(
        'furniture',
        'global_sourcing',
    );

    /**
     * Convert dimension value from any supported unit to centimeters (canonical unit).
     * 
     * @param float|string $value Dimension value
     * @param string $unit Source unit (mm, cm, m, in)
     * @return float|null Normalized value in cm, or null if invalid
     */
    public static function normalize_to_cm( $value, $unit ) {
        // Validate unit
        if ( ! in_array( $unit, self::$supported_units, true ) ) {
            return null;
        }

        // Convert to float
        $float_value = floatval( $value );
        if ( $float_value < 0 ) {
            return null; // Reject negative values
        }

        // Convert to cm based on unit
        switch ( $unit ) {
            case 'mm':
                return $float_value / 10.0;
            case 'cm':
                return $float_value;
            case 'm':
                return $float_value * 100.0;
            case 'in':
                return $float_value * 2.54;
            default:
                return null;
        }
    }

    /**
     * Calculate CBM (Cubic Meters) from normalized dimensions in cm.
     * 
     * Formula: (width_cm × depth_cm × height_cm) / 1,000,000
     * Rounding: 6 decimal places (match schema DECIMAL(10,6))
     * 
     * @param float|null $width_cm Width in cm
     * @param float|null $depth_cm Depth in cm
     * @param float|null $height_cm Height in cm
     * @return float|null CBM value (6 decimals), or null if any dimension is missing/invalid
     */
    public static function calculate_cbm( $width_cm, $depth_cm, $height_cm ) {
        // Check if any dimension is missing or invalid
        if ( $width_cm === null || $depth_cm === null || $height_cm === null ) {
            return null;
        }

        // Convert to float
        $w = floatval( $width_cm );
        $d = floatval( $depth_cm );
        $h = floatval( $height_cm );

        // Reject zero or negative values
        if ( $w <= 0 || $d <= 0 || $h <= 0 ) {
            return null;
        }

        // Reject extreme values (>1000m = 100000cm)
        if ( $w > 100000 || $d > 100000 || $h > 100000 ) {
            return null;
        }

        // Calculate CBM: (w_cm × d_cm × h_cm) / 1,000,000
        $cbm = ( $w * $d * $h ) / 1000000.0;

        // Round to 6 decimal places (match schema DECIMAL(10,6))
        return round( $cbm, 6 );
    }

    /**
     * Derive timeline_type from sourcing_type.
     * 
     * Rules:
     * - furniture → 6_step
     * - global_sourcing → 4_step
     * 
     * @param string|null $sourcing_type Sourcing type
     * @return string|null Timeline type, or null if sourcing_type is invalid/missing
     */
    public static function derive_timeline_type( $sourcing_type ) {
        if ( empty( $sourcing_type ) ) {
            return null;
        }

        if ( ! in_array( $sourcing_type, self::$allowed_sourcing_types, true ) ) {
            return null;
        }

        switch ( $sourcing_type ) {
            case 'furniture':
                return '6_step';
            case 'global_sourcing':
                return '4_step';
            default:
                return null;
        }
    }

    /**
     * Validate sourcing type.
     * 
     * @param string|null $sourcing_type Sourcing type to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_sourcing_type( $sourcing_type ) {
        if ( empty( $sourcing_type ) ) {
            return true; // NULL is allowed
        }
        return in_array( $sourcing_type, self::$allowed_sourcing_types, true );
    }

    /**
     * Validate dimension unit.
     * 
     * @param string|null $unit Unit to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_unit( $unit ) {
        if ( empty( $unit ) ) {
            return true; // NULL is allowed
        }
        return in_array( $unit, self::$supported_units, true );
    }

    /**
     * Get supported units list.
     * 
     * @return array Array of supported unit strings
     */
    public static function get_supported_units() {
        return self::$supported_units;
    }

    /**
     * Get allowed sourcing types list.
     * 
     * @return array Array of allowed sourcing type strings
     */
    public static function get_allowed_sourcing_types() {
        return self::$allowed_sourcing_types;
    }
}

