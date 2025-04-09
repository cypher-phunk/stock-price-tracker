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

define('SDP_PLUGIN_VERSION', '1.0.5'); // increment on schema change

// Define plugin constants
define('SDP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SDP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Activation hook for creating database tables
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

    dbDelta($sql_tickers);
    dbDelta($sql_prices);
    dbDelta($sql_market_tickers);
    dbDelta($sql_stock_company_info);

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

// Schedule cron job on plugin activation
register_activation_hook(__FILE__, 'sdp_schedule_cron');

function sdp_schedule_cron()
{
    if (!wp_next_scheduled('sdp_daily_update')) {
        wp_schedule_event(strtotime('16:00:00'), 'daily', 'sdp_daily_update');
    }
}

// Clear scheduled cron job on deactivation
register_deactivation_hook(__FILE__, 'sdp_clear_cron');

function sdp_clear_cron()
{
    wp_clear_scheduled_hook('sdp_daily_update');
}

// Cron hook for daily update
add_action('sdp_daily_update', 'sdp_fetch_daily_stock_data');

function sdp_fetch_daily_stock_data()
{
    require_once(SDP_PLUGIN_PATH . 'includes/sdp-cron.php');
    sdp_update_stock_data();
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

add_action('admin_init', function () {
    if (isset($_GET['build_eod_transient'])) {
        build_stock_eod_transient();
        echo '<div style="padding:10px;background:#dff0d8;color:#3c763d;">✅ Transient rebuilt successfully!</div>';
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

add_shortcode('stock_chart', function ($atts) {
    $post_id = get_the_ID();
    $report_date_raw = get_field('report_date', $post_id);
    if (!$report_date_raw) return '<p>Missing report date.</p>';

    $report_date = date('Y-m-d', strtotime($report_date_raw));
    $stock_post = get_field('symbol', $post_id);
    if (!($stock_post)) return '<p>Missing stock.</p>';

    $ticker_symbol = get_field('ticker_symbol', $stock_post[0]->ID);
    if (!$ticker_symbol) return '<p>Missing ticker symbol.</p>';

    global $wpdb;
    $ticker_id = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s LIMIT 1", $ticker_symbol)
    );
    if (!$ticker_id) return '<p>Invalid ticker symbol.</p>';

    $query = $wpdb->prepare(
        "SELECT date, close FROM {$wpdb->prefix}stock_prices
         WHERE ticker_id = %d
         ORDER BY date ASC",
        $ticker_id
    );
    $results = $wpdb->get_results($query);
    if (!$results) return '<p>No price data found.</p>';

    $data_up = [];
    $data_down = [];

    for ($i = 0; $i < count($results) - 1; $i++) {
        $curr = $results[$i];
        $next = $results[$i + 1];

        $curr_point = ['x' => $curr->date, 'y' => (float) $curr->close];
        $next_point = ['x' => $next->date, 'y' => (float) $next->close];

        if ($next->close > $curr->close) {
            $data_up[] = $curr_point;
            $data_up[] = $next_point;
            $data_down[] = ['x' => $curr->date, 'y' => null];
            $data_down[] = ['x' => $next->date, 'y' => null];
        } elseif ($next->close < $curr->close) {
            $data_down[] = $curr_point;
            $data_down[] = $next_point;
            $data_up[] = ['x' => $curr->date, 'y' => null];
            $data_up[] = ['x' => $next->date, 'y' => null];
        } else {
            // No change — optional: skip or assign to both
            $data_up[] = ['x' => $curr->date, 'y' => null];
            $data_down[] = ['x' => $curr->date, 'y' => null];
        }
    }


    $chart_id = 'stockChart_' . $post_id;

    ob_start(); ?>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <div id="<?php echo esc_attr($chart_id); ?>" style="min-height:350px;width:100%;margin:1em 0;"></div>
    <script>
        function waitForApexChart(callback) {
            if (typeof ApexCharts !== 'undefined') {
                callback();
            } else {
                setTimeout(() => waitForApexChart(callback), 50);
            }
        }

        waitForApexChart(() => {
            const options = {
                chart: {
                    type: 'line',
                    height: 350,
                    zoom: {
                        enabled: true,
                        autoScaleYaxis: true
                    },
                    animations: {
                        enabled: true,
                        easing: 'linear',
                        speed: 150,
                        animateGradually: {
                            enabled: false
                        },
                        dynamicAnimation: {
                            enabled: false
                        }
                    },

                },
                stroke: {
                    width: 2,
                    curve: 'smooth'
                },
                series: [{
                        name: 'Up',
                        data: <?php echo json_encode($data_up); ?>,
                        color: '#00E396'
                    },
                    {
                        name: 'Down',
                        data: <?php echo json_encode($data_down); ?>,
                        color: '#FF4560'
                    }
                ],

                xaxis: {
                    type: 'datetime',
                    min: new Date("<?php echo date('Y-m-d', strtotime($report_date . ' -15 days')); ?>").getTime(),
                    max: new Date("<?php echo date('Y-m-d', strtotime($report_date . ' +15 days')); ?>").getTime()
                },

                annotations: {
                    xaxis: [{
                        x: new Date("<?php echo $report_date; ?>").getTime(),
                        borderColor: '#FF4560',
                        label: {
                            style: {
                                color: '#fff',
                                background: '#FF4560'
                            },
                            text: 'Report Date'
                        }
                    }]
                },
                colors: ['#00E396']
            };

            new ApexCharts(document.querySelector("#<?php echo esc_attr($chart_id); ?>"), options).render();
        });
    </script>
<?php
    return ob_get_clean();
});
