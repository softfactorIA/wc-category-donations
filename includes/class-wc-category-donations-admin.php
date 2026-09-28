<?php
/**
 * Admin settings page: default donation percentage and per-category
 * donation settings.
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

namespace WcCategoryDonations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin menu page and the settings via the Settings API.
 */
class Admin {

	private const CATEGORY_SETTINGS_KEY = 'wccd_category_settings';
	private const PAGE                  = 'wccd-settings';
	private const GROUP                 = 'wccd_settings';

	/**
	 * Number of donations cancelled by the latest admin form submission.
	 */
	private ?int $cancel_result = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'pre_delete_term', array( $this, 'delete_category_meta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_cancel_order_donations' ) );
		add_action( 'admin_post_wccd_export_donations', array( $this, 'export_donations_csv' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_donations_section' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta_source_link' ), 10, 3 );
	}

	/**
	 * Add the top-level plugin admin menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Donations by category', 'wc-category-donations' ),
			__( 'Donations by category', 'wc-category-donations' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-money-alt',
			56
		);
	}

	/**
	 * Register the plugin options with their sanitization callbacks.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Core::OPTION_DEFAULT_PERCENTAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_percentage' ),
				'default'           => 0,
			)
		);

		register_setting(
			self::GROUP,
			self::CATEGORY_SETTINGS_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_category_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Cancel stale donations when the admin form is submitted.
	 *
	 * Runs on admin_init for a POST to the plugin settings page; the result
	 * is kept
	 * in memory so the same request renders the confirmation notice.
	 */
	public function maybe_cancel_order_donations(): void {
		if ( ! isset( $_POST['wccd_cancel_order_donations'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-category-donations' ) );
		}

		check_admin_referer( 'wccd_cancel_order_donations' );

		$this->cancel_result = Donations::instance()->cancel_orders_donations();
	}

	/**
	 * Export the donations of a year as a CSV download.
	 *
	 * Handles the 'wccd_export_donations' admin-post action. The form lives
	 * in the donations tab and is protected by a nonce plus the
	 * manage_options capability.
	 */
	public function export_donations_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-category-donations' ) );
		}

		check_admin_referer( 'wccd_export_donations' );

		$year = isset( $_REQUEST['year'] ) ? absint( wp_unslash( $_REQUEST['year'] ) ) : 0;

		if ( $year < 1 ) {
			$year = (int) gmdate( 'Y' );
		}

		$rows = Donations::instance()->get_yearly_donations( $year );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wccd-donations-' . $year . '.csv"' );
		header( 'X-Robots-Tag: noindex' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens the CSV with the right encoding.
		echo "\xEF\xBB\xBF";

		fputcsv( $output, array( 'order', 'date', 'category', 'cause', 'amount', 'status' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					'#' . $row['order_id'],
					$row['created_at'],
					$row['category_name'],
					$row['cause'],
					number_format( $row['donation_amount'], 2, '.', '' ),
					$row['status'],
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Render the customer donations section on the user edit screen.
	 *
	 * Shows, for the user being edited, the total donated on their behalf
	 * (registered orders plus guest orders sharing the billing email), the
	 * per-category breakdown and the most recent donations. Hooked on
	 * 'edit_user_profile' (admin editing a user); uses the per-row customer
	 * data recorded since schema 1.4.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function render_user_donations_section( \WP_User $user ): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_price' ) ) {
			return;
		}

		$rows = Donations::instance()->get_customer_donations( (int) $user->ID, (string) $user->user_email );

		echo '<h2>' . esc_html__( 'Customer donations', 'wc-category-donations' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No donations recorded for this customer.', 'wc-category-donations' ) . '</p>';
			return;
		}

		$total        = Donations::instance()->get_customer_donation_total( (int) $user->ID, (string) $user->user_email, $rows );
		$orders       = array();
		$per_category = array();

		foreach ( $rows as $row ) {
			$orders[ $row['order_id'] ] = true;

			$name = $row['category_name'];

			if ( ! isset( $per_category[ $name ] ) ) {
				$per_category[ $name ] = array(
					'category_name' => $name,
					'orders'        => 0,
					'total'         => 0.0,
				);
			}

			$per_category[ $name ]['orders']++;
			$per_category[ $name ]['total'] += $row['donation_amount'];
		}

		usort(
			$per_category,
			static function ( array $a, array $b ): int {
				return $b['total'] <=> $a['total'];
			}
		);

		echo '<p>';
		// translators: %s is the total donated amount.
		echo esc_html( sprintf( __( 'Total donated by this customer: %s', 'wc-category-donations' ), wp_strip_all_tags( wc_price( $total ) ) ) );
		echo '<br>';
		// translators: %d is the number of orders with at least one active donation.
		echo esc_html( sprintf( __( 'Orders with donation: %d', 'wc-category-donations' ), count( $orders ) ) );
		echo '</p>';

		echo '<table class="widefat striped" style="max-width: 480px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Category', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Orders with donation', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Total donated', 'wc-category-donations' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $per_category as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['category_name'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['orders'] ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->format_amount( $row['total'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Recent donations', 'wc-category-donations' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width: 480px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'wc-category-donations' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			$order    = wc_get_order( $row['order_id'] );
			$order_url = $order instanceof \WC_Order ? $order->get_edit_order_url() : '';

			echo '<tr>';
			echo '<td>';

			if ( '' !== $order_url ) {
				echo '<a href="' . esc_url( $order_url ) . '">' . esc_html( '#' . $row['order_id'] ) . '</a>';
			} else {
				echo esc_html( '#' . $row['order_id'] );
			}

			echo '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $row['category_name'] ) . '</td>';
			echo '<td>' . wp_kses_post( $this->format_amount( $row['donation_amount'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Sanitize an integer percentage, clamped to 0-100.
	 */
	public function sanitize_percentage( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return min( max( (int) $value, 0 ), 100 );
	}

	/**
	 * Persist the per-category settings sent by the settings form.
	 *
	 * The source of truth is term meta: each category gets its percentage and
	 * cause stored (or deleted when emptied). The returned array is only a
	 * cache of the last saved state for this registered option.
	 *
	 * @param mixed $raw Raw value posted for wccd_category_settings.
	 */
	public function sanitize_category_settings( $raw ): array {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $clean;
		}

		foreach ( $terms as $term ) {
			$id    = (int) $term->term_id;
			$entry = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();

			$percentage_raw = isset( $entry['percentage'] ) ? $entry['percentage'] : '';
			$cause_raw      = isset( $entry['cause'] ) ? $entry['cause'] : '';

			if ( '' === $percentage_raw ) {
				delete_term_meta( $id, Core::CATEGORY_PERCENTAGE_META );
			} else {
				$percentage                  = $this->sanitize_percentage( $percentage_raw );
				$clean[ $id ]['percentage'] = $percentage;
				update_term_meta( $id, Core::CATEGORY_PERCENTAGE_META, $percentage );
			}

			$cause = sanitize_text_field( (string) $cause_raw );

			if ( '' === $cause ) {
				delete_term_meta( $id, Core::CATEGORY_CAUSE_META );
			} else {
				$clean[ $id ]['cause'] = $cause;
				update_term_meta( $id, Core::CATEGORY_CAUSE_META, $cause );
			}
		}

		return $clean;
	}

	/**
	 * Remove the plugin's term meta when a product category is deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function delete_category_meta( $term_id, $taxonomy ): void {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		delete_term_meta( $term_id, Core::CATEGORY_PERCENTAGE_META );
		delete_term_meta( $term_id, Core::CATEGORY_CAUSE_META );
		delete_term_meta( $term_id, Core::CATEGORY_INITIAL_META );
	}

	/**
	 * Render the plugin admin page: Donations, Settings, Shortcodes and About tabs.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'donations'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab display
		$tabs = array(
			'donations'  => __( 'Donations', 'wc-category-donations' ),
			'settings'   => __( 'Settings', 'wc-category-donations' ),
			'shortcodes' => __( 'Shortcodes', 'wc-category-donations' ),
			'about'      => __( 'About', 'wc-category-donations' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Donations by category', 'wc-category-donations' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'tab', $key ) ); ?>"
						class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'donations' === $tab ) {
				$this->render_donations_tab();
			} elseif ( 'shortcodes' === $tab ) {
				$this->render_shortcodes_tab();
			} elseif ( 'about' === $tab ) {
				$this->render_about_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Adjust the plugin row meta on the Plugins screen.
	 *
	 * Drops the "By <author>" entry, replaces the native "Visit plugin
	 * site"/"View details" link (WordPress always labels the Plugin URI
	 * link that way; there is no header to change the label) with a
	 * "Github" link to the public repository, and appends a "Settings"
	 * link to the plugin admin page as the second entry.
	 *
	 * @param array  $plugin_meta An array of the plugin's metadata links.
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array  $plugin_data An array of plugin data.
	 * @return array
	 */
	public function plugin_row_meta_source_link( array $plugin_meta, string $plugin_file, array $plugin_data ): array {
		if ( plugin_basename( WCCD_PLUGIN_FILE ) !== $plugin_file ) {
			return $plugin_meta;
		}

		$source_url = 'https://github.com/softfactorIA/wc-category-donations';
		// translators: %s is the plugin author; taken from core ('default') so the label follows the admin locale.
		$by_prefix  = trim( sprintf( __( 'By %s', 'default' ), '' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.WP.I18n.MissingTranslatorsComment
		$filtered   = array();

		foreach ( $plugin_meta as $meta ) {
			if ( ! is_string( $meta ) ) {
				continue;
			}

			// Drop the native Plugin URI link and the "By <author>" entry.
			if ( false !== strpos( $meta, (string) $plugin_data['PluginURI'] ) ) {
				continue;
			}

			if ( '' !== $by_prefix && 0 === strpos( $meta, $by_prefix ) ) {
				continue;
			}

			$filtered[] = $meta;
		}

		$filtered[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wccd-settings' ) ),
			esc_html__( 'Settings', 'wc-category-donations' )
		);

		$filtered[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( $source_url ),
			esc_html__( 'Github', 'wc-category-donations' )
		);

		return $filtered;
	}

	/**
	 * Render the About tab: a brief description of the plugin.
	 */
	private function render_about_tab(): void {
		?>
		<h2><?php esc_html_e( 'About', 'wc-category-donations' ); ?></h2>
		<p>
			<?php esc_html_e( 'Donations by category adds a donation percentage and a cause to each product category. The donation amount is calculated on the product price before taxes, recorded with the order, and adjusted automatically when orders are refunded or cancelled.', 'wc-category-donations' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'The Donations tab shows the recorded totals (per category, per year and overall), the list of cancelled donations, and lets you download an annual CSV report. The customer donations summary appears on the user edit screen, and the Settings tab controls the default percentage and the donation message.', 'wc-category-donations' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Shortcodes and public functions let themes display donation information anywhere.', 'wc-category-donations' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'shortcodes' ) ); ?>"><?php esc_html_e( 'See the shortcodes tab.', 'wc-category-donations' ); ?></a>
		</p>
		<ul>
			<li>
				<?php esc_html_e( 'Source code:', 'wc-category-donations' ); ?>
				<a href="https://github.com/softfactorIA/wc-category-donations" target="_blank" rel="noopener">https://github.com/softfactorIA/wc-category-donations</a>
			</li>
			<li>
				<?php esc_html_e( 'License:', 'wc-category-donations' ); ?>
				GPL-2.0-or-later
			</li>
			<li>
				<?php esc_html_e( 'Authors:', 'wc-category-donations' ); ?>
				F.Coello (satoko) &amp; R.Couto (caligari)
			</li>
		</ul>
		<h2><?php esc_html_e( 'Support', 'wc-category-donations' ); ?></h2>
		<ul>
			<li>
				<?php esc_html_e( 'Community (free):', 'wc-category-donations' ); ?>
				<a href="https://github.com/softfactorIA/wc-category-donations/issues" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub issue tracker', 'wc-category-donations' ); ?></a>
			</li>
			<li>
				<?php esc_html_e( 'Professional (paid):', 'wc-category-donations' ); ?>
				<a href="https://fernandocoello.com/contacto/" target="_blank" rel="noopener">fernandocoello.com/contacto</a>
			</li>
		</ul>
		<?php
	}

	/**
	 * Render the settings tab (Settings API form).
	 */
	private function render_settings_tab(): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<?php do_settings_sections( self::PAGE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( Core::OPTION_DEFAULT_PERCENTAGE ); ?>">
							<?php esc_html_e( 'Default donation percentage', 'wc-category-donations' ); ?>
						</label>
					</th>
					<td>
						<input
							type="number"
							id="<?php echo esc_attr( Core::OPTION_DEFAULT_PERCENTAGE ); ?>"
							name="<?php echo esc_attr( Core::OPTION_DEFAULT_PERCENTAGE ); ?>"
							min="0"
							max="100"
							step="1"
							value="<?php echo esc_attr( (int) get_option( Core::OPTION_DEFAULT_PERCENTAGE, 0 ) ); ?>"
						>
						<p class="description">
							<?php esc_html_e( 'Applied to product categories without their own percentage.', 'wc-category-donations' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Donation by category', 'wc-category-donations' ); ?></h2>
			<?php $this->render_category_table(); ?>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the donations tab: totals per category plus the grand total.
	 */
	private function render_donations_tab(): void {
		$cancelled = null !== $this->cancel_result ? $this->cancel_result : ( isset( $_GET['wccd_cancelled'] ) ? absint( $_GET['wccd_cancelled'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only, absint, set by the nonce-protected action

		if ( null !== $cancelled ) {

			if ( $cancelled > 0 ) {
				// translators: %d is the number of cancelled donations.
				echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d donation cancelled.', '%d donations cancelled.', $cancelled, 'wc-category-donations' ), $cancelled ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'No donations needed cancelling.', 'wc-category-donations' ) . '</p></div>';
			}
		}

		$totals = Donations::instance()->get_per_category_totals();
		$grand  = Donations::instance()->get_total_amount();

		if ( empty( $totals ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No donations recorded yet.', 'wc-category-donations' ) . '</p></div>';
		} else {
			echo '<h2>' . esc_html__( 'Donation totals by category', 'wc-category-donations' ) . '</h2>';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Category', 'wc-category-donations' ) . '</th>';
			echo '<th>' . esc_html__( 'Orders with donation', 'wc-category-donations' ) . '</th>';
			echo '<th>' . esc_html__( 'Total donated', 'wc-category-donations' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $totals as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['category_name'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $row['orders'] ) ) . '</td>';
				echo '<td>' . wp_kses_post( $this->format_amount( $row['total'] ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '<tfoot><tr>';
			echo '<th scope="row">' . esc_html__( 'Total', 'wc-category-donations' ) . '</th>';
			echo '<td></td>';
			echo '<td>' . wp_kses_post( $this->format_amount( $grand ) ) . '</td>';
			echo '</tr></tfoot>';
			echo '</table>';

			echo '<p>';
			// translators: %d is the number of orders with at least one active donation.
			echo esc_html( sprintf( __( 'Orders with donation: %d', 'wc-category-donations' ), Donations::instance()->get_donating_order_count() ) );
			echo '<br>';
			// translators: %d is the average donation percentage, rounded to an integer.
			echo esc_html( sprintf( __( 'Average donation percentage: %d%%', 'wc-category-donations' ), (int) round( Donations::instance()->get_donation_average_percentage() ) ) );
			echo '</p>';
		}

		$this->render_cancelled_donations_section();

		$years            = Donations::instance()->get_years();
		$year_export_form = $this->render_year_export_form( $years );
		?>
		<h2><?php esc_html_e( 'Annual report (CSV)', 'wc-category-donations' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Download all donations of a year (active and cancelled) as a CSV file.', 'wc-category-donations' ); ?></p>
		<?php echo $year_export_form; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaped output below.
	}

	/**
	 * Render the export-year selector form as an escaped HTML string.
	 *
	 * Posts to admin-post.php with the export action and nonce; the current
	 * year is always offered, even when the table is empty.
	 *
	 * @param array<int,int> $years Years present in the donations table.
	 */
	private function render_year_export_form( array $years ): string {
		$current_year = (int) gmdate( 'Y' );

		$years = array_values( array_unique( array_merge( $years, array( $current_year ) ) ) );
		sort( $years );

		$options = '';

		foreach ( $years as $year ) {
			$options .= '<option value="' . esc_attr( (string) $year ) . '"' . selected( $year, $current_year, false ) . '>' . esc_html( (string) $year ) . '</option>';
		}

		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$form .= '<input type="hidden" name="action" value="wccd_export_donations">';
		$form .= wp_nonce_field( 'wccd_export_donations', '_wpnonce', true, false );
		$form .= '<label for="wccd-export-year">' . esc_html__( 'Year', 'wc-category-donations' ) . '</label> ';
		$form .= '<select name="year" id="wccd-export-year">' . $options . '</select> ';
		$form .= get_submit_button( __( 'Download donations CSV', 'wc-category-donations' ), 'secondary', 'submit', false );
		$form .= '</form>';

		return $form;
	}

	/**
	 * Render the cancelled-donations management section.
	 */
	private function render_cancelled_donations_section(): void {
		$cancelled = Donations::instance()->get_cancelled_donations();
		$year      = (int) current_time( 'Y' );

		// translators: %d is the year.
		echo '<h2>' . esc_html( sprintf( __( 'Cancelled donations (%d)', 'wc-category-donations' ), $year ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Donations recorded for orders that are cancelled, refunded, trashed or left in draft are marked as cancelled and excluded from the totals above. Use the button to re-scan after bulk order changes.', 'wc-category-donations' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=donations' ) ) . '">';
		echo '<input type="hidden" name="wccd_cancel_order_donations" value="1">';
		wp_nonce_field( 'wccd_cancel_order_donations' );
		echo '<p>' . get_submit_button( __( 'Remove donations from cancelled/trashed orders', 'wc-category-donations' ), 'secondary', 'submit', false ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_submit_button() escapes internally
		echo '</form>';

		if ( empty( $cancelled ) ) {
			echo '<p>' . esc_html__( 'No cancelled donations.', 'wc-category-donations' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Cancelled at', 'wc-category-donations' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $cancelled as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( '#' . $row['order_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['category_name'] ) . '</td>';
			echo '<td>' . wp_kses_post( $this->format_amount( $row['donation_amount'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['cancelled_reason'] ) . '</td>';
			echo '<td>' . esc_html( $row['cancelled_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the shortcodes tab: reference table with live previews.
	 */
	private function render_shortcodes_tab(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'WooCommerce is not active. Shortcode previews require it.', 'wc-category-donations' );
			echo '</p></div>';
		}

		$slug = $this->get_example_category_slug();

		$rows = array(
			array(
				'tag'         => Shortcodes::PERCENTAGE,
				'attributes'  => 'category="' . $slug . '" (optional)',
				'description' => __( 'Donation percentage (0-100) of the current product category, or of the category in the attribute.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::PERCENTAGE . ' category="' . $slug . '"]',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::PERCENTAGE . ' category="' . $slug . '"]' ),
			),
			array(
				'tag'         => Shortcodes::CAUSE,
				'attributes'  => 'category="' . $slug . '" (optional)',
				'description' => __( 'Donation cause text of the current product category, or of the category in the attribute.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::CAUSE . ' category="' . $slug . '"]',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::CAUSE . ' category="' . $slug . '"]' ),
			),
			array(
				'tag'         => Shortcodes::AMOUNT,
				'attributes'  => 'category="' . $slug . '" (optional) · format="0" (optional)',
				'description' => __( 'Donation amount for the current product: price (without tax) times the category percentage. Formatted with the store currency; format="0" returns the plain number without markup.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::AMOUNT . ' category="' . $slug . '"]',
				'example_raw' => '[' . Shortcodes::AMOUNT . ' format="0"]',
				'preview'     => '<em>' . esc_html__( 'Product page only', 'wc-category-donations' ) . '</em>',
			),
			array(
				'tag'         => Shortcodes::CART_AMOUNT,
				'attributes'  => 'format="0" (optional)',
				'description' => __( 'Donation amount for the current cart contents: net line totals times the category percentages, like the order recording. Shows 0 when nothing donates.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::CART_AMOUNT . ']',
				'example_raw' => '[' . Shortcodes::CART_AMOUNT . ' format="0"]',
				'preview'     => '<em>' . esc_html__( 'Cart page only', 'wc-category-donations' ) . '</em>',
			),
			array(
				'tag'         => Shortcodes::CATEGORY_TOTAL,
				'attributes'  => 'category="' . $slug . '" · format="0" (optional)',
				'description' => __( 'Total donated for the category (recorded donations) from the current context or the attribute. Shows an error when no category can be resolved; format="0" returns the plain number and the error as plain text.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::CATEGORY_TOTAL . ' category="' . $slug . '"]',
				'example_raw' => '[' . Shortcodes::CATEGORY_TOTAL . ' category="' . $slug . '" format="0"]',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::CATEGORY_TOTAL . ' category="' . $slug . '"]' ),
			),
			array(
				'tag'         => Shortcodes::TOTAL,
				'attributes'  => 'format="0" (optional)',
				'description' => __( 'Grand total of all donations recorded by the plugin. format="0" returns the plain number without markup.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::TOTAL . ']',
				'example_raw' => '[' . Shortcodes::TOTAL . ' format="0"]',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::TOTAL . ']' ),
			),
			array(
				'tag'         => Shortcodes::DONATING_ORDERS,
				'attributes'  => '—',
				'description' => __( 'Number of distinct orders with at least one active donation.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::DONATING_ORDERS . ']',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::DONATING_ORDERS . ']' ),
			),
			array(
				'tag'         => Shortcodes::DONATION_AVERAGE,
				'attributes'  => '—',
				'description' => __( 'Aggregate donation percentage across all orders with active donations (total donated divided by the net order value), rounded to an integer.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::DONATION_AVERAGE . ']',
				'preview'     => $this->shortcode_preview( '[' . Shortcodes::DONATION_AVERAGE . ']' ),
			),
			array(
				'tag'         => Shortcodes::USER_TOTAL,
				'attributes'  => 'user="<ID>" (optional) · format="0" (optional)',
				'description' => __( 'Total donated by a user, including guest orders with the same billing email. Defaults to the currently logged-in user; pass user="<ID>" for a specific registered user. Renders nothing when no user can be resolved.', 'wc-category-donations' ),
				'example'     => '[' . Shortcodes::USER_TOTAL . ']',
				'example_raw' => '[' . Shortcodes::USER_TOTAL . ' user="1" format="0"]',
				'preview'     => '<em>' . esc_html__( 'Depends on the resolved user', 'wc-category-donations' ) . '</em>',
			),
		);

		echo '<h2>' . esc_html__( 'Plugin shortcodes', 'wc-category-donations' ) . '</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Shortcode', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Attributes', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Example', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'wc-category-donations' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><code>[' . esc_html( $row['tag'] ) . ']</code></td>';
			echo '<td>' . esc_html( $row['description'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['attributes'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['example'] ) . '</code>';

			if ( ! empty( $row['example_raw'] ) ) {
				echo '<br><code>' . esc_html( $row['example_raw'] ) . '</code>';
			}

			echo '</td>';
			echo '<td>' . wp_kses_post( $row['preview'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Previews reflect the current values on this site.', 'wc-category-donations' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Adding format="0" to the amount and total shortcodes returns the plain number (two decimals, dot separator, no currency symbol or HTML); the category error is then plain text.', 'wc-category-donations' ) . '</p>';
	}

	/**
	 * Resolve a product category slug for the shortcode examples.
	 */
	private function get_example_category_slug(): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 1,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 'your-category';
		}

		return $terms[0]->slug;
	}

	/**
	 * Render a shortcode in the current admin context for preview purposes.
	 */
	private function shortcode_preview( string $shortcode ): string {
		return (string) do_shortcode( $shortcode );
	}

	/**
	 * Format an amount with the store currency, or a plain number.
	 */
	private function format_amount( float $amount ): string {
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_price' ) ) {
			return wp_kses_post( wc_price( $amount ) );
		}

		return esc_html( number_format_i18n( $amount, 2 ) );
	}

	/**
	 * Render the per-category settings table.
	 */
	private function render_category_table(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'WooCommerce is not active. Activate it to configure donations per product category.', 'wc-category-donations' );
			echo '</p></div>';
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<p>' . esc_html__( 'No product categories found yet.', 'wc-category-donations' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Category', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Donation percentage (%)', 'wc-category-donations' ) . '</th>';
		echo '<th>' . esc_html__( 'Cause', 'wc-category-donations' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $terms as $term ) {
			$id         = (int) $term->term_id;
			$percentage = get_term_meta( $id, Core::CATEGORY_PERCENTAGE_META, true );
			$cause      = get_term_meta( $id, Core::CATEGORY_CAUSE_META, true );
			$key        = self::CATEGORY_SETTINGS_KEY . '[' . $id . ']';

			echo '<tr>';
			echo '<td>' . esc_html( $term->name ) . '</td>';
			echo '<td>';
			echo '<input type="number" name="' . esc_attr( $key . '[percentage]' ) . '" min="0" max="100" step="1" value="' . esc_attr( '' !== $percentage ? (int) $percentage : '' ) . '">';
			echo '</td>';
			echo '<td>';
			echo '<input type="text" name="' . esc_attr( $key . '[cause]' ) . '" class="regular-text" value="' . esc_attr( (string) $cause ) . '">';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Leave the percentage empty to use the default above.', 'wc-category-donations' ) . '</p>';
	}
}