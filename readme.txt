=== Donations by category ===
Contributors: satoko, caligari
Tags: woocommerce, donations
Requires at least: 6.0
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 11.0
WC tested up to: 11.1.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce companion plugin with per-category donation settings and
shortcodes.

== Description ==

Donations by category is a WooCommerce companion plugin. Its
settings page lets the store owner configure:

* A default donation percentage applied to product categories without their
  own percentage (0-100, default 0).
* A donation percentage and cause for each WooCommerce product category.

The values are exposed through shortcodes and public functions (see Usage).

A "Donations" tab on the plugin admin page shows the recorded donation
totals per category plus the grand total.

When a purchase is confirmed — payment completed by the gateway, or the
order marked completed by the store owner — the plugin automatically records
the donations into its own database table: one row per product category with
a donation, keeping the order reference, the date, the donated amount, and a
snapshot of the category name and cause. The table is created on activation
and removed on uninstall.

Translations: the plugin ships with Spanish (es_ES) and Galician (gl_ES)
language files in its `languages` folder, the `wc-category-donations.pot`
source template, and block-editor JSON translation files for the donations
block.

== Usage ==

The shortcodes expose the per-category donation settings:

* `[wccd_donation_percentage]` — the donation percentage (0-100) for the
  current product category (or the first category of the current product),
  falling back to the default percentage when the category has none.
* `[wccd_category_cause]` — the donation cause for the current product
  category (or the first category of the current product).
* `[wccd_donation_amount]` — on a product page, the donation amount: the
  product price (without tax) times the category donation percentage,
  formatted with the store currency. Renders nothing when it cannot be
  calculated.
* `[wccd_category_donation_total]` — the total donated for a category
  (the current one, or the one in the `category` attribute), formatted
  with the store currency. Shows an error message when no category can
  be resolved.
* `[wccd_donations_total]` — the grand total of all recorded donations,
  formatted with the store currency.
* `[wccd_donating_orders]` — the number of distinct orders with at least
  one active donation.
* `[wccd_donation_average]` — the aggregate donation percentage across all
  orders with active donations, rounded to an integer.
* `[wccd_cart_donation_amount]` — the donation amount the current cart
  contents contribute, on the cart page.
* `[wccd_user_donation_total]` — the total donated by a user, formatted
  with the store currency. Defaults to the currently logged-in user;
  pass `user="<ID>"` to render a specific registered user. Pass
  `format="0"` for the plain number without currency markup.

All except `[wccd_donations_total]` and `[wccd_user_donation_total]`
accept an optional `category` attribute with a category slug:

`[wccd_donation_percentage category="cachena"]`

`[wccd_donation_amount]` is meant for product pages, where it uses the
current product; on any other page it renders nothing.

Theme developers can use the public functions instead:

* `wccd_get_donation_percentage( $category )` — accepts a term ID, slug, or
  `WP_Term` instance.
* `wccd_get_category_cause( $category )` — same references; returns an empty
  string when the category has no cause.
* `wccd_get_donation_amount( $product = null, $category = null )` — accepts
  a product ID or `WC_Product` (null = current post) and the same category
  references (null = the product's first category); returns the amount as a
  numeric string.
* `wccd_get_category_donation_total( $category )` — the total donated for
  a category, as a float.
* `wccd_get_donations_total()` — the grand total of all recorded
  donations, as a float.
* `wccd_get_user_donation_total( $user_id = 0, $email = '' )` — the total
  donated by a user, as a float (0 = the currently logged-in user; guest
  orders with the same billing email are included).

== Upgrade from the pre-1.1.0 "razas" plugin ==

The plugin was renamed from "razas" to "wccd" (slug `wc-category-donations`)
in 1.1.0. Upgrading an existing install:

1. Deactivate and delete the old `razas` plugin.
2. Install this package (the folder installs at
   `wp-content/plugins/wc-category-donations/`) and activate it.
3. The donations table, options and term meta are migrated automatically on
   activation — nothing is lost.

For backward compatibility, the pre-1.1.0 shortcodes (`[razas_donation_percentage]`
and the other `[razas_*]` tags), public functions (`razas_get_*()`) and the
`razas/donations` block name are kept as deprecated aliases, so existing
content and themes keep working. They emit a deprecation notice where
possible and will be removed in a future release.

== Installation ==

1. Upload the `wc-category-donations` folder to `/wp-content/plugins/`, or
   install the `wc-category-donations.zip` package via Plugins → Add New →
   Upload.
2. Activate the plugin through the Plugins screen.
3. Go to the plugin menu to configure the settings.

== Frequently Asked Questions ==

= Which category does a shortcode without a category attribute use? =

On a product category archive page it uses that category; on a product page
it uses the product's first category. Anywhere else it renders nothing. Pass
the `category="slug"` attribute to pin a specific category.

= Why does [wccd_donation_amount] render nothing on some products? =

It needs a resolvable product category and a valid price (without tax).
Variable products without a selected variation have no price yet, so the
shortcode renders nothing until one is chosen.

== Changelog ==

= 1.2.0 =
* New `[wccd_user_donation_total]` shortcode: the total donated by a user
  (recorded orders, including guest orders sharing the billing email).
  Defaults to the currently logged-in user; pass `user="<ID>"` for a
  specific registered user and `format="0"` for the plain number.
* New "About" tab on the plugin admin page: brief description, source
  repository and license links, and the support options (free community
  support on the GitHub issue tracker, paid professional support via
  fernandocoello.com).
* WooCommerce is now declared as a plugin dependency: the `Requires
  Plugins: woocommerce` header plus a bootstrap guard that skips the
  plugin submodules and shows an admin notice when WooCommerce is
  missing or inactive.
* The Plugins screen row shows "Settings" and "Github" links instead of
  the plugin site and the "By <author>" entry.
* Translations reviewed: es_ES and gl_ES catalogs updated for the About
  and Support strings.

= 1.1.0 =
* Customer donations summary on the user edit screen (Users -> edit):
  total donated on behalf of the customer (registered orders plus guest
  orders sharing the billing email), per-category breakdown and recent
  donations.
* Removed the per-category initial donation amount: the settings field,
  its contribution to the category and grand totals, and the stored term
  meta (leftover meta is cleaned up on upgrade).
* Plugin renamed: "Razas" is now "WooCommerce Category Donations" (slug
  `wc-category-donations`, textdomain `wc-category-donations`, `WCCD_*`
  options/term meta/table). Automatic migration from the "razas" data
  names and deprecated aliases for shortcodes, public functions, filters
  and the block name.
* Spanish (es_ES) and Galician (gl_ES) translations,
  `wc-category-donations.pot` template and block-editor JSON language
  files.

= 1.0.0 =
* Donation recording on confirmed orders with per-category percentages
  computed on the net product value, with snapshots of category and cause.
* Cancelled, refunded, trashed or draft orders and line/partial refunds
  adjust or cancel the recorded donations.
* Per-category initial donation amount (starting figure for the totals).
* Admin donations tab: totals by category, grand total, donating-orders
  count, average donation percentage, cancelled-donations management and
  yearly CSV export.
* Shortcodes: `[razas_donation_percentage]`, `[razas_category_cause]`,
  `[razas_donation_amount]`, `[razas_category_donation_total]`,
  `[razas_donations_total]`, `[razas_donating_orders]`,
  `[razas_donation_average]` and `[razas_cart_donation_amount]`, with a
  `format="0"` attribute for plain-number output.
* WooCommerce feature compatibility declaration and per-request
  calculation caching.

= 0.1.0 =
* Initial plugin skeleton.
* Settings page: default donation percentage and per-category donation
  percentage + cause (stored as term meta).
* Shortcodes `[razas_donation_percentage]`, `[razas_category_cause]` and
  `[razas_donation_amount]`, plus the public functions
  `razas_get_donation_percentage()`, `razas_get_category_cause()` and
  `razas_get_donation_amount()`.
* Uninstall handler that removes plugin options and term meta.