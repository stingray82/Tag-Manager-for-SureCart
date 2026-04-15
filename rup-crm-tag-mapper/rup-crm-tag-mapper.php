<?php
/**
 * Plugin Name:       Tag Manager for SureCart
 * Description:       Map SureCart prices or whole products to FluentCRM tags and assign tags on purchase.
 * Tested up to:      6.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           2.0
 * Author:            Reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rup-crm-tag-mapper
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RUP_CRM_TM_OPTION_ENABLED', 'rup_crm_tm_enabled' );
define( 'RUP_CRM_TM_OPTION_MAPPINGS', 'rup_crm_tm_mappings' );
define('RUP_CRM_TM_VERSION', '2.0');

/**
 * Basic debug logger.
 */
function rup_crm_tm_debuglog( string $message ) : void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[rup-crm-tag-mapper] ' . $message );
	}
}

/**
 * Capability helper.
 */
function rup_crm_tm_get_capability() : string {
	return apply_filters( 'rup_crm_tm_capability', 'manage_sc_shop_settings' );
}

/**
 * Get mappings as array.
 * Ensures at least one blank row exists.
 */
function rup_crm_tm_get_mappings() : array {
	$raw  = get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' );
	$maps = json_decode( $raw, true );

	if ( ! is_array( $maps ) ) {
		$maps = [];
	}

	$clean = [];

	foreach ( $maps as $map ) {
		if ( ! is_array( $map ) ) {
			continue;
		}

		$clean[] = [
			'product_id' => sanitize_text_field( $map['product_id'] ?? '' ),
			'price_id'   => sanitize_text_field( $map['price_id'] ?? '' ),
			'tags'       => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) ( $map['tags'] ?? [] )
					)
				)
			),
			'enabled'    => ( isset( $map['enabled'] ) && '1' === (string) $map['enabled'] ) ? '1' : '0',
		];
	}

	if ( empty( $clean ) ) {
		$clean = [
			[
				'product_id' => '',
				'price_id'   => '',
				'tags'       => [],
				'enabled'    => '1',
			],
		];
		update_option( RUP_CRM_TM_OPTION_MAPPINGS, wp_json_encode( $clean ) );
	}

	return $clean;
}

/**
 * Save mappings.
 */
function rup_crm_tm_save_mappings( array $maps ) : void {
	$clean = [];

	foreach ( $maps as $map ) {
		if ( ! is_array( $map ) ) {
			continue;
		}

		$row = [
			'product_id' => sanitize_text_field( $map['product_id'] ?? '' ),
			'price_id'   => sanitize_text_field( $map['price_id'] ?? '' ),
			'tags'       => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) ( $map['tags'] ?? [] )
					)
				)
			),
			'enabled'    => ( isset( $map['enabled'] ) && '1' === (string) $map['enabled'] ) ? '1' : '0',
		];

		if ( '' === $row['product_id'] && '' === $row['price_id'] && empty( $row['tags'] ) ) {
			continue;
		}

		$clean[] = $row;
	}

	if ( empty( $clean ) ) {
		$clean[] = [
			'product_id' => '',
			'price_id'   => '',
			'tags'       => [],
			'enabled'    => '1',
		];
	}

	update_option( RUP_CRM_TM_OPTION_MAPPINGS, wp_json_encode( array_values( $clean ) ) );
}

/**
 * Get all SureCart products and their available prices.
 *
 * Returns:
 * [
 *   [
 *     'post_id'    => 123,
 *     'title'      => 'Product Name',
 *     'product_id' => 'prod_xxx',
 *     'prices'     => [
 *        [
 *          'id'             => 'price_xxx',
 *          'name'           => 'Single Site',
 *          'display_amount' => 'US$49.00',
 *        ]
 *     ]
 *   ]
 * ]
 */
function rup_crm_tm_get_products_with_prices() : array {
	$products_with_prices = [];

	$posts = get_posts(
		[
			'post_type'      => 'sc_product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		]
	);

	if ( empty( $posts ) ) {
		return $products_with_prices;
	}

	foreach ( $posts as $post ) {
		$raw = get_post_meta( $post->ID, 'product', true );

		if ( empty( $raw ) ) {
			continue;
		}

		$product = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;

		if ( empty( $product ) || ! is_array( $product ) ) {
			continue;
		}

		$product_id = isset( $product['id'] ) ? (string) $product['id'] : '';
		$prices     = [];

		if ( ! empty( $product['active_prices'] ) && is_array( $product['active_prices'] ) ) {
			$price_source = $product['active_prices'];
		} elseif (
			! empty( $product['prices'] ) &&
			is_array( $product['prices'] ) &&
			! empty( $product['prices']['data'] ) &&
			is_array( $product['prices']['data'] )
		) {
			$price_source = $product['prices']['data'];
		} else {
			$price_source = [];
		}

		foreach ( $price_source as $price ) {
			if ( ! is_array( $price ) ) {
				continue;
			}

			$price_id   = isset( $price['id'] ) ? (string) $price['id'] : '';
			$price_name = isset( $price['name'] ) ? (string) $price['name'] : '';
			$display    = isset( $price['display_amount'] ) ? (string) $price['display_amount'] : '';

			if ( ! $price_id ) {
				continue;
			}

			$prices[] = [
				'id'             => $price_id,
				'name'           => $price_name,
				'display_amount' => $display,
			];
		}

		if ( empty( $prices ) ) {
			continue;
		}

		$products_with_prices[] = [
			'post_id'    => (int) $post->ID,
			'title'      => get_the_title( $post ),
			'product_id' => $product_id,
			'prices'     => $prices,
		];
	}

	return $products_with_prices;
}

/**
 * Register settings.
 */
add_action(
	'admin_init',
	function () {
		register_setting(
			'rup_crm_tm_group',
			RUP_CRM_TM_OPTION_ENABLED,
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return '1' === (string) $v ? '1' : '0';
				},
				'default'           => '0',
			]
		);

		register_setting(
			'rup_crm_tm_group',
			RUP_CRM_TM_OPTION_MAPPINGS,
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					if ( is_string( $v ) && null !== json_decode( $v, true ) ) {
						return $v;
					}

					if ( is_array( $v ) ) {
						$clean = [];

						foreach ( $v as $map ) {
							if ( ! is_array( $map ) ) {
								continue;
							}

							$row = [
								'product_id' => sanitize_text_field( $map['product_id'] ?? '' ),
								'price_id'   => sanitize_text_field( $map['price_id'] ?? '' ),
								'tags'       => array_values(
									array_filter(
										array_map(
											'sanitize_text_field',
											(array) ( $map['tags'] ?? [] )
										)
									)
								),
								'enabled'    => ( isset( $map['enabled'] ) && '1' === (string) $map['enabled'] ) ? '1' : '0',
							];

							if ( '' === $row['product_id'] && '' === $row['price_id'] && empty( $row['tags'] ) ) {
								continue;
							}

							$clean[] = $row;
						}

						if ( empty( $clean ) ) {
							$clean[] = [
								'product_id' => '',
								'price_id'   => '',
								'tags'       => [],
								'enabled'    => '1',
							];
						}

						return wp_json_encode( $clean );
					}

					return '[]';
				},
				'default'           => '[]',
			]
		);
	}
);

/**
 * Add submenu.
 */
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'sc-dashboard',
			__( 'CRM Tag Mapper', 'rup-crm-tag-mapper' ),
			__( 'CRM Tag Mapper', 'rup-crm-tag-mapper' ),
			rup_crm_tm_get_capability(),
			'rup-crm-tag-mapper',
			'rup_crm_tm_render_admin_page'
		);
	},
	99
);

/**
 * Admin assets.
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		if ( ! isset( $_GET['page'] ) || 'rup-crm-tag-mapper' !== $_GET['page'] ) {
			return;
		}

		wp_enqueue_style(
			'select2-css',
			'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css',
			[],
			'4.0.13'
		);

		wp_enqueue_script(
			'select2-js',
			'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js',
			[ 'jquery' ],
			'4.0.13',
			true
		);
	}
);

/**
 * Render admin page.
 */
function rup_crm_tm_render_admin_page() {
	if ( ! current_user_can( rup_crm_tm_get_capability() ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$enabled     = get_option( RUP_CRM_TM_OPTION_ENABLED, '0' );
	$maps        = rup_crm_tm_get_mappings();
	$sc_products = rup_crm_tm_get_products_with_prices();

	$maps[] = [
		'product_id' => '',
		'price_id'   => '',
		'tags'       => [],
		'enabled'    => '1',
	];

	$tags = [];
	if ( function_exists( 'FluentCrmApi' ) ) {
		try {
			$tags = FluentCrmApi( 'tags' )->all();
		} catch ( Exception $e ) {
			$tags = [];
			rup_crm_tm_debuglog( 'Could not load FluentCRM tags: ' . $e->getMessage() );
		}
	}
	?>
	<div class="wrap rup-crm-wrap">
		<h1><?php esc_html_e( 'CRM Tag Mapper', 'rup-crm-tag-mapper' ); ?></h1>
		<p class="rup-crm-lead">
			<?php esc_html_e( 'Map SureCart products or prices to FluentCRM tags. You can map an individual price/version or choose all prices for a product.', 'rup-crm-tag-mapper' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'rup_crm_tm_group' ); ?>

			<div class="rup-crm-top-card">
				<div class="rup-crm-top-card__content">
					<div>
						<h2><?php esc_html_e( 'Plugin Settings', 'rup-crm-tag-mapper' ); ?></h2>
						<p><?php esc_html_e( 'Enable or disable automatic tag mapping for SureCart purchases.', 'rup-crm-tag-mapper' ); ?></p>
					</div>

					<label class="rup-crm-toggle">
						<input
							type="checkbox"
							name="<?php echo esc_attr( RUP_CRM_TM_OPTION_ENABLED ); ?>"
							value="1"
							<?php checked( $enabled, '1' ); ?>
						/>
						<span class="rup-crm-toggle__slider"></span>
						<span class="rup-crm-toggle__label">
							<?php esc_html_e( 'Enable tag mapping', 'rup-crm-tag-mapper' ); ?>
						</span>
					</label>
				</div>
			</div>

			<div class="rup-crm-mappings-card">
				<div class="rup-crm-card-header">
					<div>
						<h2><?php esc_html_e( 'Mappings', 'rup-crm-tag-mapper' ); ?></h2>
						<p><?php esc_html_e( 'Choose a product, then either one price/version or all prices for that product.', 'rup-crm-tag-mapper' ); ?></p>
					</div>

					<button type="button" class="button button-secondary" id="rup-crm-add-row-top">
						<?php esc_html_e( 'Add Mapping', 'rup-crm-tag-mapper' ); ?>
					</button>
				</div>

				<div class="rup-crm-toolbar">
					<div class="rup-crm-toolbar__search">
						<label for="rup-crm-filter-search"><?php esc_html_e( 'Search', 'rup-crm-tag-mapper' ); ?></label>
						<input type="text" id="rup-crm-filter-search" placeholder="<?php esc_attr_e( 'Filter by product, price or tag…', 'rup-crm-tag-mapper' ); ?>" />
					</div>

					<div class="rup-crm-toolbar__select">
						<label for="rup-crm-filter-status"><?php esc_html_e( 'Status', 'rup-crm-tag-mapper' ); ?></label>
						<select id="rup-crm-filter-status">
							<option value="all"><?php esc_html_e( 'All rows', 'rup-crm-tag-mapper' ); ?></option>
							<option value="enabled"><?php esc_html_e( 'Enabled only', 'rup-crm-tag-mapper' ); ?></option>
							<option value="disabled"><?php esc_html_e( 'Disabled only', 'rup-crm-tag-mapper' ); ?></option>
						</select>
					</div>

					<div class="rup-crm-toolbar__select">
						<label for="rup-crm-filter-type"><?php esc_html_e( 'Mapping Type', 'rup-crm-tag-mapper' ); ?></label>
						<select id="rup-crm-filter-type">
							<option value="all"><?php esc_html_e( 'All mappings', 'rup-crm-tag-mapper' ); ?></option>
							<option value="product"><?php esc_html_e( 'Whole product', 'rup-crm-tag-mapper' ); ?></option>
							<option value="price"><?php esc_html_e( 'Single price', 'rup-crm-tag-mapper' ); ?></option>
						</select>
					</div>
				</div>

				<div id="rup-crm-mappings-list">
					<?php foreach ( $maps as $i => $m ) : ?>
						<?php
						$product_id_val = isset( $m['product_id'] ) ? (string) $m['product_id'] : '';
						$price_id_val   = isset( $m['price_id'] ) ? (string) $m['price_id'] : '';
						$is_last_blank  = (
							'' === $product_id_val &&
							'' === $price_id_val &&
							empty( $m['tags'] ) &&
							( count( $maps ) - 1 ) === $i
						);
						?>
						<div class="rup-crm-mapping-row" data-index="<?php echo esc_attr( $i ); ?>">
							<div class="rup-crm-row-grid">
								<div class="rup-crm-field">
									<label><?php esc_html_e( 'Product', 'rup-crm-tag-mapper' ); ?></label>
									<select
										class="rup-crm-product-select"
										name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo esc_attr( $i ); ?>][product_id]"
									>
										<option value=""><?php esc_html_e( '— Select product —', 'rup-crm-tag-mapper' ); ?></option>
										<?php foreach ( $sc_products as $product ) : ?>
											<option
												value="<?php echo esc_attr( $product['product_id'] ); ?>"
												data-post-id="<?php echo esc_attr( $product['post_id'] ); ?>"
												<?php selected( $product_id_val, $product['product_id'] ); ?>
											>
												<?php echo esc_html( $product['title'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="rup-crm-field">
									<label><?php esc_html_e( 'Price / Version', 'rup-crm-tag-mapper' ); ?></label>
									<select
										class="rup-crm-price-select"
										name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo esc_attr( $i ); ?>][price_id]"
										<?php disabled( empty( $sc_products ) ); ?>
									>
										<option value=""><?php esc_html_e( '— Select price / version —', 'rup-crm-tag-mapper' ); ?></option>
									</select>
									<p class="description rup-crm-price-description"></p>
								</div>

								<div class="rup-crm-field rup-crm-field--tags">
									<label><?php esc_html_e( 'FluentCRM Tags', 'rup-crm-tag-mapper' ); ?></label>
									<select
										class="rup-crm-tag-select"
										name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo esc_attr( $i ); ?>][tags][]"
										multiple="multiple"
									>
										<?php foreach ( $tags as $t ) : ?>
											<option
												value="<?php echo esc_attr( $t->slug ); ?>"
												<?php selected( in_array( $t->slug, (array) ( $m['tags'] ?? [] ), true ) ); ?>
											>
												<?php echo esc_html( $t->title ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="rup-crm-row-actions">
								<label class="rup-crm-checkbox">
									<input
										type="checkbox"
										class="rup-crm-enabled-checkbox"
										name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[<?php echo esc_attr( $i ); ?>][enabled]"
										value="1"
										<?php checked( ( $m['enabled'] ?? '0' ), '1' ); ?>
									/>
									<span><?php esc_html_e( 'Enabled', 'rup-crm-tag-mapper' ); ?></span>
								</label>

								<?php if ( ! $is_last_blank ) : ?>
									<button type="button" class="button-link-delete rup-crm-remove-row">
										<?php esc_html_e( 'Delete', 'rup-crm-tag-mapper' ); ?>
									</button>
								<?php else : ?>
									<span class="rup-crm-row-spacer"></span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="rup-crm-card-footer">
					<button type="button" class="button button-secondary" id="rup-crm-add-row-bottom">
						<?php esc_html_e( 'Add Mapping', 'rup-crm-tag-mapper' ); ?>
					</button>
				</div>

				<p class="description rup-crm-help">
					<?php esc_html_e( 'Select a product, then choose a specific price/version or “All prices for this product”. On purchase, matching FluentCRM tags will be applied automatically.', 'rup-crm-tag-mapper' ); ?>
				</p>
			</div>

			<?php submit_button( __( 'Save Mappings', 'rup-crm-tag-mapper' ) ); ?>
		</form>
	</div>

	<style>
		.rup-crm-wrap {
			max-width: 1280px;
		}

		.rup-crm-wrap h1 {
			font-size: 28px;
			font-weight: 700;
			line-height: 1.2;
			margin-bottom: 8px;
			color: #0f172a;
		}

		.rup-crm-lead {
			font-size: 14px;
			color: #475569;
			margin-bottom: 18px;
		}

		.rup-crm-top-card,
		.rup-crm-mappings-card {
			background: #fff;
			border: 1px solid #e2e8f0;
			border-radius: 18px;
			box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05);
			margin-bottom: 20px;
		}

		.rup-crm-top-card {
			padding: 22px 24px;
		}

		.rup-crm-top-card__content {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 20px;
			flex-wrap: wrap;
		}

		.rup-crm-top-card h2,
		.rup-crm-card-header h2 {
			margin: 0 0 6px;
			font-size: 18px;
			font-weight: 700;
			color: #0f172a;
		}

		.rup-crm-top-card p,
		.rup-crm-card-header p {
			margin: 0;
			color: #64748b;
			font-size: 13px;
		}

		.rup-crm-card-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 16px;
			padding: 22px 24px 0;
			flex-wrap: wrap;
		}

		.rup-crm-toolbar {
			display: grid;
			grid-template-columns: 2fr 1fr 1fr;
			gap: 14px;
			padding: 18px 24px 0;
		}

		.rup-crm-toolbar label {
			display: block;
			font-size: 12px;
			font-weight: 600;
			color: #334155;
			margin-bottom: 7px;
		}

		.rup-crm-toolbar input,
		.rup-crm-toolbar select {
			width: 100%;
			min-height: 40px;
			border: 1px solid #cbd5e1;
			border-radius: 10px;
			background: #f8fafc;
			padding: 8px 12px;
			box-sizing: border-box;
			color: #0f172a;
		}

		#rup-crm-mappings-list {
			padding: 20px 24px 10px;
		}

		.rup-crm-mapping-row {
			border: 1px solid #e2e8f0;
			background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
			border-radius: 16px;
			padding: 18px;
			margin-bottom: 16px;
			box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04);
		}

		.rup-crm-mapping-row.rup-crm-hidden {
			display: none;
		}

		.rup-crm-row-grid {
			display: grid;
			grid-template-columns: 1.2fr 1.2fr 1.6fr;
			gap: 14px;
		}

		.rup-crm-field {
			min-width: 0;
		}

		.rup-crm-field label {
			display: block;
			font-size: 12px;
			font-weight: 600;
			color: #334155;
			margin-bottom: 7px;
		}

		.rup-crm-field input[type="text"],
		.rup-crm-field select {
			width: 100%;
			max-width: 100%;
			min-height: 40px;
			border: 1px solid #cbd5e1;
			border-radius: 10px;
			background: #f8fafc;
			padding: 8px 12px;
			box-sizing: border-box;
			color: #0f172a;
			transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
		}

		.rup-crm-field input[type="text"]:focus,
		.rup-crm-field select:focus,
		.rup-crm-toolbar input:focus,
		.rup-crm-toolbar select:focus {
			outline: none;
			border-color: #2563eb;
			background: #fff;
			box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
		}

		.rup-crm-field--tags .select2-container {
			width: 100% !important;
		}

		.rup-crm-field--tags .select2-container--default .select2-selection--multiple {
			min-height: 40px;
			border-radius: 10px;
			border: 1px solid #cbd5e1;
			background: #f8fafc;
			padding: 3px 6px;
		}

		.rup-crm-price-description {
			min-height: 18px;
			margin: 6px 0 0;
			font-size: 12px;
			color: #64748b;
		}

		.rup-crm-row-actions {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-top: 14px;
			padding-top: 12px;
			border-top: 1px solid #edf2f7;
			gap: 12px;
			flex-wrap: wrap;
		}

		.rup-crm-checkbox {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-weight: 500;
			color: #334155;
		}

		.rup-crm-help {
			padding: 0 24px 24px;
			color: #64748b;
		}

		.rup-crm-card-footer {
			padding: 0 24px 20px;
		}

		.rup-crm-toggle {
			display: inline-flex;
			align-items: center;
			gap: 12px;
			cursor: pointer;
			user-select: none;
		}

		.rup-crm-toggle input {
			position: absolute;
			opacity: 0;
			pointer-events: none;
		}

		.rup-crm-toggle__slider {
			position: relative;
			display: inline-block;
			width: 48px;
			height: 28px;
			border-radius: 999px;
			background: #cbd5e1;
			transition: background .2s ease;
			flex: 0 0 auto;
		}

		.rup-crm-toggle__slider::after {
			content: '';
			position: absolute;
			top: 3px;
			left: 3px;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			background: #fff;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.16);
			transition: transform .2s ease;
		}

		.rup-crm-toggle input:checked + .rup-crm-toggle__slider {
			background: #2563eb;
		}

		.rup-crm-toggle input:checked + .rup-crm-toggle__slider::after {
			transform: translateX(20px);
		}

		.rup-crm-toggle__label {
			font-weight: 600;
			color: #0f172a;
		}

		.rup-crm-row-spacer {
			display: inline-block;
			min-width: 1px;
			min-height: 1px;
		}

		@media (max-width: 1100px) {
			.rup-crm-toolbar {
				grid-template-columns: 1fr;
			}

			.rup-crm-row-grid {
				grid-template-columns: 1fr;
			}
		}

		@media (max-width: 640px) {
			.rup-crm-top-card__content,
			.rup-crm-card-header,
			.rup-crm-row-actions {
				flex-direction: column;
				align-items: flex-start;
			}
		}
	</style>

	<script>
		jQuery(function($) {
			const products = <?php echo wp_json_encode( $sc_products ); ?> || [];
			const productMapByProductId = {};

			products.forEach(function(product) {
				productMapByProductId[String(product.product_id)] = product;
			});

			function initTagSelect(context) {
				$(context).find('.rup-crm-tag-select').select2({
					placeholder: 'Search & select tags…',
					width: '100%',
					allowClear: true,
					minimumResultsForSearch: 0
				});
			}

			function buildPriceOptions($row, productId) {
				const $priceSelect = $row.find('.rup-crm-price-select');
				const $desc = $row.find('.rup-crm-price-description');

				$priceSelect.empty().append(
					$('<option>', {
						value: '',
						text: '— Select price / version —'
					})
				);

				$desc.text('');

				const product = productMapByProductId[String(productId)];
				if (!product || !product.prices || !product.prices.length) {
					$priceSelect.prop('disabled', true);
					return;
				}

				$priceSelect.append(
					$('<option>', {
						value: '__all__',
						text: 'All prices for this product'
					})
				);

				product.prices.forEach(function(price) {
					let label = price.name || price.id;
					if (price.display_amount) {
						label += ' (' + price.display_amount + ')';
					}

					$priceSelect.append(
						$('<option>', {
							value: price.id,
							text: label
						})
					);
				});

				$priceSelect.prop('disabled', false);
			}

			function updateDescription($row) {
				const productId = $row.find('.rup-crm-product-select').val();
				const priceId = $row.find('.rup-crm-price-select').val();
				const $desc = $row.find('.rup-crm-price-description');

				const product = productMapByProductId[String(productId)];
				if (!product || !productId) {
					$desc.text('');
					return;
				}

				if (priceId === '__all__') {
					$desc.text(product.title + ' – All prices for this product');
					return;
				}

				if (!priceId) {
					$desc.text('');
					return;
				}

				const match = (product.prices || []).find(function(price) {
					return String(price.id) === String(priceId);
				});

				if (!match) {
					$desc.text('');
					return;
				}

				let text = product.title + ' – ' + (match.name || '');
				if (match.display_amount) {
					text += ' (' + match.display_amount + ')';
				}
				$desc.text(text);
			}

			function getRowSearchText($row) {
				const productText = $row.find('.rup-crm-product-select option:selected').text() || '';
				const priceText = $row.find('.rup-crm-price-select option:selected').text() || '';
				const tagsText = $row.find('.rup-crm-tag-select option:selected').map(function() {
					return $(this).text();
				}).get().join(' ');
				return (productText + ' ' + priceText + ' ' + tagsText).toLowerCase();
			}

			function getRowType($row) {
				const priceId = $row.find('.rup-crm-price-select').val();
				if (priceId === '__all__') {
					return 'product';
				}
				if (priceId) {
					return 'price';
				}
				return 'all';
			}

			function applyFilters() {
				const search = ($('#rup-crm-filter-search').val() || '').toLowerCase().trim();
				const status = $('#rup-crm-filter-status').val();
				const type = $('#rup-crm-filter-type').val();

				$('#rup-crm-mappings-list .rup-crm-mapping-row').each(function() {
					const $row = $(this);
					const rowText = getRowSearchText($row);
					const enabled = $row.find('.rup-crm-enabled-checkbox').is(':checked');
					const rowType = getRowType($row);

					let show = true;

					if (search && rowText.indexOf(search) === -1) {
						show = false;
					}

					if (status === 'enabled' && !enabled) {
						show = false;
					}

					if (status === 'disabled' && enabled) {
						show = false;
					}

					if (type === 'product' && rowType !== 'product') {
						show = false;
					}

					if (type === 'price' && rowType !== 'price') {
						show = false;
					}

					$row.toggleClass('rup-crm-hidden', !show);
				});
			}

			function bindRow($row) {
				const $productSelect = $row.find('.rup-crm-product-select');
				const $priceSelect = $row.find('.rup-crm-price-select');

				$productSelect.off('change.rupcrm').on('change.rupcrm', function() {
					const productId = $(this).val();
					buildPriceOptions($row, productId);
					$priceSelect.val('');
					updateDescription($row);
					applyFilters();
				});

				$priceSelect.off('change.rupcrm').on('change.rupcrm', function() {
					updateDescription($row);
					applyFilters();
				});

				$row.find('.rup-crm-enabled-checkbox').off('change.rupcrm').on('change.rupcrm', function() {
					applyFilters();
				});

				$row.find('.rup-crm-tag-select').off('change.rupcrm').on('change.rupcrm', function() {
					applyFilters();
				});

				$row.find('.rup-crm-remove-row').off('click.rupcrm').on('click.rupcrm', function() {
					$row.remove();
					applyFilters();
				});

				const existingProductId = ($productSelect.val() || '').trim();
				const existingPriceId = ($priceSelect.attr('data-stored-price-id') || $priceSelect.val() || '').trim();

				if (existingProductId) {
					buildPriceOptions($row, existingProductId);

					if (existingPriceId) {
						$priceSelect.val(existingPriceId);
					}
					updateDescription($row);
				}

				initTagSelect($row);
			}

			$('#rup-crm-mappings-list .rup-crm-mapping-row').each(function() {
				const $row = $(this);
				const storedPriceId = <?php echo wp_json_encode( array_map( static function( $map ) { return (string) ( $map['price_id'] ?? '' ); }, $maps ) ); ?>[$row.data('index')] || '';
				$row.find('.rup-crm-price-select').attr('data-stored-price-id', storedPriceId);
				bindRow($row);
			});

			function addRow() {
				const nextIndex = $('#rup-crm-mappings-list .rup-crm-mapping-row').length;

				const tagsHtml = <?php
				ob_start();
				?>
				<select class="rup-crm-tag-select" name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[__INDEX__][tags][]" multiple="multiple">
					<?php foreach ( $tags as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->title ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				echo wp_json_encode( trim( ob_get_clean() ) );
				?>;

				const productsHtml = <?php
				ob_start();
				?>
				<option value=""><?php esc_html_e( '— Select product —', 'rup-crm-tag-mapper' ); ?></option>
				<?php foreach ( $sc_products as $product ) : ?>
					<option value="<?php echo esc_attr( $product['product_id'] ); ?>" data-post-id="<?php echo esc_attr( $product['post_id'] ); ?>">
						<?php echo esc_html( $product['title'] ); ?>
					</option>
				<?php endforeach; ?>
				<?php
				echo wp_json_encode( trim( ob_get_clean() ) );
				?>;

				let rowHtml = `
					<div class="rup-crm-mapping-row" data-index="${nextIndex}">
						<div class="rup-crm-row-grid">
							<div class="rup-crm-field">
								<label><?php echo esc_js( __( 'Product', 'rup-crm-tag-mapper' ) ); ?></label>
								<select
									class="rup-crm-product-select"
									name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[${nextIndex}][product_id]"
								>
									${productsHtml}
								</select>
							</div>

							<div class="rup-crm-field">
								<label><?php echo esc_js( __( 'Price / Version', 'rup-crm-tag-mapper' ) ); ?></label>
								<select
									class="rup-crm-price-select"
									name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[${nextIndex}][price_id]"
									<?php echo empty( $sc_products ) ? 'disabled="disabled"' : ''; ?>
								>
									<option value=""><?php echo esc_js( __( '— Select price / version —', 'rup-crm-tag-mapper' ) ); ?></option>
								</select>
								<p class="description rup-crm-price-description"></p>
							</div>

							<div class="rup-crm-field rup-crm-field--tags">
								<label><?php echo esc_js( __( 'FluentCRM Tags', 'rup-crm-tag-mapper' ) ); ?></label>
								${tagsHtml.replace(/__INDEX__/g, nextIndex)}
							</div>
						</div>

						<div class="rup-crm-row-actions">
							<label class="rup-crm-checkbox">
								<input
									type="checkbox"
									class="rup-crm-enabled-checkbox"
									name="<?php echo esc_attr( RUP_CRM_TM_OPTION_MAPPINGS ); ?>[${nextIndex}][enabled]"
									value="1"
									checked="checked"
								/>
								<span><?php echo esc_js( __( 'Enabled', 'rup-crm-tag-mapper' ) ); ?></span>
							</label>

							<button type="button" class="button-link-delete rup-crm-remove-row">
								<?php echo esc_js( __( 'Delete', 'rup-crm-tag-mapper' ) ); ?>
							</button>
						</div>
					</div>
				`;

				const $row = $(rowHtml);
				$('#rup-crm-mappings-list').append($row);
				bindRow($row);
				applyFilters();
			}

			$('#rup-crm-add-row-top, #rup-crm-add-row-bottom').on('click', function() {
				addRow();
			});

			$('#rup-crm-filter-search, #rup-crm-filter-status, #rup-crm-filter-type').on('input change', function() {
				applyFilters();
			});

			applyFilters();
		});
	</script>

	<script>
		jQuery(function($) {
			const storedPriceIds = <?php echo wp_json_encode( array_map( static function( $map ) { return (string) ( $map['price_id'] ?? '' ); }, $maps ) ); ?>;

			$('#rup-crm-mappings-list .rup-crm-mapping-row').each(function() {
				const index = parseInt($(this).attr('data-index'), 10);
				const storedPriceId = storedPriceIds[index] || '';
				$(this).find('.rup-crm-price-select').attr('data-stored-price-id', storedPriceId);
			});
		});
	</script>
	<?php
}

/**
 * Extract purchased line items from checkout/order object.
 */
function rup_crm_tm_get_checkout_line_items( $checkout ) : array {
	if ( ! $checkout || empty( $checkout->line_items ) || empty( $checkout->line_items->data ) || ! is_array( $checkout->line_items->data ) ) {
		return [];
	}

	return $checkout->line_items->data;
}

/**
 * Determine whether a line item matches a mapping.
 *
 * Mapping rules:
 * - product_id + price_id="__all__" => all prices for that product
 * - product_id + exact price_id     => specific price only
 * - legacy fallback                 => exact price_id match
 */
function rup_crm_tm_line_item_matches_mapping( $item, array $mapping ) : bool {
	$item_price_id = isset( $item->price_id ) ? (string) $item->price_id : '';
	$item_product_id = '';

	if ( isset( $item->price ) && is_object( $item->price ) && ! empty( $item->price->product ) ) {
		if ( is_object( $item->price->product ) && ! empty( $item->price->product->id ) ) {
			$item_product_id = (string) $item->price->product->id;
		} elseif ( is_string( $item->price->product ) ) {
			$item_product_id = (string) $item->price->product;
		}
	}

	if ( ! $item_product_id && isset( $item->product_id ) ) {
		$item_product_id = (string) $item->product_id;
	}

	$mapping_product_id = isset( $mapping['product_id'] ) ? (string) $mapping['product_id'] : '';
	$mapping_price_id   = isset( $mapping['price_id'] ) ? (string) $mapping['price_id'] : '';

	if ( $mapping_product_id && '__all__' === $mapping_price_id ) {
		return $item_product_id && $item_product_id === $mapping_product_id;
	}

	if ( $mapping_product_id && $mapping_price_id && '__all__' !== $mapping_price_id ) {
		if ( $item_product_id && $item_product_id !== $mapping_product_id ) {
			return false;
		}
		return $item_price_id && $item_price_id === $mapping_price_id;
	}

	if ( $mapping_price_id && '__all__' !== $mapping_price_id ) {
		return $item_price_id && $item_price_id === $mapping_price_id;
	}

	return false;
}

/**
 * On purchase, apply tags via FluentCRM.
 *
 * @param object $checkout SureCart checkout/order object.
 */
function rup_crm_apply_fluentcrm_tags( $checkout ) {
	if ( '1' !== get_option( RUP_CRM_TM_OPTION_ENABLED, '0' ) || ! function_exists( 'FluentCrmApi' ) ) {
		return;
	}

	$maps = json_decode( get_option( RUP_CRM_TM_OPTION_MAPPINGS, '[]' ), true );
	if ( empty( $maps ) || ! is_array( $maps ) ) {
		return;
	}

	$maps = array_filter(
		$maps,
		static function ( $m ) {
			return ! empty( $m['enabled'] ) && '1' === (string) $m['enabled'];
		}
	);

	if ( empty( $maps ) ) {
		return;
	}

	$email = $checkout->email ?? '';
	if ( ! $email ) {
		return;
	}

	$line_items = rup_crm_tm_get_checkout_line_items( $checkout );
	if ( empty( $line_items ) ) {
		return;
	}

	$to_apply = [];

	foreach ( $line_items as $item ) {
		foreach ( $maps as $m ) {
			if ( rup_crm_tm_line_item_matches_mapping( $item, $m ) ) {
				foreach ( (array) ( $m['tags'] ?? [] ) as $slug ) {
					$slug = sanitize_text_field( $slug );
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

	$data = [
		'email'      => $email,
		'first_name' => $checkout->first_name ?? '',
		'last_name'  => $checkout->last_name ?? '',
		'status'     => 'subscribed',
		'tags'       => $to_apply,
	];

	try {
		$contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
		if ( $contact && isset( $contact->status ) && 'pending' === $contact->status ) {
			$contact->sendDoubleOptinEmail();
		}
	} catch ( Exception $e ) {
		rup_crm_tm_debuglog( 'FluentCRM error: ' . $e->getMessage() );
	}
}

/**
 * Purchase hooks.
 */
add_action( 'surecart/checkout_confirmed', 'rup_crm_apply_fluentcrm_tags', 10, 1 );
add_action( 'surelywp_tk_lm_on_new_order_create', 'rup_crm_apply_fluentcrm_tags', 10, 1 );

/**
 * Updater bootstrap.
 */
add_action(
	'plugins_loaded',
	function () {
		$updater_file = __DIR__ . '/inc/updater.php';

		if ( file_exists( $updater_file ) ) {
			require_once $updater_file;

			$updater_config = [
				'vendor'      => 'RUP',
				'plugin_file' => plugin_basename( __FILE__ ),
				'slug'        => 'rup-crm-tag-mapper',
				'name'        => 'Tag Manager for SureCart',
				'version'     => RUP_CRM_TM_VERSION,
				'key'         => 'CeW5jUv66xCMVZd83QTema',
				'server'      => 'https://raw.githubusercontent.com/stingray82/Tag-Manager-for-SureCart/main/uupd/index.json',
			];

			if ( class_exists( '\RUP\Updater\Updater_V2' ) ) {
				\RUP\Updater\Updater_V2::register( $updater_config );
			}
		}
	},
	20
);

/**
 * MainWP icon.
 */
add_filter(
	'mainwp_child_stats_get_plugin_info',
	function ( $info, $slug ) {
		if ( 'rup-crm-tag-mapper/rup-crm-tag-mapper.php' === $slug ) {
			$info['icon'] = 'https://raw.githubusercontent.com/stingray82/Tag-Manager-for-SureCart/main/uupd/icon-128.png';
		}

		return $info;
	},
	10,
	2
);


// Breaking Change Notification

/**
 * Show dismissible admin notice for 2.0 breaking change.
 */
add_action( 'admin_notices', 'rup_crm_tm_show_v2_breaking_change_notice' );

function rup_crm_tm_show_v2_breaking_change_notice() {
	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_sc_shop_settings' ) ) {
		return;
	}


	$dismissed = get_user_meta( get_current_user_id(), 'rup_crm_tm_v2_breaking_notice_dismissed', true );
	if ( $dismissed ) {
		return;
	}

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'rup_crm_tm_dismiss_v2_notice', '1' ),
		'rup_crm_tm_dismiss_v2_notice'
	);

	?>
	<div class="notice notice-warning is-dismissible rup-crm-tm-v2-notice">
		<p>
			<strong><?php esc_html_e( 'Important: Version 2.0 of Tag Manager for SureCart is a breaking change.', 'rup-crm-tag-mapper' ); ?></strong>
			<?php esc_html_e( 'After updating, you will need to remap your tags.', 'rup-crm-tag-mapper' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Dismiss notice', 'rup-crm-tag-mapper' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Handle dismissal of the 2.0 breaking change notice.
 */
add_action( 'admin_init', 'rup_crm_tm_handle_v2_breaking_notice_dismissal' );

function rup_crm_tm_handle_v2_breaking_notice_dismissal() {
	if ( ! isset( $_GET['rup_crm_tm_dismiss_v2_notice'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_sc_shop_settings' ) ) {
		return;
	}

	check_admin_referer( 'rup_crm_tm_dismiss_v2_notice' );

	update_user_meta( get_current_user_id(), 'rup_crm_tm_v2_breaking_notice_dismissed', 1 );

	wp_safe_redirect( remove_query_arg( array( 'rup_crm_tm_dismiss_v2_notice', '_wpnonce' ) ) );
	exit;
}
