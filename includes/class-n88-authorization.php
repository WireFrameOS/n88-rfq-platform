<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Authorization and Ownership Helpers
 * 
 * Phase 1.1: Core Infrastructure
 * 
 * This class provides server-side ownership checks and authorization helpers.
 * All ownership checks happen in SQL WHERE clauses - never trust incoming IDs.
 */
class N88_Authorization {

    /**
     * Get an item for a specific user (ownership check in WHERE clause).
     * 
     * Returns the item only if:
     * - The user owns the item (owner_user_id matches), OR
     * - The user is an admin (manage_options capability)
     * 
     * @param int $item_id Item ID
     * @param int $user_id User ID (typically from get_current_user_id())
     * @return object|null Item row object if found and authorized, null otherwise
     */
    public static function get_item_for_user( $item_id, $user_id ) {
        global $wpdb;

        $item_id = absint( $item_id );
        $user_id = absint( $user_id );

        if ( $item_id === 0 || $user_id === 0 ) {
            return null;
        }

        $table = $wpdb->prefix . 'n88_items';

        // Admin override: admins can access any item
        if ( current_user_can( 'manage_options' ) ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                    $item_id
                )
            );
        }

        // Ownership check in WHERE clause - never trust incoming IDs
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND owner_user_id = %d AND deleted_at IS NULL",
                $item_id,
                $user_id
            )
        );
    }

    /**
     * Get a board for a specific user (ownership check in WHERE clause).
     * 
     * Returns the board only if:
     * - The user owns the board (owner_user_id matches), OR
     * - The user is an admin (manage_options capability), OR
     * - Commit 2.6.1: The user is a team member of the firm that owns the board
     * 
     * @param int $board_id Board ID
     * @param int $user_id User ID (typically from get_current_user_id())
     * @return object|null Board row object if found and authorized, null otherwise
     */
    public static function get_board_for_user( $board_id, $user_id ) {
        global $wpdb;

        $board_id = absint( $board_id );
        $user_id = absint( $user_id );

        if ( $board_id === 0 || $user_id === 0 ) {
            return null;
        }

        $table = $wpdb->prefix . 'n88_boards';

        // Admin override: admins can access any board
        if ( current_user_can( 'manage_options' ) ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                    $board_id
                )
            );
        }

        // First check: Direct ownership
        $board = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND owner_user_id = %d AND deleted_at IS NULL",
                $board_id,
                $user_id
            )
        );

        if ( $board ) {
            return $board;
        }

        // Commit 2.6.1: Check if user is a team member of the firm that owns the board
        $firm_members_table = $wpdb->prefix . 'n88_firm_members';
        
        // Check if firm_members table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$firm_members_table}'" ) === $firm_members_table ) {
            // First, try to get board's firm_id from owner_firm_id column
            $board_firm = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT owner_firm_id, owner_user_id FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                    $board_id
                ),
                ARRAY_A
            );

            $firm_id = null;
            
            // Method 1: Use owner_firm_id if it's set on the board
            if ( $board_firm && ! empty( $board_firm['owner_firm_id'] ) ) {
                $firm_id = intval( $board_firm['owner_firm_id'] );
            }
            // Method 2: If owner_firm_id is NULL, get firm_id from board owner's firm membership
            else if ( $board_firm && ! empty( $board_firm['owner_user_id'] ) ) {
                $board_owner_id = intval( $board_firm['owner_user_id'] );
                $owner_firm = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT firm_id FROM {$firm_members_table} 
                        WHERE user_id = %d 
                        AND status = 'active' 
                        AND left_at IS NULL 
                        LIMIT 1",
                        $board_owner_id
                    ),
                    ARRAY_A
                );
                
                if ( $owner_firm && ! empty( $owner_firm['firm_id'] ) ) {
                    $firm_id = intval( $owner_firm['firm_id'] );
                }
            }

            // If we found a firm_id, check if current user is an active member of that firm
            if ( $firm_id ) {
                $firm_member = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$firm_members_table} 
                        WHERE firm_id = %d 
                        AND user_id = %d 
                        AND status = 'active' 
                        AND left_at IS NULL 
                        LIMIT 1",
                        $firm_id,
                        $user_id
                    ),
                    ARRAY_A
                );

                if ( $firm_member ) {
                    // User is a team member - return the board (view-only access)
                    return $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                            $board_id
                        )
                    );
                }
            }
        }

        return null;
    }

    /**
     * Check if a user can edit an item.
     * 
     * Commit 2.6.1: Team members (view-only) cannot edit items
     * 
     * @param int $user_id User ID
     * @param int $item_id Item ID
     * @return bool True if user can edit, false otherwise
     */
    public static function can_edit_item( $user_id, $item_id ) {
        // Commit 2.6.1: Check if user is a view-only team member
        if ( N88_RFQ_Auth::is_view_only_team_member( $user_id ) ) {
            return false;
        }

        // User must own the item
        return self::is_item_owner( $user_id, $item_id );
    }

    /**
     * Check if a user can edit a board.
     * 
     * Commit 2.6.1: Team members (view-only) cannot edit boards
     * 
     * @param int $user_id User ID
     * @param int $board_id Board ID
     * @return bool True if user can edit, false otherwise
     */
    public static function can_edit_board( $user_id, $board_id ) {
        // Commit 2.6.1: Check if user is a view-only team member
        if ( N88_RFQ_Auth::is_view_only_team_member( $user_id ) ) {
            return false;
        }

        // User must own the board (not just view it as team member)
        return self::is_board_owner( $user_id, $board_id );
    }

    /**
     * Check if a user is a platform admin.
     * 
     * @param int $user_id User ID (optional, defaults to current user)
     * @return bool True if user has manage_options capability
     */
    public static function is_platform_admin( $user_id = 0 ) {
        if ( $user_id === 0 ) {
            $user_id = get_current_user_id();
        }
        
        if ( $user_id === 0 ) {
            return false;
        }

        // Check capability for the specific user
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }

        return $user->has_cap( 'manage_options' );
    }

    /**
     * Get item owner user ID.
     * 
     * @param int $item_id Item ID
     * @return int|null Owner user ID, or null if item not found
     */
    public static function get_item_owner( $item_id ) {
        global $wpdb;

        $item_id = absint( $item_id );
        if ( $item_id === 0 ) {
            return null;
        }

        $table = $wpdb->prefix . 'n88_items';
        $owner_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT owner_user_id FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $item_id
            )
        );

        return $owner_id ? absint( $owner_id ) : null;
    }

    /**
     * Get board owner user ID.
     * 
     * @param int $board_id Board ID
     * @return int|null Owner user ID, or null if board not found
     */
    public static function get_board_owner( $board_id ) {
        global $wpdb;

        $board_id = absint( $board_id );
        if ( $board_id === 0 ) {
            return null;
        }

        $table = $wpdb->prefix . 'n88_boards';
        $owner_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT owner_user_id FROM {$table} WHERE id = %d AND deleted_at IS NULL",
                $board_id
            )
        );

        return $owner_id ? absint( $owner_id ) : null;
    }

    /**
     * Check if a user owns an item.
     * 
     * @param int $user_id User ID
     * @param int $item_id Item ID
     * @return bool True if user owns the item, false otherwise
     */
    public static function is_item_owner( $user_id, $item_id ) {
        $owner_id = self::get_item_owner( $item_id );
        return $owner_id !== null && (int) $owner_id === (int) $user_id;
    }

    /**
     * Check if a user owns a board.
     * 
     * @param int $user_id User ID
     * @param int $board_id Board ID
     * @return bool True if user owns the board, false otherwise
     */
    public static function is_board_owner( $user_id, $board_id ) {
        $owner_id = self::get_board_owner( $board_id );
        return $owner_id !== null && (int) $owner_id === (int) $user_id;
    }
}

