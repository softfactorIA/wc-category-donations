<?php
/**
 * Core bootstrap and shared API for the WooCommerce Category Donations
 * plugin.
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

namespace WcCategoryDonations;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class. Holds shared state and the public read API.
 */
final class Core {

	public const OPTION_DEFAULT_PERCENTAGE = 'wccd_default_donation_percentage';
	public const CATEGORY_PERCENTAGE_META   = 'wccd_donation_percentage';
	public const CATEGORY_CAUSE_META        = 'wccd_category_cause';

	private static ?Core $instance = null;

	/**
	 * Per-request cache of resolved product categories; null for missing.
	 *
	 * @var array<string,\WP_Term|null>
	 */
	private static array $term_cache = array();

	/**
	 * Per-request cache of donation percentages, by term ID.
	 *
	 * @var array<int,int>
	 */
	private static array $percentage_cache = array();

	/**
	 * Per-request cache of category causes, by term ID.
	 *
	 * @var array<int,string>
	 */
	private static array $cause_cache = array();

	/**
	 * Per-request cache of calculated donation amounts, keyed by product
	 * ID and category term ID.
	 *
	 * @var array<string,string>
	 */
	private static array $amount_cache = array();

	/**
	 * Get the plugin singleton.
	 */
	public static function instance(): Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Load the plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wc-category-donations', false, dirname( __DIR__ ) . '/languages' );
	}

	/**
	 * Boot frontend/admin submodules.
	 *
	 * WooCommerce defines its classes when its main file is included, i.e.
	 * before 'plugins_loaded' fires, so class_exists() is a reliable check
	 * at this point. Without WooCommerce the plugin does nothing except
	 * show an admin notice: the 'Requires Plugins: woocommerce' header
	 * already blocks activation on WordPress 6.5+, and this guard covers
	 * older installs plus the case where WooCommerce is deactivated
	 * afterwards.
	 */
	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'wccd_missing_woocommerce_notice' );
			return;
		}

		new Shortcodes();
		new Blocks();
		Donations::instance();

		if ( is_admin() ) {
			new Admin();
		}
	}

	/**
	 * Resolve a product category from a term ID, slug, or WP_Term instance.
	 *
	 * @param int|string|\WP_Term $category Product category reference.
	 */
	public function resolve_category( $category ): ?\WP_Term {
		if ( $category instanceof \WP_Term ) {
			return $category;
		}

		if ( is_numeric( $category ) ) {
			$key = 'id:' . (int) $category;
		} elseif ( is_string( $category ) && '' !== $category ) {
			$key = 'slug:' . $category;
		} else {
			return null;
		}

		if ( array_key_exists( $key, self::$term_cache ) ) {
			return self::$term_cache[ $key ];
		}

		$term = is_numeric( $category ) ? get_term( (int) $category, 'product_cat' ) : get_term_by( 'slug', $category, 'product_cat' );
		$term = $term instanceof \WP_Term ? $term : null;

		self::$term_cache[ $key ] = $term;

		return $term;
	}

	/**
	 * Get the donation percentage (0-100) for a product category.
	 *
	 * Falls back to the plugin default when the category has no percentage.
	 * Filter: wccd_donation_percentage
	 *
	 * @param int|string|\WP_Term $category Product category reference.
	 */
	public function get_donation_percentage( $category ): int {
		$term = $this->resolve_category( $category );

		if ( null === $term ) {
			return 0;
		}

		if ( array_key_exists( $term->term_id, self::$percentage_cache ) ) {
			return self::$percentage_cache[ $term->term_id ];
		}

		$saved = get_term_meta( $term->term_id, self::CATEGORY_PERCENTAGE_META, true );
		$value = '' === $saved ? (int) get_option( self::OPTION_DEFAULT_PERCENTAGE, 0 ) : (int) $saved;
		$value = min( max( $value, 0 ), 100 );

		/**
		 * Filters the donation percentage for a product category.
		 *
		 * @param int $value   The percentage (0-100).
		 * @param int $term_id The product category term ID.
		 */
		$value = (int) apply_filters( 'razas_donation_percentage', $value, $term->term_id ); // Deprecated pre-1.1.0 filter name; bridged for backward compatibility.
		$value = (int) apply_filters( 'wccd_donation_percentage', $value, $term->term_id );

		self::$percentage_cache[ $term->term_id ] = $value;

		return $value;
	}

	/**
	 * Get the donation cause for a product category.
	 *
	 * Returns an empty string when the category has no cause saved.
	 * Filter: wccd_category_cause
	 *
	 * @param int|string|\WP_Term $category Product category reference.
	 */
	public function get_category_cause( $category ): string {
		$term = $this->resolve_category( $category );

		if ( null === $term ) {
			return '';
		}

		if ( array_key_exists( $term->term_id, self::$cause_cache ) ) {
			return self::$cause_cache[ $term->term_id ];
		}

		$cause = (string) get_term_meta( $term->term_id, self::CATEGORY_CAUSE_META, true );

		/**
		 * Filters the donation cause for a product category.
		 *
		 * @param string $cause   The cause text.
		 * @param int    $term_id The product category term ID.
		 */
		$cause = (string) apply_filters( 'razas_category_cause', $cause, $term->term_id ); // Deprecated pre-1.1.0 filter name; bridged for backward compatibility.
		$cause = (string) apply_filters( 'wccd_category_cause', $cause, $term->term_id );

		self::$cause_cache[ $term->term_id ] = $cause;

		return $cause;
	}

	/**
	 * Get the donation amount for a product: price (tax excluded) times the
	 * category donation percentage.
	 *
	 * Returns the amount as a numeric string, or an empty string when it
	 * cannot be calculated (WooCommerce inactive, invalid price, or no
	 * category). Filter: wccd_donation_amount
	 *
	 * @param \WC_Product|int|null     $product  Product instance, product ID,
	 *                                           or null to use the current post.
	 * @param int|string|\WP_Term|null $category Product category reference, or
	 *                                           null for the product's first
	 *                                           category.
	 */
	public function get_donation_amount( $product = null, $category = null ): string {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		if ( null === $product ) {
			$product = wc_get_product( get_the_ID() );
		} elseif ( is_numeric( $product ) ) {
			$product = wc_get_product( (int) $product );
		}

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$term = null;

		if ( null === $category ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$term = $terms[0];
			}
		} else {
			$term = $this->resolve_category( $category );
		}

		if ( null === $term ) {
			return '';
		}

		$cache_key = 'amount:' . (int) $product->get_id() . ':' . (int) $term->term_id;

		if ( array_key_exists( $cache_key, self::$amount_cache ) ) {
			return self::$amount_cache[ $cache_key ];
		}

		$price = (float) $product->get_price_excluding_tax();

		if ( $price <= 0.0 ) {
			self::$amount_cache[ $cache_key ] = '';

			return '';
		}

		$percentage = $this->get_donation_percentage( $term );

		if ( $percentage <= 0 ) {
			self::$amount_cache[ $cache_key ] = '0';

			return '0';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$amount   = round( $price * $percentage / 100, $decimals );

		/**
		 * Filters the donation amount for a product and category.
		 *
		 * @param float       $amount  The donation amount.
		 * @param \WC_Product $product The product.
		 * @param \WP_Term    $term    The product category.
		 */
		$amount                            = (string) apply_filters( 'razas_donation_amount', $amount, $product, $term ); // Deprecated pre-1.1.0 filter name; bridged for backward compatibility.
		self::$amount_cache[ $cache_key ]  = $amount;

		return $amount;
	}

	/**
	 * Donation amount for the current cart contents.
	 *
	 * Mirrors the order recording: each cart line's net total times its
	 * product category percentage, summed per category and rounded like
	 * the recorded rows. Returns 0.0 for an empty cart or when nothing in
	 * the cart donates.
	 */
	public function get_cart_donation_amount(): float {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$per_category = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}

			$terms = get_the_terms( $cart_item['data']->get_id(), 'product_cat' );

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term       = $terms[0];
			$percentage = $this->get_donation_percentage( $term );

			if ( $percentage <= 0 ) {
				continue;
			}

			if ( isset( $cart_item['line_total'] ) && is_numeric( $cart_item['line_total'] ) ) {
				$line_total = (float) $cart_item['line_total'];
			} else {
				$line_total = (float) $cart_item['data']->get_price_excluding_tax() * (int) $cart_item['quantity'];
			}

			if ( $line_total <= 0.0 ) {
				continue;
			}

			$term_id = (int) $term->term_id;

			if ( ! isset( $per_category[ $term_id ] ) ) {
				$per_category[ $term_id ] = 0.0;
			}

			$per_category[ $term_id ] += $line_total * $percentage / 100;
		}

		if ( empty( $per_category ) ) {
			return 0.0;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$total    = 0.0;

		foreach ( $per_category as $amount ) {
			$total += round( $amount, $decimals );
		}

		return $total;
	}
}