# WooCommerce Trendyol Integration

A comprehensive, robust WooCommerce integration plugin for the Trendyol Marketplace API.

## Features

- **Cascading Category Mapping**: Map WooCommerce product categories directly to Trendyol categories with level-by-level cascading selection and automatic attribute discovery.
- **Attribute & Value Mapping**: Map WooCommerce global and custom attributes to Trendyol category-required and optional attributes, including value standardization.
- **Brand Synchronization**: Synchronize Trendyol verified brands to local WordPress custom taxonomies or WooCommerce brand extensions.
- **Product Synchronization**:
  - Push products and variations to Trendyol with price, stock, images, barcode, and attributes.
  - Automatic inventory and price updates.
  - Batch / bulk product sync management.
- **Order Management & Sync**:
  - Automatic bidirectional sync of Trendyol orders into WooCommerce.
  - Tracking status changes (Created, Picking, Invoiced, Shipped, Delivered, Cancelled, Returned).
  - Webhook and scheduled cron support for real-time and background order processing.
- **Tools & Utilities**:
  - Bulk taxonomy mapping modal.
  - Settings and mapping import/export tools for backups and migrations.
  - Comprehensive logging and API diagnostic tools.

## Requirements

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher (tested up to 9.0+)
- PHP 8.0 or higher
- Active Trendyol Marketplace Seller Account (Supplier ID, API Key, API Secret)

## Installation

1. Upload the `woo-trendyol` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Trendyol** in the WordPress admin sidebar to configure your API credentials and settings.

## Configuration

1. **API Settings**: Enter your Trendyol Supplier ID, API Key, and API Secret under **Trendyol > Settings**.
2. **Category Mapping**: Go to **Products > Categories** to assign Trendyol categories and configure default attribute values for each taxonomy.
3. **Attribute Mapping**: Map your WooCommerce attributes (Color, Size, Material, etc.) to corresponding Trendyol attributes.
4. **Order Sync**: Enable background order fetching and configure status transitions.

## Directory Structure

```
woo-trendyol/
├── admin/               # Admin dashboard UI, controllers, CSS, and JS scripts
├── assets/              # Static category data maps and shared assets
├── includes/            # Core business logic, API client, sync services, mapper
├── languages/           # Localization files
├── .gitignore           # Git ignore rules
├── README.md            # Plugin documentation
├── uninstall.php        # Cleanup routine on deletion
└── woo-trendyol.php     # Main plugin bootstrap file
```

## License

This plugin is licensed under the [GPL-2.0+](http://www.gnu.org/licenses/gpl-2.0.txt) license.
