<?php
/**
 * Plugin Name: Stock Data Plugin
 * Description: Manages stock tickers and historical data using Marketstack API.
 * Version: 1.0.3
 * Author: RoDojo Web Development
 *
 */

// Security: Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('SDP_PLUGIN_VERSION', '1.0.2'); // increment on schema change

// Define plugin constants
define('SDP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SDP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Activation hook for creating database tables
register_activation_hook(__FILE__, 'sdp_create_db_tables');

function sdp_create_db_tables() {
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    $sql_tickers = "CREATE TABLE {$wpdb->prefix}stock_tickers (
        id INT(11) NOT NULL AUTO_INCREMENT,
        symbol VARCHAR(10) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql_prices = "CREATE TABLE {$wpdb->prefix}stock_prices (
        id INT(11) NOT NULL AUTO_INCREMENT,
        ticker_id INT(11) NOT NULL,
        date DATE NOT NULL,
        open DECIMAL(10,4),
        high DECIMAL(10,4),
        low DECIMAL(10,4),
        close DECIMAL(10,4),
        volume BIGINT,
        adj_open DECIMAL(10,4),
        adj_high DECIMAL(10,4),
        adj_low DECIMAL(10,4),
        adj_close DECIMAL(10,4),
        adj_volume BIGINT,
        split_factor DECIMAL(10,4) DEFAULT 1.0,
        dividend DECIMAL(10,4) DEFAULT 0,
        symbol VARCHAR(10),
        exchange VARCHAR(10),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_date_ticker (ticker_id, date),
        FOREIGN KEY (ticker_id) REFERENCES {$wpdb->prefix}stock_tickers(id)
    ) ENGINE=InnoDB $charset_collate;";

    $sql_market_tickers = "CREATE TABLE {$wpdb->prefix}market_tickers (
        id INT(11) NOT NULL AUTO_INCREMENT,
        symbol VARCHAR(10) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql_tickers);
    dbDelta($sql_prices);
    dbDelta($sql_market_tickers);

    // Update version after creating tables
    update_option('sdp_plugin_version', SDP_PLUGIN_VERSION);
}

// Include necessary files
require_once(SDP_PLUGIN_PATH . 'includes/database-handler.php');
require_once(SDP_PLUGIN_PATH . 'includes/class-sdp-api.php');
require_once(SDP_PLUGIN_PATH . 'includes/helpers.php');
require_once(SDP_PLUGIN_PATH . 'includes/api-key-management.php');
require_once(SDP_PLUGIN_PATH . 'includes/acf-hooks.php');

// Enqueue admin styles
add_action('admin_enqueue_scripts', 'sdp_enqueue_admin_styles');
add_action('admin_enqueue_scripts', 'sdp_enqueue_admin_scripts');

function sdp_enqueue_admin_styles() {
    wp_enqueue_style('sdp-admin-style', SDP_PLUGIN_URL . 'assets/css/style.css');
}

function sdp_enqueue_admin_scripts() {
    wp_enqueue_script('ticker-autocomplete', SDP_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], null, true);
}

// Securely register settings (API key)
add_action('admin_init', 'sdp_register_settings');

function sdp_register_settings() {
    register_setting('sdp_settings_group', 'sdp_marketstack_api_key_encrypted');
}

// Plugin admin menu
add_action('admin_menu', 'sdp_add_admin_menu');

function sdp_add_admin_menu() {
    add_menu_page(
        'Stock Data Plugin',
        'Stock Data',
        'manage_options',
        'stock-data-plugin',
        'sdp_admin_page',
        'dashicons-chart-area',
        60
    );
}

// Admin page callback
function sdp_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    require_once SDP_PLUGIN_PATH . 'includes/admin-settings-page.php';
}

// Schedule cron job on plugin activation
register_activation_hook(__FILE__, 'sdp_schedule_cron');

function sdp_schedule_cron() {
    if (!wp_next_scheduled('sdp_daily_update')) {
        wp_schedule_event(strtotime('16:00:00'), 'daily', 'sdp_daily_update');
    }
}

// Clear scheduled cron job on deactivation
register_deactivation_hook(__FILE__, 'sdp_clear_cron');

function sdp_clear_cron() {
    wp_clear_scheduled_hook('sdp_daily_update');
}

// Cron hook for daily update
add_action('sdp_daily_update', 'sdp_fetch_daily_stock_data');

function sdp_fetch_daily_stock_data() {
    require_once(SDP_PLUGIN_PATH . 'includes/sdp-cron.php');
    sdp_update_stock_data();
}

add_action('plugins_loaded', 'sdp_check_db_version');

function sdp_check_db_version() {
    $installed_version = get_option('sdp_plugin_version', '1.0');
    if (version_compare($installed_version, SDP_PLUGIN_VERSION, '<')) {
        sdp_create_db_tables(); // run updates
    }
}

add_action('plugins_loaded', 'sdp_check_plugin_version');

function sdp_check_plugin_version() {
    $stored_version = get_option('sdp_plugin_version');
    if (version_compare($stored_version, SDP_PLUGIN_VERSION, '<')) {
        sdp_create_db_tables(); // run DB updates safely
    }
}
