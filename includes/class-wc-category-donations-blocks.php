<?php
/**
 * Gutenberg block: demo/diagnostic "Donations" card.
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

namespace WcCategoryDonations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wccd/donations block with a server-side render callback.
 *
 * The block is a demo aid for debugging the donation design: it renders the
 * donation information relevant to the current page context (any page,
 * product category archive, single product or cart) plus the full donation
 * summary, and links to the plugin shortcode reference for logged-in admins.
 */
final class Blocks {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block from block.json (metadata, no build step).
	 *
	 * Also registers the pre-1.1.0 "razas/donations" name as a deprecated
	 * alias so existing block instances keep rendering.
	 */
	public function register(): void {
		$render = array( $this, 'render' );

		register_block_type(
			WCCD_PLUGIN_DIR . 'block-donations',
			array( 'render_callback' => $render )
		);

		// Deprecated pre-1.1.0 block name; kept for existing instances.
		register_block_type(
			'razas/donations',
			array(
				'api_version'     => 3,
				'title'           => __( 'Donations', 'wc-category-donations' ),
				'category'        => 'widgets',
				'icon'            => 'money-alt',
				'attributes'      => array(
					'align' => array(
						'type'    => 'string',
						'default' => 'right',
					),
				),
				'supports'        => array(
					'align' => array( 'left', 'center', 'right', 'wide', 'full' ),
					'html'  => false,
				),
				'render_callback' => $render,
			)
		);
	}

	/**
	 * Render the demo Donations card for the current page context.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block inner content (not used).
	 * @param \WP_Block $block     Block instance.
	 */
	public function render( $attributes, $content, $block ): string {
		$align = isset( $attributes['align'] ) ? sanitize_key( (string) $attributes['align'] ) : 'right';

		$html  = '<div ' . get_block_wrapper_attributes( array( 'class' => 'align' . $align ) ) . '>';
		$html .= '<h2>' . esc_html__( 'Donations', 'wc-category-donations' ) . '</h2>';

		$context = $this->render_context();

		if ( '' !== $context ) {
			$html .= '<div class="wccd-donations-context">' . $context . '</div>';
		}

		$html .= $this->render_summary();
		$html .= $this->render_admin_link();
		$html .= '</div>';

		return $html;
	}

	/**
	 * Context-dependent lines: cart, product category, single product.
	 */
	private function render_context(): string {
		if ( function_exists( 'wc_get_page_id' ) && is_page( (int) wc_get_page_id( 'cart' ) ) ) {
			return '<p>' . esc_html__( 'Cart donation amount:', 'wc-category-donations' ) . ' <strong>' . $this->format_price( Core::instance()->get_cart_donation_amount() ) . '</strong></p>';
		}

		$queried = get_queried_object();

		if ( $queried instanceof \WP_Term && 'product_cat' === $queried->taxonomy ) {
			return $this->render_category_lines( $queried );
		}

		if ( is_singular( 'product' ) ) {
			$terms = get_the_terms( get_the_ID(), 'product_cat' );
			$term  = is_array( $terms ) && ! empty( $terms ) ? reset( $terms ) : null;

			if ( $term instanceof \WP_Term ) {
				$html  = $this->render_category_lines( $term );
				$html .= '<p>' . esc_html__( 'Product donation:', 'wc-category-donations' ) . ' <strong>' . $this->format_price( (float) Core::instance()->get_donation_amount( get_the_ID() ) ) . '</strong></p>';

				return $html;
			}
		}

		return '';
	}

	/**
	 * Category lines: name, percentage, cause and category total.
	 */
	private function render_category_lines( \WP_Term $term ): string {
		$html  = '<p><strong>' . esc_html( $term->name ) . '</strong></p>';
		$html .= '<p>' . esc_html__( 'Percentage:', 'wc-category-donations' ) . ' ' . esc_html( (string) Core::instance()->get_donation_percentage( $term ) ) . '%</p>';

		$cause = Core::instance()->get_category_cause( $term );

		if ( '' !== $cause ) {
			$html .= '<p>' . esc_html__( 'Cause:', 'wc-category-donations' ) . ' ' . esc_html( $cause ) . '</p>';
		}

		$html .= '<p>' . esc_html__( 'Category total:', 'wc-category-donations' ) . ' ' . $this->format_price( Donations::instance()->get_category_donation_total( $term ) ) . '</p>';

		return $html;
	}

	/**
	 * Full donation summary: grand total, donating orders, average
	 * percentage and the per-category breakdown.
	 */
	private function render_summary(): string {
		$html  = '<ul>';
		$html .= '<li>' . esc_html__( 'Total donated:', 'wc-category-donations' ) . ' <strong>' . $this->format_price( Donations::instance()->get_total_amount() ) . '</strong></li>';
		$html .= '<li>' . esc_html__( 'Orders with donation:', 'wc-category-donations' ) . ' ' . esc_html( (string) Donations::instance()->get_donating_order_count() ) . '</li>';
		$html .= '<li>' . esc_html__( 'Average donation percentage:', 'wc-category-donations' ) . ' ' . esc_html( (string) round( Donations::instance()->get_donation_average_percentage() ) ) . '%</li>';
		$html .= '</ul>';

		$totals = Donations::instance()->get_per_category_totals();

		if ( ! empty( $totals ) ) {
			$html .= '<ul class="wccd-donations-by-category">';

			foreach ( $totals as $row ) {
				$html .= '<li>' . esc_html( $row['category_name'] ) . ': ' . $this->format_price( $row['total'] ) . '</li>';
			}

			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Shortcode-reference link, only for logged-in admins.
	 */
	private function render_admin_link(): string {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<a class="wccd-donations-adminlink" href="' . esc_url( admin_url( 'admin.php?page=wccd-settings&tab=shortcodes' ) ) . '">' . esc_html__( 'Shortcode reference (admin)', 'wc-category-donations' ) . '</a>';
	}

	/**
	 * Format a money amount with the store price HTML.
	 */
	private function format_price( float $amount ): string {
		return function_exists( 'wc_price' ) ? wc_price( $amount ) : esc_html( number_format( $amount, 2, '.', '' ) );
	}
}