<?php
/**
 * Automatic donation recording on confirmed orders.
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

namespace WcCategoryDonations;

defined( 'ABSPATH' ) || exit;

/**
 * Records order donations into a dedicated table when a purchase is confirmed.
 *
 * One row per (order, product category) that produced a donation. Rows keep
 * snapshots of the category name and the cause as they were at recording
 * time, so later changes to the settings never rewrite history.
 *
 * Recording happens on 'woocommerce_payment_complete' (automatic gateways)
 * and on the 'completed' status transition, so orders paid with a manual
 * gateway (e.g. bank transfer) are recorded when the store owner marks the
 * order completed from the admin.
 */
final class Donations {

	public const DB_VERSION                 = '1.4';
	public const DB_VERSION_KEY             = 'wccd_db_version';
	public const REFUNDS_PROCESSED_META     = 'wccd_processed_refunds';
	public const MIGRATED_OPTION            = 'wccd_migrated_from_razas';

	/**
	 * Reason stored when a donation is cancelled, keyed by order post status.
	 */
	private const CANCELLED_BY_POST_STATUS = array(
		'wc-cancelled'      => 'order cancelled',
		'wc-checkout-draft' => 'order draft',
		'wc-refunded'       => 'order refunded',
		'trash'             => 'order trashed',
	);

	private static ?Donations $instance = null;

	/**
	 * Per-request cache of active donation rows, loaded with one query.
	 *
	 * @var array<int,array{order_id:int,term_id:int,category_name:string,donation_amount:float}>|null
	 */
	private static ?array $rows_cache = null;

	/**
	 * Get the plugin singleton.
	 */
	public static function instance(): Donations {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_payment_complete', array( $this, 'record_order_donations' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'record_order_donations' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_order_donations' ) );
		add_action( 'woocommerce_order_status_checkout-draft', array( $this, 'cancel_order_donations' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_order_donations' ) );
		add_action( 'woocommerce_refund_created', array( $this, 'apply_order_refund' ) );
		add_action( 'trashed_post', array( $this, 'cancel_trashed_order_donations' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * The donations table name, with the blog prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'wccd_donations';
	}

	/**
	 * Load the active donation rows once per request.
	 *
	 * @return array<int,array{order_id:int,term_id:int,category_name:string,donation_amount:float}>
	 */
	private function load_rows(): array {
		if ( null !== self::$rows_cache ) {
			return self::$rows_cache;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT order_id, term_id, category_name, donation_amount
			FROM " . self::table_name() . "
			WHERE status = 'active'", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		self::$rows_cache = is_array( $rows ) ? $rows : array();

		return self::$rows_cache;
	}

	/**
	 * Drop the per-request totals cache after a write.
	 */
	public static function invalidate_cache(): void {
		self::$rows_cache = null;
	}

	/**
	 * Migrate data stored under the pre-1.1.0 "razas" plugin identity.
	 *
	 * Idempotent and safe to run on every activation/upgrade: renames the
	 * old donations table when the new one is missing, moves the option,
	 * term meta and order meta keys to the wccd_* names, and records the
	 * migration once. Running it twice is a no-op.
	 */
	public static function migrate_legacy_data(): void {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		global $wpdb;

		$old_table = $wpdb->prefix . 'razas_donations';
		$new_table = self::table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) ) {
			$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Options: move the value when the new key is missing, then drop the
		// legacy key regardless.
		$options = array(
			'razas_default_donation_percentage' => Core::OPTION_DEFAULT_PERCENTAGE,
			'razas_category_settings'           => 'wccd_category_settings',
			'razas_db_version'                  => self::DB_VERSION_KEY,
		);

		foreach ( $options as $legacy_key => $new_key ) {
			if ( false === get_option( $new_key ) && false !== get_option( $legacy_key ) ) {
				update_option( $new_key, get_option( $legacy_key ) );
			}

			delete_option( $legacy_key );
		}

		// Term meta keys (per-category donation settings).
		$term_meta_map = array(
			'razas_donation_percentage' => Core::CATEGORY_PERCENTAGE_META,
			'razas_category_cause'      => Core::CATEGORY_CAUSE_META,
		);

		foreach ( $term_meta_map as $legacy_key => $new_key ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->termmeta} SET meta_key = %s WHERE meta_key = %s", $new_key, $legacy_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Order meta (processed refunds), for both post meta and HPOS tables.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", self::REFUNDS_PROCESSED_META, 'razas_processed_refunds' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$orders_meta_table} SET meta_key = %s WHERE meta_key = %s", self::REFUNDS_PROCESSED_META, 'razas_processed_refunds' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		update_option( self::MIGRATED_OPTION, 1 );
	}

	/**
	 * Upgrade check on admin requests (covers in-place plugin updates).
	 */
	public function maybe_upgrade(): void {
		self::migrate_legacy_data();

		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * Create or upgrade the donations table (dbDelta).
	 *
	 * Idempotent: does nothing when the schema version option already matches.
	 * Called on plugin activation and on demand right before a write.
	 */
	public static function install_table(): void {
		self::migrate_legacy_data();

		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_email varchar(200) NOT NULL DEFAULT '',
			term_id bigint(20) unsigned NOT NULL,
			category_name varchar(200) NOT NULL DEFAULT '',
			cause text NOT NULL,
			donation_amount decimal(10,2) NOT NULL DEFAULT '0',
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			cancelled_reason varchar(200) NOT NULL DEFAULT '',
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wccd_order_category (order_id, term_id),
			KEY wccd_customer_user (customer_user_id),
			KEY wccd_customer_email (customer_email)
		) {$charset_collate};";

		dbDelta( $sql );

		// The per-category initial donation amount was removed; clean up any
		// term meta still stored under the old (current or legacy) keys.
		delete_metadata( 'term', 0, 'wccd_donation_initial_amount', '', true );
		delete_metadata( 'term', 0, 'razas_donation_initial_amount', '', true );

		// Since 1.4 the customer is stored per row so that per-customer views
		// need no join with the orders; fill the new columns for existing rows.
		self::backfill_customer_columns();

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Fill the customer columns of rows recorded before schema 1.4.
	 *
	 * Loads each order referenced by a row that still has no customer data
	 * and stores the user ID and billing email; orders already deleted are
	 * left empty (their rows are cancelled and excluded from totals).
	 */
	private static function backfill_customer_columns(): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		global $wpdb;

		$table = self::table_name();

		$rows = $wpdb->get_results(
			"SELECT id, order_id FROM {$table} WHERE customer_user_id = 0 AND customer_email = ''", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );

			if ( ! $order instanceof \WC_Order ) {
				continue; // Order gone: nothing to attribute.
			}

			$wpdb->update(
				$table,
				array(
					'customer_user_id' => (int) $order->get_user_id(),
					'customer_email'   => (string) $order->get_billing_email(),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		self::invalidate_cache();
	}

	/**
	 * Make sure the table exists before writing (on-demand install).
	 */
	private function ensure_table(): void {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::install_table();
			return;
		}

		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table_name() ) );

		if ( ! $exists ) {
			self::install_table();
		}
	}

	/**
	 * Calculate and record the donations of a confirmed order.
	 *
	 * Sums each line item total (tax excluded) times its product category
	 * percentage. One row is stored per category with a donation above zero;
	 * rows for an already-recorded (order, category) pair are never
	 * duplicated.
	 *
	 * @param int $order_id Order ID.
	 */
	public function record_order_donations( $order_id ): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->ensure_table();

		$per_category = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$terms = get_the_terms( $product->get_id(), 'product_cat' );

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term       = $terms[0];
			$percentage = Core::instance()->get_donation_percentage( $term );

			if ( $percentage <= 0 ) {
				continue;
			}

			// Line total excluding tax: the product value before taxes.
			$line_total = (float) $item->get_total();

			if ( $line_total <= 0.0 ) {
				continue;
			}

			$term_id = (int) $term->term_id;

			if ( ! isset( $per_category[ $term_id ] ) ) {
				$per_category[ $term_id ] = array(
					'term'   => $term,
					'amount' => 0.0,
				);
			}

			$per_category[ $term_id ]['amount'] += $line_total * $percentage / 100;
		}

		global $wpdb;

		$table    = self::table_name();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		foreach ( $per_category as $term_id => $data ) {
			$amount = round( $data['amount'], $decimals );

			if ( $amount <= 0.0 ) {
				continue; // Nothing to donate for this category (E1).
			}

			$already_recorded = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE order_id = %d AND term_id = %d",
					$order->get_id(),
					$term_id
				)
			);

			if ( $already_recorded ) {
				continue; // Status changes must not duplicate rows.
			}

			$wpdb->insert(
				$table,
				array(
					'order_id'         => $order->get_id(),
					'customer_user_id' => (int) $order->get_user_id(),
					'customer_email'   => (string) $order->get_billing_email(),
					'term_id'          => $term_id,
					'category_name'    => $data['term']->name,
					'cause'            => Core::instance()->get_category_cause( $term_id ),
					'donation_amount'  => $amount,
					'created_at'       => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%f', '%s' )
			);
		}

		self::invalidate_cache();
	}

	/**
	 * Cancel every active donation of an order.
	 *
	 * Triggered when an order is cancelled, moved to draft or trashed. The
	 * stored reason is derived from the order status so the history keeps
	 * the actual cause. Idempotent: already-cancelled rows are left
	 * untouched and later calls never re-activate a donation.
	 *
	 * @param int $order_id Order ID.
	 */
	public function cancel_order_donations( $order_id ): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->ensure_table();

		$slug   = $order->get_status();
		$key    = 'trash' === $slug ? 'trash' : 'wc-' . $slug;
		$reason = isset( self::CANCELLED_BY_POST_STATUS[ $key ] ) ? self::CANCELLED_BY_POST_STATUS[ $key ] : 'order cancelled';

		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status'           => 'cancelled',
				'cancelled_reason' => $reason,
				'cancelled_at'     => current_time( 'mysql' ),
			),
			array(
				'order_id' => $order->get_id(),
				'status'   => 'active',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::invalidate_cache();
	}

	/**
	 * Cancel the donations of an order when it is trashed.
	 *
	 * @param int $post_id Trashed post ID.
	 */
	public function cancel_trashed_order_donations( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$this->cancel_order_donations( $post_id );
		}
	}

	/**
	 * Apply a refund to the recorded donations.
	 *
	 * Refunded product lines donate their proportion (line total excluding
	 * tax times the current category percentage); the affected category row
	 * is reduced and cancelled when it reaches zero. Refunds without
	 * attributable product lines (amount-only or shipping-only) reduce every
	 * active row proportionally to the refunded share of the order total.
	 * Each refund is applied once, tracked in the order meta. Hooked on
	 * 'woocommerce_refund_created' (first argument: the refund ID).
	 *
	 * @param int $refund_id Refund order ID.
	 */
	public function apply_order_refund( $refund_id ): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );

		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$order = wc_get_order( $refund->get_parent_id() );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$processed = (array) $order->get_meta( self::REFUNDS_PROCESSED_META );

		if ( in_array( (int) $refund->get_id(), array_map( 'intval', $processed ), true ) ) {
			return; // Already applied; never subtract twice.
		}

		$this->ensure_table();

		global $wpdb;

		$table        = self::table_name();
		$decimals     = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$per_category = array();

		foreach ( $refund->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$terms = get_the_terms( $product->get_id(), 'product_cat' );

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term       = $terms[0];
			$percentage = Core::instance()->get_donation_percentage( $term );

			if ( $percentage <= 0 ) {
				continue;
			}

			// Refund line totals are negative: use the absolute money returned.
			$line_total = abs( (float) $item->get_total() );

			if ( $line_total <= 0.0 ) {
				continue;
			}

			$term_id = (int) $term->term_id;

			if ( ! isset( $per_category[ $term_id ] ) ) {
				$per_category[ $term_id ] = 0.0;
			}

			$per_category[ $term_id ] += $line_total * $percentage / 100;
		}

		foreach ( $per_category as $term_id => $amount ) {
			$amount = round( $amount, $decimals );

			if ( $amount > 0.0 ) {
				$this->subtract_donation( $order, (int) $term_id, $amount );
			}
		}

		// No product lines to attribute: scale the recorded rows by the
		// share of the order total that this refund returns.
		if ( empty( $per_category ) && (float) $refund->get_amount() > 0.0 ) {
			$order_total = (float) $order->get_total();
			$ratio       = $order_total > 0.0 ? (float) $refund->get_amount() / $order_total : 1.0;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_id, donation_amount FROM {$table} WHERE order_id = %d AND status = 'active'",
					$order->get_id()
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			foreach ( $rows as $row ) {
				$portion = round( (float) $row->donation_amount * $ratio, $decimals );

				if ( $portion > 0.0 ) {
					$this->subtract_donation( $order, (int) $row->term_id, $portion );
				}
			}
		}

		self::invalidate_cache();

		$processed[] = (int) $refund->get_id();
		$order->update_meta_data( self::REFUNDS_PROCESSED_META, $processed );
		$order->save();
	}

	/**
	 * Reduce one active donation row and cancel it when it reaches zero.
	 *
	 * @param \WC_Order $order   The order owning the donation.
	 * @param int       $term_id Product category term ID.
	 * @param float     $amount  Donation portion to subtract.
	 */
	private function subtract_donation( \WC_Order $order, int $term_id, float $amount ): void {
		global $wpdb;

		$table = self::table_name();

		$current = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT donation_amount FROM {$table} WHERE order_id = %d AND term_id = %d AND status = 'active'",
				$order->get_id(),
				$term_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $current <= 0.0 ) {
			return;
		}

		$decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$remaining = round( $current - $amount, $decimals );

		if ( $remaining <= 0.0 ) {
			$wpdb->update(
				$table,
				array(
					'donation_amount'   => 0,
					'status'            => 'cancelled',
					'cancelled_reason'  => 'order refunded',
					'cancelled_at'      => current_time( 'mysql' ),
				),
				array(
					'order_id' => $order->get_id(),
					'term_id'  => $term_id,
					'status'   => 'active',
				),
				array( '%f', '%s', '%s', '%s' ),
				array( '%d', '%d', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$wpdb->update(
			$table,
			array( 'donation_amount' => $remaining ),
			array(
				'order_id' => $order->get_id(),
				'term_id'  => $term_id,
				'status'   => 'active',
			),
			array( '%f' ),
			array( '%d', '%d', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Cancel every active donation whose order is cancelled, refunded,
	 * trashed or in draft.
	 *
	 * Bulk cleanup for the admin action; returns the number of donations
	 * that were newly cancelled (already-cancelled rows are not re-counted).
	 */
	public function cancel_orders_donations(): int {
		$this->ensure_table();

		global $wpdb;

		$table         = self::table_name();
		$posts         = $wpdb->posts;
		$post_statuses = array_keys( self::CANCELLED_BY_POST_STATUS );
		$placeholders  = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );

		$sql = $wpdb->prepare(
			"UPDATE {$table} d
			JOIN {$posts} p ON p.ID = d.order_id
			SET d.status = 'cancelled',
				d.cancelled_reason = CASE p.post_status
					WHEN 'wc-cancelled' THEN 'order cancelled'
					WHEN 'wc-checkout-draft' THEN 'order draft'
					WHEN 'wc-refunded' THEN 'order refunded'
					WHEN 'trash' THEN 'order trashed'
					ELSE 'order cancelled'
				END,
				d.cancelled_at = NOW()
			WHERE d.status = 'active'
				AND p.post_type = 'shop_order'
				AND p.post_status IN ( {$placeholders} )",
			...$post_statuses
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$affected = $wpdb->query( $sql );

		self::invalidate_cache();

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Cancelled donation records of the current year, most recently cancelled first.
	 *
	 * @return array<int,array{order_id:int,category_name:string,donation_amount:float,cancelled_reason:string,cancelled_at:string}>
	 */
	public function get_cancelled_donations(): array {
		global $wpdb;

		$year = (int) current_time( 'Y' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, category_name, donation_amount, cancelled_reason, cancelled_at
				FROM " . self::table_name() . "
				WHERE status = 'cancelled'
					AND YEAR(created_at) = %d
				ORDER BY cancelled_at DESC, id DESC
				LIMIT 200",
				$year
			), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['order_id']        = (int) $row['order_id'];
			$row['donation_amount'] = (float) $row['donation_amount'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Distinct years present in the donations table, ascending.
	 *
	 * @return array<int,int>
	 */
	public function get_years(): array {
		global $wpdb;

		$years = $wpdb->get_col(
			"SELECT DISTINCT YEAR(created_at) FROM " . self::table_name() . " ORDER BY 1 ASC" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);

		return array_map( 'intval', is_array( $years ) ? $years : array() );
	}

	/**
	 * All donation rows recorded in a given year, oldest first.
	 *
	 * Includes both active and cancelled donations, each with its status.
	 *
	 * @param int $year Four-digit year to export.
	 * @return array<int,array{order_id:int,category_name:string,cause:string,donation_amount:float,created_at:string,status:string}>
	 */
	public function get_yearly_donations( int $year ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, category_name, cause, donation_amount, created_at, status
				FROM " . self::table_name() . "
				WHERE YEAR(created_at) = %d
				ORDER BY created_at ASC, id ASC",
				$year
			), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['order_id']        = (int) $row['order_id'];
			$row['donation_amount'] = (float) $row['donation_amount'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Donation totals per category (snapshot name), largest total first.
	 *
	 * @return array<int,array{category_name:string,orders:int,total:float}>
	 */
	public function get_per_category_totals(): array {
		$totals = array();

		foreach ( $this->load_rows() as $row ) {
			$name = (string) $row['category_name'];

			if ( ! isset( $totals[ $name ] ) ) {
				$totals[ $name ] = array(
					'category_name' => $name,
					'orders'        => 0,
					'total'         => 0.0,
				);
			}

			$totals[ $name ]['orders']++;
			$totals[ $name ]['total'] += (float) $row['donation_amount'];
		}

		$totals = array_values( $totals );

		usort(
			$totals,
			static function ( array $a, array $b ): int {
				return $b['total'] <=> $a['total'];
			}
		);

		return $totals;
	}

	/**
	 * Total donated for a category.
	 *
	 * Matches rows by term ID or by the recorded category name, so totals
	 * stay correct even after a category rename.
	 */
	public function get_category_donation_total( $category ): float {
		$term = Core::instance()->resolve_category( $category );

		if ( null === $term ) {
			return 0.0;
		}

		$total = 0.0;

		foreach ( $this->load_rows() as $row ) {
			if ( (int) $row['term_id'] === (int) $term->term_id || (string) $row['category_name'] === $term->name ) {
				$total += (float) $row['donation_amount'];
			}
		}

		return $total;
	}

	/**
	 * Active donation rows attributed to a customer, newest first.
	 *
	 * Matches the customer user ID (registered customers) and the billing
	 * email stored at recording time, so guest orders placed with the same
	 * email are included as well. Rows matched twice (a registered customer
	 * whose billing email equals their account email) are deduplicated by id.
	 *
	 * @param int    $user_id Registered customer user ID (skip when 0).
	 * @param string $email   Billing email to match (skip when empty).
	 * @return array<int,array{id:int,order_id:int,category_name:string,cause:string,donation_amount:float,created_at:string,status:string}>
	 */
	public function get_customer_donations( int $user_id, string $email = '' ): array {
		$conditions = array();
		$values     = array();

		if ( $user_id > 0 ) {
			$conditions[] = 'customer_user_id = %d';
			$values[]     = $user_id;
		}

		if ( '' !== $email ) {
			$conditions[] = 'customer_email = %s';
			$values[]     = $email;
		}

		if ( empty( $conditions ) ) {
			return array();
		}

		global $wpdb;

		$table = self::table_name();
		$sql   = $wpdb->prepare(
			"SELECT id, order_id, category_name, cause, donation_amount, created_at, status
			FROM {$table}
			WHERE status = 'active' AND ( " . implode( ' OR ', $conditions ) . " )
			ORDER BY created_at DESC, id DESC",
			...$values
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$deduped = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( isset( $deduped[ $id ] ) ) {
				continue;
			}

			$deduped[ $id ] = array(
				'id'              => $id,
				'order_id'        => (int) $row['order_id'],
				'category_name'   => (string) $row['category_name'],
				'cause'           => (string) $row['cause'],
				'donation_amount' => (float) $row['donation_amount'],
				'created_at'      => (string) $row['created_at'],
				'status'          => (string) $row['status'],
			);
		}

		return array_values( $deduped );
	}

	/**
	 * Grand total donated across all categories.
	 *
	 * Sum of all recorded donations.
	 */
	public function get_total_amount(): float {
		$total = 0.0;

		foreach ( $this->load_rows() as $row ) {
			$total += (float) $row['donation_amount'];
		}

		return $total;
	}

	/**
	 * Number of distinct orders with at least one active donation.
	 */
	public function get_donating_order_count(): int {
		$order_ids = array();

		foreach ( $this->load_rows() as $row ) {
			$order_ids[ (int) $row['order_id'] ] = true;
		}

		return count( $order_ids );
	}

	/**
	 * Aggregate donation percentage across all orders with active donations.
	 *
	 * Sum of the recorded donations divided by the sum of the net line
	 * totals of those orders (the same base donations are computed on),
	 * times 100. Returns 0.0 when there is nothing to compute.
	 */
	public function get_donation_average_percentage(): float {
		$rows = $this->load_rows();

		if ( empty( $rows ) ) {
			return 0.0;
		}

		$donated = 0.0;
		$base    = 0.0;
		$orders  = array();

		foreach ( $rows as $row ) {
			$donated += (float) $row['donation_amount'];
			$orders[ (int) $row['order_id'] ] = true;
		}

		foreach ( array_keys( $orders ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$base += (float) $item->get_total();
				}
			}
		}

		if ( $base <= 0.0 ) {
			return 0.0;
		}

		return $donated / $base * 100.0;
	}
}
