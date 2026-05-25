# BEBOP SALES BOOSTER

WordPress/WooCommerce plugin that helps increase cart value before checkout.

The public plugin name and package folder are **BEBOP SALES BOOSTER**. Internal WordPress slugs use `bebop-sales-booster` / `bebop_sales_booster`.

## Features

- Free-shipping progress bar with product suggestions.
- Product-page recommendations based on related product tags.
- Cart, mini-cart, and checkout add-on offers.
- Manual pairing rules for bundles and quick add-ons.
- AJAX add-to-cart for simple products and selected variations.
- Product edit meta box for controlling offer behavior.
- Admin dashboard with settings, rules, and diagnostics.
- Polish storefront copy styled for the Woodmart theme.

## Requirements

- WordPress 6.4 or newer.
- PHP 7.4 or newer.
- WooCommerce 7.0 or newer.

## Repository Layout

```text
.
├── README.md
├── CHANGELOG.md
├── LICENSE
├── .gitignore
└── BEBOP SALES BOOSTER/
    ├── BEBOP SALES BOOSTER.php
    ├── readme.txt
    ├── uninstall.php
    └── assets/
```

## Installation

1. Upload the `BEBOP SALES BOOSTER` folder to `wp-content/plugins`.
2. Activate **BEBOP SALES BOOSTER** in WordPress.
3. Open **BEBOP SALES BOOSTER** from the WordPress admin menu.
4. Configure free-shipping products, related offers, cart offers, and checkout offers.
5. Clear site/cache plugin caches after updating.

## Build Release ZIP

Run this from the repository root:

```bash
zip -r -FS "BEBOP SALES BOOSTER.zip" "BEBOP SALES BOOSTER" -x "__MACOSX/*" "*.DS_Store"
```

The ZIP should contain a single top-level `BEBOP SALES BOOSTER/` folder so WordPress can install it correctly.

## Validation

```bash
node --check "BEBOP SALES BOOSTER/assets/bebop-sales-booster.js"
node --check "BEBOP SALES BOOSTER/assets/bebop-sales-booster-admin.js"
php -l "BEBOP SALES BOOSTER/BEBOP SALES BOOSTER.php"
unzip -l "BEBOP SALES BOOSTER.zip"
```

If PHP is not available locally, run the PHP lint on the deployment machine or in CI.

## Release

Current version: `0.4.4`

Recommended release flow:

```bash
git tag v0.4.4
git push origin main
git push origin v0.4.4
```

Attach `BEBOP SALES BOOSTER.zip` to the GitHub release.
