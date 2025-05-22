<?php
/**
 * Plugin Name:         RUP CRM Tag Mapper for SureCart
 * Description:         Map SureCart price IDs to FluentCRM tags and assign tags on purchase.
 * Tested up to:        6.8.1
 * Requires at least:   6.5
 * Requires PHP:        8.0
 * Version:             1.0.4
 * Author:              Reallyusefulplugins.com
 * Author URI:          https://reallyusefulplugins.com
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         rup-crm-tag-mapper
 * Website:             https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define('RUP_CRM_TM_MANAGER_VERSION', '1.0.4');

// Option keys
define( 'RUP_CRM_TM_OPTION_ENABLED',  'rup_crm_tm_enabled' );
define( 'RUP_CRM_TM_OPTION_MAPPINGS', 'rup_crm_tm_mappings' );

/* --------------------------------------------------
   DEBUGGING HELPER FUNCTION
   define( 'rup_crm_tm_debug', true );
-------------------------------------------------- */
function rup_crm_tm_debuglog( $message ) {
    if ( defined( 'rup_crm_tm_debug' ) && rup_crm_tm_debug === true ) {
        error_log( $message );
    }
}

/**
 * Register settings: enable checkbox and mapping repeater
 */
add_action( 'admin_init', function() {
    register_setting( 'rup_crm_tm_group', RUP_CRM_TM_OPTION_ENABLED, [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) {
            return $v === '1' ? '1' : '0';
        },
        'default'           => '0',
    ] );

    // Store mappings as JSON
    register_setting( 'rup_crm_tm_group', RUP_CRM_TM_OPTION_MAPPINGS, [
        'type'              => 'string',
        'sanitize_callback' => function( $v ) {
            // 1) If WP is loading the existing option (JSON string), just return it untouched
            if ( is_string( $v ) && null !== json_decode( $v, true ) ) {
                return $v;
            }
            // 2) If WP is sanitizing the new form POST (array), encode it
            if ( is_array( $v ) ) {
                return wp_json_encode( array_map( function( $map ) {
                    return [
                        'price_id' => sanitize_text_field( $map['price_id'] ?? '' ),
                        'tag'      => sanitize_text_field( $map['tag'] ?? '' ),
                        'enabled'  => ( isset( $map['enabled'] ) && $map['enabled'] === '1' ) ? '1' : '0',
                    ];
                }, $v ) );
            }
            // 3) Anything else: empty list
            return '[]';
        },
        'default'           => '[]',
    ] );
} );


/**
 * Add settings page under Settings menu
 */
add_action( 'admin_menu', function() {
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
 * Render admin settings page
 */
function rup_crm_tm_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $enabled  = get_option( RUP_CRM_TM_OPTION_ENABLED, '0' );
    $json     = get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' );
    $mappings = json_decode( $json, true );

    if ( ! is_array( $mappings ) ) {
        $mappings = [];
    }

    if ( empty( $mappings ) ) {
        $mappings = [ [ 'price_id' => '', 'tag' => '', 'enabled' => '1' ] ];
    }

    // Fetch FluentCRM tags
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
                               name="<?php echo esc_attr( RUP_CRM_TM_OPTION_ENABLED ); ?>"
                               value="1"
                            <?php checked( $enabled, '1' ); ?> />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Price ID → Tag Mappings</th>
                    <td id="rup-crm-tm-mappings">
                        <?php foreach ( $mappings as $index => $map ) : ?>
                            <div class="rup-mapping-row">
                                <label>Price ID:
                                    <input type="text"
                                           name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo $index; ?>][price_id]"
                                           value="<?php echo esc_attr( $map['price_id'] ); ?>" />
                                </label>
                                <label>Tag:
                                    <select name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo $index; ?>][tag]">
                                        <option value="">&mdash; Select Tag &mdash;</option>
                                        <?php foreach ( $tags as $tag ) : ?>
                                            <option value="<?php echo esc_attr( $tag->slug ); ?>"
                                                <?php selected( $map['tag'], $tag->slug ); ?>>
                                                <?php echo esc_html( $tag->title ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo $index; ?>][enabled]"
                                           value="1"
                                        <?php checked( $map['enabled'], '1' ); ?> />
                                    Enabled
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <p><button type="button" class="button" id="rup-crm-tm-add">Add Mapping</button></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>

    <script>
    (function(){
        var container = document.getElementById('rup-crm-tm-mappings');
        var btn       = document.getElementById('rup-crm-tm-add');

        btn.addEventListener('click', function(){
            var rows      = container.querySelectorAll('.rup-mapping-row');
            if (!rows.length) return;
            var last      = rows[rows.length - 1];
            var clone     = last.cloneNode(true);
            var newIndex  = rows.length;

            clone.querySelectorAll('input, select').forEach(function(el){
                // rename the field name index
                var name = el.getAttribute('name')
                            .replace(/\[\d+\]/, '[' + newIndex + ']');
                el.setAttribute('name', name);

                if (el.tagName === 'INPUT') {
                    if (el.type === 'text')     el.value   = '';
                    if (el.type === 'checkbox') el.checked = true;
                }

                // reset any select back to the placeholder
                if (el.tagName === 'SELECT') {
                    el.selectedIndex = 0;
                    el.value         = '';
                }
            });

            container.insertBefore(clone, btn.parentNode);
        });
    })();
    </script>
    <?php
}

/**
 * Handle SureCart checkout: map price IDs to tags and update FluentCRM
 */
add_action( 'surecart/checkout_confirmed', function( $checkout, $request ) {
    if ( get_option( RUP_CRM_TM_OPTION_ENABLED ) !== '1' ) {
        return;
    }

    $json     = get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' );
    $mappings = json_decode( $json, true );

    if ( empty( $mappings ) || ! function_exists( 'FluentCrmApi' ) ) {
        return;
    }

    $email      = $checkout->email ?? '';
    $first_name = $checkout->first_name ?? '';
    $last_name  = $checkout->last_name ?? '';
    $items      = is_array( $checkout->line_items->data ) ? $checkout->line_items->data : [];

    $tags_to_apply = [];
    foreach ( $items as $item ) {
        foreach ( $mappings as $map ) {
            if ( $map['enabled'] === '1' && $map['price_id'] === $item->price_id && ! empty( $map['tag'] ) ) {
                $tags_to_apply[] = $map['tag'];
                rup_crm_tm_debuglog("Matched price {$item->price_id}, applying tag {$map['tag']}");
            }
        }
    }

    if ( empty( $tags_to_apply ) || ! $email ) {
        return;
    }

    $data = [
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'status'     => 'subscribed',
        'tags'       => array_values( array_unique( $tags_to_apply ) )
    ];

    try {
        $subscriber = FluentCrmApi('contacts')->createOrUpdate($data);
        if ( $subscriber && $subscriber->status === 'pending' ) {
            $subscriber->sendDoubleOptinEmail();
        }
    } catch ( Exception $e ) {
        rup_crm_tm_debuglog( 'FluentCRM error: ' . $e->getMessage() );
    }
}, 10, 2 );



add_action( 'plugins_loaded', function() {
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'rup-crm-tag-mapper',
        'name'        => 'rup-crm-tag-mapper',
        'version'     => RUP_CRM_TM_MANAGER_VERSION,
        'key'         => 'CeW5jUv66xCMVZd83QTema',
        'server'      => 'https://updater.reallyusefulplugins.com/u/',
    ];

    require_once __DIR__ . '/inc/updater.php';
    $updater = new \UUPD\V1\UUPD_Updater_V1( $updater_config  );
} );