<?php
/**
 * Plugin Name:       Tag Manager for SureCart
 * Description:       Map SureCart price IDs to FluentCRM tags and assign tags on purchase.
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.12
 * Author:            Reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rup-crm-tag-mapper
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RUP_CRM_TM_OPTION_ENABLED',  'rup_crm_tm_enabled' );
define( 'RUP_CRM_TM_OPTION_MAPPINGS', 'rup_crm_tm_mappings' );
define('RUP_CRM_TM_VERSION', '1.0.12');

// Always ensure there's at least one blank mapping
function rup_crm_tm_get_mappings() {
    $raw = get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' );
    $maps = json_decode( $raw, true );
    if ( ! is_array( $maps ) || empty( $maps ) ) {
        $maps = [
            [ 'name'=>'', 'price_id'=>'', 'tags'=>[], 'enabled'=>'1' ]
        ];
        update_option( RUP_CRM_TM_OPTION_MAPPINGS, wp_json_encode( $maps ) );
    }
    return $maps;
}

/**
 * Register settings
 */
add_action( 'admin_init', function(){
    register_setting( 'rup_crm_tm_group', RUP_CRM_TM_OPTION_ENABLED, [
        'type'              => 'string',
        'sanitize_callback' => fn( $v ) => $v === '1' ? '1' : '0',
        'default'           => '0',
    ] );
    register_setting( 'rup_crm_tm_group', RUP_CRM_TM_OPTION_MAPPINGS, [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) {
            if ( is_string( $v ) && json_decode( $v, true ) !== null ) {
                return $v;
            }
            if ( is_array( $v ) ) {
                return wp_json_encode( array_map( function( $map ){
                    return [
                        'name'     => sanitize_text_field( $map['name'] ?? '' ),
                        'price_id' => sanitize_text_field( $map['price_id'] ?? '' ),
                        'tags'     => array_values( array_map( 'sanitize_text_field', (array) ( $map['tags'] ?? [] ) ) ),
                        'enabled'  => ( isset( $map['enabled'] ) && $map['enabled'] === '1' ) ? '1' : '0',
                    ];
                }, $v ) );
            }
            return '[]';
        },
        'default'           => '[]',
    ] );
});

/**
 * Add submenu
 */
add_action( 'admin_menu', function(){
    add_submenu_page(
        'sc-onboarding-checklist',
        'CRM Tag Mapper',
        'CRM Tag Mapper',
        'manage_sc_shop_settings',
        'rup-crm-tag-mapper',
        'rup_crm_tm_render_admin_page'
    );
}, 100 );

/**
 * Enqueue Select2
 */
add_action( 'admin_enqueue_scripts', function(){
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'rup-crm-tag-mapper' ) {
        return;
    }
    wp_enqueue_style(  'select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' );
    wp_enqueue_script( 'select2-js',  'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js', [ 'jquery' ], null, true );
    wp_add_inline_script( 'select2-js', "
jQuery(function($){
  $('.rup-crm-tag-select').select2({
    placeholder: 'Search & select tags…',
    width: '100%',
    allowClear: true,
    minimumResultsForSearch: 0
  });
});
" );
});

/**
 * Render settings page and handle add/delete via PHP
 */
function rup_crm_tm_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // load & guarantee at least one
    $maps = rup_crm_tm_get_mappings();

    // handle Add
    if ( isset( $_GET['add_mapping'], $_GET['_wpnonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rup_crm_tm_add' ) ) {
            $maps[] = [ 'name'=>'', 'price_id'=>'', 'tags'=>[], 'enabled'=>'1' ];
            update_option( RUP_CRM_TM_OPTION_MAPPINGS, wp_json_encode( $maps ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=rup-crm-tag-mapper' ) );
        exit;
    }

    // handle Delete
    if ( isset( $_GET['delete_mapping'], $_GET['_wpnonce'] ) ) {
        $i = absint( $_GET['delete_mapping'] );
        if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rup_crm_tm_delete_' . $i ) ) {
            if ( isset( $maps[ $i ] ) ) {
                array_splice( $maps, $i, 1 );
                // after deletion, re-add blank if empty
                if ( empty( $maps ) ) {
                    $maps = [ [ 'name'=>'','price_id'=>'','tags'=>[],'enabled'=>'1' ] ];
                }
                update_option( RUP_CRM_TM_OPTION_MAPPINGS, wp_json_encode( $maps ) );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=rup-crm-tag-mapper' ) );
        exit;
    }

    // After a normal POST save, WP will redirect back here; re-load maps
    $enabled = get_option( RUP_CRM_TM_OPTION_ENABLED, '0' );
    $maps    = rup_crm_tm_get_mappings();

    // fetch FluentCRM tags
    $tags = [];
    if ( function_exists( 'FluentCrmApi' ) ) {
        try {
            $tags = FluentCrmApi( 'tags' )->all();
        } catch ( Exception $e ) {
            $tags = [];
        }
    }
    ?>
    <div class="wrap">
      <h1>CRM Tag Mapper</h1>
      <form method="post" action="options.php">
        <?php settings_fields( 'rup_crm_tm_group' ); ?>

        <table class="form-table">
          <tr>
            <th scope="row">Enable Tag Mapping</th>
            <td>
              <input type="checkbox"
                     name="<?php echo esc_attr(RUP_CRM_TM_OPTION_ENABLED);?>"
                     value="1"<?php checked( $enabled, '1' );?>>
            </td>
          </tr>

          <tr valign="top">
            <th scope="row">Mappings</th>
            <td id="rup-crm-tm-mappings">
              <?php foreach ( $maps as $i => $m ) : ?>
                <div class="rup-mapping-row">
                  <label>
                    <strong>Name</strong><br>
                    <input type="text" style="width:100%;"
                           name="<?php echo esc_attr(RUP_CRM_TM_OPTION_MAPPINGS);?>[<?php echo $i;?>][name]"
                           value="<?php echo esc_attr($m['name']);?>">
                  </label>
                  <label>
                    <strong>Price ID</strong><br>
                    <input type="text" style="width:100%;"
                           name="<?php echo esc_attr(RUP_CRM_TM_OPTION_MAPPINGS);?>[<?php echo $i;?>][price_id]"
                           value="<?php echo esc_attr($m['price_id']);?>">
                  </label>
                  <label>
                    <strong>Tags</strong><br>
                    <select class="rup-crm-tag-select"
                            name="<?php echo esc_attr(RUP_CRM_TM_OPTION_MAPPINGS);?>[<?php echo $i;?>][tags][]"
                            multiple="multiple" style="width:100%;">
                      <?php foreach ( $tags as $t ) : ?>
                        <option value="<?php echo esc_attr($t->slug);?>"
                          <?php selected( in_array( $t->slug, (array)$m['tags'], true ) );?>>
                          <?php echo esc_html( $t->title );?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label style="flex:0 0 auto; display:block; margin-top:1.5em;">
                    <input type="checkbox"
                           name="<?php echo esc_attr(RUP_CRM_TM_OPTION_MAPPINGS);?>[<?php echo $i;?>][enabled]"
                           value="1"<?php checked( $m['enabled'], '1' );?>>
                    Enabled
                  </label>
                  <?php
                    $del_nonce = wp_create_nonce( 'rup_crm_tm_delete_' . $i );
                    $del_url   = add_query_arg( [
                      'page'           => 'rup-crm-tag-mapper',
                      'delete_mapping' => $i,
                      '_wpnonce'       => $del_nonce
                    ], admin_url( 'admin.php' ) );
                  ?>
                  <a href="<?php echo esc_url( $del_url );?>"
                     class="button-link delete-mapping"
                     onclick="return confirm('Delete this mapping?');"
                     style="color:#a00; align-self:flex-start;margin-left:1rem;">
                    Delete
                  </a>
                </div>
              <?php endforeach; ?>

              <p style="margin-top:1em;">
                <?php
                  $add_nonce = wp_create_nonce( 'rup_crm_tm_add' );
                  $add_url   = add_query_arg( [
                    'page'         => 'rup-crm-tag-mapper',
                    'add_mapping'  => '1',
                    '_wpnonce'     => $add_nonce
                  ], admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $add_url );?>" class="button">Add Mapping</a>
              </p>
            </td>
          </tr>
        </table>

        <?php submit_button(); ?>
      </form>
    </div>

    <style>
      #rup-crm-tm-mappings .rup-mapping-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: .75rem;
        border: 1px solid #ddd;
        border-radius: 4px;
      }
      #rup-crm-tm-mappings .rup-mapping-row label {
        flex: 1 1 200px;
        margin: 0;
      }

      #rup-crm-tm-mappings {
          max-height: 600px;
          overflow-y: auto;
          padding: 1em;
          background: #fafafa;
          border: 1px solid #eee;
        }
    </style>
    <?php
}

/**
 * On purchase (or other event), apply tags via FluentCRM
 *
 * @param object $checkout  The SureCart checkout/subscription object.
 */
function rup_crm_apply_fluentcrm_tags( $checkout ) {
    if ( get_option( RUP_CRM_TM_OPTION_ENABLED ) !== '1' || ! function_exists( 'FluentCrmApi' ) ) {
        return;
    }

    $maps = json_decode( get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' ), true );
    if ( empty( $maps ) ) {
        return;
    }

    // 1) Remove all mappings that aren't enabled
    $maps = array_filter( $maps, fn( $m ) => ! empty( $m['enabled'] ) && $m['enabled'] === '1' );

    $email = $checkout->email ?? '';
    if ( ! $email ) {
        return;
    }

    // 2) Loop through items and only match price_id (every $m here is enabled)
    $to_apply = [];
    foreach ( $checkout->line_items->data as $item ) {
        foreach ( $maps as $m ) {
            if ( $m['price_id'] === $item->price_id ) {
                foreach ( (array) $m['tags'] as $slug ) {
                    if ( $slug ) {
                        $to_apply[] = $slug;
                    }
                }
            }
        }
    }

    $to_apply = array_values( array_unique( $to_apply ) );
    if ( empty( $to_apply ) ) {
        return;
    }

    // 3) Push to FluentCRM
    $data = [
        'email'      => $email,
        'first_name' => $checkout->first_name ?? '',
        'last_name'  => $checkout->last_name  ?? '',
        'status'     => 'subscribed',
        'tags'       => $to_apply,
    ];

    try {
        $contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
        if ( $contact && $contact->status === 'pending' ) {
            $contact->sendDoubleOptinEmail();
        }
    } catch ( Exception $e ) {
        rup_crm_tm_debuglog( 'FluentCRM error: ' . $e->getMessage() );
    }
}

// Hook it to SureCart’s checkout confirmation
add_action( 'surecart/checkout_confirmed', 'rup_crm_apply_fluentcrm_tags', 10, 1 );

// Also hook it to your second event
add_action( 'surelywp_tk_lm_on_new_order_create', 'rup_crm_apply_fluentcrm_tags', 10, 1 );

// ──────────────────────────────────────────────────────────────────────────
//  Updater bootstrap (plugins_loaded priority 1):
// ──────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // 1) Load our universal drop-in. Because that file begins with "namespace UUPD\V1;",
    //    both the class and the helper live under UUPD\V1.
    require_once __DIR__ . '/inc/updater.php';

    // 2) Build a single $updater_config array:
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),             // e.g. "simply-static-export-notify/simply-static-export-notify.php"
        'slug'        => 'rup-crm-tag-mapper',           // must match your updater‐server slug
        'name'        => 'Tag Manager for SureCart',         // human‐readable plugin name
        'version'     => RUP_CRM_TM_VERSION, // same as the VERSION constant above
        'key'         => 'CeW5jUv66xCMVZd83QTema',                 // your secret key for private updater
        'server'      => 'https://raw.githubusercontent.com/stingray82/Tag-Manager-for-SureCart/main/uupd/index.json',
        // 'textdomain' is omitted, so the helper will automatically use 'slug'
        //'textdomain'  => 'rup-crm-tag-mapper',           // used to translate “Check for updates”
    ];

    // 3) Call the helper in the UUPD\V1 namespace:
    \RUP\Updater\Updater_V1::register( $updater_config );
}, 20 );

// MainWP Icon Filter
add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {

    if ('rup-crm-tag-mapper/rup-crm-tag-mapper.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/Tag-Manager-for-SureCart/main/uupd/icon-128.png'; // Supported types: jpeg, jpg, gif, ico, png
    }

    return $info;

}, 10, 2);