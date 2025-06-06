# MarketData Stock Price Tracker Wordpress Plugin

## Optional Functionality
We recommend that you disable the built-in wp cron and ensure that your cron job runs ontime.

This will disable the built-in psuedo-cron system in wp-config.php
define('DISABLE_WP_CRON', true);

You should now run a command in your server to cron your site. For example:

```*/5 * * * * wget https://example.com/wp-cron.php?doing_wp_cron```

## Overview

The MarketData Stock Price Tracker is a WordPress plugin that allows users to manage stock tickers and view historical stock data using the Marketstack API. This plugin provides an admin interface for managing API keys, adding and viewing stock tickers, and fetching historical stock data.

## Features

- Manage Marketstack API key securely.
- Add and manage stock tickers.
- Fetch and display historical stock data.
- Schedule daily updates for stock data using WordPress cron jobs.

## Installation

1. Download the plugin files and upload them to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the 'Stock Data' menu in the WordPress admin dashboard to configure the plugin.

## Usage

### Setting Up API Key

1. Go to the 'Stock Data' menu in the WordPress admin dashboard.
2. Enter your Marketstack API key in the provided field and save it.

### Managing Tickers

1. Go to the 'Stock Data' menu in the WordPress admin dashboard.
2. Use the 'Manage Tickers' section to add new stock tickers by entering their symbols.
3. View the list of current tickers and their details.

### Fetching Historical Data

1. Go to the 'Stock Data' menu in the WordPress admin dashboard.
2. Use the 'Manual Historical Data Pull' section to fetch historical data for a specific ticker by selecting the ticker and date range.

### Viewing Historical Data

1. Go to the 'Stock Data' menu in the WordPress admin dashboard.
2. Use the 'View Historical Data' section to select a ticker and view its historical prices.

## Cron Job for Daily Updates

The plugin schedules a daily cron job to fetch the latest stock data for all tickers. This ensures that the stock data is always up-to-date.

## Development

### Key Files

- `stock-data-plugin.php`: Main plugin file that initializes the plugin and registers hooks.
- `includes/admin-settings-page.php`: Contains the HTML and PHP code for the admin settings page.
- `includes/api-key-management.php`: Functions for encrypting, decrypting, and validating the API key.
- `includes/class-sdp-api.php`: Class for interacting with the Marketstack API.
- `includes/database-handler.php`: Class for handling database operations.
- `includes/helpers.php`: Helper functions.
- `includes/sdp-cron.php`: Functions for scheduling and executing cron jobs.

## Structure
```bash
.
├── assets
│   ├── css
│   │   └── style.css
│   └── js
│       ├── admin.js
│       ├── search.js
│       └── ticker-posts.js
├── bricks-elements
│   └── class-stock-performance-chart.php
├── includes
│   ├── acf-hooks.php
│   ├── admin-settings-page.php
│   ├── api-key-management.php
│   ├── class-sdp-api.php
│   ├── database-handler.php
│   ├── helpers.php
│   └── sdp-cron.php
├── README.md
└── stock-data-plugin.php
```

## Author

RoDojo Web Development - [support@rodojo.dev](mailto:support@rodojo.dev)