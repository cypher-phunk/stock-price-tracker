<?php

/**
 * Plugin Name: Stock Data Plugin
 * Description: Stock Data Plugin for WordPress using MarketStack API.
 * Version: 1.0.5
 * Author: RoDojo Web Development
 *
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDP_PLUGIN_VERSION', '1.0.5');

define('SDP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SDP_PLUGIN_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, 'sdp_create_db_tables');

function sdp_create_db_tables()
{
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    $sql_tickers = "CREATE TABLE {$wpdb->prefix}stock_tickers (
        id INT(11) NOT NULL AUTO_INCREMENT,
        symbol VARCHAR(10) NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        post_created TINYINT(1) DEFAULT 0,
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

    $sql_stock_company_info = "CREATE TABLE {$wpdb->prefix}stock_company_info (
        id INT(11) NOT NULL AUTO_INCREMENT,
        ticker_id INT(11) NOT NULL,
        name VARCHAR(255),
        exchange VARCHAR(10),
        sector VARCHAR(255),
        industry VARCHAR(255),
        website VARCHAR(255),
        about TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        FOREIGN KEY (ticker_id) REFERENCES {$wpdb->prefix}stock_tickers(id)
    ) $charset_collate;";

    $sql_metrics = "CREATE TABLE {$wpdb->prefix}stock_metrics (
    ticker_id INT(11) NOT NULL,
    latest_date DATE NOT NULL,
    latest_close DECIMAL(10,4) NOT NULL,
    previous_close DECIMAL(10,4) NOT NULL,
    percent_change DECIMAL(6,2) NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ticker_id),
    FOREIGN KEY (ticker_id) REFERENCES {$wpdb->prefix}stock_tickers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB $charset_collate;";

    dbDelta($sql_tickers);
    dbDelta($sql_prices);
    dbDelta($sql_market_tickers);
    dbDelta($sql_stock_company_info);
    dbDelta($sql_metrics);

    update_option('sdp_plugin_version', SDP_PLUGIN_VERSION);
}

require_once(SDP_PLUGIN_PATH . 'includes/database-handler.php');
require_once(SDP_PLUGIN_PATH . 'includes/class-sdp-api.php');
require_once(SDP_PLUGIN_PATH . 'includes/log-helpers.php');
require_once(SDP_PLUGIN_PATH . 'includes/api-key-management.php');
require_once(SDP_PLUGIN_PATH . 'includes/acf-hooks.php');
require_once(SDP_PLUGIN_PATH . 'includes/sdp-cron.php');
require_once(SDP_PLUGIN_PATH . 'includes/shortcodes.php');
require_once(SDP_PLUGIN_PATH . 'includes/stock-template-helper.php');
require_once(SDP_PLUGIN_PATH . 'includes/cpt-helper.php');
require_once(SDP_PLUGIN_PATH . 'includes/db-helpers.php');
require_once(SDP_PLUGIN_PATH . 'includes/reports-helper.php');

add_action('admin_enqueue_scripts', 'sdp_enqueue_admin_styles');
add_action('admin_enqueue_scripts', 'sdp_enqueue_admin_scripts');

function sdp_enqueue_admin_styles()
{
    wp_enqueue_style('sdp-admin-style', SDP_PLUGIN_URL . 'assets/css/style.css');
}

function sdp_enqueue_admin_scripts()
{
    wp_enqueue_script('ticker-autocomplete', SDP_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], null, true);
}

// Securely register settings (API key)
add_action('admin_init', 'sdp_register_settings');

function sdp_register_settings()
{
    register_setting('sdp_settings_group', 'sdp_marketstack_api_key_encrypted');
}

// Plugin admin menu
add_action('admin_menu', 'sdp_add_admin_menu');

function sdp_add_admin_menu()
{
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
function sdp_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    require_once SDP_PLUGIN_PATH . 'includes/admin-settings-page.php';
}

// Cron for EOD Prices from stock_tickers
add_action('sdp_daily_update', 'sdp_fetch_daily_stock_data');

function sdp_fetch_daily_stock_data()
{
    sdp_cron_log("✅ sdp_daily_update cron fired.");
    sdp_update_stock_data();
}

add_action('sdp_update_stock_metrics', 'update_stock_metrics');
function update_stock_metrics()
{
    sdp_update_stock_metrics();
}

register_activation_hook(__FILE__, 'sdp_schedule_cron');

function sdp_update_all_report_fields()
{
    $reports = get_posts([
        'post_type' => 'report',
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    foreach ($reports as $report) {
        sdp_update_report_post_fields($report->ID);
    }
}

// Cron hook
add_action('sdp_update_report_fields_cron', 'sdp_update_all_report_fields');

function sdp_schedule_cron()
{
    $timezone = new DateTimeZone('America/New_York');
    $now = time();

    $base_time = new DateTime('today 17:30:00', $timezone);
    if ($base_time->getTimestamp() <= $now) {
        $base_time->modify('+1 day');
    }

    if (!wp_next_scheduled('sdp_daily_update')) {
        wp_schedule_event($base_time->getTimestamp(), 'daily', 'sdp_daily_update');
    }

    if (!wp_next_scheduled('sdp_update_stock_metrics')) {
        $stock_time = clone $base_time;
        $stock_time->modify('+5 minutes');
        wp_schedule_event($stock_time->getTimestamp(), 'daily', 'sdp_update_stock_metrics');
    }

    if (!wp_next_scheduled('sdp_update_report_fields_cron')) {
        $report_time = clone $base_time;
        $report_time->modify('+10 minutes');
        wp_schedule_event($report_time->getTimestamp(), 'daily', 'sdp_update_report_fields_cron');
    }
}


add_action('init', function () {
    if (isset($_GET['trigger_stock_update']) && current_user_can('manage_options')) {
        sdp_fetch_daily_stock_data();
        echo "Stock data update triggered.";
        exit;
    }
});


// Clear scheduled cron job on deactivation
register_deactivation_hook(__FILE__, 'sdp_clear_cron');

function sdp_clear_cron()
{
    wp_clear_scheduled_hook('sdp_daily_update');
    wp_clear_scheduled_hook('sdp_update_stock_metrics');
}

add_action('plugins_loaded', 'sdp_check_db_version');

function sdp_check_db_version()
{
    $installed_version = get_option('sdp_plugin_version', '1.0');
    if (version_compare($installed_version, SDP_PLUGIN_VERSION, '<')) {
        sdp_create_db_tables(); // run updates
    }
}

add_action('plugins_loaded', 'sdp_check_plugin_version');

function sdp_check_plugin_version()
{
    $stored_version = get_option('sdp_plugin_version');
    if (version_compare($stored_version, SDP_PLUGIN_VERSION, '<')) {
        sdp_create_db_tables(); // run DB updates safely
    }
}

// AJAX Handler
add_action('wp_ajax_search_market_tickers', 'search_market_tickers_callback');

function search_market_tickers_callback()
{
    global $wpdb;

    $search = sanitize_text_field($_POST['search_term']);
    if (strlen($search) < 2) {
        wp_send_json_error();
    }

    $table = $wpdb->prefix . 'market_tickers';

    $search_term = $wpdb->esc_like($search) . '%';

    $query = $wpdb->prepare(
        "SELECT symbol FROM $table WHERE symbol LIKE %s ORDER BY symbol ASC LIMIT 20",
        $search_term
    );

    $results = $wpdb->get_results($query);

    if ($results) {
        wp_send_json_success($results);
    } else {
        wp_send_json_error();
    }

    wp_die(); // very important
}

// AJAX Handler for checking tickers against DB
add_action('wp_ajax_check_stock_tickers', 'check_stock_tickers_callback');
function check_stock_tickers_callback()
{
    global $wpdb;

    $input = $_POST['tickers'] ?? [];
    if (!is_array($input)) wp_send_json_error();

    $input = array_map('strtoupper', array_map('sanitize_text_field', $input));

    $stock_table = $wpdb->prefix . 'stock_tickers';
    $market_table = $wpdb->prefix . 'market_tickers';

    $placeholders = implode(',', array_fill(0, count($input), '%s'));

    // Step 1: Confirm these tickers exist in market_tickers (master list)
    $query = $wpdb->prepare(
        "SELECT symbol FROM $market_table WHERE symbol IN ($placeholders)",
        ...$input
    );
    $valid_symbols = $wpdb->get_col($query);

    // Step 2: Find which of those are already in the tracked stock_tickers table
    if (!empty($valid_symbols)) {
        $placeholders2 = implode(',', array_fill(0, count($valid_symbols), '%s'));
        $query2 = $wpdb->prepare(
            "SELECT symbol FROM $stock_table WHERE symbol IN ($placeholders2)",
            ...$valid_symbols
        );
        $already_tracked = $wpdb->get_col($query2);
    } else {
        $already_tracked = [];
    }

    // Calculate
    $validated_input = array_map('strtoupper', $valid_symbols);
    $existing = array_intersect($validated_input, $already_tracked);
    $to_add = array_diff($validated_input, $already_tracked);
    $invalid = array_diff($input, $validated_input); // input not in main list

    wp_send_json_success([
        'existing' => array_values($existing),
        'missing' => array_values($to_add),
        'invalid' => array_values($invalid)
    ]);
}


add_action('wp_ajax_get_stock_tickers', 'get_stock_tickers_callback');
function get_stock_tickers_callback()
{
    global $wpdb;
    $table = $wpdb->prefix . 'stock_tickers';
    $symbols = $wpdb->get_col("SELECT symbol FROM $table ORDER BY symbol ASC");
    wp_send_json_success($symbols);
}

add_action('wp_ajax_remove_stock_ticker', 'remove_stock_ticker_callback');
function remove_stock_ticker_callback()
{
    global $wpdb;

    $symbol = strtoupper(sanitize_text_field($_POST['symbol'] ?? ''));
    if (!$symbol) wp_send_json_error();

    $table = $wpdb->prefix . 'stock_tickers';
    $wpdb->delete($table, ['symbol' => $symbol]);

    wp_send_json_success();
}

add_action('wp_ajax_get_stock_tickers_paginated', 'get_stock_tickers_paginated_callback');
function get_stock_tickers_paginated_callback()
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_tickers';
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = max(1, intval($_POST['per_page'] ?? 25));
    $offset = ($page - 1) * $per_page;

    $tickers = $wpdb->get_col($wpdb->prepare(
        "SELECT symbol FROM $table ORDER BY symbol ASC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $total_pages = ceil($total / $per_page);

    wp_send_json_success([
        'tickers' => $tickers,
        'total_pages' => $total_pages
    ]);
}

add_action('wp_ajax_add_stock_tickers', 'add_stock_tickers_callback');
function add_stock_tickers_callback()
{
    global $wpdb;

    $input = $_POST['tickers'] ?? [];
    if (!is_array($input)) wp_send_json_error();

    $table = $wpdb->prefix . 'stock_tickers';
    $added = [];

    foreach ($input as $symbol) {
        $symbol = sanitize_text_field(strtoupper($symbol));

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE symbol = %s",
            $symbol
        ));

        if (!$exists) {
            $wpdb->insert($table, ['symbol' => $symbol]);
            $added[] = $symbol;
        }
        grab_company_info($symbol); // Fetch company info
    }

    wp_send_json_success(['added' => $added]);
}

function grab_company_info($symbol)
{
    $api = new SDP_API_Handler();
    $company_info = $api->get_company_info($symbol);

    if ($company_info) {
        global $wpdb;
        $table = $wpdb->prefix . 'stock_company_info';

        $exchange = null;
        if (!empty($company_info['stock_exchanges']) && is_array($company_info['stock_exchanges'])) {
            $last_exchange = end($company_info['stock_exchanges']);
            $exchange = $last_exchange['acronym1'] ?? null;
        }

        $data = [
            'ticker_id' => $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s",
                $symbol
            )),
            'name' => $company_info['name'] ?? null,
            'exchange' => $exchange,
            'sector' => $company_info['sector'] ?? null,
            'industry' => $company_info['industry'] ?? null,
            'website' => $company_info['website'] ?? null,
            'about' => $company_info['about'] ?? null
        ];

        // Insert or update company info
        if ($data['ticker_id']) {
            // Check if the ticker already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE ticker_id = %d",
                $data['ticker_id']
            ));
            if ($existing) {
                // Update existing record
                $wpdb->update($table, $data, ['ticker_id' => $data['ticker_id']]);
            } else {
                // Insert new record
                $wpdb->insert($table, $data);
            }
        }
    }
}

function create_stock_post($symbol)
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_tickers';
    $ticker_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE symbol = %s",
        $symbol
    ));
    $company_info = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_company_info WHERE ticker_id = %d",
        $ticker_id
    ));
    if (!$ticker_id) {
        return;
    }
    // Check if post exists in stock post type using wp get_posts
    // Search by post title
    $post = get_posts([
        'post_type' => 'stock',
        'post_status' => 'publish',
        'title' => $symbol
    ]);
    if ($post) {
        // Update post ACF Fields
        if (function_exists('update_field')) {
            update_field('company_name', $company_info->name, $post[0]->ID);
            update_field('ticker_symbol', $symbol, $post[0]->ID);
            update_field('stock_exchange', $company_info->exchange, $post[0]->ID);
            update_field('sector', $company_info->sector, $post[0]->ID);
            update_field('industry', $company_info->industry, $post[0]->ID);
            update_field('website', $company_info->website, $post[0]->ID);
            update_field('about', $company_info->about, $post[0]->ID);
            // Set category to sector
            $sector = $company_info->sector;
            $term = term_exists($sector, 'category');
            if ($term) {
                $term_id = $term['term_id'];
            } else {
                $term_id = wp_insert_term($sector, 'category');
                if (is_wp_error($term_id)) {
                    error_log('Error creating category: ' . $term_id->get_error_message());
                    return;
                }
                $term_id = $term_id['term_id'];
            }
        }
        return;
    }
    // Create post
    $post_id = wp_insert_post([
        'post_title' => $symbol,
        'post_type' => 'stock',
        'post_status' => 'publish'
    ]);

    if (!is_wp_error($post_id)) {
        if (function_exists('update_field')) {
            update_field('company_name', $company_info->name, $post_id);
            update_field('ticker_symbol', $symbol, $post_id);
            update_field('stock_exchange', $company_info->exchange, $post_id);
            update_field('sector', $company_info->sector, $post_id);
            update_field('industry', $company_info->industry, $post_id);
            update_field('website', $company_info->website, $post_id);
            update_field('about', $company_info->about, $post_id);
            update_field('ticker_id', $ticker_id, $post_id);
            // Set category to sector
            $sector = $company_info->sector;
            $term = term_exists($sector, 'category');
            if ($term) {
                $term_id = $term['term_id'];
            } else {
                $term_id = wp_insert_term($sector, 'category');
                if (is_wp_error($term_id)) {
                    error_log('Error creating category: ' . $term_id->get_error_message());
                    return;
                }
                $term_id = $term_id['term_id'];
            }
        } else {
            error_log('ACF function not found');
        }
        $wpdb->update(
            $table,
            ['post_created' => 1],
            ['id' => $symbol->id]
        );
    } else {
        error_log('Error creating post: ' . $post_id->get_error_message());
    }
}

add_action('wp_ajax_grab_company_info', 'grab_company_info_callback');
function grab_company_info_callback()
{
    $symbol = strtoupper(sanitize_text_field($_POST['symbol'] ?? ''));
    if (!$symbol) wp_send_json_error();

    grab_company_info($symbol);
    create_stock_post($symbol);

    wp_send_json_success();
}

add_action('wp_ajax_fetch_all_company_info', 'fetch_all_company_info_callback');
function fetch_all_company_info_callback()
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_tickers';
    $tickers = $wpdb->get_col("SELECT symbol FROM $table");

    foreach ($tickers as $symbol) {
        grab_company_info($symbol);
        create_stock_post($symbol);
    }

    wp_send_json_success();
}

function get_all_tracked_symbols()
{
    global $wpdb;
    $table = $wpdb->prefix . 'stock_tickers';
    return $wpdb->get_col("SELECT DISTINCT symbol FROM {$table}");
}

function build_stock_eod_transient()
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_prices';
    $tracked_stocks = get_all_tracked_symbols();

    $data = [];

    foreach ($tracked_stocks as $symbol) {
        // Get latest record for this symbol
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$table}
            WHERE symbol = %s
            ORDER BY date DESC
            LIMIT 1
        ", $symbol));

        if (!$row) continue; // No data for this ticker

        // Get previous day’s close
        $prev_close = $wpdb->get_var($wpdb->prepare("
            SELECT close FROM {$table}
            WHERE symbol = %s AND date < %s
            ORDER BY date DESC
            LIMIT 1
        ", $symbol, $row->date));

        $data[$symbol] = (object)[
            'symbol'      => $symbol,
            'date'        => $row->date,
            'close'       => (float)$row->close,
            'prev_close'  => $prev_close ? (float)$prev_close : null,
        ];
    }

    set_transient('stock_eod_cache', $data, DAY_IN_SECONDS);
}

function display_stock_eod_info($ticker)
{
    $data = get_transient('stock_eod_cache');

    if (!$data) {
        return '<div style="color: red;">⚠️ Transient not found. Try rebuilding it.</div>';
    }

    if (!isset($data[$ticker])) {
        return '<div style="color: orange;">⚠️ No EOD data found for <strong>' . esc_html($ticker) . '</strong>.</div><pre>' .
            'Available tickers: <br>' .
            implode(', ', array_map('htmlspecialchars', array_keys($data))) .
            '</pre>';
    }

    $stock = $data[$ticker];
    $price = number_format($stock->close, 2);
    $date  = date('M j, Y', strtotime($stock->date));

    $percent_change = '';
    if ($stock->prev_close && $stock->prev_close > 0) {
        $change = $stock->close - $stock->prev_close;
        $percent = ($change / $stock->prev_close) * 100;
        $percent_change = sprintf(
            ' (<span style="color:%s">%+.2f%%</span>)',
            $change >= 0 ? 'green' : 'red',
            $percent
        );
    }

    return "<strong>\${$price}{$percent_change}</strong><br><small>EOD Price Date: {$date}</small>";
}


add_shortcode('stock_eod', function ($atts) {
    $atts = shortcode_atts([
        'ticker' => ''
    ], $atts);

    return display_stock_eod_info($atts['ticker']);
});

add_action('init', 'sdp_register_shortcodes');

add_action('admin_init', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;

    if (isset($_GET['build_eod_transient'])) {
        build_stock_eod_transient();
        echo '<div style="padding:10px;background:#dff0d8;color:#3c763d;">✅ Transient rebuilt successfully!</div>';
        exit;
    }
    
    if (isset($_GET['run_metrics_update'])) {
        sdp_update_stock_metrics();
        echo '<div class="notice notice-success is-dismissible"><p>Stock metrics updated successfully.</p></div>';
        exit;
    }
});

add_action('admin_notices', function () {
    $data = get_transient('stock_eod_cache');
    if ($data) {
        echo '<div style="background:#d9edf7;padding:10px;">✅ Transient is loaded with ' . count($data) . ' tickers.</div>';
    } else {
        echo '<div style="background:#f2dede;padding:10px;">❌ Still no transient.</div>';
    }
});

// Report Page Grid.js pkgs
add_action('wp_enqueue_scripts', 'sdp_enqueue_gridjs_assets');
function sdp_enqueue_gridjs_assets()
{
    if (!is_post_type_archive('report')) return;

    wp_enqueue_script('gridjs', 'https://unpkg.com/gridjs/dist/gridjs.umd.js', [], null, true);
    wp_enqueue_style('gridjs-style', 'https://unpkg.com/gridjs/dist/theme/mermaid.min.css');
}
add_action('wp_enqueue_scripts', 'sdp_localize_report_data');
