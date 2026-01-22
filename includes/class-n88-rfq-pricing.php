<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * N88 RFQ Pricing Calculator
 * 
 * Handles instant pricing calculations for quotes including:
 * - Unit price calculation (labor + materials + overhead + margin)
 * - Total price calculation
 * - CBM (cubic meters) volume calculation
 * - Volume-based pricing rules
 * - Lead time estimation
 */
class N88_RFQ_Pricing {

    /**
     * Calculate unit price from cost components
     *
     * @param float $labor_cost Labor cost per unit
     * @param float $materials_cost Materials cost per unit
     * @param float $overhead_cost Overhead cost per unit
     * @param float $margin_percentage Margin percentage (e.g., 15.5 for 15.5%)
     * @return float Unit price
     */
    public static function calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage ) {
        $labor_cost = (float) $labor_cost;
        $materials_cost = (float) $materials_cost;
        $overhead_cost = (float) $overhead_cost;
        $margin_percentage = (float) $margin_percentage;

        // Total cost before margin
        $total_cost = $labor_cost + $materials_cost + $overhead_cost;

        // Apply margin
        $margin_amount = $total_cost * ( $margin_percentage / 100 );
        $unit_price = $total_cost + $margin_amount;

        return round( $unit_price, 2 );
    }

    /**
     * Calculate total price
     *
     * @param float $unit_price Unit price
     * @param int $quantity Quantity
     * @return float Total price
     */
    public static function calculate_total_price( $unit_price, $quantity ) {
        $unit_price = (float) $unit_price;
        $quantity = (int) $quantity;

        return round( $unit_price * $quantity, 2 );
    }

    /**
     * Calculate CBM (Cubic Meters) from dimensions in inches
     *
     * @param float $length_in Length in inches
     * @param float $depth_in Depth in inches
     * @param float $height_in Height in inches
     * @param int $quantity Quantity
     * @return float CBM volume
     */
    public static function calculate_cbm( $length_in, $depth_in, $height_in, $quantity = 1 ) {
        $length_in = (float) $length_in;
        $depth_in = (float) $depth_in;
        $height_in = (float) $height_in;
        $quantity = (int) $quantity;

        // Convert inches to meters (1 inch = 0.0254 meters)
        $length_m = $length_in * 0.0254;
        $depth_m = $depth_in * 0.0254;
        $height_m = $height_in * 0.0254;

        // Calculate volume in cubic meters
        $cbm_per_unit = $length_m * $depth_m * $height_m;
        $total_cbm = $cbm_per_unit * $quantity;

        return round( $total_cbm, 4 );
    }

    /**
     * Apply volume-based pricing rules
     *
     * @param float $base_unit_price Base unit price
     * @param float $total_cbm Total CBM volume
     * @param int $quantity Total quantity
     * @return array Array with adjusted_price and rules_applied
     */
    public static function apply_volume_rules( $base_unit_price, $total_cbm, $quantity ) {
        $base_unit_price = (float) $base_unit_price;
        $total_cbm = (float) $total_cbm;
        $quantity = (int) $quantity;

        $adjusted_price = $base_unit_price;
        $rules_applied = array();

        // Rule 1: Volume discount for large orders (CBM > 10)
        if ( $total_cbm > 10 ) {
            $discount_percentage = min( 15, ( $total_cbm - 10 ) * 0.5 ); // Max 15% discount
            $discount_amount = $base_unit_price * ( $discount_percentage / 100 );
            $adjusted_price = $base_unit_price - $discount_amount;
            $rules_applied[] = sprintf( 'Volume discount: %.1f%% (CBM: %.2f)', $discount_percentage, $total_cbm );
        }

        // Rule 2: Quantity discount for bulk orders (quantity >= 10)
        if ( $quantity >= 10 ) {
            $qty_discount = min( 10, ( $quantity - 10 ) * 0.5 ); // Max 10% discount
            $qty_discount_amount = $adjusted_price * ( $qty_discount / 100 );
            $adjusted_price = $adjusted_price - $qty_discount_amount;
            $rules_applied[] = sprintf( 'Bulk discount: %.1f%% (Qty: %d)', $qty_discount, $quantity );
        }

        // Rule 3: Small order surcharge (CBM < 1 and quantity < 5)
        if ( $total_cbm < 1 && $quantity < 5 ) {
            $surcharge_percentage = 10;
            $surcharge_amount = $adjusted_price * ( $surcharge_percentage / 100 );
            $adjusted_price = $adjusted_price + $surcharge_amount;
            $rules_applied[] = sprintf( 'Small order surcharge: %.1f%%', $surcharge_percentage );
        }

        return array(
            'adjusted_price' => round( $adjusted_price, 2 ),
            'rules_applied' => $rules_applied,
        );
    }

    /**
     * Calculate lead time based on quantity and complexity
     *
     * @param int $quantity Total quantity
     * @param float $total_cbm Total CBM volume
     * @param string $shipping_zone Shipping zone
     * @return string Lead time estimate
     */
    public static function calculate_lead_time( $quantity, $total_cbm, $shipping_zone = '' ) {
        $quantity = (int) $quantity;
        $total_cbm = (float) $total_cbm;

        // Base production time (weeks)
        $base_weeks = 2;

        // Adjust based on quantity
        if ( $quantity >= 50 ) {
            $base_weeks += 2; // Large orders take longer
        } elseif ( $quantity >= 20 ) {
            $base_weeks += 1;
        }

        // Adjust based on volume
        if ( $total_cbm > 20 ) {
            $base_weeks += 1; // Large items take longer
        }

        // Shipping time based on zone
        $shipping_weeks = 0;
        switch ( strtolower( $shipping_zone ) ) {
            case 'domestic':
            case 'local':
                $shipping_weeks = 1;
                break;
            case 'international':
            case 'overseas':
                $shipping_weeks = 3;
                break;
            case 'express':
                $shipping_weeks = 0.5;
                break;
            default:
                $shipping_weeks = 1;
        }

        $total_weeks = $base_weeks + $shipping_weeks;

        // Format as readable string
        if ( $total_weeks < 1 ) {
            return '3-5 days';
        } elseif ( $total_weeks == 1 ) {
            return '1 week';
        } elseif ( $total_weeks < 2 ) {
            return '1-2 weeks';
        } elseif ( $total_weeks < 4 ) {
            return round( $total_weeks ) . ' weeks';
        } else {
            return round( $total_weeks ) . '-' . ( round( $total_weeks ) + 1 ) . ' weeks';
        }
    }

    /**
     * Calculate pricing for all items in a project
     *
     * @param int $project_id Project ID
     * @param float $labor_cost Labor cost per unit
     * @param float $materials_cost Materials cost per unit
     * @param float $overhead_cost Overhead cost per unit
     * @param float $margin_percentage Margin percentage
     * @param string $shipping_zone Shipping zone
     * @return array Pricing summary
     */
    public static function calculate_project_pricing( $project_id, $labor_cost, $materials_cost, $overhead_cost, $margin_percentage, $shipping_zone ) {
        $projects_class = new N88_RFQ_Projects();
        $project = $projects_class->get_project_admin( $project_id );
        
        if ( ! $project ) {
            return false;
        }

        // Get project items
        $items_json = $projects_class->get_project_metadata( $project_id, 'n88_repeater_raw' );
        $items = ! empty( $items_json ) ? json_decode( $items_json, true ) : array();

        if ( empty( $items ) ) {
            return false;
        }

        $total_quantity = 0;
        $total_cbm = 0;
        $item_pricing = array();

        foreach ( $items as $index => $item ) {
            $length = isset( $item['length_in'] ) ? (float) $item['length_in'] : ( isset( $item['dimensions']['length'] ) ? (float) $item['dimensions']['length'] : 0 );
            $depth = isset( $item['depth_in'] ) ? (float) $item['depth_in'] : ( isset( $item['dimensions']['depth'] ) ? (float) $item['dimensions']['depth'] : 0 );
            $height = isset( $item['height_in'] ) ? (float) $item['height_in'] : ( isset( $item['dimensions']['height'] ) ? (float) $item['dimensions']['height'] : 0 );
            $quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

            // Calculate CBM for this item
            $item_cbm = self::calculate_cbm( $length, $depth, $height, $quantity );
            $total_cbm += $item_cbm;
            $total_quantity += $quantity;

            // Calculate base unit price
            $base_unit_price = self::calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage );

            // Apply volume rules
            $volume_result = self::apply_volume_rules( $base_unit_price, $item_cbm, $quantity );
            $adjusted_unit_price = $volume_result['adjusted_price'];

            // Calculate item total
            $item_total = self::calculate_total_price( $adjusted_unit_price, $quantity );

            $item_pricing[] = array(
                'item_index' => $index,
                'quantity' => $quantity,
                'cbm' => $item_cbm,
                'base_unit_price' => $base_unit_price,
                'adjusted_unit_price' => $adjusted_unit_price,
                'item_total' => $item_total,
                'rules_applied' => $volume_result['rules_applied'],
            );
        }

        // Calculate overall totals
        $overall_unit_price = self::calculate_unit_price( $labor_cost, $materials_cost, $overhead_cost, $margin_percentage );
        $overall_volume_result = self::apply_volume_rules( $overall_unit_price, $total_cbm, $total_quantity );
        $final_unit_price = $overall_volume_result['adjusted_price'];
        $grand_total = self::calculate_total_price( $final_unit_price, $total_quantity );

        // Calculate lead time
        $lead_time = self::calculate_lead_time( $total_quantity, $total_cbm, $shipping_zone );

        return array(
            'project_id' => $project_id,
            'labor_cost' => (float) $labor_cost,
            'materials_cost' => (float) $materials_cost,
            'overhead_cost' => (float) $overhead_cost,
            'margin_percentage' => (float) $margin_percentage,
            'shipping_zone' => $shipping_zone,
            'base_unit_price' => $overall_unit_price,
            'final_unit_price' => $final_unit_price,
            'total_quantity' => $total_quantity,
            'total_cbm' => round( $total_cbm, 4 ),
            'total_price' => $grand_total,
            'lead_time' => $lead_time,
            'volume_rules_applied' => $overall_volume_result['rules_applied'],
            'item_pricing' => $item_pricing,
        );
    }

    /**
     * Commit 2.3.10: Calculate CBM from dimensions in centimeters
     * Formula: (length_cm × width_cm × height_cm) / 1,000,000 × quantity
     *
     * @param float $length_cm Length in centimeters
     * @param float $width_cm Width in centimeters
     * @param float $height_cm Height in centimeters
     * @param int $quantity Quantity
     * @return float CBM volume
     */
    public static function calculate_cbm_from_cm( $length_cm, $width_cm, $height_cm, $quantity = 1 ) {
        $length_cm = (float) $length_cm;
        $width_cm = (float) $width_cm;
        $height_cm = (float) $height_cm;
        $quantity = (int) $quantity;

        // Calculate CBM: (length_cm × width_cm × height_cm) / 1,000,000 × quantity
        $cbm_per_unit = ( $length_cm * $width_cm * $height_cm ) / 1000000;
        $total_cbm = $cbm_per_unit * $quantity;

        return round( $total_cbm, 6 );
    }

    /**
     * Commit 2.3.10: Calculate USA door-to-door delivery cost based on CBM
     * 
     * Rule 1 — LCL (<= 14.99 CBM):
     *   - Minimum billable CBM = 1.5
     *   - Rate: $390 per CBM
     *   - Plus: $350 delivery fee
     *   Formula: max(actual_cbm, 1.5) * 390 + 350
     * 
     * Rule 2 — FCL 20' (15.00–24.00 CBM):
     *   - Fixed: $4,850
     * 
     * Rule 3 — FCL 40' HQ (> 24.00 CBM):
     *   - Fixed: $5,750
     *
     * @param float $total_cbm Total CBM volume
     * @return array Array with delivery_cost_usd and shipping_mode
     */
    public static function calculate_usa_delivery_cost( $total_cbm ) {
        $total_cbm = (float) $total_cbm;
        
        $delivery_cost_usd = 0.00;
        $shipping_mode = '';

        if ( $total_cbm <= 14.99 ) {
            // Rule 1: LCL
            $billable_cbm = max( $total_cbm, 1.5 );
            $delivery_cost_usd = ( $billable_cbm * 390 ) + 350;
            $shipping_mode = 'LCL';
        } elseif ( $total_cbm >= 15.00 && $total_cbm <= 24.00 ) {
            // Rule 2: FCL 20'
            $delivery_cost_usd = 4850;
            $shipping_mode = 'FCL_20';
        } else {
            // Rule 3: FCL 40' HQ (> 24.00 CBM)
            $delivery_cost_usd = 5750;
            $shipping_mode = 'FCL_40HQ';
        }

        return array(
            'delivery_cost_usd' => round( $delivery_cost_usd, 2 ),
            'shipping_mode' => $shipping_mode,
            'cbm' => $total_cbm
        );
    }

    /**
     * Commit 2.3.10: Calculate and store delivery cost for an item
     * 
     * This function:
     * 1. Gets item dimensions and quantity (from item meta OR delivery_context)
     * 2. Calculates CBM
     * 3. Calculates delivery cost (if USA)
     * 4. Stores results in n88_item_delivery_context
     *
     * @param int $item_id Item ID
     * @return array|false Delivery calculation result or false on error
     */
    public static function calculate_and_store_delivery_cost( $item_id ) {
        global $wpdb;
        
        $item_id = (int) $item_id;
        if ( $item_id <= 0 ) {
            error_log( 'N88 Delivery Cost Calc - ERROR: Invalid item_id' );
            return false;
        }
        
        error_log( sprintf( 'N88 Delivery Cost Calc - START: Item %d', $item_id ) );

        $items_table = $wpdb->prefix . 'n88_items';
        $delivery_context_table = $wpdb->prefix . 'n88_item_delivery_context';
        
        // Check if tables exist
        $items_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table;
        $delivery_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$delivery_context_table}'" ) === $delivery_context_table;
        
        if ( ! $items_table_exists ) {
            error_log( sprintf( 'N88 Delivery Cost Calc - ERROR: Items table does not exist' ) );
            return false;
        }
        
        if ( ! $delivery_table_exists ) {
            error_log( sprintf( 'N88 Delivery Cost Calc - ERROR: Delivery context table does not exist' ) );
            return array(
                'cbm' => null,
                'shipping_mode' => null,
                'delivery_cost_usd' => null,
                'error' => 'table_not_exists'
            );
        }

        // Step 1: Get delivery context for country (needed for calculation)
        $delivery_context = $wpdb->get_row( $wpdb->prepare(
            "SELECT delivery_country_code, dimensions_json, quantity 
            FROM {$delivery_context_table} 
            WHERE item_id = %d",
            $item_id
        ), ARRAY_A );
        
        // Step 2: Initialize variables
        $length_cm = null;
        $width_cm = null;
        $height_cm = null;
        $quantity = 1;
        $delivery_country = null;
        
        // Get country from delivery_context
        if ( $delivery_context && isset( $delivery_context['delivery_country_code'] ) ) {
            $delivery_country = strtoupper( trim( $delivery_context['delivery_country_code'] ) );
        }
        
        // Step 3: PRIORITY 1 - Get dimensions from item meta_json (LATEST/UPDATED dimensions)
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, meta_json, dimension_width_cm, dimension_depth_cm, dimension_height_cm 
            FROM {$items_table} WHERE id = %d",
            $item_id
        ), ARRAY_A );
        
        if ( $item ) {
            $meta = ! empty( $item['meta_json'] ) ? json_decode( $item['meta_json'], true ) : array();
            if ( ! is_array( $meta ) ) {
                $meta = array();
            }
            
            // Try dims_cm from meta_json (PRIORITY - this has the latest updated dimensions)
            if ( isset( $meta['dims_cm'] ) && is_array( $meta['dims_cm'] ) ) {
                $length_cm = isset( $meta['dims_cm']['w'] ) ? (float) $meta['dims_cm']['w'] : null;
                $width_cm = isset( $meta['dims_cm']['d'] ) ? (float) $meta['dims_cm']['d'] : null;
                $height_cm = isset( $meta['dims_cm']['h'] ) ? (float) $meta['dims_cm']['h'] : null;
                
                if ( isset( $meta['quantity'] ) && $meta['quantity'] > 0 ) {
                    $quantity = (int) $meta['quantity'];
                }
                
                error_log( sprintf( 
                    'N88 Delivery Cost Calc - Item %d: Got dimensions from item meta_json dims_cm (LATEST) - length_cm=%.2f, width_cm=%.2f, height_cm=%.2f, qty=%d',
                    $item_id, $length_cm, $width_cm, $height_cm, $quantity
                ) );
            }
            // Try dimension columns
            elseif ( ! empty( $item['dimension_width_cm'] ) && ! empty( $item['dimension_depth_cm'] ) && ! empty( $item['dimension_height_cm'] ) ) {
                $length_cm = (float) $item['dimension_width_cm'];
                $width_cm = (float) $item['dimension_depth_cm'];
                $height_cm = (float) $item['dimension_height_cm'];
                
                error_log( sprintf( 
                    'N88 Delivery Cost Calc - Item %d: Got dimensions from item dimension columns (LATEST)',
                    $item_id
                ) );
            }
        }
        
        // Step 4: FALLBACK - Get dimensions from delivery_context (only if not found in item)
        if ( ( $length_cm === null || $width_cm === null || $height_cm === null ) && $delivery_context && ! empty( $delivery_context['dimensions_json'] ) ) {
            $delivery_dims = json_decode( $delivery_context['dimensions_json'], true );
            
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $delivery_dims ) ) {
                if ( isset( $delivery_dims['width'] ) && isset( $delivery_dims['depth'] ) && isset( $delivery_dims['height'] ) ) {
                    $dim_unit = isset( $delivery_dims['unit'] ) ? strtolower( trim( $delivery_dims['unit'] ) ) : 'in';
                    $w = (float) $delivery_dims['width'];
                    $d = (float) $delivery_dims['depth'];
                    $h = (float) $delivery_dims['height'];
                    
                    // Convert to cm
                    switch ( $dim_unit ) {
                        case 'mm':
                            $length_cm = $w / 10;
                            $width_cm = $d / 10;
                            $height_cm = $h / 10;
                            break;
                        case 'cm':
                            $length_cm = $w;
                            $width_cm = $d;
                            $height_cm = $h;
                            break;
                        case 'm':
                            $length_cm = $w * 100;
                            $width_cm = $d * 100;
                            $height_cm = $h * 100;
                            break;
                        case 'in':
                        default:
                            $length_cm = $w * 2.54;
                            $width_cm = $d * 2.54;
                            $height_cm = $h * 2.54;
                            break;
                    }
                    
                    // Get quantity from delivery_context if not set from item
                    if ( $quantity === 1 && isset( $delivery_context['quantity'] ) && $delivery_context['quantity'] > 0 ) {
                        $quantity = (int) $delivery_context['quantity'];
                    }
                    
                    error_log( sprintf( 
                        'N88 Delivery Cost Calc - Item %d: Got dimensions from delivery_context (FALLBACK) - w=%.2f, d=%.2f, h=%.2f (%s) -> length_cm=%.2f, width_cm=%.2f, height_cm=%.2f',
                        $item_id, $w, $d, $h, $dim_unit, $length_cm, $width_cm, $height_cm
                    ) );
                }
            }
        }


        // Step 5: Validate dimensions
        if ( $length_cm === null || $width_cm === null || $height_cm === null || $length_cm <= 0 || $width_cm <= 0 || $height_cm <= 0 ) {
            error_log( sprintf( 
                'N88 Delivery Cost Calc - Item %d: ERROR - Dimensions missing or invalid (length_cm=%s, width_cm=%s, height_cm=%s)',
                $item_id, 
                $length_cm === null ? 'NULL' : $length_cm,
                $width_cm === null ? 'NULL' : $width_cm,
                $height_cm === null ? 'NULL' : $height_cm
            ) );
            
            // Clear delivery cost in database
            $columns = $wpdb->get_col( "DESCRIBE {$delivery_context_table}" );
            if ( in_array( 'cbm', $columns, true ) ) {
                $wpdb->update(
                    $delivery_context_table,
                    array(
                        'cbm' => null,
                        'shipping_mode' => null,
                        'delivery_cost_usd' => null,
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'item_id' => $item_id ),
                    array( '%f', '%s', '%f', '%s' ),
                    array( '%d' )
                );
            }
            
            return array(
                'cbm' => null,
                'shipping_mode' => null,
                'delivery_cost_usd' => null,
                'error' => 'dimensions_missing'
            );
        }

        // Step 6: Calculate CBM
        $cbm = self::calculate_cbm_from_cm( $length_cm, $width_cm, $height_cm, $quantity );
        error_log( sprintf( 
            'N88 Delivery Cost Calc - Item %d: Calculated CBM=%.6f (length=%.2f, width=%.2f, height=%.2f, qty=%d)',
            $item_id, $cbm, $length_cm, $width_cm, $height_cm, $quantity
        ) );

        // Step 7: Check if required columns exist
        $columns = $wpdb->get_col( "DESCRIBE {$delivery_context_table}" );
        $has_cbm_column = in_array( 'cbm', $columns, true );
        $has_shipping_mode_column = in_array( 'shipping_mode', $columns, true );
        $has_delivery_cost_column = in_array( 'delivery_cost_usd', $columns, true );
        
        if ( ! $has_cbm_column && ! $has_shipping_mode_column && ! $has_delivery_cost_column ) {
            error_log( sprintf( 
                'N88 Delivery Cost Calc - Item %d: ERROR - Required columns do not exist',
                $item_id
            ) );
            return array(
                'cbm' => $cbm,
                'shipping_mode' => null,
                'delivery_cost_usd' => null,
                'error' => 'columns_missing'
            );
        }

        // Step 8: Calculate delivery cost (only for US)
        $delivery_cost_usd = null;
        $shipping_mode = null;
        
        if ( $delivery_country === 'US' ) {
            $delivery_result = self::calculate_usa_delivery_cost( $cbm );
            $delivery_cost_usd = $delivery_result['delivery_cost_usd'];
            $shipping_mode = $delivery_result['shipping_mode'];
            error_log( sprintf( 
                'N88 Delivery Cost Calc - Item %d: US delivery calculated - cbm=%.6f, cost=$%.2f, mode=%s',
                $item_id, $cbm, $delivery_cost_usd, $shipping_mode
            ) );
        } else {
            error_log( sprintf( 
                'N88 Delivery Cost Calc - Item %d: Country is not US (country=%s), skipping delivery cost calculation',
                $item_id, $delivery_country ? $delivery_country : 'NULL'
            ) );
        }

        // Step 9: Update database
        $update_data = array();
        $update_format = array();

        if ( $has_cbm_column ) {
            $update_data['cbm'] = $cbm;
            $update_format[] = '%f';
        }

        if ( $has_shipping_mode_column ) {
            $update_data['shipping_mode'] = $shipping_mode;
            $update_format[] = '%s';
        }

        if ( $has_delivery_cost_column ) {
            $update_data['delivery_cost_usd'] = $delivery_cost_usd;
            $update_format[] = '%f';
        }

        // Add updated_at if column exists
        if ( in_array( 'updated_at', $columns, true ) ) {
            $update_data['updated_at'] = current_time( 'mysql' );
            $update_format[] = '%s';
        }

        if ( ! empty( $update_data ) ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT item_id FROM {$delivery_context_table} WHERE item_id = %d",
                $item_id
            ) );
            
            if ( $existing ) {
                // Update existing record
                $update_result = $wpdb->update(
                    $delivery_context_table,
                    $update_data,
                    array( 'item_id' => $item_id ),
                    $update_format,
                    array( '%d' )
                );
                
                if ( $update_result === false ) {
                    error_log( sprintf( 
                        'N88 Delivery Cost Calc - Item %d: UPDATE FAILED - %s',
                        $item_id, $wpdb->last_error
                    ) );
                } else {
                    error_log( sprintf( 
                        'N88 Delivery Cost Calc - Item %d: UPDATE SUCCESS - %d rows updated, data=%s',
                        $item_id, $update_result, wp_json_encode( $update_data )
                    ) );
                }
            } else {
                // Insert new record (should not happen, but handle it)
                $insert_data = array_merge(
                    $update_data,
                    array(
                        'item_id' => $item_id,
                        'delivery_country_code' => $delivery_country ? $delivery_country : 'US',
                        'shipping_estimate_mode' => 'auto',
                    )
                );
                $insert_format = array_merge( array( '%d' ), $update_format, array( '%s', '%s' ) );
                
                $insert_result = $wpdb->insert( $delivery_context_table, $insert_data, $insert_format );
                
                if ( $insert_result === false ) {
                    error_log( sprintf( 
                        'N88 Delivery Cost Calc - Item %d: INSERT FAILED - %s',
                        $item_id, $wpdb->last_error
                    ) );
                } else {
                    error_log( sprintf( 
                        'N88 Delivery Cost Calc - Item %d: INSERT SUCCESS - data=%s',
                        $item_id, wp_json_encode( $insert_data )
                    ) );
                }
            }
        }

        // Step 10: Return result
        $result = array(
            'cbm' => $cbm,
            'shipping_mode' => $shipping_mode,
            'delivery_cost_usd' => $delivery_cost_usd,
        );
        
        error_log( sprintf( 
            'N88 Delivery Cost Calc - END: Item %d, Result=%s',
            $item_id, wp_json_encode( $result )
        ) );
        
        return $result;
    }
}

