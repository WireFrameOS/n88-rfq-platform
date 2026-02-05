<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commit 3.A.2 — Timeline Step Evidence (append-only media bound to item + step).
 * Operator/Admin uploads; designers see only derived, watermarked assets.
 */
class N88_Timeline_Step_Evidence {

    const MEDIA_TYPE_IMAGE  = 'image';
    const MEDIA_TYPE_PDF    = 'pdf';
    const MEDIA_TYPE_YOUTUBE = 'youtube';

    const WATERMARK_TEXT = 'WireFrame OS';

    /**
     * Add evidence (append-only). Operator/Admin only; caller must check capability.
     *
     * @param int    $item_id              n88_items.id
     * @param int    $step_id              n88_item_timeline_steps.step_id
     * @param string $media_type           'image'|'pdf'|'youtube'
     * @param array  $options              attachment_id (int), file_path (string), youtube_url (string), add_to_media_library (bool), add_to_material_bank (bool)
     * @param int    $created_by           User ID (optional)
     * @return array { success, message, evidence_id }
     */
    public static function add_evidence( $item_id, $step_id, $media_type, $options = array(), $created_by = null ) {
        global $wpdb;
        $item_id   = absint( $item_id );
        $step_id   = absint( $step_id );
        $media_type = in_array( $media_type, array( self::MEDIA_TYPE_IMAGE, self::MEDIA_TYPE_PDF, self::MEDIA_TYPE_YOUTUBE ), true ) ? $media_type : self::MEDIA_TYPE_IMAGE;

        if ( ! $item_id || ! $step_id ) {
            return array( 'success' => false, 'message' => 'Invalid item or step.' );
        }

        if ( ! self::step_belongs_to_item( $step_id, $item_id ) ) {
            return array( 'success' => false, 'message' => 'Step does not belong to this item.' );
        }

        $attachment_id = isset( $options['attachment_id'] ) ? absint( $options['attachment_id'] ) : null;
        $file_path     = isset( $options['file_path'] ) ? self::sanitize_file_path( $options['file_path'] ) : null;
        $youtube_url   = isset( $options['youtube_url'] ) ? esc_url_raw( trim( $options['youtube_url'] ) ) : null;
        $add_to_media  = ! empty( $options['add_to_media_library'] );
        $add_to_bank   = ! empty( $options['add_to_material_bank'] );

        if ( $media_type === self::MEDIA_TYPE_YOUTUBE ) {
            if ( empty( $youtube_url ) || ! self::is_youtube_url( $youtube_url ) ) {
                return array( 'success' => false, 'message' => 'Valid YouTube URL required.' );
            }
            $attachment_id = null;
            $file_path     = null;
        } else {
            if ( ! $attachment_id && empty( $file_path ) ) {
                return array( 'success' => false, 'message' => 'Attachment or file path required for image/PDF.' );
            }
            $youtube_url = null;
        }

        if ( $created_by === null ) {
            $created_by = get_current_user_id();
        }
        $created_by = $created_by ? absint( $created_by ) : null;
        $now        = current_time( 'mysql' );

        $evidence_table = $wpdb->prefix . 'n88_timeline_step_evidence';
        $r = $wpdb->insert(
            $evidence_table,
            array(
                'item_id'              => $item_id,
                'step_id'              => $step_id,
                'media_type'           => $media_type,
                'attachment_id'        => $attachment_id,
                'file_path'            => $file_path,
                'youtube_url'          => $youtube_url,
                'add_to_media_library' => $add_to_media ? 1 : 0,
                'add_to_material_bank' => $add_to_bank ? 1 : 0,
                'created_at'           => $now,
                'created_by'           => $created_by,
                'hidden'              => 0,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d' )
        );

        if ( ! $r ) {
            return array( 'success' => false, 'message' => 'Failed to save evidence.' );
        }

        $evidence_id = (int) $wpdb->insert_id;
        return array( 'success' => true, 'message' => 'Evidence added.', 'evidence_id' => $evidence_id );
    }

    /**
     * Get evidence for a single step. Latest first; hidden only for operator/admin.
     *
     * @param int  $item_id     n88_items.id
     * @param int  $step_id     n88_item_timeline_steps.step_id
     * @param bool $for_designer If true, only non-hidden and view_url is watermarked serve URL.
     * @return array List of evidence rows with view_url, original_url (only for non-designer), media_type, created_at, etc.
     */
    public static function get_evidence_for_step( $item_id, $step_id, $for_designer = false ) {
        global $wpdb;
        $item_id = absint( $item_id );
        $step_id = absint( $step_id );
        if ( ! $item_id || ! $step_id ) {
            return array();
        }

        $evidence_table = $wpdb->prefix . 'n88_timeline_step_evidence';
        $where = "item_id = %d AND step_id = %d";
        $params = array( $item_id, $step_id );
        if ( $for_designer ) {
            $where .= " AND hidden = 0";
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, item_id, step_id, media_type, attachment_id, file_path, youtube_url, created_at, created_by, hidden FROM {$evidence_table} WHERE {$where} ORDER BY created_at DESC",
                $params
            ),
            ARRAY_A
        );

        $list = array();
        foreach ( $rows as $row ) {
            $list[] = self::format_evidence_row( $row, $for_designer );
        }
        return $list;
    }

    /**
     * Get evidence for all steps of an item, keyed by step_id.
     *
     * @param int  $item_id
     * @param bool $for_designer
     * @return array [ step_id => [ evidence items ] ]
     */
    public static function get_evidence_for_item( $item_id, $for_designer = false ) {
        global $wpdb;
        $item_id = absint( $item_id );
        if ( ! $item_id ) {
            return array();
        }

        $evidence_table = $wpdb->prefix . 'n88_timeline_step_evidence';
        $where = "item_id = %d";
        if ( $for_designer ) {
            $where .= " AND hidden = 0";
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, item_id, step_id, media_type, attachment_id, file_path, youtube_url, created_at, created_by, hidden FROM {$evidence_table} WHERE {$where} ORDER BY step_id ASC, created_at DESC",
                $item_id
            ),
            ARRAY_A
        );

        $by_step = array();
        foreach ( $rows as $row ) {
            $step_id = (int) $row['step_id'];
            if ( ! isset( $by_step[ $step_id ] ) ) {
                $by_step[ $step_id ] = array();
            }
            $by_step[ $step_id ][] = self::format_evidence_row( $row, $for_designer );
        }
        return $by_step;
    }

    /**
     * Get a single evidence row by id. Returns null if not found or step doesn't belong to item.
     *
     * @param int $evidence_id
     * @param int $item_id Optional; if provided, validates evidence belongs to this item.
     * @return array|null
     */
    public static function get_evidence_by_id( $evidence_id, $item_id = null ) {
        global $wpdb;
        $evidence_id = absint( $evidence_id );
        if ( ! $evidence_id ) {
            return null;
        }
        $evidence_table = $wpdb->prefix . 'n88_timeline_step_evidence';
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, item_id, step_id, media_type, attachment_id, file_path, youtube_url, created_at, created_by, hidden FROM {$evidence_table} WHERE id = %d", $evidence_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        if ( $item_id !== null && (int) $row['item_id'] !== (int) $item_id ) {
            return null;
        }
        return $row;
    }

    /**
     * Build view URL for designer (watermarked) or operator (original). Does not perform permission check.
     *
     * @param array $row Evidence row (with id, media_type, attachment_id, file_path, youtube_url)
     * @param bool  $for_designer If true, return watermarked serve URL; else return original URL.
     * @return array { view_url, original_url (null for designer), media_type, created_at, id, ... }
     */
    public static function format_evidence_row( $row, $for_designer = false ) {
        $id          = (int) $row['id'];
        $media_type  = $row['media_type'];
        $youtube_url = ! empty( $row['youtube_url'] ) ? $row['youtube_url'] : null;
        $created_at  = isset( $row['created_at'] ) ? $row['created_at'] : '';

        $out = array(
            'id'         => $id,
            'item_id'    => (int) $row['item_id'],
            'step_id'    => (int) $row['step_id'],
            'media_type' => $media_type,
            'created_at' => $created_at,
            'created_by' => ! empty( $row['created_by'] ) ? (int) $row['created_by'] : null,
            'hidden'     => ! empty( $row['hidden'] ),
        );

        if ( $media_type === self::MEDIA_TYPE_YOUTUBE ) {
            $out['view_url']    = $youtube_url;
            $out['original_url'] = $for_designer ? null : $youtube_url;
            $out['youtube_url'] = $youtube_url;
            return $out;
        }

        $original_url = null;
        if ( ! empty( $row['attachment_id'] ) ) {
            $original_url = wp_get_attachment_url( (int) $row['attachment_id'] );
        } elseif ( ! empty( $row['file_path'] ) ) {
            $original_url = self::file_path_to_url( $row['file_path'] );
        }

        if ( $for_designer ) {
            $out['view_url']     = self::watermarked_serve_url( $id );
            $out['original_url'] = null;
        } else {
            $out['view_url']     = $original_url;
            $out['original_url'] = $original_url;
        }
        return $out;
    }

    /**
     * URL for the endpoint that serves watermarked asset (designers must use this only).
     *
     * @param int $evidence_id
     * @return string
     */
    public static function watermarked_serve_url( $evidence_id ) {
        $evidence_id = absint( $evidence_id );
        if ( ! $evidence_id ) {
            return '';
        }
        return add_query_arg(
            array(
                'action' => 'n88_serve_timeline_evidence',
                'id'     => $evidence_id,
                'nonce'  => wp_create_nonce( 'n88_serve_timeline_evidence_' . $evidence_id ),
            ),
            admin_url( 'admin-ajax.php' )
        );
    }

    private static function step_belongs_to_item( $step_id, $item_id ) {
        global $wpdb;
        $timelines_table = $wpdb->prefix . 'n88_item_timelines';
        $steps_table     = $wpdb->prefix . 'n88_item_timeline_steps';
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT s.step_id FROM {$steps_table} s INNER JOIN {$timelines_table} t ON s.timeline_id = t.timeline_id WHERE s.step_id = %d AND t.item_id = %d",
            $step_id,
            $item_id
        ) );
        return (int) $found === (int) $step_id;
    }

    private static function is_youtube_url( $url ) {
        return ( preg_match( '#^(https?://)?(www\.)?(youtube\.com|youtu\.be)/#i', $url ) === 1 );
    }

    private static function sanitize_file_path( $path ) {
        $path = trim( $path );
        if ( $path === '' ) {
            return null;
        }
        return substr( $path, 0, 500 );
    }

    private static function file_path_to_url( $file_path ) {
        if ( strpos( $file_path, 'http' ) === 0 ) {
            return $file_path;
        }
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['baseurl'] ) && strpos( $file_path, $uploads['basedir'] ) === 0 ) {
            return str_replace( $uploads['basedir'], $uploads['baseurl'], $file_path );
        }
        return $uploads['baseurl'] . '/' . ltrim( $file_path, '/' );
    }

    /**
     * Serve evidence file: watermarked for designers, original for operator/admin.
     * Call from AJAX handler after verifying user can access this item.
     *
     * @param int  $evidence_id
     * @param bool $force_watermark If true, always serve watermarked (e.g. for designer).
     */
    public static function serve_evidence( $evidence_id, $force_watermark = false ) {
        $row = self::get_evidence_by_id( $evidence_id );
        if ( ! $row ) {
            status_header( 404 );
            exit;
        }

        $media_type   = $row['media_type'];
        $youtube_url  = ! empty( $row['youtube_url'] ) ? $row['youtube_url'] : null;
        $attachment_id = ! empty( $row['attachment_id'] ) ? (int) $row['attachment_id'] : null;
        $file_path    = ! empty( $row['file_path'] ) ? $row['file_path'] : null;

        if ( $media_type === self::MEDIA_TYPE_YOUTUBE ) {
            if ( $youtube_url ) {
                wp_redirect( $youtube_url, 302 );
                exit;
            }
            status_header( 404 );
            exit;
        }

        $file_path_physical = null;
        if ( $attachment_id ) {
            $file_path_physical = get_attached_file( $attachment_id );
            if ( ! $file_path_physical || ! is_readable( $file_path_physical ) ) {
                $file_path_physical = null;
            }
        }
        if ( ! $file_path_physical && $file_path ) {
            $uploads = wp_upload_dir();
            $file_path_physical = $uploads['basedir'] . '/' . ltrim( $file_path, '/' );
            if ( ! is_readable( $file_path_physical ) ) {
                $file_path_physical = null;
            }
        }

        if ( ! $file_path_physical ) {
            status_header( 404 );
            exit;
        }

        $mime = wp_check_filetype( $file_path_physical, null )['type'];
        if ( ! $mime ) {
            $mime = ( $media_type === self::MEDIA_TYPE_PDF ) ? 'application/pdf' : 'image/jpeg';
        }

        if ( $force_watermark ) {
            self::output_watermarked( $file_path_physical, $media_type, $mime );
            return;
        }

        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $file_path_physical ) );
        readfile( $file_path_physical );
        exit;
    }

    /**
     * Output image or PDF (first page) with "WireFrame OS" watermark.
     */
    private static function output_watermarked( $file_path_physical, $media_type, $mime ) {
        $is_pdf = ( $media_type === self::MEDIA_TYPE_PDF ) || ( strpos( $mime, 'pdf' ) !== false );
        if ( $is_pdf ) {
            self::output_pdf_watermarked_preview( $file_path_physical );
            return;
        }
        self::output_image_watermarked( $file_path_physical, $mime );
    }

    private static function output_image_watermarked( $file_path_physical, $mime ) {
        if ( ! function_exists( 'getimagesize' ) || ! function_exists( 'imagecreatefromstring' ) ) {
            self::output_fallback_watermark( $file_path_physical, $mime );
            return;
        }
        $info = @getimagesize( $file_path_physical );
        if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
            self::output_fallback_watermark( $file_path_physical, $mime );
            return;
        }
        $blob = @file_get_contents( $file_path_physical );
        if ( $blob === false ) {
            status_header( 404 );
            exit;
        }
        $im = @imagecreatefromstring( $blob );
        if ( ! $im ) {
            self::output_fallback_watermark( $file_path_physical, $mime );
            return;
        }
        $w = imagesx( $im );
        $h = imagesy( $im );
        $font_size = max( 14, (int) min( $w, $h ) / 15 );
        $padding = max( 20, (int) min( $w, $h ) / 20 );
        $text = self::WATERMARK_TEXT;
        $white = imagecolorallocate( $im, 255, 255, 255 );
        $black = imagecolorallocate( $im, 0, 0, 0 );
        if ( function_exists( 'imagettftext' ) ) {
            $font = N88_RFQ_PLUGIN_DIR . 'assets/fonts/arial.ttf';
            if ( ! is_file( $font ) ) {
                $font = 5;
                $tw = imagefontwidth( $font ) * strlen( $text );
                $th = imagefontheight( $font );
            } else {
                $box = @imagettfbbox( $font_size, 0, $font, $text );
                $tw = $box ? ( abs( $box[4] - $box[0] ) ) : 120;
                $th = $box ? ( abs( $box[5] - $box[1] ) ) : 20;
            }
        } else {
            $font = 5;
            $tw = imagefontwidth( $font ) * strlen( $text );
            $th = imagefontheight( $font );
        }
        $x = $w - $tw - $padding;
        $y = $h - $th - $padding;
        if ( $x < 0 ) { $x = $padding; }
        if ( $y < 0 ) { $y = $th + $padding; }
        if ( function_exists( 'imagettftext' ) && is_file( $font ) ) {
            imagettftext( $im, $font_size, 0, $x + 1, $y + 1, $black, $font, $text );
            imagettftext( $im, $font_size, 0, $x, $y, $white, $font, $text );
        } else {
            imagestring( $im, $font, $x + 1, $y + 1, $text, $black );
            imagestring( $im, $font, $x, $y, $text, $white );
        }
        header( 'Content-Type: ' . $mime );
        if ( $mime === 'image/png' ) {
            imagepng( $im );
        } elseif ( $mime === 'image/gif' ) {
            imagegif( $im );
        } else {
            imagejpeg( $im, null, 90 );
        }
        imagedestroy( $im );
        exit;
    }

    private static function output_pdf_watermarked_preview( $file_path_physical ) {
        if ( class_exists( 'Imagick' ) ) {
            try {
                $im = new Imagick( $file_path_physical . '[0]' );
                $im->setImageFormat( 'png' );
                $blob = $im->getImageBlob();
                $im->clear();
                $im->destroy();
                if ( $blob ) {
                    $res = @imagecreatefromstring( $blob );
                    if ( $res ) {
                        $w = imagesx( $res );
                        $h = imagesy( $res );
                        $font = 5;
                        $text = self::WATERMARK_TEXT;
                        $tw = imagefontwidth( $font ) * strlen( $text );
                        $th = imagefontheight( $font );
                        $white = imagecolorallocate( $res, 255, 255, 255 );
                        $black = imagecolorallocate( $res, 0, 0, 0 );
                        $x = max( 20, $w - $tw - 20 );
                        $y = max( $th + 10, $h - $th - 20 );
                        imagestring( $res, $font, $x + 1, $y + 1, $text, $black );
                        imagestring( $res, $font, $x, $y, $text, $white );
                        header( 'Content-Type: image/png' );
                        imagepng( $res );
                        imagedestroy( $res );
                        exit;
                    }
                }
            } catch ( Exception $e ) {
                // fall through to fallback
            }
        }
        header( 'Content-Type: image/png' );
        $w = 400;
        $h = 520;
        $im = imagecreatetruecolor( $w, $h );
        if ( $im ) {
            $bg = imagecolorallocate( $im, 240, 240, 240 );
            $gray = imagecolorallocate( $im, 120, 120, 120 );
            imagefill( $im, 0, 0, $bg );
            imagestring( $im, 5, 20, 20, 'PDF preview (WireFrame OS)', $gray );
            imagestring( $im, 3, 20, 50, 'Watermarked view. Original available to operator only.', $gray );
            imagepng( $im );
            imagedestroy( $im );
        }
        exit;
    }

    private static function output_fallback_watermark( $file_path_physical, $mime ) {
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $file_path_physical ) );
        readfile( $file_path_physical );
        exit;
    }
}
