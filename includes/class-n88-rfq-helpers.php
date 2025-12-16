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

    /**
     * Validate file MIME type by checking actual file headers (not just client-provided MIME type).
     * This provides additional security against MIME type spoofing.
     * 
     * @param string $file_path Path to the uploaded file.
     * @param string $client_mime_type MIME type provided by client.
     * @param array  $allowed_types Array of allowed MIME types.
     * @return bool True if file is valid, false otherwise.
     */
    public static function validate_file_mime_type( $file_path, $client_mime_type, $allowed_types ) {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return false;
        }

        // Check client-provided MIME type first
        if ( ! in_array( $client_mime_type, $allowed_types, true ) ) {
            // Special handling for DWG files (extension check)
            $file_ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
            if ( 'dwg' !== $file_ext ) {
                return false;
            }
        }

        // Get actual MIME type from file headers using finfo (more secure)
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $actual_mime_type = finfo_file( $finfo, $file_path );
                finfo_close( $finfo );
                
                // Verify actual MIME type matches allowed types
                if ( ! in_array( $actual_mime_type, $allowed_types, true ) ) {
                    // Special handling for DWG files
                    $file_ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
                    if ( 'dwg' !== $file_ext ) {
                        return false;
                    }
                }
            }
        } elseif ( function_exists( 'mime_content_type' ) ) {
            // Fallback to mime_content_type if finfo not available
            $actual_mime_type = mime_content_type( $file_path );
            if ( $actual_mime_type && ! in_array( $actual_mime_type, $allowed_types, true ) ) {
                $file_ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
                if ( 'dwg' !== $file_ext ) {
                    return false;
                }
            }
        }

        // Additional security: Check file header signatures for common file types
        $file_handle = fopen( $file_path, 'rb' );
        if ( ! $file_handle ) {
            return false;
        }

        $header = fread( $file_handle, 12 );
        fclose( $file_handle );

        // Check for PDF signature (%PDF)
        if ( 'application/pdf' === $client_mime_type ) {
            if ( 0 !== strpos( $header, '%PDF' ) ) {
                return false;
            }
        }

        // Check for JPEG signature (FF D8 FF)
        if ( 'image/jpeg' === $client_mime_type ) {
            if ( 0 !== strpos( bin2hex( $header ), 'ffd8ff' ) ) {
                return false;
            }
        }

        // Check for PNG signature (89 50 4E 47)
        if ( 'image/png' === $client_mime_type ) {
            if ( 0 !== strpos( bin2hex( $header ), '89504e47' ) ) {
                return false;
            }
        }

        // Check for GIF signature (GIF87a or GIF89a)
        if ( 'image/gif' === $client_mime_type ) {
            if ( 0 !== strpos( $header, 'GIF87a' ) && 0 !== strpos( $header, 'GIF89a' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get allowed file types for uploads.
     * 
     * @return array Array of allowed MIME types.
     */
    public static function get_allowed_file_types() {
        return array( 
            'application/pdf', 
            'image/jpeg', 
            'image/png', 
            'image/gif',
            'application/acad',
            'application/x-acad',
            'image/vnd.dwg',
            'application/dwg',
            'application/x-dwg',
            'image/x-dwg'
        );
    }
}

