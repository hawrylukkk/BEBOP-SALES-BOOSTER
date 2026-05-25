<?php
/**
 * Plugin Name: BEBOP SALES BOOSTER
 * Description: Sales booster dla WooCommerce: darmowa dostawa, komplety i szybkie dorzutki.
 * Version: 0.4.4
 * Author: Maciek Hawryluk
 * Text Domain: bebop-sales-booster
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bebop_Sales_Booster {
	const VERSION       = '0.4.4';
	const OPTION        = 'bebop_sales_booster';
	const NONCE_ACTION  = 'bebop_sales_booster';
	const AJAX_ADD      = 'bebop_sales_booster_add_offer';
	const MENU_SLUG     = 'bebop-sales-booster';
	const META_NONCE    = 'bebop_sales_booster_product_meta';
	const CART_ITEM_KEY = 'bebop_sales_booster';
	const META_PREFIX   = '_bebop_sales_booster_';

	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_product_metabox' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product_metabox' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'render_delivery_bar_top' ), 5 );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'render_product_related_offers' ), 14 );
		add_action( 'woocommerce_before_main_content', array( __CLASS__, 'render_delivery_bar_top' ), 4 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_cart_offers' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_offers' ) );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( __CLASS__, 'render_mini_cart_offers' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_delivery_bar_bottom' ), 20 );

		add_action( 'wp_ajax_' . self::AJAX_ADD, array( __CLASS__, 'handle_add_offer' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ADD, array( __CLASS__, 'handle_add_offer' ) );

		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_offer_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'add_cart_item_offer_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_order_item_offer_meta' ), 10, 4 );
	}

	/**
	 * Add default options on activation.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Add a single admin menu entry with internal tabs.
	 */
	public static function register_settings_page() {
		$capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';

		add_menu_page(
			__( 'BEBOP SALES BOOSTER', 'bebop-sales-booster' ),
			__( 'BEBOP SALES BOOSTER', 'bebop-sales-booster' ),
			$capability,
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-chart-line',
			56
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting(
			'bebop_sales_booster',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Enqueue WooCommerce admin selectors and the repeater helper.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin_page  = false !== strpos( $hook, self::MENU_SLUG );
		$is_product_page = $screen && 'product' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! $is_plugin_page && ! $is_product_page ) {
			return;
		}

		if ( function_exists( 'WC' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		wp_enqueue_style(
			'bebop-sales-booster-admin',
			plugin_dir_url( __FILE__ ) . 'assets/bebop-sales-booster-admin.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'bebop-sales-booster-admin',
			plugin_dir_url( __FILE__ ) . 'assets/bebop-sales-booster-admin.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);
	}

	/**
	 * Enqueue small frontend assets on public pages.
	 */
	public static function enqueue_frontend_assets() {
		if ( is_admin() || ! self::woocommerce_ready() || ! self::is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'bebop-sales-booster',
			plugin_dir_url( __FILE__ ) . 'assets/bebop-sales-booster.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'bebop-sales-booster',
			plugin_dir_url( __FILE__ ) . 'assets/bebop-sales-booster.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'bebop-sales-booster',
			'bebopSalesBooster',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'action'        => self::AJAX_ADD,
				'isCart'        => function_exists( 'is_cart' ) && is_cart(),
				'isCheckout'    => function_exists( 'is_checkout' ) && is_checkout(),
				'addingText'    => __( 'Dorzucamy...', 'bebop-sales-booster' ),
				'addedText'     => __( 'Dorzucone', 'bebop-sales-booster' ),
				'errorText'     => __( 'Nie siadło. Spróbuj jeszcze raz.', 'bebop-sales-booster' ),
				'chooseText'    => __( 'Wybierz rozmiar', 'bebop-sales-booster' ),
			)
		);
	}

	/**
	 * Render the top free-delivery bar.
	 */
	public static function render_delivery_bar_top() {
		self::render_delivery_bar( 'top' );
	}

	/**
	 * Render the bottom free-delivery bar.
	 */
	public static function render_delivery_bar_bottom() {
		self::render_delivery_bar( 'bottom' );
	}

	/**
	 * Render automatic related offers on a single product page.
	 */
	public static function render_product_related_offers() {
		if ( ! self::woocommerce_ready() || ! self::is_enabled() ) {
			return;
		}

		$options = self::options();
		if ( empty( $options['related']['product_enabled'] ) ) {
			return;
		}

		$product_id = get_the_ID();
		if ( empty( $product_id ) || 'product' !== get_post_type( $product_id ) ) {
			return;
		}

		$offers = self::related_offers_for_source(
			$product_id,
			'product_page',
			min( (int) $options['related']['product_limit'], self::placement_offer_limit( 'product_page' ) ),
			array( $product_id )
		);

		if ( empty( $offers ) ) {
			return;
		}

		?>
		<section class="bebop-sales-booster bebop-sales-booster--product-page" data-placement="product_page">
			<div class="bebop-sales-booster__head">
				<h3><?php esc_html_e( 'Pasuje do tego', 'bebop-sales-booster' ); ?></h3>
			</div>
			<div class="bebop-sales-booster__grid">
				<?php foreach ( $offers as $offer ) : ?>
					<?php self::offer_card( $offer, 'product_page' ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render cart-page offers.
	 */
	public static function render_cart_offers() {
		self::render_offer_area( 'cart' );
	}

	/**
	 * Render checkout-page offers.
	 */
	public static function render_checkout_offers() {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		self::render_offer_area( 'checkout' );
	}

	/**
	 * Render mini-cart offers.
	 */
	public static function render_mini_cart_offers() {
		self::render_offer_area( 'mini_cart' );
	}

	/**
	 * Add an offer product to the cart via AJAX.
	 */
	public static function handle_add_offer() {
		if ( ! self::woocommerce_ready() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce jest niedostępny.', 'bebop-sales-booster' ) ), 400 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$rule_id    = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';
		$placement  = isset( $_POST['placement'] ) ? sanitize_key( wp_unslash( $_POST['placement'] ) ) : '';

		if ( empty( $product_id ) || empty( $rule_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Brakuje danych. Odśwież stronę i spróbuj jeszcze raz.', 'bebop-sales-booster' ) ), 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Ten produkt już nie istnieje.', 'bebop-sales-booster' ) ), 404 );
		}

		if ( $product->is_type( 'variable' ) ) {
			wp_send_json_error(
				array(
					'message'     => __( 'Wybierz rozmiar na stronie produktu.', 'bebop-sales-booster' ),
					'product_url' => get_permalink( $product->get_id() ),
				),
				400
			);
		}

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'Tego teraz nie da się dodać.', 'bebop-sales-booster' ) ), 400 );
		}

		if ( self::should_hide_products_in_cart() && self::cart_contains_product( $product_id ) ) {
			wp_send_json_success(
				array(
					'message'       => __( 'Już siedzi w koszyku.', 'bebop-sales-booster' ),
					'added'         => true,
					'delivery_bars' => self::delivery_bar_fragments(),
				)
			);
		}

		$offer = self::find_offer_for_add( $rule_id, $product_id );
		if ( empty( $offer ) ) {
			wp_send_json_error( array( 'message' => __( 'Ta dorzutka nie jest już aktywna.', 'bebop-sales-booster' ) ), 400 );
		}

		$add_product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$variation_id   = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		$variation      = $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array();
		$cart_item_data = array(
			'bebop_sales_booster' => array(
				'rule_id'        => $rule_id,
				'rule_name'      => $offer['name'],
				'placement'      => $placement,
				'discount_type'  => $offer['discount_type'],
				'discount_value' => $offer['discount_value'],
				'original_price' => (float) $product->get_price(),
			),
			'bebop_sales_booster_key' => md5( $rule_id . '|' . $product_id . '|' . microtime( true ) ),
		);

		$cart_item_key = WC()->cart->add_to_cart( $add_product_id, 1, $variation_id, $variation, $cart_item_data );

		if ( empty( $cart_item_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Nie siadło. Spróbuj jeszcze raz.', 'bebop-sales-booster' ) ), 400 );
		}

		WC()->cart->calculate_totals();

		wp_send_json_success(
			array(
				'message'       => __( 'W koszyku.', 'bebop-sales-booster' ),
				'cart_hash'     => WC()->cart->get_cart_hash(),
				'cart_item_key' => $cart_item_key,
				'cart_count'    => WC()->cart->get_cart_contents_count(),
				'delivery_bars' => self::delivery_bar_fragments(),
			)
		);
	}

	/**
	 * Get offer data from the BEBOP cart item key.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	private static function cart_item_offer( $cart_item ) {
		if ( ! empty( $cart_item[ self::CART_ITEM_KEY ] ) && is_array( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			return $cart_item[ self::CART_ITEM_KEY ];
		}

		return array();
	}

	/**
	 * Apply configured offer pricing to cart items.
	 *
	 * @param WC_Cart $cart Cart object.
	 */
	public static function apply_offer_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( empty( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$offer = self::cart_item_offer( $cart_item );
			if ( empty( $offer ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$original = isset( $offer['original_price'] ) ? (float) $offer['original_price'] : (float) $cart_item['data']->get_price();
			$price    = self::discounted_price( $original, $offer['discount_type'], $offer['discount_value'] );

			$cart_item['data']->set_price( $price );
		}
	}

	/**
	 * Show a small offer marker in cart line item details.
	 *
	 * @param array $item_data Existing item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function add_cart_item_offer_data( $item_data, $cart_item ) {
		$offer = self::cart_item_offer( $cart_item );
		if ( empty( $offer['rule_name'] ) ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => __( 'Dorzutka', 'bebop-sales-booster' ),
			'value' => wc_clean( $offer['rule_name'] ),
		);

		return $item_data;
	}

	/**
	 * Store offer attribution on completed orders.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item values.
	 * @param WC_Order              $order Order object.
	 */
	public static function add_order_item_offer_meta( $item, $cart_item_key, $values, $order ) {
		$offer = self::cart_item_offer( $values );
		if ( empty( $offer ) ) {
			return;
		}

		$item->add_meta_data( '_bebop_sales_booster_rule_id', sanitize_key( $offer['rule_id'] ), true );
		$item->add_meta_data( '_bebop_sales_booster_rule_name', sanitize_text_field( $offer['rule_name'] ), true );
		$item->add_meta_data( '_bebop_sales_booster_placement', sanitize_key( $offer['placement'] ), true );
		$item->add_meta_data( '_bebop_sales_booster_discount_type', sanitize_key( $offer['discount_type'] ), true );
		$item->add_meta_data( '_bebop_sales_booster_discount_value', wc_format_decimal( $offer['discount_value'] ), true );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Posted settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input          = is_array( $input ) ? $input : array();
		$options        = self::options();
		$active_section = isset( $input['__section'] ) ? sanitize_key( wp_unslash( $input['__section'] ) ) : 'all';
		$general_visible = in_array( $active_section, array( 'all', 'general' ), true );

		if ( $general_visible || array_key_exists( 'enabled', $input ) ) {
			$options['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		}

		if ( isset( $input['max_offers'] ) ) {
			$options['max_offers'] = self::clamp_absint( $input['max_offers'], 1, 8 );
		}

		if ( isset( $input['limits'] ) && is_array( $input['limits'] ) ) {
			$limits_input = $input['limits'];
			$options['limits'] = array(
				'product_page' => self::clamp_absint( isset( $limits_input['product_page'] ) ? $limits_input['product_page'] : $options['limits']['product_page'], 1, 8 ),
				'cart'         => self::clamp_absint( isset( $limits_input['cart'] ) ? $limits_input['cart'] : $options['limits']['cart'], 1, 8 ),
				'checkout'     => self::clamp_absint( isset( $limits_input['checkout'] ) ? $limits_input['checkout'] : $options['limits']['checkout'], 1, 6 ),
				'mini_cart'    => self::clamp_absint( isset( $limits_input['mini_cart'] ) ? $limits_input['mini_cart'] : $options['limits']['mini_cart'], 1, 4 ),
			);
		}

		if ( $general_visible || ( isset( $input['behavior'] ) && is_array( $input['behavior'] ) ) ) {
			$behavior_input = isset( $input['behavior'] ) && is_array( $input['behavior'] ) ? $input['behavior'] : array();
			$options['behavior'] = array(
				'hide_in_cart'             => empty( $behavior_input['hide_in_cart'] ) ? 0 : 1,
				'quick_add_only_mini_cart' => empty( $behavior_input['quick_add_only_mini_cart'] ) ? 0 : 1,
				'debug_enabled'            => empty( $behavior_input['debug_enabled'] ) ? 0 : 1,
			);
		}

		if ( isset( $input['free_shipping'] ) && is_array( $input['free_shipping'] ) ) {
			$free_shipping_input = $input['free_shipping'];
			$options['free_shipping'] = array(
				'enabled'    => empty( $free_shipping_input['enabled'] ) ? 0 : 1,
				'threshold'  => self::sanitize_decimal_or_empty( isset( $free_shipping_input['threshold'] ) ? $free_shipping_input['threshold'] : '' ),
				'message'    => isset( $free_shipping_input['message'] ) ? sanitize_text_field( wp_unslash( $free_shipping_input['message'] ) ) : $options['free_shipping']['message'],
				'product_ids' => self::sanitize_absint_array( isset( $free_shipping_input['product_ids'] ) ? $free_shipping_input['product_ids'] : array() ),
				'placements' => self::sanitize_placements( isset( $free_shipping_input['placements'] ) ? $free_shipping_input['placements'] : array() ),
				'match_mode' => self::sanitize_choice( isset( $free_shipping_input['match_mode'] ) ? $free_shipping_input['match_mode'] : $options['free_shipping']['match_mode'], array( 'unlock_first', 'closest', 'under' ), 'unlock_first' ),
			);
		}

		if ( isset( $input['delivery_bar'] ) && is_array( $input['delivery_bar'] ) ) {
			$delivery_bar_input = $input['delivery_bar'];
			$options['delivery_bar'] = array(
				'enabled'            => empty( $delivery_bar_input['enabled'] ) ? 0 : 1,
				'top_enabled'        => empty( $delivery_bar_input['top_enabled'] ) ? 0 : 1,
				'bottom_enabled'     => empty( $delivery_bar_input['bottom_enabled'] ) ? 0 : 1,
				'bottom_sticky'      => empty( $delivery_bar_input['bottom_sticky'] ) ? 0 : 1,
				'show_product'       => empty( $delivery_bar_input['show_product'] ) ? 0 : 1,
				'product_source'     => self::sanitize_choice( isset( $delivery_bar_input['product_source'] ) ? $delivery_bar_input['product_source'] : 'mixed', array( 'mixed', 'manual', 'tag' ), 'mixed' ),
				'product_ids'        => self::sanitize_absint_array( isset( $delivery_bar_input['product_ids'] ) ? $delivery_bar_input['product_ids'] : array() ),
				'tag_slug'           => isset( $delivery_bar_input['tag_slug'] ) ? sanitize_title( wp_unslash( $delivery_bar_input['tag_slug'] ) ) : $options['delivery_bar']['tag_slug'],
				'fallback_threshold' => self::sanitize_decimal_or_empty( isset( $delivery_bar_input['fallback_threshold'] ) ? $delivery_bar_input['fallback_threshold'] : $options['delivery_bar']['fallback_threshold'] ),
			);
		}

		if ( isset( $input['related'] ) && is_array( $input['related'] ) ) {
			$related_input = $input['related'];
			$options['related'] = array(
				'product_enabled'  => empty( $related_input['product_enabled'] ) ? 0 : 1,
				'cart_enabled'     => empty( $related_input['cart_enabled'] ) ? 0 : 1,
				'checkout_enabled' => empty( $related_input['checkout_enabled'] ) ? 0 : 1,
				'product_limit'    => self::clamp_absint( isset( $related_input['product_limit'] ) ? $related_input['product_limit'] : 4, 1, 8 ),
				'cart_limit'       => self::clamp_absint( isset( $related_input['cart_limit'] ) ? $related_input['cart_limit'] : 3, 1, 6 ),
				'min_score'        => self::clamp_absint( isset( $related_input['min_score'] ) ? $related_input['min_score'] : 8, 1, 50 ),
				'strategy'         => self::sanitize_choice( isset( $related_input['strategy'] ) ? $related_input['strategy'] : 'mixed', array( 'mixed', 'similar', 'complete' ), 'mixed' ),
				'ignored_tags'     => self::sanitize_slug_list( isset( $related_input['ignored_tags'] ) ? $related_input['ignored_tags'] : '' ),
			);
		}

		if ( ! in_array( $active_section, array( 'all', 'rules' ), true ) ) {
			return self::normalize_options( $options );
		}

		$rules_input     = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();
		$options['rules'] = array();

		foreach ( $rules_input as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$name               = isset( $rule['name'] ) ? sanitize_text_field( wp_unslash( $rule['name'] ) ) : '';
			$trigger_products   = self::sanitize_absint_array( isset( $rule['trigger_products'] ) ? $rule['trigger_products'] : array() );
			$trigger_categories = self::sanitize_absint_array( isset( $rule['trigger_categories'] ) ? $rule['trigger_categories'] : array() );
			$offer_products     = self::sanitize_absint_array( isset( $rule['offer_products'] ) ? $rule['offer_products'] : array() );

			if ( '' === $name && empty( $trigger_products ) && empty( $trigger_categories ) && empty( $offer_products ) ) {
				continue;
			}

			if ( empty( $offer_products ) ) {
				add_settings_error(
					self::OPTION,
					'bebop_sales_booster_missing_offer_product',
					__( 'Jedna reguła dorzutek została pominięta, bo nie ma produktów w ofercie.', 'bebop-sales-booster' )
				);
				continue;
			}

			$rule_id = isset( $rule['id'] ) ? sanitize_key( wp_unslash( $rule['id'] ) ) : '';
			if ( empty( $rule_id ) ) {
				$rule_id = sanitize_key( 'rule_' . wp_generate_uuid4() );
			}

			$options['rules'][] = array(
				'id'                 => $rule_id,
				'name'               => '' === $name ? __( 'Dorzutka BEBOP', 'bebop-sales-booster' ) : $name,
				'enabled'            => empty( $rule['enabled'] ) ? 0 : 1,
				'trigger_products'   => $trigger_products,
				'trigger_categories' => $trigger_categories,
				'offer_products'     => $offer_products,
				'placements'         => self::sanitize_placements( isset( $rule['placements'] ) ? $rule['placements'] : array() ),
				'min_cart_total'     => self::sanitize_decimal_or_empty( isset( $rule['min_cart_total'] ) ? $rule['min_cart_total'] : '' ),
				'max_cart_total'     => self::sanitize_decimal_or_empty( isset( $rule['max_cart_total'] ) ? $rule['max_cart_total'] : '' ),
				'only_below_free_shipping' => empty( $rule['only_below_free_shipping'] ) ? 0 : 1,
				'discount_type'      => self::sanitize_choice( isset( $rule['discount_type'] ) ? $rule['discount_type'] : 'none', array( 'none', 'percent', 'amount_off', 'fixed_price' ), 'none' ),
				'discount_value'     => self::sanitize_decimal_or_empty( isset( $rule['discount_value'] ) ? $rule['discount_value'] : '' ),
				'priority'           => self::clamp_absint( isset( $rule['priority'] ) ? $rule['priority'] : 10, 0, 100 ),
				'message'            => isset( $rule['message'] ) ? sanitize_text_field( wp_unslash( $rule['message'] ) ) : '',
			);
		}

		return self::normalize_options( $options );
	}

	/**
	 * Route the single admin page to an internal tab.
	 */
	public static function render_admin_page() {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard';

		switch ( $section ) {
			case 'settings':
				self::render_settings_page( 'all' );
				break;
			case 'rules':
				self::render_settings_page( 'rules' );
				break;
			case 'free_shipping':
				self::render_settings_page( 'free_shipping' );
				break;
			case 'related':
				self::render_settings_page( 'related' );
				break;
			case 'debug':
				self::render_debug_page();
				break;
			case 'dashboard':
			default:
				self::render_dashboard_page();
				break;
		}
	}

	/**
	 * Render the plugin settings page.
	 *
	 * @param string $active_section Section to show.
	 */
	public static function render_settings_page( $active_section = 'all' ) {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			return;
		}

		$options = self::options();
		?>
		<div class="wrap bebop-sales-booster-admin">
			<?php self::admin_header( __( 'Dorzutki do koszyka, darmowa dostawa i produkty do kompletu, bez pozakupowego cyrku.', 'bebop-sales-booster' ) ); ?>
			<?php self::admin_nav( $active_section ); ?>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'WooCommerce nie jest aktywny. Ustawienia dorzutek można zapisać, ale ruszą dopiero po włączeniu WooCommerce.', 'bebop-sales-booster' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'bebop_sales_booster' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[__section]" value="<?php echo esc_attr( $active_section ); ?>">

				<?php if ( self::admin_section_visible( $active_section, 'general' ) ) : ?>
				<div class="bebop-sales-booster-admin__section">
					<h2><?php esc_html_e( 'Ogólne', 'bebop-sales-booster' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'bebop-sales-booster' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>>
									<?php esc_html_e( 'Włącz dorzutki w koszyku i kasie', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-max"><?php esc_html_e( 'Maksymalna liczba dorzutek', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-max" type="number" min="1" max="8" name="<?php echo esc_attr( self::OPTION ); ?>[max_offers]" value="<?php echo esc_attr( $options['max_offers'] ); ?>">
								<p class="description"><?php esc_html_e( 'Awaryjny limit globalny. Niżej możesz ustawić dokładniejsze limity dla każdego miejsca.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Limity miejsc', 'bebop-sales-booster' ); ?></th>
							<td class="bebop-sales-booster-admin__inline-inputs">
								<label>
									<?php esc_html_e( 'Produkt', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="8" name="<?php echo esc_attr( self::OPTION ); ?>[limits][product_page]" value="<?php echo esc_attr( $options['limits']['product_page'] ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Koszyk', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="8" name="<?php echo esc_attr( self::OPTION ); ?>[limits][cart]" value="<?php echo esc_attr( $options['limits']['cart'] ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Kasa', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="6" name="<?php echo esc_attr( self::OPTION ); ?>[limits][checkout]" value="<?php echo esc_attr( $options['limits']['checkout'] ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Mini-koszyk', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="4" name="<?php echo esc_attr( self::OPTION ); ?>[limits][mini_cart]" value="<?php echo esc_attr( $options['limits']['mini_cart'] ); ?>">
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Zachowanie', 'bebop-sales-booster' ); ?></th>
							<td>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[behavior][hide_in_cart]" value="1" <?php checked( ! empty( $options['behavior']['hide_in_cart'] ) ); ?>>
									<?php esc_html_e( 'Nie pokazuj produktów, które już są w koszyku', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[behavior][quick_add_only_mini_cart]" value="1" <?php checked( ! empty( $options['behavior']['quick_add_only_mini_cart'] ) ); ?>>
									<?php esc_html_e( 'W mini-koszyku pokazuj tylko rzeczy dodawane jednym kliknięciem', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[behavior][debug_enabled]" value="1" <?php checked( ! empty( $options['behavior']['debug_enabled'] ) ); ?>>
									<?php esc_html_e( 'Pokaż adminowi powód rekomendacji', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>
				<?php endif; ?>

				<?php if ( self::admin_section_visible( $active_section, 'free_shipping' ) ) : ?>
				<div class="bebop-sales-booster-admin__section">
					<h2><?php esc_html_e( 'Darmowa dostawa', 'bebop-sales-booster' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'bebop-sales-booster' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[free_shipping][enabled]" value="1" <?php checked( ! empty( $options['free_shipping']['enabled'] ) ); ?>>
									<?php esc_html_e( 'Pokaż postęp i produkty, które dobijają do darmowej dostawy', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-threshold"><?php esc_html_e( 'Próg darmowej dostawy', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-threshold" type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[free_shipping][threshold]" value="<?php echo esc_attr( $options['free_shipping']['threshold'] ); ?>" placeholder="<?php esc_attr_e( 'Automatycznie z WooCommerce', 'bebop-sales-booster' ); ?>">
								<p class="description"><?php esc_html_e( 'Zostaw puste, aby wykryć najniższy aktywny próg darmowej dostawy WooCommerce.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Produkty do dobicia', 'bebop-sales-booster' ); ?></th>
							<td>
								<?php self::product_multiselect( self::OPTION . '[free_shipping][product_ids][]', $options['free_shipping']['product_ids'], 'bebop-sales-booster-free-products' ); ?>
								<p class="description"><?php esc_html_e( 'Wybierz proste produkty albo konkretne warianty, które naturalnie domykają koszyk.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Miejsca wyświetlania', 'bebop-sales-booster' ); ?></th>
							<td><?php self::placement_checkboxes( self::OPTION . '[free_shipping][placements]', $options['free_shipping']['placements'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-free-message"><?php esc_html_e( 'Komunikat', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-free-message" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[free_shipping][message]" value="<?php echo esc_attr( $options['free_shipping']['message'] ); ?>">
								<p class="description"><?php esc_html_e( 'Użyj {amount}, aby wstawić brakującą kwotę.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-free-match"><?php esc_html_e( 'Dobór produktu', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<select id="bebop-sales-booster-free-match" name="<?php echo esc_attr( self::OPTION ); ?>[free_shipping][match_mode]">
									<option value="unlock_first" <?php selected( $options['free_shipping']['match_mode'], 'unlock_first' ); ?>><?php esc_html_e( 'Najpierw produkt, który odblokuje dostawę', 'bebop-sales-booster' ); ?></option>
									<option value="closest" <?php selected( $options['free_shipping']['match_mode'], 'closest' ); ?>><?php esc_html_e( 'Najbliżej brakującej kwoty', 'bebop-sales-booster' ); ?></option>
									<option value="under" <?php selected( $options['free_shipping']['match_mode'], 'under' ); ?>><?php esc_html_e( 'Tylko poniżej brakującej kwoty', 'bebop-sales-booster' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				</div>
				<?php endif; ?>

				<?php if ( self::admin_section_visible( $active_section, 'delivery_bar' ) ) : ?>
				<div class="bebop-sales-booster-admin__section">
					<h2><?php esc_html_e( 'Różowy pasek dostawy', 'bebop-sales-booster' ); ?></h2>
					<p><?php esc_html_e( 'Krótki pasek w stylu strony: ile brakuje i co można dorzucić.', 'bebop-sales-booster' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'bebop-sales-booster' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][enabled]" value="1" <?php checked( ! empty( $options['delivery_bar']['enabled'] ) ); ?>>
									<?php esc_html_e( 'Pokaż różowy pasek dostawy', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pozycja', 'bebop-sales-booster' ); ?></th>
							<td>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][top_enabled]" value="1" <?php checked( ! empty( $options['delivery_bar']['top_enabled'] ) ); ?>>
									<?php esc_html_e( 'Górny pasek', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][bottom_enabled]" value="1" <?php checked( ! empty( $options['delivery_bar']['bottom_enabled'] ) ); ?>>
									<?php esc_html_e( 'Dolny pasek', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][bottom_sticky]" value="1" <?php checked( ! empty( $options['delivery_bar']['bottom_sticky'] ) ); ?>>
									<?php esc_html_e( 'Przyklej dolny pasek', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Produkt do dorzucenia', 'bebop-sales-booster' ); ?></th>
							<td>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][show_product]" value="1" <?php checked( ! empty( $options['delivery_bar']['show_product'] ) ); ?>>
									<?php esc_html_e( 'Pokaż jedną dorzutkę w pasku', 'bebop-sales-booster' ); ?>
								</label>
								<?php self::product_multiselect( self::OPTION . '[delivery_bar][product_ids][]', $options['delivery_bar']['product_ids'], 'bebop-sales-booster-delivery-bar-products' ); ?>
								<p class="description"><?php esc_html_e( 'Opcjonalna ręczna pula. Proste produkty i konkretne warianty dodają się jednym kliknięciem; produkty z wariantami prowadzą do wyboru rozmiaru.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-delivery-source"><?php esc_html_e( 'Źródło produktów', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<select id="bebop-sales-booster-delivery-source" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][product_source]">
									<option value="mixed" <?php selected( $options['delivery_bar']['product_source'], 'mixed' ); ?>><?php esc_html_e( 'Ręczna pula + tag dobijający', 'bebop-sales-booster' ); ?></option>
									<option value="manual" <?php selected( $options['delivery_bar']['product_source'], 'manual' ); ?>><?php esc_html_e( 'Tylko ręczna pula', 'bebop-sales-booster' ); ?></option>
									<option value="tag" <?php selected( $options['delivery_bar']['product_source'], 'tag' ); ?>><?php esc_html_e( 'Tylko tag dobijający', 'bebop-sales-booster' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Tryb mieszany używa też produktów wybranych w sekcji Darmowa dostawa.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-delivery-tag"><?php esc_html_e( 'Slug tagu dobijającego', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-delivery-tag" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][tag_slug]" value="<?php echo esc_attr( $options['delivery_bar']['tag_slug'] ); ?>">
								<p class="description"><?php esc_html_e( 'Domyślnie: upsell-dobij-do-dostawy.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-delivery-fallback"><?php esc_html_e( 'Awaryjny próg paska', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-delivery-fallback" type="number" min="0" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[delivery_bar][fallback_threshold]" value="<?php echo esc_attr( $options['delivery_bar']['fallback_threshold'] ); ?>">
								<p class="description"><?php esc_html_e( 'Używany tylko wtedy, gdy plugin nie wykryje progu darmowej dostawy z WooCommerce. Domyślnie 777.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
				<?php endif; ?>

				<?php if ( self::admin_section_visible( $active_section, 'related' ) ) : ?>
				<div class="bebop-sales-booster-admin__section">
					<h2><?php esc_html_e( 'Produkty w tym samym klimacie', 'bebop-sales-booster' ); ?></h2>
					<p><?php esc_html_e( 'Automat dobiera rzeczy po motywach, kolekcjach, liniach i typie produktu. Szerokie tagi nie robią tu bałaganu.', 'bebop-sales-booster' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Gdzie wyświetlać', 'bebop-sales-booster' ); ?></th>
							<td>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[related][product_enabled]" value="1" <?php checked( ! empty( $options['related']['product_enabled'] ) ); ?>>
									<?php esc_html_e( 'Strona produktu', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[related][cart_enabled]" value="1" <?php checked( ! empty( $options['related']['cart_enabled'] ) ); ?>>
									<?php esc_html_e( 'Koszyk', 'bebop-sales-booster' ); ?>
								</label>
								<label class="bebop-sales-booster-placement">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[related][checkout_enabled]" value="1" <?php checked( ! empty( $options['related']['checkout_enabled'] ) ); ?>>
									<?php esc_html_e( 'Kasa', 'bebop-sales-booster' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-related-strategy"><?php esc_html_e( 'Strategia', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<select id="bebop-sales-booster-related-strategy" name="<?php echo esc_attr( self::OPTION ); ?>[related][strategy]">
									<option value="mixed" <?php selected( $options['related']['strategy'], 'mixed' ); ?>><?php esc_html_e( 'Mieszana: ten klimat + do kompletu', 'bebop-sales-booster' ); ?></option>
									<option value="similar" <?php selected( $options['related']['strategy'], 'similar' ); ?>><?php esc_html_e( 'Najpierw podobne produkty', 'bebop-sales-booster' ); ?></option>
									<option value="complete" <?php selected( $options['related']['strategy'], 'complete' ); ?>><?php esc_html_e( 'Najpierw produkty do kompletu', 'bebop-sales-booster' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Limity', 'bebop-sales-booster' ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Strona produktu', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="8" name="<?php echo esc_attr( self::OPTION ); ?>[related][product_limit]" value="<?php echo esc_attr( $options['related']['product_limit'] ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Koszyk/kasa', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="6" name="<?php echo esc_attr( self::OPTION ); ?>[related][cart_limit]" value="<?php echo esc_attr( $options['related']['cart_limit'] ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Minimalny wynik', 'bebop-sales-booster' ); ?>
									<input type="number" min="1" max="50" name="<?php echo esc_attr( self::OPTION ); ?>[related][min_score]" value="<?php echo esc_attr( $options['related']['min_score'] ); ?>">
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bebop-sales-booster-related-ignored"><?php esc_html_e( 'Ignorowane tagi', 'bebop-sales-booster' ); ?></label></th>
							<td>
								<input id="bebop-sales-booster-related-ignored" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[related][ignored_tags]" value="<?php echo esc_attr( implode( ', ', $options['related']['ignored_tags'] ) ); ?>" placeholder="<?php esc_attr_e( 'np. kolor-bialy, rozmiar-xs', 'bebop-sales-booster' ); ?>">
								<p class="description"><?php esc_html_e( 'Tagi po przecinku. Plugin nie będzie używać ich do automatycznego dobierania produktów.', 'bebop-sales-booster' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
				<?php endif; ?>

				<?php if ( self::admin_section_visible( $active_section, 'rules' ) ) : ?>
				<div class="bebop-sales-booster-admin__section">
					<h2><?php esc_html_e( 'Ręczne dorzutki i komplety', 'bebop-sales-booster' ); ?></h2>
					<p><?php esc_html_e( 'Ustaw konkretne połączenia produktów albo globalne rzeczy do koszyka i kasy.', 'bebop-sales-booster' ); ?></p>

					<table class="widefat striped bebop-sales-booster-rules">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Reguła', 'bebop-sales-booster' ); ?></th>
								<th><?php esc_html_e( 'Wyzwalacze', 'bebop-sales-booster' ); ?></th>
								<th><?php esc_html_e( 'Produkty do dorzucenia', 'bebop-sales-booster' ); ?></th>
								<th><?php esc_html_e( 'Rabat', 'bebop-sales-booster' ); ?></th>
							</tr>
						</thead>
						<tbody class="bebop-sales-booster-rules__body">
							<?php
							foreach ( $options['rules'] as $index => $rule ) {
								self::rule_row( (string) $index, $rule );
							}
							?>
						</tbody>
					</table>

					<p>
						<button type="button" class="button bebop-sales-booster-add-rule"><?php esc_html_e( 'Dodaj regułę', 'bebop-sales-booster' ); ?></button>
					</p>

					<template id="bebop-sales-booster-rule-template">
						<?php self::rule_row( '__INDEX__', self::blank_rule() ); ?>
					</template>
				</div>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_dashboard_page() {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			return;
		}

		$stats = self::dashboard_stats();
		?>
		<div class="wrap bebop-sales-booster-admin">
			<?php self::admin_header( __( 'Szybki podgląd tego, co podbija koszyk przed płatnością.', 'bebop-sales-booster' ) ); ?>
			<?php self::admin_nav( 'dashboard' ); ?>

			<div class="bebop-sales-booster-admin__cards">
				<?php foreach ( $stats as $stat ) : ?>
					<div class="bebop-sales-booster-admin__card">
						<span><?php echo esc_html( $stat['label'] ); ?></span>
						<strong><?php echo esc_html( $stat['value'] ); ?></strong>
						<?php if ( ! empty( $stat['note'] ) ) : ?>
							<p><?php echo esc_html( $stat['note'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php self::render_diagnostics_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render only the manual rules screen.
	 */
	public static function render_rules_page() {
		self::render_settings_page( 'rules' );
	}

	/**
	 * Render free-delivery and delivery-bar settings.
	 */
	public static function render_free_shipping_page() {
		self::render_settings_page( 'free_shipping' );
	}

	/**
	 * Render related-product settings.
	 */
	public static function render_related_page() {
		self::render_settings_page( 'related' );
	}

	/**
	 * Render diagnostics screen.
	 */
	public static function render_debug_page() {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap bebop-sales-booster-admin">
			<?php self::admin_header( __( 'Kontrola konfiguracji, żeby szybciej znaleźć brakujące produkty, progi i reguły.', 'bebop-sales-booster' ) ); ?>
			<?php self::admin_nav( 'debug' ); ?>
			<?php self::render_diagnostics_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render the branded admin title screen.
	 *
	 * @param string $subtitle Short screen subtitle.
	 */
	private static function admin_header( $subtitle ) {
		?>
		<div class="bebop-sales-booster-admin__brand">
			<img class="bebop-sales-booster-admin__brand-logo" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/bebop-sales-booster.png' ); ?>" alt="<?php esc_attr_e( 'BEBOP SALES BOOSTER', 'bebop-sales-booster' ); ?>" width="320" height="213">
			<div class="bebop-sales-booster-admin__brand-copy">
				<h1><?php esc_html_e( 'BEBOP SALES BOOSTER', 'bebop-sales-booster' ); ?></h1>
				<p><?php echo esc_html( $subtitle ); ?></p>
				<p class="bebop-sales-booster-admin__brand-author"><?php esc_html_e( 'Autor: Maciek Hawryluk', 'bebop-sales-booster' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin navigation tabs.
	 *
	 * @param string $active Active section.
	 */
	private static function admin_nav( $active ) {
		$active = 'all' === $active ? 'settings' : $active;
		$pages  = self::admin_pages();
		?>
		<nav class="nav-tab-wrapper bebop-sales-booster-admin__nav" aria-label="<?php esc_attr_e( 'Menu BEBOP SALES BOOSTER', 'bebop-sales-booster' ); ?>">
			<?php foreach ( $pages as $key => $page ) : ?>
				<a class="nav-tab <?php echo esc_attr( $active === $key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'section' => $key ), admin_url( 'admin.php' ) ) ); ?>">
					<?php echo esc_html( $page['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Admin page definitions.
	 *
	 * @return array
	 */
	private static function admin_pages() {
		return array(
			'dashboard'     => array(
				'label' => __( 'Dashboard', 'bebop-sales-booster' ),
			),
			'settings'      => array(
				'label' => __( 'Ustawienia', 'bebop-sales-booster' ),
			),
			'rules'         => array(
				'label' => __( 'Reguły', 'bebop-sales-booster' ),
			),
			'free_shipping' => array(
				'label' => __( 'Darmowa dostawa', 'bebop-sales-booster' ),
			),
			'related'       => array(
				'label' => __( 'Produkty powiązane', 'bebop-sales-booster' ),
			),
			'debug'         => array(
				'label' => __( 'Diagnostyka', 'bebop-sales-booster' ),
			),
		);
	}

	/**
	 * Check if a settings section should render.
	 *
	 * @param string $active_section Active screen section.
	 * @param string $section Section key.
	 * @return bool
	 */
	private static function admin_section_visible( $active_section, $section ) {
		if ( 'all' === $active_section ) {
			return true;
		}

		if ( 'free_shipping' === $active_section ) {
			return in_array( $section, array( 'free_shipping', 'delivery_bar' ), true );
		}

		return $active_section === $section;
	}

	/**
	 * Build admin dashboard stats.
	 *
	 * @return array
	 */
	private static function dashboard_stats() {
		$options       = self::options();
		$active_rules  = 0;
		$total_rules   = count( $options['rules'] );
		$free_products = self::free_shipping_candidate_product_ids();

		foreach ( $options['rules'] as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				$active_rules++;
			}
		}

		return array(
			array(
				'label' => __( 'Status', 'bebop-sales-booster' ),
				'value' => empty( $options['enabled'] ) ? __( 'Wyłączone', 'bebop-sales-booster' ) : __( 'Aktywne', 'bebop-sales-booster' ),
				'note'  => __( 'Globalny przełącznik pluginu.', 'bebop-sales-booster' ),
			),
			array(
				'label' => __( 'Reguły', 'bebop-sales-booster' ),
				'value' => sprintf(
					/* translators: 1: active rules, 2: all rules. */
					__( '%1$d/%2$d', 'bebop-sales-booster' ),
					$active_rules,
					$total_rules
				),
				'note'  => __( 'Aktywne ręczne dorzutki.', 'bebop-sales-booster' ),
			),
			array(
				'label' => __( 'Próg dostawy', 'bebop-sales-booster' ),
				'value' => self::free_shipping_threshold() > 0 ? wp_strip_all_tags( wc_price( self::free_shipping_threshold() ) ) : __( 'Brak', 'bebop-sales-booster' ),
				'note'  => __( 'Z ustawień pluginu albo WooCommerce.', 'bebop-sales-booster' ),
			),
			array(
				'label' => __( 'Produkty do dobicia', 'bebop-sales-booster' ),
				'value' => (string) count( $free_products ),
				'note'  => __( 'Ręczne + oznaczone w produktach.', 'bebop-sales-booster' ),
			),
			array(
				'label' => __( 'Mini-koszyk', 'bebop-sales-booster' ),
				'value' => (string) count( self::product_ids_by_meta( '_bebop_sales_booster_mini_cart', 'yes', 200 ) ),
				'note'  => __( 'Produkty oznaczone jako szybkie dorzutki.', 'bebop-sales-booster' ),
			),
			array(
				'label' => __( 'Wykluczone produkty', 'bebop-sales-booster' ),
				'value' => (string) count( self::product_ids_by_meta( '_bebop_sales_booster_exclude', 'yes', 200 ) ),
				'note'  => __( 'Nie będą pokazywane jako dorzutki.', 'bebop-sales-booster' ),
			),
		);
	}

	/**
	 * Render diagnostics panel.
	 */
	private static function render_diagnostics_panel() {
		$items = self::diagnostics();
		?>
		<div class="bebop-sales-booster-admin__section">
			<h2><?php esc_html_e( 'Diagnostyka', 'bebop-sales-booster' ); ?></h2>
			<ul class="bebop-sales-booster-admin__diagnostics">
				<?php foreach ( $items as $item ) : ?>
					<li class="is-<?php echo esc_attr( $item['status'] ); ?>">
						<strong><?php echo esc_html( $item['label'] ); ?></strong>
						<span><?php echo esc_html( $item['message'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get diagnostic checks.
	 *
	 * @return array
	 */
	private static function diagnostics() {
		$options     = self::options();
		$diagnostics = array();

		$diagnostics[] = array(
			'status'  => self::woocommerce_ready() ? 'ok' : 'warning',
			'label'   => __( 'WooCommerce', 'bebop-sales-booster' ),
			'message' => self::woocommerce_ready() ? __( 'Aktywny i gotowy.', 'bebop-sales-booster' ) : __( 'WooCommerce nie jest aktywny.', 'bebop-sales-booster' ),
		);

		$diagnostics[] = array(
			'status'  => empty( $options['enabled'] ) ? 'warning' : 'ok',
			'label'   => __( 'Status pluginu', 'bebop-sales-booster' ),
			'message' => empty( $options['enabled'] ) ? __( 'Dorzutki są wyłączone globalnie.', 'bebop-sales-booster' ) : __( 'Dorzutki są włączone.', 'bebop-sales-booster' ),
		);

		$free_candidates = self::free_shipping_candidate_product_ids();
		$diagnostics[]   = array(
			'status'  => empty( $free_candidates ) && ! empty( $options['free_shipping']['enabled'] ) ? 'warning' : 'ok',
			'label'   => __( 'Produkty do darmowej dostawy', 'bebop-sales-booster' ),
			'message' => empty( $free_candidates ) ? __( 'Brak produktów do dobicia koszyka.', 'bebop-sales-booster' ) : sprintf(
				/* translators: %d: product count. */
				__( 'Dostępnych kandydatów: %d.', 'bebop-sales-booster' ),
				count( $free_candidates )
			),
		);

		$active_rules = 0;
		foreach ( $options['rules'] as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				$active_rules++;
			}
		}

		$diagnostics[] = array(
			'status'  => $active_rules > 0 ? 'ok' : 'info',
			'label'   => __( 'Ręczne reguły', 'bebop-sales-booster' ),
			'message' => $active_rules > 0 ? sprintf(
				/* translators: %d: active rule count. */
				__( 'Aktywne reguły: %d.', 'bebop-sales-booster' ),
				$active_rules
			) : __( 'Brak aktywnych ręcznych reguł. Automat tagowy może nadal działać.', 'bebop-sales-booster' ),
		);

		$diagnostics[] = array(
			'status'  => ! empty( $options['behavior']['debug_enabled'] ) ? 'info' : 'ok',
			'label'   => __( 'Debug frontu', 'bebop-sales-booster' ),
			'message' => ! empty( $options['behavior']['debug_enabled'] ) ? __( 'Admin widzi powody rekomendacji na froncie.', 'bebop-sales-booster' ) : __( 'Debug frontu jest ukryty.', 'bebop-sales-booster' ),
		);

		return $diagnostics;
	}

	/**
	 * Render a repeated rule row.
	 *
	 * @param string $index Row index.
	 * @param array  $rule Rule data.
	 */
	private static function rule_row( $index, $rule ) {
		$field = self::OPTION . '[rules][' . $index . ']';
		?>
		<tr class="bebop-sales-booster-rule">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( $rule['id'] ); ?>">
				<label class="bebop-sales-booster-rule__line">
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?>>
					<?php esc_html_e( 'Aktywna', 'bebop-sales-booster' ); ?>
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Nazwa', 'bebop-sales-booster' ); ?></span>
					<input class="regular-text" type="text" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $rule['name'] ); ?>">
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Priorytet', 'bebop-sales-booster' ); ?></span>
					<input type="number" min="0" max="100" name="<?php echo esc_attr( $field ); ?>[priority]" value="<?php echo esc_attr( $rule['priority'] ); ?>">
				</label>
				<div class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Miejsca wyświetlania', 'bebop-sales-booster' ); ?></span>
					<?php self::placement_checkboxes( $field . '[placements]', $rule['placements'] ); ?>
				</div>
			</td>
			<td>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Produkty wyzwalające', 'bebop-sales-booster' ); ?></span>
					<?php self::product_multiselect( $field . '[trigger_products][]', $rule['trigger_products'], 'bebop-sales-booster-trigger-' . $index ); ?>
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Kategorie wyzwalające', 'bebop-sales-booster' ); ?></span>
					<?php self::category_multiselect( $field . '[trigger_categories][]', $rule['trigger_categories'] ); ?>
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Koszyk od', 'bebop-sales-booster' ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $field ); ?>[min_cart_total]" value="<?php echo esc_attr( $rule['min_cart_total'] ); ?>" placeholder="0">
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Koszyk do', 'bebop-sales-booster' ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $field ); ?>[max_cart_total]" value="<?php echo esc_attr( $rule['max_cart_total'] ); ?>" placeholder="<?php esc_attr_e( 'bez limitu', 'bebop-sales-booster' ); ?>">
				</label>
				<label class="bebop-sales-booster-rule__line bebop-sales-booster-rule__check">
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[only_below_free_shipping]" value="1" <?php checked( ! empty( $rule['only_below_free_shipping'] ) ); ?>>
					<span><?php esc_html_e( 'Tylko przed odblokowaniem darmowej dostawy', 'bebop-sales-booster' ); ?></span>
				</label>
			</td>
			<td>
				<?php self::product_multiselect( $field . '[offer_products][]', $rule['offer_products'], 'bebop-sales-booster-offer-' . $index ); ?>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Komunikat na karcie', 'bebop-sales-booster' ); ?></span>
					<input class="regular-text" type="text" name="<?php echo esc_attr( $field ); ?>[message]" value="<?php echo esc_attr( $rule['message'] ); ?>" placeholder="<?php esc_attr_e( 'Pasuje do tego, co jest w koszyku.', 'bebop-sales-booster' ); ?>">
				</label>
			</td>
			<td>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Typ', 'bebop-sales-booster' ); ?></span>
					<select name="<?php echo esc_attr( $field ); ?>[discount_type]">
						<option value="none" <?php selected( $rule['discount_type'], 'none' ); ?>><?php esc_html_e( 'Bez rabatu', 'bebop-sales-booster' ); ?></option>
						<option value="percent" <?php selected( $rule['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Procent rabatu', 'bebop-sales-booster' ); ?></option>
						<option value="amount_off" <?php selected( $rule['discount_type'], 'amount_off' ); ?>><?php esc_html_e( 'Kwota rabatu', 'bebop-sales-booster' ); ?></option>
						<option value="fixed_price" <?php selected( $rule['discount_type'], 'fixed_price' ); ?>><?php esc_html_e( 'Stała cena', 'bebop-sales-booster' ); ?></option>
					</select>
				</label>
				<label class="bebop-sales-booster-rule__line">
					<span><?php esc_html_e( 'Wartość', 'bebop-sales-booster' ); ?></span>
					<input type="number" min="0" step="0.01" name="<?php echo esc_attr( $field ); ?>[discount_value]" value="<?php echo esc_attr( $rule['discount_value'] ); ?>">
				</label>
				<button type="button" class="button-link-delete bebop-sales-booster-remove-rule"><?php esc_html_e( 'Usuń wiersz', 'bebop-sales-booster' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Add WooCommerce product-side controls.
	 */
	public static function add_product_metabox() {
		add_meta_box(
			'bebop-sales-booster-product',
			__( 'BEBOP SALES BOOSTER', 'bebop-sales-booster' ),
			array( __CLASS__, 'render_product_metabox' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render product meta box.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function render_product_metabox( $post ) {
		$exclude = get_post_meta( $post->ID, '_bebop_sales_booster_exclude', true );
		$free    = get_post_meta( $post->ID, '_bebop_sales_booster_free_shipping', true );
		$mini    = get_post_meta( $post->ID, '_bebop_sales_booster_mini_cart', true );
		$priority = self::clamp_absint( get_post_meta( $post->ID, '_bebop_sales_booster_priority', true ), 0, 100 );
		$forced  = self::sanitize_absint_array( get_post_meta( $post->ID, '_bebop_sales_booster_forced_products', true ) );

		wp_nonce_field( self::META_NONCE, self::META_NONCE );
		?>
		<div class="bebop-sales-booster-product-meta">
			<p><?php esc_html_e( 'Steruj dorzutkami bezpośrednio z produktu WooCommerce.', 'bebop-sales-booster' ); ?></p>

			<label class="bebop-sales-booster-rule__line bebop-sales-booster-rule__check">
				<input type="checkbox" name="bebop_sales_booster_product_meta[exclude]" value="1" <?php checked( 'yes', $exclude ); ?>>
				<span><?php esc_html_e( 'Nie pokazuj tego produktu jako dorzutki', 'bebop-sales-booster' ); ?></span>
			</label>

			<label class="bebop-sales-booster-rule__line bebop-sales-booster-rule__check">
				<input type="checkbox" name="bebop_sales_booster_product_meta[free_shipping]" value="1" <?php checked( 'yes', $free ); ?>>
				<span><?php esc_html_e( 'Produkt do dobijania darmowej dostawy', 'bebop-sales-booster' ); ?></span>
			</label>

			<label class="bebop-sales-booster-rule__line bebop-sales-booster-rule__check">
				<input type="checkbox" name="bebop_sales_booster_product_meta[mini_cart]" value="1" <?php checked( 'yes', $mini ); ?>>
				<span><?php esc_html_e( 'Pokazuj jako szybką dorzutkę w mini-koszyku', 'bebop-sales-booster' ); ?></span>
			</label>

			<label class="bebop-sales-booster-rule__line">
				<span><?php esc_html_e( 'Priorytet rekomendacji', 'bebop-sales-booster' ); ?></span>
				<input type="number" min="0" max="100" name="bebop_sales_booster_product_meta[priority]" value="<?php echo esc_attr( $priority ); ?>">
			</label>

			<label class="bebop-sales-booster-rule__line">
				<span><?php esc_html_e( 'Wymuszone produkty do kompletu', 'bebop-sales-booster' ); ?></span>
				<?php self::product_multiselect( 'bebop_sales_booster_product_meta[forced_products][]', $forced, 'bebop-sales-booster-forced-products-' . absint( $post->ID ) ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Save product-side controls.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post Product post.
	 */
	public static function save_product_metabox( $post_id, $post ) {
		if ( ! isset( $_POST[ self::META_NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::META_NONCE ] ) ), self::META_NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['bebop_sales_booster_product_meta'] ) && is_array( $_POST['bebop_sales_booster_product_meta'] )
			? wp_unslash( $_POST['bebop_sales_booster_product_meta'] )
			: array();

		self::update_yes_no_meta( $post_id, '_bebop_sales_booster_exclude', ! empty( $input['exclude'] ) );
		self::update_yes_no_meta( $post_id, '_bebop_sales_booster_free_shipping', ! empty( $input['free_shipping'] ) );
		self::update_yes_no_meta( $post_id, '_bebop_sales_booster_mini_cart', ! empty( $input['mini_cart'] ) );

		$priority = self::clamp_absint( isset( $input['priority'] ) ? $input['priority'] : 0, 0, 100 );
		if ( $priority > 0 ) {
			update_post_meta( $post_id, '_bebop_sales_booster_priority', $priority );
		} else {
			delete_post_meta( $post_id, '_bebop_sales_booster_priority' );
		}
		
		$forced = self::sanitize_absint_array( isset( $input['forced_products'] ) ? $input['forced_products'] : array() );
		if ( ! empty( $forced ) ) {
			update_post_meta( $post_id, '_bebop_sales_booster_forced_products', $forced );
		} else {
			delete_post_meta( $post_id, '_bebop_sales_booster_forced_products' );
		}
	}

	/**
	 * Render product selector using WooCommerce's native product search endpoint.
	 *
	 * @param string $name Field name.
	 * @param array  $selected Selected product IDs.
	 * @param string $id Field ID.
	 */
	private static function product_multiselect( $name, $selected, $id ) {
		$selected = self::sanitize_absint_array( $selected );
		?>
		<select
			id="<?php echo esc_attr( $id ); ?>"
			class="wc-product-search"
			multiple="multiple"
			style="width: 100%;"
			name="<?php echo esc_attr( $name ); ?>"
			data-action="woocommerce_json_search_products_and_variations"
			data-placeholder="<?php esc_attr_e( 'Szukaj produktu lub wariantu...', 'bebop-sales-booster' ); ?>"
			data-allow_clear="true"
		>
			<?php foreach ( $selected as $product_id ) : ?>
				<?php $label = self::product_label( $product_id ); ?>
				<?php if ( '' !== $label ) : ?>
					<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render a product category multi-select.
	 *
	 * @param string $name Field name.
	 * @param array  $selected Selected category IDs.
	 */
	private static function category_multiselect( $name, $selected ) {
		$selected = self::sanitize_absint_array( $selected );
		$terms    = taxonomy_exists( 'product_cat' ) ? get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		) : array();
		?>
		<select multiple="multiple" class="bebop-sales-booster-category-select" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $selected, true ) ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render placement checkboxes.
	 *
	 * @param string $name Field name.
	 * @param array  $selected Selected placements.
	 */
	private static function placement_checkboxes( $name, $selected ) {
		$selected = self::sanitize_placements( $selected );
		$labels   = array(
			'cart'      => __( 'Koszyk', 'bebop-sales-booster' ),
			'checkout'  => __( 'Kasa', 'bebop-sales-booster' ),
			'mini_cart' => __( 'Mini-koszyk', 'bebop-sales-booster' ),
		);

		foreach ( $labels as $placement => $label ) :
			?>
			<label class="bebop-sales-booster-placement">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $placement ); ?>" <?php checked( in_array( $placement, $selected, true ) ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
		endforeach;
	}

	/**
	 * Echo a free-delivery bar for a position.
	 *
	 * @param string $position Bar position.
	 */
	private static function render_delivery_bar( $position ) {
		static $rendered = array();

		if ( ! empty( $rendered[ $position ] ) ) {
			return;
		}

		$html = self::delivery_bar_html( $position );

		if ( '' === $html ) {
			return;
		}

		$rendered[ $position ] = true;

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the free-delivery bar HTML.
	 *
	 * @param string $position Bar position.
	 * @return string
	 */
	private static function delivery_bar_html( $position ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! self::woocommerce_ready() || ! self::is_enabled() || ! self::delivery_bar_has_position( $position ) ) {
			return '';
		}

		$options  = self::options();
		$progress = self::delivery_bar_progress();

		if ( empty( $progress['threshold'] ) ) {
			return self::delivery_bar_debug_comment( $position, 'brak progu darmowej dostawy' );
		}

		$product = ! empty( $options['delivery_bar']['show_product'] ) && $progress['remaining'] > 0
			? self::delivery_bar_product( $progress )
			: false;

		$classes = array(
			'bebop-delivery-bar',
			'bebop-delivery-bar--' . sanitize_html_class( $position ),
		);

		if ( 'bottom' === $position && ! empty( $options['delivery_bar']['bottom_sticky'] ) ) {
			$classes[] = 'bebop-delivery-bar--sticky';
		}

		if ( $progress['remaining'] <= 0 ) {
			$classes[] = 'is-unlocked';
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			$classes[] = 'is-empty';
		}

		$actions = self::delivery_bar_actions( $position );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-delivery-bar="<?php echo esc_attr( $position ); ?>">
			<div class="bebop-delivery-bar__inner">
				<div class="bebop-delivery-bar__copy">
					<strong><?php echo esc_html( self::delivery_bar_message( $progress ) ); ?></strong>
					<?php if ( $product ) : ?>
						<span><?php echo esc_html( self::delivery_bar_product_message( $product, $progress ) ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $product ) : ?>
					<?php
					$product_url   = get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
					$can_quick_add = ! $product->is_type( 'variable' );
					?>
					<div class="bebop-delivery-bar__product">
						<a class="bebop-delivery-bar__thumb" href="<?php echo esc_url( $product_url ); ?>" aria-hidden="true" tabindex="-1">
							<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
						</a>
						<a class="bebop-delivery-bar__name" href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
						<span class="bebop-delivery-bar__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
						<?php if ( $can_quick_add ) : ?>
							<button
								type="button"
								class="bebop-delivery-bar__button bebop-sales-booster__add"
								data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
								data-rule-id="delivery_bar"
								data-placement="<?php echo esc_attr( 'delivery_bar_' . $position ); ?>"
							>
								<?php esc_html_e( 'Dorzuć', 'bebop-sales-booster' ); ?>
							</button>
						<?php else : ?>
							<a class="bebop-delivery-bar__button bebop-delivery-bar__button--choose" href="<?php echo esc_url( $product_url ); ?>" aria-label="<?php esc_attr_e( 'Wybierz rozmiar', 'bebop-sales-booster' ); ?>" title="<?php esc_attr_e( 'Wybierz rozmiar', 'bebop-sales-booster' ); ?>">
								<span aria-hidden="true">+</span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $actions ) ) : ?>
					<div class="bebop-delivery-bar__actions">
						<?php echo wp_kses_post( $actions ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return trim( ob_get_clean() );
	}

	/**
	 * Build bottom-bar cart actions.
	 *
	 * @param string $position Bar position.
	 * @return string
	 */
	private static function delivery_bar_actions( $position ) {
		if ( 'bottom' !== $position || ! self::woocommerce_ready() || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}

		$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		$links        = array();

		if ( $cart_url ) {
			$links[] = sprintf(
				'<a class="bebop-delivery-bar__button bebop-delivery-bar__button--cart" href="%1$s">%2$s</a>',
				esc_url( $cart_url ),
				esc_html__( 'Zobacz koszyk', 'bebop-sales-booster' )
			);
		}

		if ( $checkout_url ) {
			$links[] = sprintf(
				'<a class="bebop-delivery-bar__button bebop-delivery-bar__button--checkout" href="%1$s">%2$s</a>',
				esc_url( $checkout_url ),
				esc_html__( 'Zamówienie', 'bebop-sales-booster' )
			);
		}

		return implode( '', $links );
	}

	/**
	 * Render one offer area for a placement.
	 *
	 * @param string $placement Placement key.
	 */
	private static function render_offer_area( $placement ) {
		if ( ! self::woocommerce_ready() || ! self::is_enabled() || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$progress = self::free_shipping_progress();
		$offers   = self::offers_for_placement( $placement, $progress );

		if ( empty( $offers ) && empty( $progress['threshold'] ) ) {
			return;
		}

		$classes = 'bebop-sales-booster bebop-sales-booster--' . sanitize_html_class( $placement );
		?>
		<section class="<?php echo esc_attr( $classes ); ?>" data-placement="<?php echo esc_attr( $placement ); ?>">
			<?php if ( ! empty( $progress['threshold'] ) && self::placement_has_free_shipping( $placement ) ) : ?>
				<div class="bebop-sales-booster__progress">
					<div class="bebop-sales-booster__progress-copy">
						<strong><?php echo esc_html( $progress['message'] ); ?></strong>
						<?php if ( $progress['remaining'] > 0 ) : ?>
							<span><?php esc_html_e( 'Dorzuć coś jeszcze i zbliż się do darmowej dostawy.', 'bebop-sales-booster' ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'Darmowa dostawa jest odblokowana dla tego koszyka.', 'bebop-sales-booster' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="bebop-sales-booster__bar" aria-hidden="true">
						<span style="width: <?php echo esc_attr( $progress['percent'] ); ?>%;"></span>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $offers ) ) : ?>
				<div class="bebop-sales-booster__head">
					<h3><?php echo esc_html( self::placement_heading( $placement ) ); ?></h3>
				</div>
				<div class="bebop-sales-booster__grid">
					<?php foreach ( $offers as $offer ) : ?>
						<?php self::offer_card( $offer, $placement ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render one frontend offer card.
	 *
	 * @param array  $offer Offer payload.
	 * @param string $placement Placement key.
	 */
	private static function offer_card( $offer, $placement ) {
		$product       = $offer['product'];
		$can_quick_add = ! $product->is_type( 'variable' );
		$product_url   = get_permalink( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
		$icon_action   = 'mini_cart' === $placement;
		?>
		<article class="bebop-sales-booster__card" data-rule-id="<?php echo esc_attr( $offer['rule_id'] ); ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<a class="bebop-sales-booster__image" href="<?php echo esc_url( $product_url ); ?>">
				<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
			</a>
			<div class="bebop-sales-booster__content">
				<p class="bebop-sales-booster__kicker"><?php echo esc_html( $offer['message'] ); ?></p>
				<h4><a href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h4>
				<div class="bebop-sales-booster__price"><?php echo wp_kses_post( self::offer_price_html( $product, $offer ) ); ?></div>
				<?php if ( self::debug_enabled() && ! empty( $offer['debug'] ) ) : ?>
					<p class="bebop-sales-booster__debug"><?php echo esc_html( $offer['debug'] ); ?></p>
				<?php endif; ?>
			</div>
			<div class="bebop-sales-booster__actions">
				<?php if ( $can_quick_add ) : ?>
					<button
						type="button"
						class="button bebop-sales-booster__add <?php echo esc_attr( $icon_action ? 'bebop-sales-booster__add--icon' : '' ); ?>"
						data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
						data-rule-id="<?php echo esc_attr( $offer['rule_id'] ); ?>"
						data-placement="<?php echo esc_attr( $placement ); ?>"
						<?php if ( $icon_action ) : ?>
							aria-label="<?php esc_attr_e( 'Dorzuć do koszyka', 'bebop-sales-booster' ); ?>"
							title="<?php esc_attr_e( 'Dorzuć do koszyka', 'bebop-sales-booster' ); ?>"
						<?php endif; ?>
					>
						<?php if ( $icon_action ) : ?>
							<span aria-hidden="true">+</span>
						<?php else : ?>
							<?php esc_html_e( 'Dorzuć', 'bebop-sales-booster' ); ?>
						<?php endif; ?>
					</button>
				<?php else : ?>
					<a class="button bebop-sales-booster__choose" href="<?php echo esc_url( $product_url ); ?>" aria-label="<?php esc_attr_e( 'Wybierz rozmiar', 'bebop-sales-booster' ); ?>" title="<?php esc_attr_e( 'Wybierz rozmiar', 'bebop-sales-booster' ); ?>">
						<span aria-hidden="true">+</span>
					</a>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	/**
	 * Get offer payloads for a placement.
	 *
	 * @param string $placement Placement key.
	 * @param array  $progress Free-shipping progress.
	 * @return array
	 */
	private static function offers_for_placement( $placement, $progress ) {
		$options  = self::options();
		$offers   = array();
		$seen_ids = array();

		if ( self::placement_has_free_shipping( $placement ) && ! empty( $options['free_shipping']['enabled'] ) && ! empty( $progress['remaining'] ) ) {
			$free_products = self::rank_free_shipping_products( self::free_shipping_candidate_product_ids(), $progress['remaining'], $placement );

			foreach ( $free_products as $product ) {
				if ( self::offer_should_be_hidden( $product, $placement ) ) {
					continue;
				}

				$seen_ids[ $product->get_id() ] = true;
				$offers[] = array(
					'rule_id'        => 'free_shipping',
					'name'           => __( 'Darmowa dostawa', 'bebop-sales-booster' ),
					'message'        => __( 'Dobija do darmowej dostawy', 'bebop-sales-booster' ),
					'product'        => $product,
					'discount_type'  => 'none',
					'discount_value' => '',
					'priority'       => 100,
					'debug'          => self::free_shipping_debug_label( $progress['remaining'] ),
				);
			}
		}

		if ( self::placement_has_related( $placement ) ) {
			$related_offers = self::related_offers_for_cart( $placement, min( (int) $options['related']['cart_limit'], self::placement_offer_limit( $placement ) ), array_keys( $seen_ids ) );

			foreach ( $related_offers as $offer ) {
				$product = $offer['product'];

				if ( ! empty( $seen_ids[ $product->get_id() ] ) || self::offer_should_be_hidden( $product, $placement ) ) {
					continue;
				}

				$seen_ids[ $product->get_id() ] = true;
				$offers[] = $offer;
			}
		}

		foreach ( self::meta_offers_for_placement( $placement, array_keys( $seen_ids ) ) as $offer ) {
			$product = $offer['product'];

			if ( ! empty( $seen_ids[ $product->get_id() ] ) || self::offer_should_be_hidden( $product, $placement ) ) {
				continue;
			}

			$seen_ids[ $product->get_id() ] = true;
			$offers[] = $offer;
		}

		$context = self::cart_context();
		foreach ( $options['rules'] as $rule ) {
			if ( empty( $rule['enabled'] ) || ! in_array( $placement, $rule['placements'], true ) || ! self::rule_matches_cart( $rule, $context ) ) {
				continue;
			}

			foreach ( $rule['offer_products'] as $product_id ) {
				$product = self::offerable_product( $product_id );

				if ( ! $product || ! empty( $seen_ids[ $product->get_id() ] ) || self::offer_should_be_hidden( $product, $placement ) ) {
					continue;
				}

				$seen_ids[ $product->get_id() ] = true;
				$offers[] = array(
					'rule_id'        => $rule['id'],
					'name'           => $rule['name'],
					'message'        => '' !== $rule['message'] ? $rule['message'] : __( 'Dorzuć do koszyka', 'bebop-sales-booster' ),
					'product'        => $product,
					'discount_type'  => $rule['discount_type'],
					'discount_value' => $rule['discount_value'],
					'priority'       => 200 + (int) $rule['priority'],
					'debug'          => self::manual_rule_debug_label( $rule, $context ),
				);
			}
		}

		usort(
			$offers,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);

		return array_slice( $offers, 0, min( (int) $options['max_offers'], self::placement_offer_limit( $placement ) ) );
	}

	/**
	 * Build offers from product-level flags.
	 *
	 * @param string $placement Placement key.
	 * @param array  $exclude_product_ids Product IDs already used.
	 * @return array
	 */
	private static function meta_offers_for_placement( $placement, $exclude_product_ids = array() ) {
		if ( 'mini_cart' !== $placement ) {
			return array();
		}

		$exclude_product_ids = self::sanitize_absint_array( $exclude_product_ids );
		$offers              = array();

		foreach ( self::product_ids_by_meta( '_bebop_sales_booster_mini_cart', 'yes', 80 ) as $product_id ) {
			if ( in_array( $product_id, $exclude_product_ids, true ) ) {
				continue;
			}

			$product = self::offerable_product( $product_id );
			if ( ! $product || self::offer_should_be_hidden( $product, $placement ) ) {
				continue;
			}

			$offers[] = array(
				'rule_id'        => 'meta_mini_cart',
				'name'           => __( 'Mini-koszyk', 'bebop-sales-booster' ),
				'message'        => __( 'Szybka dorzutka', 'bebop-sales-booster' ),
				'product'        => $product,
				'discount_type'  => 'none',
				'discount_value' => '',
				'priority'       => 150 + self::product_recommendation_priority( $product ),
				'debug'          => __( 'Oznaczone w edycji produktu jako mini-koszyk.', 'bebop-sales-booster' ),
			);
		}

		return $offers;
	}

	/**
	 * Build related offers for all cart products.
	 *
	 * @param string $placement Placement key.
	 * @param int    $limit Number of offers.
	 * @param array  $exclude_product_ids Product IDs to skip.
	 * @return array
	 */
	private static function related_offers_for_cart( $placement, $limit, $exclude_product_ids = array() ) {
		if ( ! WC()->cart ) {
			return array();
		}

		$offers            = array();
		$exclude_product_ids = self::sanitize_absint_array( $exclude_product_ids );

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$source_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : absint( $cart_item['product_id'] );

			if ( empty( $source_id ) ) {
				continue;
			}

			$exclude_product_ids[] = $source_id;
			$source_offers = self::related_offers_for_source( $source_id, $placement, $limit, $exclude_product_ids );

			foreach ( $source_offers as $offer ) {
				$product_id = $offer['product']->get_id();

				if ( isset( $offers[ $product_id ] ) && $offers[ $product_id ]['priority'] >= $offer['priority'] ) {
					continue;
				}

				$offers[ $product_id ] = $offer;
			}
		}

		usort(
			$offers,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);

		return array_slice( array_values( $offers ), 0, $limit );
	}

	/**
	 * Get automatic related offers for one source product.
	 *
	 * @param int    $source_id Product or variation ID.
	 * @param string $placement Placement key.
	 * @param int    $limit Number of offers.
	 * @param array  $exclude_product_ids Product IDs to skip.
	 * @return array
	 */
	private static function related_offers_for_source( $source_id, $placement, $limit, $exclude_product_ids = array() ) {
		$options     = self::options();
		$source      = wc_get_product( $source_id );
		$source_tags = self::product_tag_slugs( $source_id );

		if ( ! $source || empty( $source_tags ) ) {
			return array();
		}

		$exclude_product_ids = self::sanitize_absint_array( $exclude_product_ids );
		$exclude_product_ids[] = $source->is_type( 'variation' ) ? $source->get_parent_id() : $source->get_id();

		$candidate_ids = self::candidate_product_ids_for_tags( $source_tags, $exclude_product_ids );
		$ranked        = array();
		$ranked_ids    = array();

		foreach ( self::product_forced_product_ids( $source_id ) as $forced_id ) {
			if ( in_array( $forced_id, $exclude_product_ids, true ) ) {
				continue;
			}

			$product = self::offerable_product( $forced_id );
			if ( ! $product || self::offer_should_be_hidden( $product, $placement ) ) {
				continue;
			}

			$ranked_ids[ $product->get_id() ] = true;
			$ranked[] = array(
				'rule_id'        => 'related_' . absint( $source_id ),
				'name'           => __( 'Do kompletu', 'bebop-sales-booster' ),
				'message'        => __( 'Do kompletu', 'bebop-sales-booster' ),
				'product'        => $product,
				'discount_type'  => 'none',
				'discount_value' => '',
				'priority'       => 600 + self::product_recommendation_priority( $product ),
				'debug'          => sprintf(
					/* translators: %d: source product ID. */
					__( 'Wymuszone w edycji produktu #%d.', 'bebop-sales-booster' ),
					absint( $source_id )
				),
			);
		}

		foreach ( $candidate_ids as $candidate_id ) {
			$product = self::offerable_product( $candidate_id );

			if ( ! $product || ! empty( $ranked_ids[ $product->get_id() ] ) || self::offer_should_be_hidden( $product, $placement ) ) {
				continue;
			}

			$score = self::related_score( $source, $source_tags, $product );

			if ( $score['score'] < (int) $options['related']['min_score'] ) {
				continue;
			}

			$ranked[] = array(
				'rule_id'        => 'related_' . absint( $source_id ),
				'name'           => __( 'Ten sam klimat', 'bebop-sales-booster' ),
				'message'        => self::related_message( $score['reason'], $placement ),
				'product'        => $product,
				'discount_type'  => 'none',
				'discount_value' => '',
				'priority'       => (int) $score['score'] + self::product_recommendation_priority( $product ),
				'debug'          => sprintf(
					/* translators: 1: source product ID, 2: matching score. */
					__( 'Powiązane z #%1$d, wynik %2$d.', 'bebop-sales-booster' ),
					absint( $source_id ),
					(int) $score['score']
				),
			);
		}

		usort(
			$ranked,
			static function ( $a, $b ) {
				return (int) $b['priority'] <=> (int) $a['priority'];
			}
		);

		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Get candidate product IDs sharing at least one useful product tag.
	 *
	 * @param array $source_tags Source tag slugs.
	 * @param array $exclude_product_ids Product IDs to skip.
	 * @return array
	 */
	private static function candidate_product_ids_for_tags( $source_tags, $exclude_product_ids ) {
		$query_tags = array_values(
			array_filter(
				$source_tags,
				static function ( $tag ) {
					return self::tag_weight( $tag ) > 0;
				}
			)
		);

		if ( self::has_tag( $source_tags, 'upsell-bundle-core' ) ) {
			$query_tags = array_merge( $query_tags, array( 'upsell-bundle-addon', 'upsell-addon', 'upsell-checkout-bump' ) );
		}

		if ( self::has_any_tag( $source_tags, array( 'upsell-bundle-addon', 'upsell-addon', 'upsell-checkout-bump' ) ) ) {
			$query_tags[] = 'upsell-bundle-core';
		}

		if ( self::has_tag( $source_tags, 'upsell-entry' ) ) {
			$query_tags[] = 'upsell-premium';
		}

		$query_tags = array_values( array_unique( $query_tags ) );

		if ( empty( $query_tags ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'post__not_in'           => self::sanitize_absint_array( $exclude_product_ids ),
				'post_status'            => 'publish',
				'post_type'              => 'product',
				'posts_per_page'         => 120,
				'tax_query'              => array(
					array(
						'field'    => 'slug',
						'taxonomy' => 'product_tag',
						'terms'    => $query_tags,
					),
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => true,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Score a candidate product against a source product.
	 *
	 * @param WC_Product $source Source product.
	 * @param array      $source_tags Source tag slugs.
	 * @param WC_Product $candidate Candidate product.
	 * @return array
	 */
	private static function related_score( $source, $source_tags, $candidate ) {
		$options        = self::options();
		$candidate_tags = self::product_tag_slugs( $candidate->get_id() );
		$score          = 0;
		$reason         = 'similar';

		foreach ( array_intersect( $source_tags, $candidate_tags ) as $tag ) {
			$score += self::tag_weight( $tag );
		}

		if ( self::has_tag( $source_tags, 'upsell-bundle-core' ) && self::has_any_tag( $candidate_tags, array( 'upsell-bundle-addon', 'upsell-addon', 'upsell-checkout-bump' ) ) ) {
			$score += 5;
			$reason = 'complete';
		}

		if ( self::has_any_tag( $source_tags, array( 'upsell-bundle-addon', 'upsell-addon', 'upsell-checkout-bump' ) ) && self::has_tag( $candidate_tags, 'upsell-bundle-core' ) ) {
			$score += 5;
			$reason = 'complete';
		}

		if ( self::has_tag( $source_tags, 'upsell-entry' ) && self::has_tag( $candidate_tags, 'upsell-premium' ) ) {
			$score += 3;
			$reason = 'premium';
		}

		if ( self::is_complementary_type( $source_tags, $candidate_tags ) ) {
			$score += 'similar' === $options['related']['strategy'] ? 1 : 5;
			$reason = 'complete';
		} elseif ( self::has_same_product_type( $source_tags, $candidate_tags ) && 'complete' !== $options['related']['strategy'] ) {
			$score += 2;
		}

		if ( self::shares_tag_prefix( $source_tags, $candidate_tags, 'motyw-' ) && self::shares_tag_prefix( $source_tags, $candidate_tags, 'kolekcja-' ) ) {
			$score += 3;
		}

		if ( self::prices_are_near( $source, $candidate ) ) {
			$score += 1;
		}

		return array(
			'score'  => $score,
			'reason' => $reason,
		);
	}

	/**
	 * Product tag slugs for a product or variation.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array
	 */
	private static function product_tag_slugs( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$tag_source_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms         = get_the_terms( $tag_source_id, 'product_tag' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'sanitize_title', wp_list_pluck( $terms, 'slug' ) ) );
	}

	/**
	 * Weight a tag for related-product matching.
	 *
	 * @param string $tag Tag slug.
	 * @return int
	 */
	private static function tag_weight( $tag ) {
		$options = self::options();
		if ( in_array( $tag, $options['related']['ignored_tags'], true ) ) {
			return 0;
		}

		if ( 0 === strpos( $tag, 'motyw-' ) ) {
			return 7;
		}

		if ( 0 === strpos( $tag, 'kolekcja-' ) ) {
			return 6;
		}

		if ( 0 === strpos( $tag, 'linia-' ) ) {
			return 4;
		}

		if ( in_array( $tag, array( 'oversize', 'vintage-fit', 'basic-fit', 'cropped' ), true ) ) {
			return 3;
		}

		if ( 0 === strpos( $tag, 'typ-' ) ) {
			return 2;
		}

		if ( 0 === strpos( $tag, 'price-' ) || in_array( $tag, array( 'sale', 'unikat', 'upcycled', 'second-hand', 'one-of-one', 'made-to-order' ), true ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Create a human-facing reason for the recommendation.
	 *
	 * @param string $reason Reason key.
	 * @param string $placement Placement key.
	 * @return string
	 */
	private static function related_message( $reason, $placement ) {
		if ( 'premium' === $reason ) {
			return __( 'Wyższy level', 'bebop-sales-booster' );
		}

		if ( 'complete' === $reason ) {
			return __( 'Do kompletu', 'bebop-sales-booster' );
		}

		if ( 'product_page' === $placement ) {
			return __( 'Ten sam klimat', 'bebop-sales-booster' );
		}

		return __( 'Do Twojego koszyka', 'bebop-sales-booster' );
	}

	/**
	 * Admin debug label for a free-shipping recommendation.
	 *
	 * @param float $remaining Missing amount.
	 * @return string
	 */
	private static function free_shipping_debug_label( $remaining ) {
		$options = self::options();
		$modes   = array(
			'unlock_first' => __( 'najpierw odblokowanie dostawy', 'bebop-sales-booster' ),
			'closest'      => __( 'najbliżej brakującej kwoty', 'bebop-sales-booster' ),
			'under'        => __( 'tylko poniżej brakującej kwoty', 'bebop-sales-booster' ),
		);
		$mode    = isset( $modes[ $options['free_shipping']['match_mode'] ] ) ? $modes[ $options['free_shipping']['match_mode'] ] : $modes['unlock_first'];

		return sprintf(
			/* translators: 1: missing amount, 2: matching mode. */
			__( 'Darmowa dostawa: brakuje %1$s, tryb: %2$s.', 'bebop-sales-booster' ),
			wp_strip_all_tags( wc_price( (float) $remaining ) ),
			$mode
		);
	}

	/**
	 * Admin debug label for a manual rule.
	 *
	 * @param array $rule Rule data.
	 * @param array $context Cart context.
	 * @return string
	 */
	private static function manual_rule_debug_label( $rule, $context ) {
		$parts = array();

		if ( ! empty( $rule['trigger_products'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: product count. */
				__( 'produkty: %d', 'bebop-sales-booster' ),
				count( $rule['trigger_products'] )
			);
		}

		if ( ! empty( $rule['trigger_categories'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: category count. */
				__( 'kategorie: %d', 'bebop-sales-booster' ),
				count( $rule['trigger_categories'] )
			);
		}

		if ( '' !== $rule['min_cart_total'] || '' !== $rule['max_cart_total'] ) {
			$parts[] = sprintf(
				/* translators: %s: cart subtotal. */
				__( 'koszyk: %s', 'bebop-sales-booster' ),
				wp_strip_all_tags( wc_price( isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0 ) )
			);
		}

		if ( empty( $parts ) ) {
			$parts[] = __( 'globalna reguła', 'bebop-sales-booster' );
		}

		return sprintf(
			/* translators: 1: rule name, 2: condition summary. */
			__( 'Reguła "%1$s" pasuje: %2$s.', 'bebop-sales-booster' ),
			$rule['name'],
			implode( ', ', $parts )
		);
	}

	/**
	 * Check if a related offer can still be added.
	 *
	 * @param int $source_id Source product or variation ID.
	 * @param int $product_id Offer product or variation ID.
	 * @return bool
	 */
	private static function related_add_is_allowed( $source_id, $product_id ) {
		$options     = self::options();
		$source      = wc_get_product( $source_id );
		$product     = wc_get_product( $product_id );
		$source_tags = self::product_tag_slugs( $source_id );

		if ( ! $source || ! $product || empty( $source_tags ) ) {
			return false;
		}

		if ( self::candidate_ids_contain_product( $product_id, self::product_forced_product_ids( $source_id ) ) ) {
			return true;
		}

		$score = self::related_score( $source, $source_tags, $product );

		return $score['score'] >= (int) $options['related']['min_score'];
	}

	/**
	 * Does a tag list contain a tag?
	 *
	 * @param array  $tags Tag slugs.
	 * @param string $tag Tag slug.
	 * @return bool
	 */
	private static function has_tag( $tags, $tag ) {
		return in_array( $tag, $tags, true );
	}

	/**
	 * Does a tag list contain any of the provided tags?
	 *
	 * @param array $tags Tag slugs.
	 * @param array $wanted Wanted tag slugs.
	 * @return bool
	 */
	private static function has_any_tag( $tags, $wanted ) {
		return (bool) array_intersect( $tags, $wanted );
	}

	/**
	 * Check if two products naturally complete each other by product type.
	 *
	 * @param array $source_tags Source tag slugs.
	 * @param array $candidate_tags Candidate tag slugs.
	 * @return bool
	 */
	private static function is_complementary_type( $source_tags, $candidate_tags ) {
		$tops        = array( 'typ-tshirt', 'typ-bluza', 'typ-kurtka' );
		$bottoms     = array( 'typ-spodnie' );
		$outfits     = array( 'typ-sukienka' );
		$accessories = array( 'typ-czapka', 'typ-frotka', 'typ-rekawiczki', 'typ-szalik', 'typ-parasol', 'typ-poduszka', 'typ-bielizna' );
		$gifts       = array( 'typ-karta-podarunkowa', 'typ-mystery-box' );

		$source_is_main      = self::has_any_tag( $source_tags, array_merge( $tops, $bottoms, $outfits ) );
		$candidate_is_main   = self::has_any_tag( $candidate_tags, array_merge( $tops, $bottoms, $outfits ) );
		$source_is_top       = self::has_any_tag( $source_tags, $tops );
		$candidate_is_top    = self::has_any_tag( $candidate_tags, $tops );
		$source_is_bottom    = self::has_any_tag( $source_tags, $bottoms );
		$candidate_is_bottom = self::has_any_tag( $candidate_tags, $bottoms );

		if ( $source_is_main && self::has_any_tag( $candidate_tags, $accessories ) ) {
			return true;
		}

		if ( $source_is_top && $candidate_is_bottom ) {
			return true;
		}

		if ( $source_is_bottom && $candidate_is_top ) {
			return true;
		}

		if ( $candidate_is_main && self::has_any_tag( $source_tags, $accessories ) ) {
			return true;
		}

		if ( $source_is_main && self::has_any_tag( $candidate_tags, $gifts ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if products share the same product-type tag.
	 *
	 * @param array $source_tags Source tag slugs.
	 * @param array $candidate_tags Candidate tag slugs.
	 * @return bool
	 */
	private static function has_same_product_type( $source_tags, $candidate_tags ) {
		foreach ( array_intersect( $source_tags, $candidate_tags ) as $tag ) {
			if ( 0 === strpos( $tag, 'typ-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether two products share a tag with a given prefix.
	 *
	 * @param array  $source_tags Source tag slugs.
	 * @param array  $candidate_tags Candidate tag slugs.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	private static function shares_tag_prefix( $source_tags, $candidate_tags, $prefix ) {
		foreach ( array_intersect( $source_tags, $candidate_tags ) as $tag ) {
			if ( 0 === strpos( $tag, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if product prices are close enough to be a comfortable recommendation.
	 *
	 * @param WC_Product $source Source product.
	 * @param WC_Product $candidate Candidate product.
	 * @return bool
	 */
	private static function prices_are_near( $source, $candidate ) {
		$source_price    = (float) $source->get_price();
		$candidate_price = (float) $candidate->get_price();

		if ( $source->is_type( 'variable' ) && method_exists( $source, 'get_variation_price' ) ) {
			$source_price = (float) $source->get_variation_price( 'min', true );
		}

		if ( $candidate->is_type( 'variable' ) && method_exists( $candidate, 'get_variation_price' ) ) {
			$candidate_price = (float) $candidate->get_variation_price( 'min', true );
		}

		if ( $source_price <= 0 || $candidate_price <= 0 ) {
			return false;
		}

		$highest = max( $source_price, $candidate_price );

		return ( abs( $source_price - $candidate_price ) / $highest ) <= 0.35;
	}

	/**
	 * Rank free-shipping products by how neatly they cover the missing amount.
	 *
	 * @param array  $product_ids Product IDs.
	 * @param float  $remaining Missing amount.
	 * @param string $placement Placement key.
	 * @return array
	 */
	private static function rank_free_shipping_products( $product_ids, $remaining, $placement = '' ) {
		$options  = self::options();
		$mode     = $options['free_shipping']['match_mode'];
		$products = array();

		foreach ( $product_ids as $product_id ) {
			$product = self::offerable_product( $product_id );

			if ( ! $product || self::offer_should_be_hidden( $product, $placement ) ) {
				continue;
			}

			$price = self::product_display_price( $product );

			if ( 'under' === $mode && $price > $remaining ) {
				continue;
			}

			if ( 'closest' === $mode ) {
				$rank = abs( $price - $remaining );
			} elseif ( 'under' === $mode ) {
				$rank = $remaining - $price;
			} else {
				$rank = $price >= $remaining ? $price - $remaining : $remaining - $price + 10000;
			}

			$rank = max( 0, $rank - ( self::product_recommendation_priority( $product ) * 10 ) );

			$products[] = array(
				'product' => $product,
				'rank'    => $rank,
			);
		}

		usort(
			$products,
			static function ( $a, $b ) {
				return $a['rank'] <=> $b['rank'];
			}
		);

		return array_map(
			static function ( $item ) {
				return $item['product'];
			},
			$products
		);
	}

	/**
	 * Get the display price used for recommendation ranking.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	private static function product_display_price( $product ) {
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$price = (float) $product->get_variation_price( 'min', true );

			return (float) wc_get_price_to_display( $product, array( 'price' => $price ) );
		}

		return (float) wc_get_price_to_display( $product );
	}

	/**
	 * Find the live offer config for an AJAX add request.
	 *
	 * @param string $rule_id Rule ID.
	 * @param int    $product_id Product ID.
	 * @return array
	 */
	private static function find_offer_for_add( $rule_id, $product_id ) {
		$options = self::options();

		if ( 'free_shipping' === $rule_id && self::candidate_ids_contain_product( $product_id, self::free_shipping_candidate_product_ids() ) ) {
			return array(
				'name'           => __( 'Darmowa dostawa', 'bebop-sales-booster' ),
				'discount_type'  => 'none',
				'discount_value' => '',
			);
		}

		if ( 'meta_mini_cart' === $rule_id && self::product_has_meta_flag( $product_id, '_bebop_sales_booster_mini_cart' ) ) {
			return array(
				'name'           => __( 'Mini-koszyk', 'bebop-sales-booster' ),
				'discount_type'  => 'none',
				'discount_value' => '',
			);
		}

		if ( 'delivery_bar' === $rule_id && self::delivery_bar_product_is_allowed( $product_id ) ) {
			return array(
				'name'           => __( 'Pasek darmowej dostawy', 'bebop-sales-booster' ),
				'discount_type'  => 'none',
				'discount_value' => '',
			);
		}

		if ( 0 === strpos( $rule_id, 'related_' ) ) {
			$source_id = absint( substr( $rule_id, strlen( 'related_' ) ) );

			if ( $source_id > 0 && self::related_add_is_allowed( $source_id, $product_id ) ) {
				return array(
					'name'           => __( 'Ten sam klimat', 'bebop-sales-booster' ),
					'discount_type'  => 'none',
					'discount_value' => '',
				);
			}
		}

		foreach ( $options['rules'] as $rule ) {
			if ( $rule_id === $rule['id'] && ! empty( $rule['enabled'] ) && in_array( $product_id, $rule['offer_products'], true ) ) {
				return array(
					'name'           => $rule['name'],
					'discount_type'  => $rule['discount_type'],
					'discount_value' => $rule['discount_value'],
				);
			}
		}

		return array();
	}

	/**
	 * Check if a placement should show the free shipping block.
	 *
	 * @param string $placement Placement key.
	 * @return bool
	 */
	private static function placement_has_free_shipping( $placement ) {
		$options = self::options();

		return ! empty( $options['free_shipping']['enabled'] ) && in_array( $placement, $options['free_shipping']['placements'], true );
	}

	/**
	 * Check if a placement should show automatic related products.
	 *
	 * @param string $placement Placement key.
	 * @return bool
	 */
	private static function placement_has_related( $placement ) {
		$options = self::options();

		if ( 'checkout' === $placement ) {
			return ! empty( $options['related']['checkout_enabled'] );
		}

		if ( in_array( $placement, array( 'cart', 'mini_cart' ), true ) ) {
			return ! empty( $options['related']['cart_enabled'] );
		}

		if ( 'product_page' === $placement ) {
			return ! empty( $options['related']['product_enabled'] );
		}

		return false;
	}

	/**
	 * Check if the free-delivery bar should render in a position.
	 *
	 * @param string $position Bar position.
	 * @return bool
	 */
	private static function delivery_bar_has_position( $position ) {
		$options = self::options();

		if ( empty( $options['delivery_bar']['enabled'] ) ) {
			return false;
		}

		if ( 'top' === $position ) {
			return ! empty( $options['delivery_bar']['top_enabled'] );
		}

		if ( 'bottom' === $position ) {
			return ! empty( $options['delivery_bar']['bottom_enabled'] );
		}

		return false;
	}

	/**
	 * Get refreshed delivery bar HTML keyed by position.
	 *
	 * @return array
	 */
	private static function delivery_bar_fragments() {
		return array(
			'top'    => self::delivery_bar_html( 'top' ),
			'bottom' => self::delivery_bar_html( 'bottom' ),
		);
	}

	/**
	 * Build delivery-bar progress with an optional fallback threshold.
	 *
	 * @return array
	 */
	private static function delivery_bar_progress() {
		$progress = self::free_shipping_progress();

		if ( ! empty( $progress['threshold'] ) ) {
			return $progress;
		}

		$options   = self::options();
		$threshold = isset( $options['delivery_bar']['fallback_threshold'] ) ? (float) $options['delivery_bar']['fallback_threshold'] : 0;

		if ( $threshold <= 0 ) {
			return $progress;
		}

		$subtotal  = self::cart_subtotal_for_threshold();
		$remaining = max( 0, $threshold - $subtotal );
		$percent   = min( 100, max( 0, ( $subtotal / $threshold ) * 100 ) );
		$message   = $remaining > 0
			? str_replace( '{amount}', wp_strip_all_tags( wc_price( $remaining ) ), self::defaults()['free_shipping']['message'] )
			: __( 'Darmowa dostawa odblokowana.', 'bebop-sales-booster' );

		return array(
			'threshold' => $threshold,
			'subtotal'  => $subtotal,
			'remaining' => $remaining,
			'percent'   => round( $percent, 2 ),
			'message'   => $message,
		);
	}

	/**
	 * Show a small admin-only debug note when the bar cannot render.
	 *
	 * @param string $position Bar position.
	 * @param string $reason Reason.
	 * @return string
	 */
	private static function delivery_bar_debug_comment( $position, $reason ) {
		if ( current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			return "\n<!-- BEBOP SALES BOOSTER: pasek " . esc_html( $position ) . " ukryty, powód: " . esc_html( $reason ) . " -->\n";
		}

		return '';
	}

	/**
	 * Human-facing free-delivery bar message.
	 *
	 * @param array $progress Free-shipping progress.
	 * @return string
	 */
	private static function delivery_bar_message( $progress ) {
		if ( empty( $progress['threshold'] ) ) {
			return '';
		}

		if ( $progress['remaining'] <= 0 ) {
			return __( 'Masz darmową dostawę!', 'bebop-sales-booster' );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return sprintf(
				/* translators: %s: free delivery threshold. */
				__( 'Darmowa dostawa od %s', 'bebop-sales-booster' ),
				wp_strip_all_tags( wc_price( $progress['threshold'] ) )
			);
		}

		return sprintf(
			/* translators: %s: missing amount. */
			__( 'Brakuje %s do darmowej dostawy', 'bebop-sales-booster' ),
			wp_strip_all_tags( wc_price( $progress['remaining'] ) )
		);
	}

	/**
	 * Human-facing product suggestion line for the delivery bar.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $progress Free-shipping progress.
	 * @return string
	 */
	private static function delivery_bar_product_message( $product, $progress ) {
		$price = (float) wc_get_price_to_display( $product );

		if ( $price >= (float) $progress['remaining'] ) {
			return sprintf(
				/* translators: %s: product name. */
				__( 'Dorzuć %s i odblokuj darmową dostawę', 'bebop-sales-booster' ),
				$product->get_name()
			);
		}

		return sprintf(
			/* translators: %s: product name. */
			__( 'Do koszyka pasuje: %s', 'bebop-sales-booster' ),
			$product->get_name()
		);
	}

	/**
	 * Pick one product for the delivery bar.
	 *
	 * @param array $progress Free-shipping progress.
	 * @return WC_Product|false
	 */
	private static function delivery_bar_product( $progress ) {
		if ( empty( $progress['remaining'] ) ) {
			return false;
		}

		$product_ids = self::delivery_bar_candidate_product_ids();

		if ( empty( $product_ids ) ) {
			return false;
		}

		$products = self::rank_free_shipping_products( $product_ids, (float) $progress['remaining'], 'delivery_bar' );

		return empty( $products ) ? false : reset( $products );
	}

	/**
	 * Get possible products for the delivery bar.
	 *
	 * @return array
	 */
	private static function delivery_bar_candidate_product_ids() {
		$options    = self::options();
		$source     = $options['delivery_bar']['product_source'];
		$product_ids = array();

		if ( in_array( $source, array( 'mixed', 'manual' ), true ) ) {
			$product_ids = array_merge( $product_ids, $options['delivery_bar']['product_ids'] );
		}

		if ( 'mixed' === $source ) {
			$product_ids = array_merge( $product_ids, self::free_shipping_candidate_product_ids() );
		}

		if ( in_array( $source, array( 'mixed', 'tag' ), true ) && '' !== $options['delivery_bar']['tag_slug'] ) {
			$product_ids = array_merge( $product_ids, self::product_ids_by_tag( $options['delivery_bar']['tag_slug'], 80 ) );
		}

		return self::sanitize_absint_array( $product_ids );
	}

	/**
	 * Get published product IDs by tag slug.
	 *
	 * @param string $tag_slug Product tag slug.
	 * @param int    $limit Max products.
	 * @return array
	 */
	private static function product_ids_by_tag( $tag_slug, $limit ) {
		if ( ! taxonomy_exists( 'product_tag' ) || '' === $tag_slug ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'post_status'            => 'publish',
				'post_type'              => 'product',
				'posts_per_page'         => self::clamp_absint( $limit, 1, 200 ),
				'tax_query'              => array(
					array(
						'field'    => 'slug',
						'taxonomy' => 'product_tag',
						'terms'    => array( $tag_slug ),
					),
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Check if a delivery bar product can still be added.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	private static function delivery_bar_product_is_allowed( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! self::offerable_product( $product_id ) || self::offer_should_be_hidden( $product, 'delivery_bar' ) ) {
			return false;
		}

		$options       = self::options();
		$candidate_ids = self::delivery_bar_candidate_product_ids();
		$parent_id     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( in_array( $product->get_id(), $candidate_ids, true ) || in_array( $parent_id, $candidate_ids, true ) ) {
			return true;
		}

		return in_array( $options['delivery_bar']['product_source'], array( 'mixed', 'tag' ), true )
			&& '' !== $options['delivery_bar']['tag_slug']
			&& self::has_tag( self::product_tag_slugs( $parent_id ), $options['delivery_bar']['tag_slug'] );
	}

	/**
	 * Build the free-shipping progress model.
	 *
	 * @return array
	 */
	private static function free_shipping_progress() {
		$options   = self::options();
		$threshold = self::free_shipping_threshold();
		$subtotal  = self::cart_subtotal_for_threshold();
		$remaining = $threshold > 0 ? max( 0, $threshold - $subtotal ) : 0;
		$percent   = $threshold > 0 ? min( 100, max( 0, ( $subtotal / $threshold ) * 100 ) ) : 0;
		$message   = ! empty( $options['free_shipping']['message'] ) ? $options['free_shipping']['message'] : self::defaults()['free_shipping']['message'];

		if ( $threshold <= 0 ) {
			return array(
				'threshold' => 0,
				'subtotal'  => $subtotal,
				'remaining' => 0,
				'percent'   => 0,
				'message'   => '',
			);
		}

		if ( $remaining > 0 ) {
			$message = str_replace( '{amount}', wp_strip_all_tags( wc_price( $remaining ) ), $message );
		} else {
			$message = __( 'Masz darmową dostawę!', 'bebop-sales-booster' );
		}

		return array(
			'threshold' => $threshold,
			'subtotal'  => $subtotal,
			'remaining' => $remaining,
			'percent'   => round( $percent, 2 ),
			'message'   => $message,
		);
	}

	/**
	 * Get free-shipping threshold from settings or WooCommerce shipping methods.
	 *
	 * @return float
	 */
	private static function free_shipping_threshold() {
		$options = self::options();

		if ( '' !== $options['free_shipping']['threshold'] ) {
			return (float) $options['free_shipping']['threshold'];
		}

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return 0;
		}

		$thresholds = array();
		$zones      = WC_Shipping_Zones::get_zones();
		$zones[]    = array( 'zone_id' => 0 );

		foreach ( $zones as $zone_data ) {
			$zone    = WC_Shipping_Zones::get_zone( $zone_data['zone_id'] );
			$methods = $zone ? $zone->get_shipping_methods( true ) : array();

			foreach ( $methods as $method ) {
				if ( 'free_shipping' !== $method->id || 'yes' !== $method->enabled ) {
					continue;
				}

				$requires   = isset( $method->requires ) ? $method->requires : '';
				$min_amount = isset( $method->min_amount ) ? (float) $method->min_amount : 0;

				if ( $min_amount > 0 && in_array( $requires, array( 'min_amount', 'either', 'both' ), true ) ) {
					$thresholds[] = $min_amount;
				}
			}
		}

		return empty( $thresholds ) ? 0 : min( $thresholds );
	}

	/**
	 * Get cart subtotal used for the free-delivery nudge.
	 *
	 * @return float
	 */
	private static function cart_subtotal_for_threshold() {
		if ( ! WC()->cart ) {
			return 0;
		}

		$subtotal = method_exists( WC()->cart, 'get_displayed_subtotal' )
			? (float) WC()->cart->get_displayed_subtotal()
			: (float) WC()->cart->get_subtotal();

		return max( 0, $subtotal - (float) WC()->cart->get_discount_total() );
	}

	/**
	 * Current cart context for rule matching.
	 *
	 * @return array
	 */
	private static function cart_context() {
		$products   = array();
		$categories = array();

		if ( ! self::woocommerce_ready() || ! WC()->cart ) {
			return array(
				'products'                => array(),
				'categories'              => array(),
				'subtotal'                => 0,
				'free_shipping_remaining' => 0,
			);
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id   = ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$variation_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

			if ( $product_id ) {
				$products[]   = $product_id;
				$categories   = array_merge( $categories, wc_get_product_cat_ids( $product_id ) );
			}

			if ( $variation_id ) {
				$products[] = $variation_id;
			}
		}

		$progress = self::free_shipping_progress();

		return array(
			'products'                => array_values( array_unique( array_map( 'absint', $products ) ) ),
			'categories'              => array_values( array_unique( array_map( 'absint', $categories ) ) ),
			'subtotal'                => self::cart_subtotal_for_threshold(),
			'free_shipping_remaining' => isset( $progress['remaining'] ) ? (float) $progress['remaining'] : 0,
		);
	}

	/**
	 * Check if a rule matches the current cart.
	 *
	 * @param array $rule Rule data.
	 * @param array $context Cart context.
	 * @return bool
	 */
	private static function rule_matches_cart( $rule, $context ) {
		$has_product_triggers  = ! empty( $rule['trigger_products'] );
		$has_category_triggers = ! empty( $rule['trigger_categories'] );
		$subtotal              = isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0;

		if ( '' !== $rule['min_cart_total'] && $subtotal < (float) $rule['min_cart_total'] ) {
			return false;
		}

		if ( '' !== $rule['max_cart_total'] && $subtotal > (float) $rule['max_cart_total'] ) {
			return false;
		}

		if ( ! empty( $rule['only_below_free_shipping'] ) && empty( $context['free_shipping_remaining'] ) ) {
			return false;
		}

		if ( ! $has_product_triggers && ! $has_category_triggers ) {
			return true;
		}

		if ( $has_product_triggers && array_intersect( $rule['trigger_products'], $context['products'] ) ) {
			return true;
		}

		if ( $has_category_triggers && array_intersect( $rule['trigger_categories'], $context['categories'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a product or variation is already in the cart.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	private static function cart_contains_product( $product_id ) {
		if ( ! self::woocommerce_ready() || ! WC()->cart ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$target_product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$target_variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$cart_product_id   = ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
			$cart_variation_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

			if ( $target_variation_id && $cart_variation_id === $target_variation_id ) {
				return true;
			}

			if ( ! $target_variation_id && $cart_product_id === $target_product_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Should recommendations hide products already present in the cart?
	 *
	 * @return bool
	 */
	private static function should_hide_products_in_cart() {
		$options = self::options();

		return ! empty( $options['behavior']['hide_in_cart'] );
	}

	/**
	 * Check if a product should be hidden for a placement.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $placement Placement key.
	 * @return bool
	 */
	private static function offer_should_be_hidden( $product, $placement ) {
		if ( ! $product ) {
			return true;
		}

		$options = self::options();

		if ( ! empty( $options['behavior']['hide_in_cart'] ) && self::cart_contains_product( $product->get_id() ) ) {
			return true;
		}

		if ( 'mini_cart' === $placement && ! empty( $options['behavior']['quick_add_only_mini_cart'] ) && $product->is_type( 'variable' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get a product if it can be presented as an offer.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|false
	 */
	private static function offerable_product( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_in_stock() ) {
			return false;
		}

		if ( self::product_has_meta_flag( $product, '_bebop_sales_booster_exclude' ) ) {
			return false;
		}

		if ( ! $product->is_type( 'variable' ) && ! $product->is_purchasable() ) {
			return false;
		}

		return $product;
	}

	/**
	 * Format offer price HTML.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $offer Offer data.
	 * @return string
	 */
	private static function offer_price_html( $product, $offer ) {
		if ( 'none' === $offer['discount_type'] || '' === $offer['discount_value'] || $product->is_type( 'variable' ) ) {
			return $product->get_price_html();
		}

		$original = (float) $product->get_price();
		$offer_price = self::discounted_price( $original, $offer['discount_type'], $offer['discount_value'] );

		if ( $offer_price >= $original ) {
			return $product->get_price_html();
		}

		$display_original = wc_get_price_to_display( $product, array( 'price' => $original ) );
		$display_offer    = wc_get_price_to_display( $product, array( 'price' => $offer_price ) );

		return '<del>' . wc_price( $display_original ) . '</del> <ins>' . wc_price( $display_offer ) . '</ins>';
	}

	/**
	 * Calculate a discounted raw product price.
	 *
	 * @param float  $original Original raw price.
	 * @param string $type Discount type.
	 * @param mixed  $value Discount value.
	 * @return float
	 */
	private static function discounted_price( $original, $type, $value ) {
		$value = (float) $value;

		if ( $original < 0 ) {
			$original = 0;
		}

		switch ( $type ) {
			case 'percent':
				return max( 0, $original * ( 1 - min( 100, $value ) / 100 ) );
			case 'amount_off':
				return max( 0, $original - $value );
			case 'fixed_price':
				return max( 0, $value );
			default:
				return $original;
		}
	}

	/**
	 * Get placement heading.
	 *
	 * @param string $placement Placement key.
	 * @return string
	 */
	private static function placement_heading( $placement ) {
		if ( 'checkout' === $placement ) {
			return __( 'Ostatnia szansa dorzucić', 'bebop-sales-booster' );
		}

		if ( 'mini_cart' === $placement ) {
			return __( 'Szybkie dorzutki', 'bebop-sales-booster' );
		}

		return __( 'Dobierz coś jeszcze', 'bebop-sales-booster' );
	}

	/**
	 * Get the max number of offers for a placement.
	 *
	 * @param string $placement Placement key.
	 * @return int
	 */
	private static function placement_offer_limit( $placement ) {
		$options = self::options();

		if ( isset( $options['limits'][ $placement ] ) ) {
			return (int) $options['limits'][ $placement ];
		}

		return (int) $options['max_offers'];
	}

	/**
	 * Get plugin options.
	 *
	 * @return array
	 */
	private static function options() {
		return self::normalize_options( get_option( self::OPTION, array() ) );
	}

	/**
	 * Normalize saved options with defaults.
	 *
	 * @param array $options Saved options.
	 * @return array
	 */
	private static function normalize_options( $options ) {
		$defaults = self::defaults();
		$options  = is_array( $options ) ? $options : array();
		$options  = wp_parse_args( $options, $defaults );

		$options['enabled']    = empty( $options['enabled'] ) ? 0 : 1;
		$options['max_offers'] = self::clamp_absint( $options['max_offers'], 1, 8 );

		$options['limits'] = wp_parse_args(
			isset( $options['limits'] ) && is_array( $options['limits'] ) ? $options['limits'] : array(),
			$defaults['limits']
		);
		$options['limits']['product_page'] = self::clamp_absint( $options['limits']['product_page'], 1, 8 );
		$options['limits']['cart']         = self::clamp_absint( $options['limits']['cart'], 1, 8 );
		$options['limits']['checkout']     = self::clamp_absint( $options['limits']['checkout'], 1, 6 );
		$options['limits']['mini_cart']    = self::clamp_absint( $options['limits']['mini_cart'], 1, 4 );

		$options['behavior'] = wp_parse_args(
			isset( $options['behavior'] ) && is_array( $options['behavior'] ) ? $options['behavior'] : array(),
			$defaults['behavior']
		);
		$options['behavior']['hide_in_cart']             = empty( $options['behavior']['hide_in_cart'] ) ? 0 : 1;
		$options['behavior']['quick_add_only_mini_cart'] = empty( $options['behavior']['quick_add_only_mini_cart'] ) ? 0 : 1;
		$options['behavior']['debug_enabled']            = empty( $options['behavior']['debug_enabled'] ) ? 0 : 1;

		$options['free_shipping'] = wp_parse_args(
			isset( $options['free_shipping'] ) && is_array( $options['free_shipping'] ) ? $options['free_shipping'] : array(),
			$defaults['free_shipping']
		);
		$options['free_shipping']['enabled']     = empty( $options['free_shipping']['enabled'] ) ? 0 : 1;
		$options['free_shipping']['threshold']   = self::sanitize_decimal_or_empty( $options['free_shipping']['threshold'] );
		$options['free_shipping']['product_ids'] = self::sanitize_absint_array( $options['free_shipping']['product_ids'] );
		$options['free_shipping']['placements']  = self::sanitize_placements( $options['free_shipping']['placements'] );
		$options['free_shipping']['message']     = sanitize_text_field( $options['free_shipping']['message'] );
		$options['free_shipping']['match_mode']  = self::sanitize_choice( $options['free_shipping']['match_mode'], array( 'unlock_first', 'closest', 'under' ), 'unlock_first' );

		$options['delivery_bar'] = wp_parse_args(
			isset( $options['delivery_bar'] ) && is_array( $options['delivery_bar'] ) ? $options['delivery_bar'] : array(),
			$defaults['delivery_bar']
		);
		$options['delivery_bar']['enabled']            = empty( $options['delivery_bar']['enabled'] ) ? 0 : 1;
		$options['delivery_bar']['top_enabled']        = empty( $options['delivery_bar']['top_enabled'] ) ? 0 : 1;
		$options['delivery_bar']['bottom_enabled']     = empty( $options['delivery_bar']['bottom_enabled'] ) ? 0 : 1;
		$options['delivery_bar']['bottom_sticky']      = empty( $options['delivery_bar']['bottom_sticky'] ) ? 0 : 1;
		$options['delivery_bar']['show_product']       = empty( $options['delivery_bar']['show_product'] ) ? 0 : 1;
		$options['delivery_bar']['product_source']     = self::sanitize_choice( $options['delivery_bar']['product_source'], array( 'mixed', 'manual', 'tag' ), 'mixed' );
		$options['delivery_bar']['product_ids']        = self::sanitize_absint_array( $options['delivery_bar']['product_ids'] );
		$options['delivery_bar']['tag_slug']           = sanitize_title( $options['delivery_bar']['tag_slug'] );
		$options['delivery_bar']['fallback_threshold'] = self::sanitize_decimal_or_empty( $options['delivery_bar']['fallback_threshold'] );

		$options['related'] = wp_parse_args(
			isset( $options['related'] ) && is_array( $options['related'] ) ? $options['related'] : array(),
			$defaults['related']
		);
		$options['related']['product_enabled']  = empty( $options['related']['product_enabled'] ) ? 0 : 1;
		$options['related']['cart_enabled']     = empty( $options['related']['cart_enabled'] ) ? 0 : 1;
		$options['related']['checkout_enabled'] = empty( $options['related']['checkout_enabled'] ) ? 0 : 1;
		$options['related']['product_limit']    = self::clamp_absint( $options['related']['product_limit'], 1, 8 );
		$options['related']['cart_limit']       = self::clamp_absint( $options['related']['cart_limit'], 1, 6 );
		$options['related']['min_score']        = self::clamp_absint( $options['related']['min_score'], 1, 50 );
		$options['related']['strategy']         = self::sanitize_choice( $options['related']['strategy'], array( 'mixed', 'similar', 'complete' ), 'mixed' );
		$options['related']['ignored_tags']     = self::sanitize_slug_list( $options['related']['ignored_tags'] );

		$rules = array();
		if ( ! empty( $options['rules'] ) && is_array( $options['rules'] ) ) {
			foreach ( $options['rules'] as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$rule = wp_parse_args( $rule, self::blank_rule() );
				$rules[] = array(
					'id'                 => sanitize_key( $rule['id'] ),
					'name'               => sanitize_text_field( $rule['name'] ),
					'enabled'            => empty( $rule['enabled'] ) ? 0 : 1,
					'trigger_products'   => self::sanitize_absint_array( $rule['trigger_products'] ),
					'trigger_categories' => self::sanitize_absint_array( $rule['trigger_categories'] ),
					'offer_products'     => self::sanitize_absint_array( $rule['offer_products'] ),
					'placements'         => self::sanitize_placements( $rule['placements'] ),
					'min_cart_total'     => self::sanitize_decimal_or_empty( $rule['min_cart_total'] ),
					'max_cart_total'     => self::sanitize_decimal_or_empty( $rule['max_cart_total'] ),
					'only_below_free_shipping' => empty( $rule['only_below_free_shipping'] ) ? 0 : 1,
					'discount_type'      => self::sanitize_choice( $rule['discount_type'], array( 'none', 'percent', 'amount_off', 'fixed_price' ), 'none' ),
					'discount_value'     => self::sanitize_decimal_or_empty( $rule['discount_value'] ),
					'priority'           => self::clamp_absint( $rule['priority'], 0, 100 ),
					'message'            => sanitize_text_field( $rule['message'] ),
				);
			}
		}
		$options['rules'] = $rules;

		return $options;
	}

	/**
	 * Default options.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'enabled'       => 0,
			'max_offers'    => 3,
			'limits'        => array(
				'product_page' => 4,
				'cart'         => 3,
				'checkout'     => 3,
				'mini_cart'    => 2,
			),
			'behavior'      => array(
				'hide_in_cart'             => 1,
				'quick_add_only_mini_cart' => 0,
				'debug_enabled'            => 0,
			),
			'free_shipping' => array(
				'enabled'     => 1,
				'threshold'   => '',
				'message'     => 'Brakuje {amount} do darmowej dostawy.',
				'product_ids' => array(),
				'placements'  => array( 'cart', 'checkout', 'mini_cart' ),
				'match_mode'  => 'unlock_first',
			),
			'delivery_bar'  => array(
				'enabled'            => 1,
				'top_enabled'        => 1,
				'bottom_enabled'     => 1,
				'bottom_sticky'      => 1,
				'show_product'       => 1,
				'product_source'     => 'mixed',
				'product_ids'        => array(),
				'tag_slug'           => 'upsell-dobij-do-dostawy',
				'fallback_threshold' => '777',
			),
			'related'       => array(
				'product_enabled'  => 1,
				'cart_enabled'     => 1,
				'checkout_enabled' => 1,
				'product_limit'    => 4,
				'cart_limit'       => 3,
				'min_score'        => 8,
				'strategy'         => 'mixed',
				'ignored_tags'     => array(),
			),
			'rules'         => array(),
		);
	}

	/**
	 * Empty admin rule.
	 *
	 * @return array
	 */
	private static function blank_rule() {
		return array(
			'id'                 => '',
			'name'               => '',
			'enabled'            => 1,
			'trigger_products'   => array(),
			'trigger_categories' => array(),
			'offer_products'     => array(),
			'placements'         => array( 'cart', 'checkout' ),
			'min_cart_total'     => '',
			'max_cart_total'     => '',
			'only_below_free_shipping' => 0,
			'discount_type'      => 'none',
			'discount_value'     => '',
			'priority'           => 10,
			'message'            => '',
		);
	}

	/**
	 * Is WooCommerce ready enough for frontend cart work?
	 *
	 * @return bool
	 */
	private static function woocommerce_ready() {
		return function_exists( 'WC' ) && class_exists( 'WooCommerce' );
	}

	/**
	 * Is the plugin enabled?
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		$options = self::options();

		return ! empty( $options['enabled'] );
	}

	/**
	 * Should admin-facing debug notes be rendered?
	 *
	 * @return bool
	 */
	private static function debug_enabled() {
		$options = self::options();

		return ! empty( $options['behavior']['debug_enabled'] ) && current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
	}

	/**
	 * Update a yes/no post meta flag.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param bool   $enabled Enabled flag.
	 */
	private static function update_yes_no_meta( $post_id, $key, $enabled ) {
		if ( $enabled ) {
			update_post_meta( $post_id, $key, 'yes' );
			return;
		}

		delete_post_meta( $post_id, $key );
	}

	/**
	 * Get product IDs by a post meta flag.
	 *
	 * @param string $key Meta key.
	 * @param string $value Meta value.
	 * @param int    $limit Max products.
	 * @return array
	 */
	private static function product_ids_by_meta( $key, $value = 'yes', $limit = 80 ) {
		if ( ! post_type_exists( 'product' ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'meta_key'               => $key,
				'meta_value'             => $value,
				'no_found_rows'          => true,
				'post_status'            => 'publish',
				'post_type'              => 'product',
				'posts_per_page'         => self::clamp_absint( $limit, 1, 500 ),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Get free-shipping candidates from settings and product meta.
	 *
	 * @return array
	 */
	private static function free_shipping_candidate_product_ids() {
		$options = self::options();

		return self::sanitize_absint_array(
			array_merge(
				$options['free_shipping']['product_ids'],
				self::product_ids_by_meta( '_bebop_sales_booster_free_shipping', 'yes', 200 )
			)
		);
	}

	/**
	 * Check if candidate IDs contain a product or its parent.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $candidate_ids Candidate IDs.
	 * @return bool
	 */
	private static function candidate_ids_contain_product( $product_id, $candidate_ids ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$candidate_ids = self::sanitize_absint_array( $candidate_ids );
		$parent_id     = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return in_array( $product->get_id(), $candidate_ids, true ) || in_array( $parent_id, $candidate_ids, true );
	}

	/**
	 * Check a product-level yes/no flag, using parent for variations.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @param string         $key Meta key.
	 * @return bool
	 */
	private static function product_has_meta_flag( $product, $key ) {
		$product = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return false;
		}

		$source_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return 'yes' === get_post_meta( $source_id, $key, true );
	}

	/**
	 * Product-level recommendation priority.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return int
	 */
	private static function product_recommendation_priority( $product ) {
		$product = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return 0;
		}

		$source_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return self::clamp_absint( get_post_meta( $source_id, '_bebop_sales_booster_priority', true ), 0, 100 );
	}

	/**
	 * Forced bundle product IDs for a source product.
	 *
	 * @param int $source_id Product or variation ID.
	 * @return array
	 */
	private static function product_forced_product_ids( $source_id ) {
		$product = wc_get_product( $source_id );
		if ( ! $product ) {
			return array();
		}

		$source_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return self::sanitize_absint_array( get_post_meta( $source_id, '_bebop_sales_booster_forced_products', true ) );
	}

	/**
	 * Product label for admin selects.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function product_label( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return '';
		}

		return wp_strip_all_tags( $product->get_formatted_name() );
	}

	/**
	 * Sanitize allowed placement array.
	 *
	 * @param mixed $placements Raw placements.
	 * @return array
	 */
	private static function sanitize_placements( $placements ) {
		$placements = is_array( $placements ) ? $placements : array();
		$allowed    = array( 'cart', 'checkout', 'mini_cart' );
		$clean      = array();

		foreach ( $placements as $placement ) {
			$placement = sanitize_key( wp_unslash( $placement ) );
			if ( in_array( $placement, $allowed, true ) ) {
				$clean[] = $placement;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize positive integer array.
	 *
	 * @param mixed $values Raw values.
	 * @return array
	 */
	private static function sanitize_absint_array( $values ) {
		$values = is_array( $values ) ? $values : array();
		$clean  = array();

		foreach ( $values as $value ) {
			$value = absint( wp_unslash( $value ) );
			if ( $value > 0 ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize a comma-separated or array-based list of slugs.
	 *
	 * @param mixed $values Raw values.
	 * @return array
	 */
	private static function sanitize_slug_list( $values ) {
		if ( is_string( $values ) ) {
			$values = preg_split( '/[\s,]+/', $values );
		}

		$values = is_array( $values ) ? $values : array();
		$clean  = array();

		foreach ( $values as $value ) {
			$value = sanitize_title( wp_unslash( $value ) );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize a decimal string or return empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_decimal_or_empty( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		$value = wp_unslash( $value );

		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value );
		}

		return preg_replace( '/[^0-9.]/', '', (string) $value );
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 * @return int
	 */
	private static function clamp_absint( $value, $min, $max ) {
		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Sanitize one of several values.
	 *
	 * @param mixed  $value Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function sanitize_choice( $value, $allowed, $fallback ) {
		$value = sanitize_key( wp_unslash( $value ) );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}

Bebop_Sales_Booster::boot();
register_activation_hook( __FILE__, array( 'Bebop_Sales_Booster', 'activate' ) );
