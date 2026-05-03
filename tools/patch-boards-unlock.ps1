$ErrorActionPreference = 'Stop'
$p = 'd:\n88-rfq-plugin\n88-rfq-plugin\includes\class-n88-boards.php'
$c = [IO.File]::ReadAllText($p)
$marker = '            // Designer deleting: remove from ALL boards (designer, supplier, operator) and soft-delete the item'
$ix = $c.IndexOf($marker)
if ($ix -lt 0) { throw 'MARKER_NOT_FOUND' }

$inject = @'
            if ( class_exists( 'N88_Item_Unlock' ) ) {
                $pre_item = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT project_id, meta_json FROM {$items_table} WHERE id = %d AND deleted_at IS NULL",
                        $item_id
                    ),
                    ARRAY_A
                );
                if ( is_array( $pre_item ) ) {
                    N88_Item_Unlock::notify_full_process_item_deleted(
                        $item_id,
                        $user_id,
                        isset( $pre_item['project_id'] ) ? absint( $pre_item['project_id'] ) : 0,
                        isset( $pre_item['meta_json'] ) ? $pre_item['meta_json'] : array()
                    );
                }
            }

'@
$c2 = $c.Insert($ix, $inject)
[IO.File]::WriteAllText($p, $c2)
Write-Host 'PATCHED_BOARDS_UNLOCK'
