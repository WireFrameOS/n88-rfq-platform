<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ PDF Auto-Extraction Class
 *
 * Handles PDF upload, extraction of item data, and preview before confirmation.
 */
class N88_RFQ_PDF_Extractor {

    /**
     * Process uploaded PDF file and extract item data.
     *
     * @param int   $file_id Attachment ID of the uploaded PDF.
     * @param int   $project_id Project ID for context.
     * @return array|WP_Error Array with extraction results or error.
     */
    public static function extract_from_pdf( $file_id, $project_id ) {
        
        // Validate file
        $file_path = get_attached_file( $file_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'PDF file not found.' );
        }

        // Extract text from PDF
        $pdf_text = self::extract_text_from_pdf( $file_path );
        
        if ( is_wp_error( $pdf_text ) ) {
            // Phase 2B: Notify admin if extraction fails
            if ( class_exists( 'N88_RFQ_Notifications' ) ) {
                N88_RFQ_Notifications::notify_extraction_failed( $project_id, $pdf_text->get_error_message() );
            }
            return $pdf_text;
        }

        // Clean and normalize extracted text
        $pdf_text_before = $pdf_text;
        $pdf_text = self::clean_extracted_text( $pdf_text );
        
        // Debug: Log first 500 characters of extracted text (before and after cleaning)
        error_log( 'N88 RFQ: Extracted PDF text BEFORE cleaning (first 500 chars): ' . substr( $pdf_text_before, 0, 500 ) );
        error_log( 'N88 RFQ: Extracted PDF text AFTER cleaning (first 500 chars): ' . substr( $pdf_text, 0, 500 ) );

        // Parse extracted text to find items
        $items = self::parse_pdf_text_to_items( $pdf_text );
        
        // Debug: Log number of items found
        error_log( 'N88 RFQ: Found ' . count( $items ) . ' items in PDF' );

        $extraction_result = array(
            'file_id'         => $file_id,
            'project_id'      => $project_id,
            'items_detected'  => count( $items ),
            'items'           => $items,
            'status'          => count( $items ) > 0 ? 'success' : 'full_failure',
            'errors'          => array(),
            'message'         => count( $items ) > 0 
                ? sprintf( 'Successfully extracted %d item(s) from PDF.', count( $items ) )
                : 'No items could be extracted from PDF. Please check the format.',
        );

        /**
         * Allow third-party integrations to process the PDF.
         * Hook should return array with items extracted.
         *
         * @param array $result Extraction result array.
         * @param int   $file_id Attachment ID.
         * @param int   $project_id Project ID.
         */
        $processed = apply_filters( 'n88_rfq_pdf_extract_process', $extraction_result, $file_id, $project_id );

        if ( is_array( $processed ) ) {
            $extraction_result = $processed;
        }

        return $extraction_result;
    }

    /**
     * Extract text content from PDF file.
     * Uses advanced methods: pdftotext command or Smalot PDFParser library.
     *
     * @param string $file_path Path to PDF file.
     * @return string|WP_Error Extracted text or error.
     */
    private static function extract_text_from_pdf( $file_path ) {
        $text = '';
        
        // Method 1: Try using pdftotext command (if available on server) - BEST QUALITY
        // pdftotext is part of poppler-utils package
        if ( function_exists( 'exec' ) && ! ini_get( 'safe_mode' ) ) {
            // Try multiple common paths for pdftotext
            $pdftotext_paths = array(
                'pdftotext', // In PATH
                '/usr/bin/pdftotext',
                '/usr/local/bin/pdftotext',
                '/opt/local/bin/pdftotext',
            );
            
            $pdftotext_cmd = false;
            foreach ( $pdftotext_paths as $path ) {
                if ( self::command_exists( $path ) ) {
                    $pdftotext_cmd = $path;
                    break;
                }
            }
            
            if ( $pdftotext_cmd ) {
                // Try with -layout first (preserves formatting)
                $output_file = sys_get_temp_dir() . '/pdf_extract_' . uniqid() . '.txt';
                $command = sprintf( '%s -layout -enc UTF-8 -nopgbrk "%s" "%s" 2>&1', 
                    escapeshellarg( $pdftotext_cmd ), 
                    escapeshellarg( $file_path ), 
                    escapeshellarg( $output_file ) 
                );
                
                @exec( $command, $output, $return_var );
                
                if ( $return_var === 0 && file_exists( $output_file ) ) {
                    $text = @file_get_contents( $output_file );
                    @unlink( $output_file );
                    
                    if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
                        error_log( 'N88 RFQ: PDF extracted using pdftotext command (layout mode)' );
                        return $text;
                    }
                }
                
                // Try with -raw mode (sometimes better for structured text)
                $output_file = sys_get_temp_dir() . '/pdf_extract_' . uniqid() . '.txt';
                $command = sprintf( '%s -raw -enc UTF-8 -nopgbrk "%s" "%s" 2>&1', 
                    escapeshellarg( $pdftotext_cmd ), 
                    escapeshellarg( $file_path ), 
                    escapeshellarg( $output_file ) 
                );
                
                @exec( $command, $output, $return_var );
                
                if ( $return_var === 0 && file_exists( $output_file ) ) {
                    $text = @file_get_contents( $output_file );
                    @unlink( $output_file );
                    
                    if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
                        error_log( 'N88 RFQ: PDF extracted using pdftotext command (raw mode)' );
                        return $text;
                    }
                }
                
                // Try direct stdout output
                $command = sprintf( '%s -layout -enc UTF-8 -nopgbrk "%s" - 2>&1', 
                    escapeshellarg( $pdftotext_cmd ), 
                    escapeshellarg( $file_path ) 
                );
                
                $text = @shell_exec( $command );
                
                if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
                    error_log( 'N88 RFQ: PDF extracted using pdftotext command (stdout)' );
                    return $text;
                }
            }
        }

        // Method 2: Try using Smalot PDFParser library (if installed via Composer)
        // Install via: composer require smalot/pdfparser
        $autoload_paths = array(
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
        );
        
        foreach ( $autoload_paths as $autoload_path ) {
            if ( file_exists( $autoload_path ) ) {
                require_once $autoload_path;
                
                if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
                    try {
                        $parser = new \Smalot\PdfParser\Parser();
                        $pdf = $parser->parseFile( $file_path );
                        $text = $pdf->getText();
                        
                        if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
                            error_log( 'N88 RFQ: PDF extracted using Smalot PDFParser library' );
                            return $text;
                        }
                    } catch ( Exception $e ) {
                        error_log( 'N88 RFQ PDF Parser Error: ' . $e->getMessage() );
                    }
                }
                break;
            }
        }

        // Method 3: Try using Python pdfplumber (if available)
        if ( function_exists( 'exec' ) && self::command_exists( 'python3' ) ) {
            $python_script = sys_get_temp_dir() . '/pdf_extract_' . uniqid() . '.py';
            $output_file = sys_get_temp_dir() . '/pdf_extract_' . uniqid() . '.txt';
            
            $script_content = <<<PYTHON
import sys
try:
    import pdfplumber
    with pdfplumber.open('{$file_path}') as pdf:
        text = ''
        for page in pdf.pages:
            text += page.extract_text() or ''
        with open('{$output_file}', 'w', encoding='utf-8') as f:
            f.write(text)
    sys.exit(0)
except Exception as e:
    sys.exit(1)
PYTHON;
            
            @file_put_contents( $python_script, $script_content );
            $command = sprintf( 'python3 "%s" 2>&1', escapeshellarg( $python_script ) );
            @exec( $command, $output, $return_var );
            @unlink( $python_script );
            
            if ( $return_var === 0 && file_exists( $output_file ) ) {
                $text = @file_get_contents( $output_file );
                @unlink( $output_file );
                
                if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
                    error_log( 'N88 RFQ: PDF extracted using Python pdfplumber' );
                    return $text;
                }
            }
        }
        
        // Method 4: Fallback - Simple PDF text extraction (last resort)
        $text = self::simple_pdf_text_extraction( $file_path );
        
        if ( ! empty( $text ) && strlen( trim( $text ) ) > 50 ) {
            error_log( 'N88 RFQ: PDF extracted using simple extraction method (fallback)' );
            return $text;
        }
        
        // If all methods fail, return error with helpful message
        return new WP_Error( 
            'extraction_failed', 
            'Could not extract text from PDF. Please install pdftotext (poppler-utils) or Smalot PDFParser library for better extraction. Current PDF may be image-based and require OCR.'
        );
    }

    /**
     * Clean and normalize extracted PDF text.
     * Handles fragmented text where spaces/newlines appear between characters.
     *
     * @param string $text Raw extracted text.
     * @return string Cleaned text.
     */
    private static function clean_extracted_text( $text ) {
        // Remove null bytes and control characters (except newlines and tabs)
        $text = preg_replace( '/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $text );
        
        // Normalize line breaks
        $text = preg_replace( '/\r\n|\r/', "\n", $text );
        
        // Detect fragmented text: if we see patterns like "I\nt\ne\nm" or "I t e m"
        // Count how many single-character words we have
        $single_char_words = preg_match_all( '/\b\w\b/', $text );
        $total_words = preg_match_all( '/\b\w+\b/', $text );
        $fragmented = ( $total_words > 0 && ( $single_char_words / $total_words ) > 0.3 );
        
        if ( $fragmented ) {
            // Text is fragmented - reconstruct it more aggressively
            
            // Step 1: First, fix known field names before general reconstruction
            $field_fixes = array(
                '/I\s*t\s*e\s*m\s*\s*(\d+)\s*[:\.]/i' => 'Item $1:',
                '/P\s*r\s*o\s*d\s*u\s*c\s*t\s*\s*N\s*a\s*m\s*e\s*[:]/i' => 'Product Name:',
                '/L\s*e\s*n\s*g\s*t\s*h\s*\(?\s*i\s*n\s*\)?\s*[:]/i' => 'Length (in):',
                '/D\s*e\s*p\s*t\s*h\s*\(?\s*i\s*n\s*\)?\s*[:]/i' => 'Depth (in):',
                '/H\s*e\s*i\s*g\s*h\s*t\s*\(?\s*i\s*n\s*\)?\s*[:]/i' => 'Height (in):',
                '/Q\s*u\s*a\s*n\s*t\s*i\s*t\s*y\s*[:]/i' => 'Quantity:',
                '/P\s*r\s*i\s*m\s*a\s*r\s*y\s*\s*M\s*a\s*t\s*e\s*r\s*i\s*a\s*l\s*[:]/i' => 'Primary Material:',
                '/F\s*i\s*n\s*i\s*s\s*h\s*e\s*s\s*[:]/i' => 'Finishes:',
                '/C\s*o\s*n\s*s\s*t\s*r\s*u\s*c\s*t\s*i\s*o\s*n\s*\s*N\s*o\s*t\s*e\s*s\s*[:]/i' => 'Construction Notes:',
            );
            
            foreach ( $field_fixes as $pattern => $replacement ) {
                $text = preg_replace( $pattern, $replacement, $text );
            }
            
            // Step 2: Remove newlines between letters/numbers (they're fragmentation)
            // But preserve intentional newlines (after colons, before field names)
            $text = preg_replace( '/([a-zA-Z0-9])\s*\n+\s*([a-zA-Z0-9])/', '$1$2', $text );
            
            // Step 3: Remove spaces between consecutive letters (but keep spaces around numbers, punctuation, and field boundaries)
            // Only remove spaces between letters if they're not part of a field name or number
            $text = preg_replace( '/([a-zA-Z])\s+([a-zA-Z])/', '$1$2', $text );
            
            // Step 4: Fix spaces around numbers and punctuation
            // Add space before numbers if missing (but not if it's part of a decimal)
            $text = preg_replace( '/([a-zA-Z])(\d)/', '$1 $2', $text );
            // Add space after numbers if missing (before letters, but preserve decimals)
            $text = preg_replace( '/(\d)([a-zA-Z])/', '$1 $2', $text );
            // Fix spaces around colons
            $text = preg_replace( '/\s*:\s*/', ': ', $text );
            
            // Step 5: Add spaces between merged words (e.g., "ExecutiveOfficeChair" -> "Executive Office Chair")
            // Add space before capital letters in the middle of a word
            $text = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $text );
        }
        
        // Normalize whitespace but preserve structure
        $text = preg_replace( '/[ \t]+/', ' ', $text ); // Collapse spaces/tabs
        $text = preg_replace( '/\n{3,}/', "\n\n", $text ); // Max 2 consecutive newlines
        
        // Try to fix common encoding issues
        if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
            $text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
        }
        
        // Remove non-printable characters but keep newlines, tabs, and printable ASCII/UTF-8
        $text = preg_replace( '/[^\x20-\x7E\n\t]/u', '', $text );
        
        return trim( $text );
    }

    /**
     * Simple PDF text extraction (fallback method).
     * Extracts readable text from PDF files by parsing PDF structure.
     *
     * @param string $file_path Path to PDF file.
     * @return string Extracted text.
     */
    private static function simple_pdf_text_extraction( $file_path ) {
        $content = file_get_contents( $file_path );
        $text = '';
        
        // Extract text from stream objects
        preg_match_all( '/stream\s*(.*?)\s*endstream/s', $content, $stream_matches );
        
        foreach ( $stream_matches[1] as $stream ) {
            // Try different decompression methods
            $decoded = @gzuncompress( $stream );
            if ( $decoded === false ) {
                $decoded = @gzinflate( $stream );
            }
            if ( $decoded === false ) {
                // Try FlateDecode (most common PDF compression)
                $decoded = @gzuncompress( "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $stream );
            }
            if ( $decoded === false ) {
                $decoded = $stream; // Use as-is if can't decompress
            }
            
            // Extract text from parentheses (common PDF text format)
            preg_match_all( '/\((.*?)\)/s', $decoded, $text_matches );
            foreach ( $text_matches[1] as $match ) {
                // Decode escape sequences
                $match = str_replace( array( '\\n', '\\r', '\\t', '\\\\(', '\\\\)' ), array( "\n", "\r", "\t", '(', ')' ), $match );
                // Remove octal escapes
                $match = preg_replace( '/\\\\([0-7]{1,3})/', '', $match );
                $text .= $match . "\n";
            }
            
            // Extract text from TJ operator (text positioning)
            preg_match_all( '/\[(.*?)\]\s*TJ/s', $decoded, $tj_matches );
            foreach ( $tj_matches[1] as $match ) {
                preg_match_all( '/\((.*?)\)/', $match, $inner_matches );
                foreach ( $inner_matches[1] as $inner ) {
                    $inner = str_replace( array( '\\n', '\\r', '\\t', '\\\\(', '\\\\)' ), array( "\n", "\r", "\t", '(', ')' ), $inner );
                    $inner = preg_replace( '/\\\\([0-7]{1,3})/', '', $inner );
                    $text .= $inner . "\n";
                }
            }
            
            // Extract text from Tj operator (simple text)
            preg_match_all( '/\((.*?)\)\s*Tj/s', $decoded, $tj_simple_matches );
            foreach ( $tj_simple_matches[1] as $match ) {
                $match = str_replace( array( '\\n', '\\r', '\\t', '\\\\(', '\\\\)' ), array( "\n", "\r", "\t", '(', ')' ), $match );
                $match = preg_replace( '/\\\\([0-7]{1,3})/', '', $match );
                $text .= $match . "\n";
            }
        }
        
        // Clean up the text but preserve line breaks for better parsing
        $text = preg_replace( '/[ \t]+/', ' ', $text ); // Only collapse spaces/tabs, keep newlines
        $text = trim( $text );
        
        return $text;
    }

    /**
     * Check if a command exists on the system.
     *
     * @param string $command Command name.
     * @return bool True if command exists.
     */
    private static function command_exists( $command ) {
        $whereIsCommand = ( PHP_OS == 'WINNT' ) ? 'where' : 'which';
        $process = proc_open(
            "$whereIsCommand $command",
            array(
                0 => array( 'pipe', 'r' ),
                1 => array( 'pipe', 'w' ),
                2 => array( 'pipe', 'w' ),
            ),
            $pipes
        );
        
        if ( $process !== false ) {
            $stdout = stream_get_contents( $pipes[1] );
            $return_value = proc_close( $process );
            return $return_value === 0 && ! empty( $stdout );
        }
        
        return false;
    }

    /**
     * Parse PDF text content to extract structured item data.
     * Reads complete PDF content and extracts items based on field names.
     *
     * @param string $text Extracted PDF text.
     * @return array Array of extracted items.
     */
    private static function parse_pdf_text_to_items( $text ) {
        $items = array();
        
        // Preserve line breaks for better parsing
        $text = preg_replace( '/\r\n|\r/', "\n", $text );
        
        // First, try to find items by looking for our exact field names
        // Look for patterns like "Item 1:", "Item 2:", "ITEM 1:", etc.
        $item_sections = self::split_into_item_sections( $text );
        
        if ( empty( $item_sections ) ) {
            // If no clear item sections, try format-specific parsing
            $table_items = self::parse_table_format( $text );
            if ( ! empty( $table_items ) ) {
                return $table_items;
            }
            
            $line_items = self::parse_line_item_format( $text );
            if ( ! empty( $line_items ) ) {
                return $line_items;
            }
            
            $form_items = self::parse_structured_format( $text );
            if ( ! empty( $form_items ) ) {
                return $form_items;
            }
            
            $list_items = self::parse_list_format( $text );
            if ( ! empty( $list_items ) ) {
                return $list_items;
            }
            
            // Fallback: treat entire text as one item
            $item_sections = array( $text );
        }
        
        // Parse each item section using our exact field names
        foreach ( $item_sections as $index => $section ) {
            $item = self::parse_item_section_authentic( $section, $index + 1 );
            
            // Only add item if it has at least some meaningful data
            if ( ! empty( $item['title'] ) || ! empty( $item['length'] ) || ! empty( $item['depth'] ) || ! empty( $item['height'] ) ) {
                $items[] = $item;
            }
        }
        
        return $items;
    }

    /**
     * Split PDF text into individual item sections.
     *
     * @param string $text Full PDF text.
     * @return array Array of item text sections.
     */
    private static function split_into_item_sections( $text ) {
        $sections = array();
        
        // Look for item markers: "Item 1:", "Item 2:", "ITEM 1:", "Line 1:", etc.
        // Patterns are flexible with whitespace to handle fragmented text
        $patterns = array(
            '/Item\s+(\d+)\s*[:\.]\s*(.*?)(?=Item\s+\d+\s*[:\.]|={10,}|-{10,}|$)/is',
            '/ITEM\s+(\d+)\s*[:\.]\s*(.*?)(?=ITEM\s+\d+\s*[:\.]|={10,}|-{10,}|$)/is',
            '/Line\s+(\d+)\s*[:\.]\s*(.*?)(?=Line\s+\d+\s*[:\.]|SUBTOTAL|TOTAL|={10,}|-{10,}|$)/is',
        );
        
        foreach ( $patterns as $pattern ) {
            if ( preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $section = isset( $match[2] ) ? trim( $match[2] ) : '';
                    // Clean up the section - remove excessive whitespace
                    $section = preg_replace( '/\s+/', ' ', $section );
                    if ( ! empty( $section ) && strlen( $section ) > 20 ) {
                        $sections[] = $section;
                    }
                }
                if ( ! empty( $sections ) ) {
                    error_log( 'N88 RFQ: Found ' . count( $sections ) . ' items using pattern: ' . $pattern );
                    break; // Use first pattern that finds items
                }
            }
        }
        
        // If no item markers found, try splitting by separator lines (=== or ---)
        if ( empty( $sections ) ) {
            // Split by separator lines
            $split_sections = preg_split( '/={10,}|-{10,}/', $text );
            foreach ( $split_sections as $section ) {
                $section = trim( $section );
                $section = preg_replace( '/\s+/', ' ', $section );
                // Only keep sections that contain our field names and are substantial
                if ( strlen( $section ) > 50 && (
                    preg_match( '/Length\s*\(?in\)?|Depth\s*\(?in\)?|Height\s*\(?in\)?|Primary\s+Material|Quantity|Construction\s+Notes/i', $section )
                ) ) {
                    $sections[] = $section;
                }
            }
            if ( ! empty( $sections ) ) {
                error_log( 'N88 RFQ: Found ' . count( $sections ) . ' items by splitting on separators' );
            }
        }
        
        // If still no sections, try splitting by double newlines
        if ( empty( $sections ) ) {
            $split_sections = preg_split( '/\n\s*\n{2,}/', $text );
            foreach ( $split_sections as $section ) {
                $section = trim( $section );
                $section = preg_replace( '/\s+/', ' ', $section );
                if ( strlen( $section ) > 50 && (
                    preg_match( '/Product\s+Name|Length\s*\(?in\)?|Depth\s*\(?in\)?|Height\s*\(?in\)?|Primary\s+Material|Quantity|Construction\s+Notes/i', $section )
                ) ) {
                    $sections[] = $section;
                }
            }
        }
        
        return $sections;
    }

    /**
     * Parse Format 1: Table Format (pipe-separated or tab-separated)
     */
    private static function parse_table_format( $text ) {
        $items = array();
        
        // Look for table patterns with pipes or tabs
        if ( preg_match_all( '/\d+\s*[|]\s*([^|]+)\s*[|]\s*([^|]+)\s*[|]\s*([^|]+)\s*[|]\s*([^|]+)\s*[|]\s*(\d+)/i', $text, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $item = array(
                    'title' => trim( $match[1] ),
                    'quantity' => (int) trim( $match[5] ),
                );
                
                // Parse dimensions from match[2]
                if ( preg_match( '/(\d+\.?\d*)\s*["×x]\s*(\d+\.?\d*)\s*["×x]\s*(\d+\.?\d*)/i', $match[2], $dims ) ) {
                    $item['length'] = (float) $dims[1];
                    $item['depth'] = (float) $dims[2];
                    $item['height'] = (float) $dims[3];
                }
                
                // Parse materials from match[3]
                $materials = preg_split( '/[,;]/', trim( $match[3] ) );
                $item['primary_material'] = trim( $materials[0] );
                if ( count( $materials ) > 1 ) {
                    $item['frame_material'] = trim( $materials[1] );
                }
                
                // Parse finishes from match[4]
                $item['finishes'] = trim( $match[4] );
                
                $items[] = $item;
            }
        }
        
        return $items;
    }

    /**
     * Parse Format 5: Invoice/Quotation Format (Line items)
     */
    private static function parse_line_item_format( $text ) {
        $items = array();
        
        // Look for "Line 1:", "Line 2:", etc.
        if ( preg_match_all( '/Line\s+(\d+)[:\s]+(.*?)(?=Line\s+\d+[:]|SUBTOTAL|TOTAL|$)/is', $text, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $section = $match[2];
                $item = self::parse_item_section( $section, (int) $match[1] );
                
                // Also look for Description, Model, etc.
                if ( preg_match( '/Description[:\s]+([^\n]+)/i', $section, $desc ) ) {
                    $item['title'] = trim( $desc[1] );
                }
                if ( preg_match( '/Model[:\s]+([^\n]+)/i', $section, $model ) ) {
                    $item['title'] = ( $item['title'] ? $item['title'] . ' - ' : '' ) . trim( $model[1] );
                }
                
                if ( ! empty( $item['title'] ) || ! empty( $item['length'] ) ) {
                    $items[] = $item;
                }
            }
        }
        
        return $items;
    }

    /**
     * Parse Format 2 & 3: Structured Form/Catalog Format
     */
    private static function parse_structured_format( $text ) {
        $items = array();
        
        // Look for "ITEM 1:", "Item 1:", "Product Code:", etc.
        $patterns = array(
            '/Item\s+(\d+)[:\s]+(.*?)(?=Item\s+\d+[:]|Product Code|Summary|Total|$)/is',
            '/Product\s+Code[:\s]+([^\n]+)(.*?)(?=Product\s+Code|Item\s+\d+|Summary|Total|$)/is',
        );
        
        foreach ( $patterns as $pattern ) {
            if ( preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $section = isset( $match[2] ) ? $match[2] : $match[0];
                    $item = self::parse_item_section( $section, count( $items ) + 1 );
                    
                    if ( ! empty( $item['title'] ) || ! empty( $item['length'] ) || ! empty( $item['depth'] ) ) {
                        $items[] = $item;
                    }
                }
                if ( ! empty( $items ) ) {
                    break;
                }
            }
        }
        
        return $items;
    }

    /**
     * Parse Format 4: Simple List Format
     */
    private static function parse_list_format( $text ) {
        $items = array();
        
        // Look for numbered list items: "1. Product Name" or "1) Product Name"
        if ( preg_match_all( '/(\d+)[\.\)]\s+([^\n]+)\s*\n(.*?)(?=\d+[\.\)]\s+|$)/is', $text, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $item = array(
                    'title' => trim( $match[2] ),
                );
                
                $section = $match[3];
                
                // Extract Size/Dimensions
                if ( preg_match( '/Size[:\s]+([^\n]+)/i', $section, $size ) ) {
                    $size_text = $size[1];
                    if ( preg_match( '/(\d+\.?\d*)\s*(?:x|×)\s*(\d+\.?\d*)\s*(?:x|×)\s*(\d+\.?\d*)/i', $size_text, $dims ) ) {
                        $item['length'] = (float) $dims[1];
                        $item['depth'] = (float) $dims[2];
                        $item['height'] = (float) $dims[3];
                    }
                }
                
                // Extract Material
                if ( preg_match( '/Material[:\s]+([^\n]+)/i', $section, $mat ) ) {
                    $item['primary_material'] = trim( $mat[1] );
                }
                
                // Extract Color/Finish
                if ( preg_match( '/Color\/Finish[:\s]+([^\n]+)/i', $section, $finish ) ) {
                    $item['finishes'] = trim( $finish[1] );
                }
                
                // Extract Quantity
                if ( preg_match( '/Quantity[:\s]+(\d+)/i', $section, $qty ) ) {
                    $item['quantity'] = (int) $qty[1];
                }
                
                // Extract Notes
                if ( preg_match( '/Notes?[:\s]+([^\n]+)/i', $section, $notes ) ) {
                    $item['notes'] = trim( $notes[1] );
                }
                
                if ( ! empty( $item['title'] ) ) {
                    $items[] = $item;
                }
            }
        }
        
        return $items;
    }

    /**
     * Parse Generic Format (fallback)
     */
    private static function parse_generic_format( $text ) {
        $items = array();
        
        // Split by common item markers
        $sections = preg_split( '/(?:^|\n)(?:Item|Product|Line)\s+\d+[:\s\.]/mi', $text );
        
        foreach ( $sections as $index => $section ) {
            if ( empty( trim( $section ) ) ) {
                continue;
            }
            
            $item = self::parse_item_section( $section, $index + 1 );
            
            if ( ! empty( $item['title'] ) || ! empty( $item['length'] ) || ! empty( $item['depth'] ) ) {
                $items[] = $item;
            }
        }
        
        return $items;
    }

    /**
     * Parse a single item section using our exact field names.
     * Authentic extraction based on actual field labels.
     *
     * @param string $section Text section for one item.
     * @param int    $item_number Item number.
     * @return array Parsed item data.
     */
    private static function parse_item_section_authentic( $section, $item_number ) {
        $item = array(
            'title'              => '',
            'length'             => 0,
            'depth'              => 0,
            'height'             => 0,
            'quantity'           => 1,
            'primary_material'   => '',
            'finishes'           => '',
            'construction_notes' => '',
            'status'             => 'extracted',
        );
        
        // Debug: Log the section being parsed (first 300 chars)
        error_log( 'N88 RFQ: Parsing item ' . $item_number . ' section (first 300 chars): ' . substr( $section, 0, 300 ) );
        
        // Extract Item/Product Name - find text between "Product Name:" and next field
        // Look for the pattern: "Product Name: [TEXT] Length" or "Product Name: [TEXT] Depth" etc.
        if ( preg_match( '/Product\s+Name\s*[:]\s*(.*?)(?:\s+Length\s*\(in\)|Length\s*\(|Length\s*[:]|Depth\s*\(in\)|Depth\s*\(|Depth\s*[:]|Height\s*\(in\)|Height\s*\(|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:])/is', $section, $matches ) ) {
            $item['title'] = trim( $matches[1] );
        } elseif ( preg_match( '/Product\s*[:]\s*(.*?)(?:\s+Length\s*\(in\)|Length\s*\(|Length\s*[:]|Depth\s*\(in\)|Depth\s*\(|Depth\s*[:]|Height\s*\(in\)|Height\s*\(|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:])/is', $section, $matches ) ) {
            $item['title'] = trim( $matches[1] );
        }
        
        // Clean up the title
        if ( ! empty( $item['title'] ) ) {
            // Remove any trailing field names that might have been captured
            $item['title'] = preg_replace( '/\s*(Length|Depth|Height|Quantity|Primary\s+Material|Finishes|Construction\s+Notes).*$/i', '', $item['title'] );
            // Remove any trailing special characters but keep spaces and common punctuation
            $item['title'] = preg_replace( '/[^\w\s\-\.]/', '', $item['title'] );
            $item['title'] = preg_replace( '/\s+/', ' ', $item['title'] ); // Normalize spaces
            $item['title'] = trim( $item['title'] );
            
            // Add spaces between words that got merged (e.g., "ExecutiveOfficeChair" -> "Executive Office Chair")
            // This is a simple heuristic: add space before capital letters in the middle of a word
            $item['title'] = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $item['title'] );
            
            if ( strlen( $item['title'] ) > 2 ) {
                error_log( 'N88 RFQ: Extracted title: ' . $item['title'] );
            } else {
                $item['title'] = ''; // Reset if too short
            }
        }
        
        if ( empty( $item['title'] ) ) {
            $item['title'] = 'Item ' . $item_number;
            error_log( 'N88 RFQ: No title found for item ' . $item_number . ', using default' );
        }
        
        // Extract Length (in) - Look for exact field name, capture number only
        // Match: "Length (in): 24.5" or "Length (in):24.5" - stop at newline or next field
        $length_patterns = array(
            '/Length\s*\(in\)\s*[:]\s*(\d+\.?\d*)\s*(?:\n|Depth\s*\(in\)|Depth\s*[:]|Height\s*\(in\)|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
            '/Length\s*\(?in\)?\s*[:]\s*(\d+\.?\d*)\s*(?:\n|Depth\s*\(in\)|Depth\s*[:]|Height\s*\(in\)|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
        );
        foreach ( $length_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['length'] = (float) trim( $matches[1] );
                if ( $item['length'] > 0 ) {
                    error_log( 'N88 RFQ: Extracted Length: ' . $item['length'] );
                    break;
                }
            }
        }
        
        // Extract Depth (in) - Look for exact field name, capture number only
        $depth_patterns = array(
            '/Depth\s*\(in\)\s*[:]\s*(\d+\.?\d*)\s*(?:\n|Height\s*\(in\)|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
            '/Depth\s*\(?in\)?\s*[:]\s*(\d+\.?\d*)\s*(?:\n|Height\s*\(in\)|Height\s*[:]|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
        );
        foreach ( $depth_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['depth'] = (float) trim( $matches[1] );
                if ( $item['depth'] > 0 ) {
                    error_log( 'N88 RFQ: Extracted Depth: ' . $item['depth'] );
                    break;
                }
            }
        }
        
        // Extract Height (in) - Look for exact field name, capture number only
        $height_patterns = array(
            '/Height\s*\(in\)\s*[:]\s*(\d+\.?\d*(?:\s*-\s*\d+\.?\d*)?)\s*(?:\n|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
            '/Height\s*\(?in\)?\s*[:]\s*(\d+\.?\d*(?:\s*-\s*\d+\.?\d*)?)\s*(?:\n|Quantity\s*[:]|Primary\s+Material\s*[:]|Finishes\s*[:]|Construction\s+Notes\s*[:]|$)/i',
        );
        foreach ( $height_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $height_str = trim( $matches[1] );
                // Handle ranges like "28.5 - 48.0"
                if ( preg_match( '/(\d+\.?\d*)\s*-\s*(\d+\.?\d*)/', $height_str, $range_matches ) ) {
                    $item['height'] = (float) $range_matches[1];
                } else {
                    $item['height'] = (float) $height_str;
                }
                if ( $item['height'] > 0 ) {
                    error_log( 'N88 RFQ: Extracted Height: ' . $item['height'] );
                    break;
                }
            }
        }
        
        // Only try combined dimension format if ALL individual fields were NOT found
        // AND make sure it's not already in a dimension field
        if ( empty( $item['length'] ) && empty( $item['depth'] ) && empty( $item['height'] ) ) {
            // Only match combined format if it's NOT part of a field label
            if ( preg_match( '/(?<!Length\s*\(in\)\s*[:]\s*)(?<!Depth\s*\(in\)\s*[:]\s*)(?<!Height\s*\(in\)\s*[:]\s*)(\d+\.?\d*)\s*["×x]\s*(\d+\.?\d*)\s*["×x]\s*(\d+\.?\d*)/i', $section, $dims ) ) {
                $item['length'] = (float) $dims[1];
                $item['depth'] = (float) $dims[2];
                $item['height'] = (float) $dims[3];
                error_log( 'N88 RFQ: Used combined dimension format - Length: ' . $item['length'] . ', Depth: ' . $item['depth'] . ', Height: ' . $item['height'] );
            }
        }
        
        // Extract Quantity - Look for exact field name, stop at next field or newline
        $qty_patterns = array(
            '/Quantity\s*[:]\s*(\d+)\s*(?:\s+Primary\s+Material\s*[:]|Primary\s+Material|Finishes\s*[:]|Finishes|Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/i',
            '/Qty\s*[:]\s*(\d+)\s*(?:\s+Primary\s+Material\s*[:]|Primary\s+Material|Finishes\s*[:]|Finishes|Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/i',
        );
        foreach ( $qty_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['quantity'] = (int) trim( $matches[1] );
                if ( $item['quantity'] > 0 ) {
                    error_log( 'N88 RFQ: Extracted Quantity: ' . $item['quantity'] );
                    break;
                }
            }
        }
        
        // Extract Primary Material - Look for exact field name, stop at next field or newline
        $material_patterns = array(
            '/Primary\s+Material\s*[:]\s*(.*?)(?:\s+Finishes\s*[:]|Finishes|Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/is',
        );
        foreach ( $material_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['primary_material'] = trim( $matches[1] );
                // Remove any trailing field names that might have been captured
                $item['primary_material'] = preg_replace( '/\s*(Finishes|Construction\s+Notes).*$/i', '', $item['primary_material'] );
                // Clean up any trailing special characters but keep spaces
                $item['primary_material'] = preg_replace( '/[^\w\s\-\.]/', '', $item['primary_material'] );
                $item['primary_material'] = preg_replace( '/\s+/', ' ', $item['primary_material'] );
                $item['primary_material'] = trim( $item['primary_material'] );
                if ( ! empty( $item['primary_material'] ) ) {
                    error_log( 'N88 RFQ: Extracted Primary Material: ' . $item['primary_material'] );
                    break;
                }
            }
        }
        
        // Fallback: Try generic "Material" if Primary Material not found
        if ( empty( $item['primary_material'] ) ) {
            if ( preg_match( '/Material\s*[:]\s*(.*?)(?:\s+Finishes\s*[:]|Finishes|Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/is', $section, $matches ) ) {
                $material_text = trim( $matches[1] );
                // Take first material if comma-separated
                if ( preg_match( '/^([^,;]+)/', $material_text, $first_mat ) ) {
                    $item['primary_material'] = trim( $first_mat[1] );
                } else {
                    $item['primary_material'] = preg_replace( '/[^\w\s\-\.]/', '', $material_text );
                    $item['primary_material'] = preg_replace( '/\s+/', ' ', $item['primary_material'] );
                    $item['primary_material'] = trim( $item['primary_material'] );
                }
            }
        }
        
        // Extract Finishes - Look for exact field name, stop at next field or newline
        $finish_patterns = array(
            '/Finishes\s*[:]\s*(.*?)(?:\s+Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/is',
            '/Finish\s*[:]\s*(.*?)(?:\s+Construction\s+Notes\s*[:]|Construction\s+Notes|Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$|\n)/is',
        );
        foreach ( $finish_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['finishes'] = trim( $matches[1] );
                // Remove any trailing field names that might have been captured
                $item['finishes'] = preg_replace( '/\s*(Construction\s+Notes).*$/i', '', $item['finishes'] );
                // Clean up any trailing special characters but keep spaces and common punctuation
                $item['finishes'] = preg_replace( '/[^\w\s\-\.]/', '', $item['finishes'] );
                $item['finishes'] = preg_replace( '/\s+/', ' ', $item['finishes'] );
                $item['finishes'] = trim( $item['finishes'] );
                if ( ! empty( $item['finishes'] ) ) {
                    error_log( 'N88 RFQ: Extracted Finishes: ' . $item['finishes'] );
                    break;
                }
            }
        }
        
        // Extract Construction Notes - Look for exact field name, stop at next item or end
        $construction_patterns = array(
            '/Construction\s+Notes\s*[:]\s*(.*?)(?:\s+Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$)/is',
            '/Construction\s+Notes?\s*[:]\s*(.*?)(?:\s+Item\s+\d+|Line\s+\d+|={10,}|-{10,}|$)/is',
        );
        foreach ( $construction_patterns as $pattern ) {
            if ( preg_match( $pattern, $section, $matches ) ) {
                $item['construction_notes'] = trim( $matches[1] );
                // Remove any trailing item markers
                $item['construction_notes'] = preg_replace( '/\s*(Item\s+\d+|Line\s+\d+).*$/i', '', $item['construction_notes'] );
                // Clean up whitespace but preserve sentence structure
                $item['construction_notes'] = preg_replace( '/\s+/', ' ', $item['construction_notes'] );
                // Clean up any trailing special characters but keep punctuation
                $item['construction_notes'] = preg_replace( '/[^\w\s\-\.\,\!\?\(\)]/', '', $item['construction_notes'] );
                $item['construction_notes'] = trim( $item['construction_notes'] );
                if ( ! empty( $item['construction_notes'] ) ) {
                    error_log( 'N88 RFQ: Extracted Construction Notes: ' . substr( $item['construction_notes'], 0, 100 ) . '...' );
                    break;
                }
            }
        }
        
        // Ensure dimensions are numeric, not strings
        if ( is_string( $item['length'] ) ) {
            // Extract first number from string (in case it contains "24.5" × 26" × 42"")
            if ( preg_match( '/(\d+\.?\d*)/', $item['length'], $num_match ) ) {
                $item['length'] = (float) $num_match[1];
            } else {
                $item['length'] = 0;
            }
        }
        if ( is_string( $item['depth'] ) ) {
            if ( preg_match( '/(\d+\.?\d*)/', $item['depth'], $num_match ) ) {
                $item['depth'] = (float) $num_match[1];
            } else {
                $item['depth'] = 0;
            }
        }
        if ( is_string( $item['height'] ) ) {
            if ( preg_match( '/(\d+\.?\d*)/', $item['height'], $num_match ) ) {
                $item['height'] = (float) $num_match[1];
            } else {
                $item['height'] = 0;
            }
        }
        
        // Debug: Log extracted item data with full details
        error_log( 'N88 RFQ: Extracted item ' . $item_number . ' - Title: ' . $item['title'] . ', Length: ' . var_export( $item['length'], true ) . ', Depth: ' . var_export( $item['depth'], true ) . ', Height: ' . var_export( $item['height'], true ) . ', Quantity: ' . $item['quantity'] . ', Material: ' . substr( $item['primary_material'], 0, 50 ) . ', Finishes: ' . substr( $item['finishes'], 0, 50 ) . ', Construction Notes: ' . substr( $item['construction_notes'], 0, 50 ) );
        
        // Ensure dimensions are numeric (not strings) before returning
        $item['length'] = is_numeric( $item['length'] ) ? (float) $item['length'] : 0;
        $item['depth'] = is_numeric( $item['depth'] ) ? (float) $item['depth'] : 0;
        $item['height'] = is_numeric( $item['height'] ) ? (float) $item['height'] : 0;
        
        // Mark as needs_review ONCE if ANY critical field is empty
        // Only check essential fields: dimensions, primary material, quantity, title
        $needs_review = false;
        $needs_review_reason = '';
        
        // Check for missing critical fields - mark once if any are empty
        if ( empty( $item['length'] ) || $item['length'] <= 0 ) {
            $needs_review = true;
            $needs_review_reason = 'Missing Length';
        } elseif ( empty( $item['depth'] ) || $item['depth'] <= 0 ) {
            $needs_review = true;
            $needs_review_reason = 'Missing Depth';
        } elseif ( empty( $item['height'] ) || $item['height'] <= 0 ) {
            $needs_review = true;
            $needs_review_reason = 'Missing Height';
        } elseif ( empty( $item['primary_material'] ) || trim( $item['primary_material'] ) === '' ) {
            $needs_review = true;
            $needs_review_reason = 'Missing Primary Material';
        } elseif ( empty( $item['quantity'] ) || $item['quantity'] <= 0 ) {
            $needs_review = true;
            $needs_review_reason = 'Missing Quantity';
        } elseif ( empty( $item['title'] ) || strlen( trim( $item['title'] ) ) < 3 ) {
            $needs_review = true;
            $needs_review_reason = 'Missing or unclear title';
        }
        
        // Set status once if any critical field is missing
        if ( $needs_review ) {
            $item['status'] = 'needs_review';
            $item['needs_review_reason'] = $needs_review_reason;
        }
        
        // Final debug: Log the exact structure being returned
        error_log( 'N88 RFQ: Returning item ' . $item_number . ' structure: ' . json_encode( array(
            'title' => $item['title'],
            'length' => $item['length'],
            'depth' => $item['depth'],
            'height' => $item['height'],
            'quantity' => $item['quantity'],
            'primary_material' => substr( $item['primary_material'], 0, 30 ),
            'finishes' => substr( $item['finishes'], 0, 30 ),
            'construction_notes' => substr( $item['construction_notes'], 0, 30 ),
        ) ) );
        
        return $item;
    }

    /**
     * Parse a single item section from PDF text (legacy method for backward compatibility).
     *
     * @param string $section Text section for one item.
     * @param int    $item_number Item number.
     * @return array Parsed item data.
     */
    private static function parse_item_section( $section, $item_number ) {
        // Use the new authentic parsing method
        return self::parse_item_section_authentic( $section, $item_number );
    }

    /**
     * Confirm extraction and create item cards.
     *
     * @param int   $project_id Project ID.
     * @param array $items Items data from extraction.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function confirm_extraction( $project_id, $items ) {
        global $wpdb;

        if ( ! is_array( $items ) || empty( $items ) ) {
            return new WP_Error( 'invalid_items', 'No items provided for extraction.' );
        }

        $meta_table = $wpdb->prefix . 'project_metadata';
        $extraction_count = 0;
        $needs_review_count = 0;

        foreach ( $items as $item ) {
            // Validate required fields
            if ( empty( $item['title'] ) ) {
                continue;
            }

            // Get product_category if available
            $product_category = sanitize_text_field( $item['product_category'] ?? '' );
            
            $item_data = array(
                'title'              => sanitize_text_field( $item['title'] ),
                'dimensions'         => array(
                    'length'  => isset( $item['length'] ) ? (float) $item['length'] : 0,
                    'depth'   => isset( $item['depth'] ) ? (float) $item['depth'] : 0,
                    'height'  => isset( $item['height'] ) ? (float) $item['height'] : 0,
                ),
                'materials'          => array_map( 'sanitize_text_field', (array) ( $item['materials'] ?? array() ) ),
                'finishes'           => sanitize_text_field( $item['finishes'] ?? '' ),
                'quantity'           => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
                'extracted'          => true,
                'extraction_status'  => $item['status'] ?? 'extracted', // extracted, needs_review
                'locked'             => true, // Lock extracted fields
                // Store original values for change tracking
                'original_length'    => isset( $item['length'] ) ? (float) $item['length'] : 0,
                'original_depth'     => isset( $item['depth'] ) ? (float) $item['depth'] : 0,
                'original_height'    => isset( $item['height'] ) ? (float) $item['height'] : 0,
                'original_material'  => sanitize_text_field( $item['primary_material'] ?? '' ),
                'original_finishes'  => sanitize_text_field( $item['finishes'] ?? '' ),
                'original_quantity'  => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
                'original_notes'     => sanitize_text_field( $item['construction_notes'] ?? '' ),
            );
            
            // Phase 3: Add product_category if available
            if ( ! empty( $product_category ) ) {
                $item_data['product_category'] = $product_category;
            }
            
            // Phase 3: Ensure timeline_structure is assigned
            if ( class_exists( 'N88_RFQ_Timeline' ) ) {
                // Get project-level sourcing_category for fallback
                $sourcing_category = $wpdb->get_var( $wpdb->prepare(
                    "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = 'n88_sourcing_category'",
                    $project_id
                ) );
                $sourcing_category = $sourcing_category ? $sourcing_category : '';
                
                $item_data = N88_RFQ_Timeline::ensure_item_timeline( $item_data, $sourcing_category );
            }

            // Save as repeater item
            $existing_items = self::get_project_items( $project_id );
            $item_index = count( $existing_items ); // Index for this new item
            $existing_items[] = $item_data;
            
            // Auto-flag items that need review using Item Flags class
            if ( class_exists( 'N88_RFQ_Item_Flags' ) && ( $item['status'] ?? 'extracted' ) === 'needs_review' ) {
                $flags_class = new N88_RFQ_Item_Flags();
                $reason = $item['needs_review_reason'] ?? 'Missing or unreadable data detected during extraction';
                $flags_class->add_flag( $project_id, $item_index, N88_RFQ_Item_Flags::FLAG_NEEDS_REVIEW, $reason );
                $needs_review_count++;
            }

            $wpdb->delete(
                $meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key'   => 'n88_repeater_raw',
                ),
                array( '%d', '%s' )
            );

            $wpdb->insert(
                $meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key'   => 'n88_repeater_raw',
                    'meta_value' => wp_json_encode( $existing_items ),
                ),
                array( '%d', '%s', '%s' )
            );

            $extraction_count++;
        }

        // Update project metadata to mark as extraction mode
        $wpdb->update(
            $meta_table,
            array( 'meta_value' => '1' ),
            array( 'project_id' => $project_id, 'meta_key' => 'n88_extraction_mode' ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        if ( $extraction_count === 0 ) {
            return new WP_Error( 'no_items_extracted', 'No valid items were extracted from PDF.' );
        }

        // Phase 2B: Notify user if extraction requires review
        if ( $needs_review_count > 0 && class_exists( 'N88_RFQ_Notifications' ) ) {
            N88_RFQ_Notifications::notify_extraction_requires_review( $project_id, $needs_review_count, $extraction_count );
        }

        return true;
    }

    /**
     * Get all items for a project.
     *
     * @param int $project_id Project ID.
     * @return array Array of items.
     */
    public static function get_project_items( $project_id ) {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'project_metadata';

        $items_json = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = 'n88_repeater_raw'",
                $project_id
            )
        );

        if ( ! $items_json ) {
            return array();
        }

        $items = json_decode( $items_json, true );
        return is_array( $items ) ? $items : array();
    }

    /**
     * Mark an item as "Needs Review" and unlock it for editing.
     *
     * @param int $project_id Project ID.
     * @param int $item_index Item index in array.
     * @return bool True on success.
     */
    public static function flag_item_needs_review( $project_id, $item_index ) {
        global $wpdb;

        $items = self::get_project_items( $project_id );

        if ( ! isset( $items[ $item_index ] ) ) {
            return false;
        }

        $items[ $item_index ]['extraction_status'] = 'needs_review';
        $items[ $item_index ]['locked'] = false;

        $meta_table = $wpdb->prefix . 'project_metadata';

        $wpdb->update(
            $meta_table,
            array( 'meta_value' => wp_json_encode( $items ) ),
            array( 'project_id' => $project_id, 'meta_key' => 'n88_repeater_raw' ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        return true;
    }

    /**
     * Check if project is in extraction mode.
     *
     * @param int $project_id Project ID.
     * @return bool True if in extraction mode.
     */
    public static function is_extraction_mode( $project_id ) {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'project_metadata';

        $mode = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE project_id = %d AND meta_key = 'n88_extraction_mode'",
                $project_id
            )
        );

        return '1' === $mode;
    }

    /**
     * Set extraction mode for a project.
     *
     * @param int  $project_id Project ID.
     * @param bool $enabled Whether extraction mode is enabled.
     * @return bool True on success.
     */
    public static function set_extraction_mode( $project_id, $enabled ) {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'project_metadata';

        if ( $enabled ) {
            // Check if already exists
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$meta_table} WHERE project_id = %d AND meta_key = 'n88_extraction_mode'",
                    $project_id
                )
            );

            if ( $existing ) {
                $wpdb->update(
                    $meta_table,
                    array( 'meta_value' => '1' ),
                    array( 'id' => $existing ),
                    array( '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $meta_table,
                    array(
                        'project_id' => $project_id,
                        'meta_key'   => 'n88_extraction_mode',
                        'meta_value' => '1',
                    ),
                    array( '%d', '%s', '%s' )
                );
            }
        } else {
            $wpdb->delete(
                $meta_table,
                array(
                    'project_id' => $project_id,
                    'meta_key'   => 'n88_extraction_mode',
                ),
                array( '%d', '%s' )
            );
        }

        return true;
    }
}
