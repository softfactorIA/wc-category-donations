# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Display name renamed to **Donations by category with WooCommerce**
  (required by the WordPress.org trademark rules: the name must include
  "with woocommerce"). Code, textdomain and folder slug stay
  `wc-category-donations`; the wp.org slug at submission is
  `donations-by-category-with-woocommerce`.

## [1.2.0] - 2026-09-28

### Added

- New shortcode `[wccd_user_donation_total]` rendering the total donated
  by a user: defaults to the currently logged-in user, accepts an
  optional `user` attribute (user ID) and the standard `format="0"`
  plain-number option. Backed by the new
  `Donations::get_customer_donation_total()` aggregation and the public
  `wccd_get_user_donation_total()` function; the admin customer summary
  reuses the same aggregation.
- New "About" tab on the admin page with a brief description of the
  plugin, links to the source repository and license, and a Support
  section (free community support via the GitHub issue tracker, paid
  professional support via fernandocoello.com).
- WooCommerce is now declared as a plugin dependency: the official
  `Requires Plugins: woocommerce` header (enforced on WordPress 6.5+)
  and a bootstrap guard in `Core::init()` that skips the plugin
  submodules and shows an admin notice when WooCommerce is missing or
  inactive.

### Changed

- The plugin row on the Plugins screen now links to the source
  repository instead of the plugin site: the meta reads "Settings"
  (second entry, pointing to the plugin admin page) and "Github"
  (pointing to https://github.com/softfactorIA/wc-category-donations).
  The "By <author>" entry is no longer shown on the plugin row (authors
  remain in the plugin header, readme and the About tab). The `Plugin
  URI` header was updated accordingly.
- Translations reviewed: new es_ES/gl_ES catalogs for the About and
  Support strings, tab names aligned in the About prose, an orphan
  "Source code" msgid removed and the duplicate "Donations" entry
  merged.

## [1.1.0] - 2026-09-27

### Changed

- Renamed the plugin from "Razas" to **WooCommerce Category Donations**
  (slug `wc-category-donations`, textdomain `wc-category-donations`,
  namespace `WcCategoryDonations\`, `WCCD_*`/`wccd_*` prefixes, table
  `wccd_donations`). Legacy `razas_*` options, term meta, order meta and
  the donations table are migrated automatically on activation; the old
  shortcodes, public functions, filters and block name are kept as
  deprecated aliases.

### Added

- Customer donations summary on the WordPress user profile screen
  (Users → edit): total donated on behalf of the user (registered orders
  plus guest orders sharing the billing email), per-category breakdown
  and recent donations. The donations table now stores the customer user
  ID and billing email per row (schema `1.4`, backfilled on upgrade).

- Spanish (es_ES) and Galician (gl_ES) translations (PO/MO files, the
  `wc-category-donations.pot` source template and block-editor JSON
  translation files).

### Removed

- Per-category initial donation amount: the settings field, its
  contribution to the category/grand totals and the stored term meta are
  gone; leftover meta is cleaned up on upgrade (donations table schema
  `1.3`).

## [1.0.0] - 2026-09-27

### Added

- Yearly CSV donation report from the donations admin tab, with a year
  selector and one row per recorded donation (active and cancelled).
- `format="0"` attribute on the money shortcodes to output the plain
  number without currency markup, and plain-text category errors.
- `[razas_donating_orders]` and `[razas_donation_average]` shortcodes,
  plus both totals shown on the donations admin tab.
- Per-category initial donation amount (starting figure the category
  total is calculated from), currency-aware field in the settings table.
- `[razas_cart_donation_amount]` shortcode with the donation amount of
  the current cart contents.
- WooCommerce feature compatibility declaration.

### Changed

- Donation amounts are calculated on the net product value (price before
  tax) everywhere: product shortcode, order recording and refunds.

## [0.2.0] - 2026-09-26

### Added

- Per-category donation percentage and cause settings, stored as term
  meta, with a default donation percentage for the rest.
- Donation recording on confirmed orders and per-category/grand-total
  shortcodes (`[razas_category_donation_total]`, `[razas_donations_total]`).
- Cancellation of recorded donations for cancelled, refunded, trashed and
  draft orders, and refund handling.
- Shortcodes reference tab on the plugin admin page with live previews.
- Idempotent demo data seeding (categories, products, shop front page,
  old orders, bank transfer) for the dev Docker stack.
- Per-request calculation caching.

### Changed

- Dropped the legacy "donation message" setting.

## [0.1.0] - 2026-09-26

### Added

- Initial plugin scaffold with the Docker WordPress dev stack.
- Settings page: default donation percentage.
- `[razas_donation_percentage]`, `[razas_category_cause]` and
  `[razas_donation_amount]` shortcodes, plus the public functions
  `razas_get_donation_percentage()`, `razas_get_category_cause()` and
  `razas_get_donation_amount()`.
- Uninstall handler that removes plugin options and term meta.