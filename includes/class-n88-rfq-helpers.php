<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper functions for N88 RFQ Platform
 */
class N88_RFQ_Helpers {

    /**
     * Nonce action constants
     */
    const NONCE_ACTION_AJAX = 'n88-rfq-nonce';
    const NONCE_ACTION_FORM = 'n88_rfq_submit';
    const NONCE_ACTION_QUOTE = 'n88_update_client_quote';

    /**
     * Nonce parameter name constants
     */
    const NONCE_PARAM_AJAX = 'nonce';
    const NONCE_PARAM_FORM = 'n88_rfq_nonce';
    const NONCE_PARAM_QUOTE = 'n88_quote_nonce';

    /**
     * Verify AJAX nonce for standard AJAX endpoints.
     * 
     * This is the standard method for all AJAX endpoints.
     * Uses 'n88-rfq-nonce' action with 'nonce' parameter.
     * 
     * @param bool $die_on_failure Whether to die on failure (default: true for check_ajax_referer behavior).
     * @return bool|int False on failure, 1 or 2 on success (1 = valid, 2 = valid and generated new nonce).
     */
    public static function verify_ajax_nonce( $die_on_failure = true ) {
        if ( $die_on_failure ) {
            return check_ajax_referer( self::NONCE_ACTION_AJAX, self::NONCE_PARAM_AJAX, false );
        } else {
            $nonce = isset( $_REQUEST[ self::NONCE_PARAM_AJAX ] ) ? sanitize_text_field( $_REQUEST[ self::NONCE_PARAM_AJAX ] ) : '';
            if ( empty( $nonce ) ) {
                return false;
            }
            return wp_verify_nonce( $nonce, self::NONCE_ACTION_AJAX );
        }
    }

    /**
     * Verify form submission nonce.
     * 
     * Used for standard form submissions (not AJAX).
     * Uses 'n88_rfq_submit' action with 'n88_rfq_nonce' parameter.
     * 
     * @param bool $die_on_failure Whether to die on failure (default: true).
     * @return bool True if valid, false otherwise.
     */
    public static function verify_form_nonce( $die_on_failure = true ) {
        $nonce = isset( $_POST[ self::NONCE_PARAM_FORM ] ) ? sanitize_text_field( $_POST[ self::NONCE_PARAM_FORM ] ) : '';
        if ( empty( $nonce ) ) {
            if ( $die_on_failure ) {
                wp_die( 'Security check failed. Please try again.' );
            }
            return false;
        }
        
        $valid = wp_verify_nonce( $nonce, self::NONCE_ACTION_FORM );
        
        if ( ! $valid && $die_on_failure ) {
            wp_die( 'Security check failed. Please try again.' );
        }
        
        return $valid;
    }

    /**
     * Verify quote update nonce.
     * 
     * Used for quote-specific AJAX endpoints.
     * Uses 'n88_update_client_quote' action with 'n88_quote_nonce' parameter.
     * 
     * @param bool $die_on_failure Whether to die on failure (default: true for check_ajax_referer behavior).
     * @return bool|int False on failure, 1 or 2 on success.
     */
    public static function verify_quote_nonce( $die_on_failure = true ) {
        if ( $die_on_failure ) {
            return check_ajax_referer( self::NONCE_ACTION_QUOTE, self::NONCE_PARAM_QUOTE, false );
        } else {
            $nonce = isset( $_REQUEST[ self::NONCE_PARAM_QUOTE ] ) ? sanitize_text_field( $_REQUEST[ self::NONCE_PARAM_QUOTE ] ) : '';
            if ( empty( $nonce ) ) {
                return false;
            }
            return wp_verify_nonce( $nonce, self::NONCE_ACTION_QUOTE );
        }
    }

    /**
     * Verify nonce with fallback support (for backward compatibility).
     * 
     * This method checks both 'n88-rfq-nonce' and 'n88_rfq_submit' nonces.
     * Used for endpoints that need to support both AJAX and form submissions.
     * 
     * @param string $nonce_param Parameter name to check (default: 'nonce').
     * @param bool $die_on_failure Whether to die on failure (default: false for AJAX).
     * @return bool True if valid, false otherwise.
     */
    public static function verify_nonce_with_fallback( $nonce_param = 'nonce', $die_on_failure = false ) {
        $nonce = isset( $_REQUEST[ $nonce_param ] ) ? sanitize_text_field( $_REQUEST[ $nonce_param ] ) : '';
        
        if ( empty( $nonce ) ) {
            if ( $die_on_failure ) {
                wp_send_json_error( array( 'message' => 'Security check failed' ) );
            }
            return false;
        }
        
        // Try both nonce actions for backward compatibility
        $valid = wp_verify_nonce( $nonce, self::NONCE_ACTION_AJAX ) || wp_verify_nonce( $nonce, self::NONCE_ACTION_FORM );
        
        if ( ! $valid && $die_on_failure ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }
        
        return $valid;
    }

    /**
     * Create AJAX nonce for JavaScript.
     * 
     * @return string Nonce token.
     */
    public static function create_ajax_nonce() {
        return wp_create_nonce( self::NONCE_ACTION_AJAX );
    }

    /**
     * Create form nonce for form submissions.
     * 
     * @return string Nonce token.
     */
    public static function create_form_nonce() {
        return wp_create_nonce( self::NONCE_ACTION_FORM );
    }

    /**
     * Create quote nonce for quote updates.
     * 
     * @return string Nonce token.
     */
    public static function create_quote_nonce() {
        return wp_create_nonce( self::NONCE_ACTION_QUOTE );
    }
}

