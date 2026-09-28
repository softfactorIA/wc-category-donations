<?php
/**
 * Plugin Name: WooCommerce Category Donations
 * Plugin URI:  https://example.local/wc-category-donations
 * Description: WooCommerce companion plugin with per-category donation settings and shortcodes.
 * Version:     1.1.0
 * Author:      F.Coello (satoko) & R.Couto (caligari)
 * Author URI:  https://example.local
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-category-donations
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * WC requires at least: 11.0
 * WC tested up to: 11.1.2
 */

defined( 'ABSPATH' ) || exit;

define( 'WCCD_VERSION', '1.1.0' );
define( 'WCCD_PLUGIN_FILE', __FILE__ );
define( 'WCCD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCCD_PLUGIN_DIR . 'includes/class-wc-category-donations-core.php';
require_once WCCD_PLUGIN_DIR . 'includes/class-wc-category-donations-admin.php';
require_once WCCD_PLUGIN_DIR . 'includes/class-wc-category-donations-shortcodes.php';
require_once WCCD_PLUGIN_DIR . 'includes/class-wc-category-donations-donations.php';
require_once WCCD_PLUGIN_DIR . 'includes/class-wc-category-donations-blocks.php';

register_activation_hook( __FILE__, array( 'WcCategoryDonations\\Donations', 'install_table' ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// 'custom_order_tables' is HPOS; 'hpos' is kept for older WooCommerce
			// builds that registered that id (unknown ids are silently ignored).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'hpos', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

WcCategoryDonations\Core::instance();

/**
 * Admin notice shown when WooCommerce is missing or inactive.
 */
function wccd_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'WooCommerce Category Donations requires WooCommerce to be installed and active. The plugin is not running.', 'wc-category-donations' )
	);
}

/**
 * Get the donation percentage (0-100) for a product category.
 *
 * Plugin public API; filters through 'wccd_donation_percentage'. Accepts a
 * term ID, a category slug, or a WP_Term instance.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return int
 */
function wccd_get_donation_percentage( $category ): int {
	return WcCategoryDonations\Core::instance()->get_donation_percentage( $category );
}

/**
 * Get the donation cause for a product category.
 *
 * Plugin public API; filters through 'wccd_category_cause'. Accepts a term
 * ID, a category slug, or a WP_Term instance. Returns an empty string when
 * the category has no cause saved.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return string
 */
function wccd_get_category_cause( $category ): string {
	return WcCategoryDonations\Core::instance()->get_category_cause( $category );
}

/**
 * Get the donation amount for a product: price (without tax) times the
 * category donation percentage.
 *
 * Plugin public API; filters through 'wccd_donation_amount'. Returns the
 * amount as a numeric string (rounding to the store currency decimals), or
 * an empty string when it cannot be calculated.
 *
 * @param \WC_Product|int|null     $product  Product instance, product ID, or
 *                                           null to use the current post.
 * @param int|string|\WP_Term|null $category Product category reference, or
 *                                           null for the product's first
 *                                           category.
 * @return string
 */
function wccd_get_donation_amount( $product = null, $category = null ): string {
	return WcCategoryDonations\Core::instance()->get_donation_amount( $product, $category );
}

/**
 * Get the total donated for a product category.
 *
 * Sums the recorded donations whose term ID or recorded category name
 * matches the category. Returns 0.0 when the category is invalid or has no
 * donations yet.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return float
 */
function wccd_get_category_donation_total( $category ): float {
	return WcCategoryDonations\Donations::instance()->get_category_donation_total( $category );
}

/**
 * Get the grand total of all recorded donations.
 *
 * @return float
 */
function wccd_get_donations_total(): float {
	return WcCategoryDonations\Donations::instance()->get_total_amount();
}

/**
 * Get the total donated by a user.
 *
 * Defaults to the currently logged-in user when $user_id is 0 and no
 * email is given. Includes guest orders placed with the same billing
 * email as the registered account (like the admin customer summary).
 *
 * @param int    $user_id Registered customer user ID (0 = current user).
 * @param string $email   Billing email to match (skip when empty).
 * @return float
 */
function wccd_get_user_donation_total( int $user_id = 0, string $email = '' ): float {
	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	if ( $user_id <= 0 ) {
		return 0.0;
	}

	if ( '' === $email ) {
		$user  = get_userdata( $user_id );
		$email = $user instanceof \WP_User ? (string) $user->user_email : '';
	}

	return WcCategoryDonations\Donations::instance()->get_customer_donation_total( $user_id, $email );
}

/**
 * Deprecated alias of wccd_get_donation_percentage().
 *
 * Kept for backward compatibility with the pre-1.1.0 "razas" plugin name.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return int
 */
function razas_get_donation_percentage( $category ): int {
	_deprecated_function( 'razas_get_donation_percentage', '1.1.0', 'wccd_get_donation_percentage' );

	return wccd_get_donation_percentage( $category );
}

/**
 * Deprecated alias of wccd_get_category_cause().
 *
 * Kept for backward compatibility with the pre-1.1.0 "razas" plugin name.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return string
 */
function razas_get_category_cause( $category ): string {
	_deprecated_function( 'razas_get_category_cause', '1.1.0', 'wccd_get_category_cause' );

	return wccd_get_category_cause( $category );
}

/**
 * Deprecated alias of wccd_get_donation_amount().
 *
 * Kept for backward compatibility with the pre-1.1.0 "razas" plugin name.
 *
 * @param \WC_Product|int|null     $product  Product instance, product ID, or
 *                                           null to use the current post.
 * @param int|string|\WP_Term|null $category Product category reference, or
 *                                           null for the product's first
 *                                           category.
 * @return string
 */
function razas_get_donation_amount( $product = null, $category = null ): string {
	_deprecated_function( 'razas_get_donation_amount', '1.1.0', 'wccd_get_donation_amount' );

	return wccd_get_donation_amount( $product, $category );
}

/**
 * Deprecated alias of wccd_get_category_donation_total().
 *
 * Kept for backward compatibility with the pre-1.1.0 "razas" plugin name.
 *
 * @param int|string|\WP_Term $category Product category reference.
 * @return float
 */
function razas_get_category_donation_total( $category ): float {
	_deprecated_function( 'razas_get_category_donation_total', '1.1.0', 'wccd_get_category_donation_total' );

	return wccd_get_category_donation_total( $category );
}

/**
 * Deprecated alias of wccd_get_donations_total().
 *
 * Kept for backward compatibility with the pre-1.1.0 "razas" plugin name.
 *
 * @return float
 */
function razas_get_donations_total(): float {
	_deprecated_function( 'razas_get_donations_total', '1.1.0', 'wccd_get_donations_total' );

	return wccd_get_donations_total();
}