<?php
/**
 * Shortcodes exposing the per-category donation settings.
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

namespace WcCategoryDonations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the donation shortcodes.
 */
final class Shortcodes {

	public const PERCENTAGE     = 'wccd_donation_percentage';
	public const CAUSE          = 'wccd_category_cause';
	public const AMOUNT         = 'wccd_donation_amount';
	public const CATEGORY_TOTAL = 'wccd_category_donation_total';
	public const TOTAL          = 'wccd_donations_total';
	public const DONATING_ORDERS = 'wccd_donating_orders';
	public const DONATION_AVERAGE = 'wccd_donation_average';
	public const CART_AMOUNT      = 'wccd_cart_donation_amount';
	public const USER_TOTAL       = 'wccd_user_donation_total';

	public function __construct() {
		add_shortcode( self::PERCENTAGE, array( $this, 'render_percentage' ) );
		add_shortcode( self::CAUSE, array( $this, 'render_cause' ) );
		add_shortcode( self::AMOUNT, array( $this, 'render_amount' ) );
		add_shortcode( self::CATEGORY_TOTAL, array( $this, 'render_category_donation_total' ) );
		add_shortcode( self::TOTAL, array( $this, 'render_donations_total' ) );
		add_shortcode( self::DONATING_ORDERS, array( $this, 'render_donating_orders' ) );
		add_shortcode( self::DONATION_AVERAGE, array( $this, 'render_donation_average' ) );
		add_shortcode( self::CART_AMOUNT, array( $this, 'render_cart_donation_amount' ) );
		add_shortcode( self::USER_TOTAL, array( $this, 'render_user_donation_total' ) );

		// Deprecated pre-1.1.0 shortcode tags; kept so existing content keeps
		// rendering without edits.
		add_shortcode( 'razas_donation_percentage', array( $this, 'render_percentage' ) );
		add_shortcode( 'razas_category_cause', array( $this, 'render_cause' ) );
		add_shortcode( 'razas_donation_amount', array( $this, 'render_amount' ) );
		add_shortcode( 'razas_category_donation_total', array( $this, 'render_category_donation_total' ) );
		add_shortcode( 'razas_donations_total', array( $this, 'render_donations_total' ) );
		add_shortcode( 'razas_donating_orders', array( $this, 'render_donating_orders' ) );
		add_shortcode( 'razas_donation_average', array( $this, 'render_donation_average' ) );
		add_shortcode( 'razas_cart_donation_amount', array( $this, 'render_cart_donation_amount' ) );
	}

	/**
	 * Render the donation percentage for the resolved category.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_percentage( $atts ): string {
		$term = $this->resolve_category( $atts, self::PERCENTAGE );

		if ( null === $term ) {
			return '';
		}

		return (string) Core::instance()->get_donation_percentage( $term );
	}

	/**
	 * Render the donation cause for the resolved category.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_cause( $atts ): string {
		$term = $this->resolve_category( $atts, self::CAUSE );

		if ( null === $term ) {
			return '';
		}

		return Core::instance()->get_category_cause( $term );
	}

	/**
	 * Render the donation amount for the current product.
	 *
	 * Price (tax excluded) times the category donation percentage, formatted
	 * with wc_price(). Pass format="0" to get the plain number without any
	 * currency markup. Renders nothing when the amount cannot be calculated.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_amount( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'format'   => 1,
			),
			$atts,
			self::AMOUNT
		);

		$category = '' !== trim( (string) $atts['category'] ) ? $atts['category'] : null;
		$amount   = Core::instance()->get_donation_amount( get_the_ID(), $category );

		if ( '' === $amount ) {
			return '';
		}

		return $this->format_money( (float) $amount, $atts['format'] );
	}

	/**
	 * Render the donation amount for the current cart contents.
	 *
	 * Mirrors the order recording (net line totals times the category
	 * percentages). Pass format="0" to get the plain number. Shows 0 when
	 * the cart has no donating products.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_cart_donation_amount( $atts ): string {
		$atts = shortcode_atts(
			array( 'format' => 1 ),
			$atts,
			self::CART_AMOUNT
		);

		return $this->format_money( Core::instance()->get_cart_donation_amount(), $atts['format'] );
	}

	/**
	 * Render the total donated for the resolved category.
	 *
	 * Resolves like the other shortcodes (category="slug" attribute, then
	 * the current product category or the first category of the current
	 * product) but shows an error message when no category can be resolved.
	 * Pass format="0" to get the plain number without currency markup and
	 * the error as plain text.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_category_donation_total( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'format'   => 1,
			),
			$atts,
			self::CATEGORY_TOTAL
		);

		$term = $this->resolve_category( $atts, self::CATEGORY_TOTAL );

		if ( null === $term ) {
			return $this->render_category_error( $atts['format'] );
		}

		return $this->format_money( Donations::instance()->get_category_donation_total( $term ), $atts['format'] );
	}

	/**
	 * Render the grand total of all recorded donations.
	 *
	 * Pass format="0" to get the plain number without currency markup.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_donations_total( $atts ): string {
		$atts = shortcode_atts(
			array( 'format' => 1 ),
			$atts,
			self::TOTAL
		);

		return $this->format_money( Donations::instance()->get_total_amount(), $atts['format'] );
	}

	/**
	 * Render the total donated by a user.
	 *
	 * Defaults to the currently logged-in user. Pass user="<ID>" to render
	 * a specific registered user, and format="0" for the plain number
	 * without currency markup. Renders nothing when no user can be
	 * resolved.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_user_donation_total( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user'   => 0,
				'format' => 1,
			),
			$atts,
			self::USER_TOTAL
		);

		$user_id = (int) $atts['user'];

		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return '';
		}

		$email = '';
		$user  = get_userdata( $user_id );

		if ( $user instanceof \WP_User ) {
			$email = (string) $user->user_email;
		}

		$total = Donations::instance()->get_customer_donation_total( $user_id, $email );

		return $this->format_money( $total, $atts['format'] );
	}

	/**
	 * Render the number of distinct orders with at least one active donation.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_donating_orders( $atts ): string {
		return (string) Donations::instance()->get_donating_order_count();
	}

	/**
	 * Render the aggregate donation percentage across all donating orders.
	 *
	 * Total donated divided by the net order value, rounded to an integer.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_donation_average( $atts ): string {
		return (string) round( Donations::instance()->get_donation_average_percentage() );
	}

	/**
	 * Error message shown when the category total shortcode has no category.
	 *
	 * Plain text without markup when format="0" is set.
	 *
	 * @param mixed $format_value Shortcode format attribute.
	 */
	private function render_category_error( $format_value ): string {
		$message = __( 'Donations by category with WooCommerce: no category found. Add a category attribute or use this shortcode on a product category or product page.', 'wc-category-donations' );

		if ( ! $this->is_formatted( $format_value ) ) {
			return $message;
		}

		return sprintf( '<span class="wccd-shortcode-error">%s</span>', esc_html( $message ) );
	}

	/**
	 * Format a money value as store price HTML or as a plain number.
	 *
	 * format="0" (or "false"/"no") returns the raw number with two
	 * decimals and no currency symbol or markup; anything else uses
	 * wc_price() when WooCommerce is active.
	 *
	 * @param float $amount       Money value.
	 * @param mixed $format_value Shortcode format attribute.
	 */
	private function format_money( float $amount, $format_value ): string {
		if ( ! $this->is_formatted( $format_value ) ) {
			return number_format( $amount, 2, '.', '' );
		}

		return function_exists( 'wc_price' ) ? wc_price( $amount ) : number_format( $amount, 2, '.', '' );
	}

	/**
	 * Whether the format attribute requests the styled output.
	 *
	 * @param mixed $value Shortcode format attribute.
	 */
	private function is_formatted( $value ): bool {
		return ! in_array( (string) $value, array( '0', 'false', 'no' ), true );
	}

	/**
	 * Resolve the category the shortcode refers to.
	 *
	 * An explicit category="slug" attribute wins; otherwise the current
	 * product category (archive page) or the first category of the current
	 * product is used.
	 *
	 * @param array|string $atts      Shortcode attributes.
	 * @param string       $shortcode Shortcode tag (for shortcode_atts).
	 */
	private function resolve_category( $atts, string $shortcode ): ?\WP_Term {
		$atts = shortcode_atts(
			array( 'category' => '' ),
			$atts,
			$shortcode
		);

		$slug = trim( (string) $atts['category'] );

		if ( '' !== $slug ) {
			return Core::instance()->resolve_category( $slug );
		}

		$queried = get_queried_object();

		if ( $queried instanceof \WP_Term && 'product_cat' === $queried->taxonomy ) {
			return $queried;
		}

		if ( is_singular( 'product' ) ) {
			$terms = get_the_terms( get_the_ID(), 'product_cat' );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				return $terms[0];
			}
		}

		return null;
	}
}