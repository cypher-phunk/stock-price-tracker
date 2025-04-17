<?php
/**
 * Admin Settings Page for Stock Data Plugin
 *
 * This file contains the HTML and PHP code for the admin settings
 * page of the Stock Data Plugin.
 * It includes forms for API key input, ticker management,
 * and historical data viewing.
 *
 * @category Admin
 * @package  StockDataPlugin
 * @author   RoDojo Web Development <support@rodojo.dev>
 * @license  MIT https://opensource.org/licenses/MIT
 * @version  GIT: <git_id>
 * @php      version 7.4
 * @link     https://rodojo.dev/
 */
?>

<?php
error_log('Admin page loaded');

if (isset($_GET['test_xdebug'])) {
    error_log('Xdebug trigger block hit');
    // Set your breakpoint here
    $x = 1 + 1; // <- Break here
    echo '<div style="padding: 10px; background: #efe; border: 1px solid #ccc;">Xdebug test triggered!</div>';
}
?>

<script src="<?php echo plugin_dir_url(__FILE__) . '../assets/js/search.js'; ?>"></script>
<script src="<?php echo plugin_dir_url(__FILE__) . '../assets/js/ticker-posts.js'; ?>"></script>
<div class="wrap">
    <h1>Stock Data Plugin Settings</h1>

    <form method="post" action="">
        <?php
        settings_fields('sdp_settings_group');
        do_settings_sections('sdp_settings_group');
        $api_key = esc_attr(get_option('sdp_marketstack_api_key'));
        ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row">Marketstack API Key</th>
                <td>
                    <input type="text" name="sdp_marketstack_api_key" value="<?php echo $api_key; ?>" size="50" required />
                    <p class="description">Your API key from <a href="https://marketstack.com/" target="_blank">Marketstack</a>. Keep this key secure.</p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <?php
    // When saving API key
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['sdp_marketstack_api_key'])) {
            $new_api_key = sanitize_text_field($_POST['sdp_marketstack_api_key']);
            // Validate the API key
            if (sdp_validate_api_key($new_api_key)) {
                sdp_save_marketstack_api_key($new_api_key);
                add_settings_error('sdp_messages', 'sdp_message', 'API Key saved successfully', 'updated');
                echo '<div class="updated"><p>API Updated Successfully!</p></div>';
            } else {
                add_settings_error('sdp_messages', 'sdp_message', 'Invalid API Key', 'error');
            }
        }
    }
    ?>

    <!-- Search on Internal Stock DB -->
    <h2>Live Stock Symbol Search</h2>
    <input type="text" id="ticker-search" placeholder="Search stock symbol..." />
    <div id="results"></div>

    <!-- Refresh Tickers Database -->
    <h2>Refresh Internal Tickers Database V1</h2>
    <form method="post">
        <?php wp_nonce_field('refresh_tickers_action', 'refresh_tickers_nonce'); ?>
        <p>Click the button below to refresh the internal tickers database.</p>
        <p>Caution: This will cost about 50 API Queries. Only need to run when your ticker is not here, but is on MarketStack</p>
        <?php submit_button('Refresh Tickers Database'); ?>
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_tickers_nonce'])) {
        if (wp_verify_nonce($_POST['refresh_tickers_nonce'], 'refresh_tickers_action')) {
            // Delete existing cached tickers
            delete_transient('sdp_marketstack_tickers');

            global $wpdb;

            $api_handler = new SDP_API_Handler();
            $tickers = $api_handler->fetch_marketstack_tickers();
          
            // Check for errors
            if (is_wp_error($tickers)) {
                echo '<div class="error"><p>Error refreshing tickers: ' .
                    esc_html($tickers->get_error_message()) . '</p></div>';
                return;
            }
            // Validate tickers
            if (empty($tickers)) {
                echo '<div class="error"><p>No tickers were retrieved from Marketstack.</p></div>';
                return;
            }

            // Clear existing tickers in db
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}market_tickers");

            // Insert new tickers
            $insert_count = 0;
            foreach ($tickers as $ticker) {
                // Use 'ticker' instead of 'symbol'
                if (isset($ticker['ticker'])) {
                    $wpdb->insert("{$wpdb->prefix}market_tickers", [
                        'symbol' => $ticker['ticker'],
                    ]);
                    $insert_count++;
                }
            }

            echo '<div class="updated"><p>Tickers refreshed successfully! Inserted ' .
                esc_html($insert_count) . ' tickers.</p></div>';
        }
    }
    ?>
  
    <h3>Check & Add Tracked Tickers</h3>
    <textarea id="bulk-tickers" rows="3" placeholder="Enter symbols like: AAPL,TSLA,AI"></textarea>
    <br>
    <button id="check-tickers" class="button button-primary">Check Tickers</button>

    <div id="check-results"></div>

    <h3>Currently Tracked Tickers</h3>
    <table id="tracked-ticker-table">
        <thead>
            <tr>
                <th>Symbol</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <div id="pagination-controls"></div>

    <div>
        <!-- Add all ticker posts here -->
        <button id="add-ticker-posts" class="button button-primary">***DANGER: Add Ticker Posts***</button>
    </div>

    <h2>Manage Tickers</h2>

    <form method="post">
        <?php wp_nonce_field('test_cron_action', 'test_cron_nonce'); ?>
        <p>Click the button below to test the cron job.</p>
        <?php submit_button('Test Cron Job'); ?>
    </form>
    <?php
    // Handle Test Cron Job
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_cron_nonce'])) {
        if (wp_verify_nonce($_POST['test_cron_nonce'], 'test_cron_action')) {
            if (function_exists('sdp_update_stock_data')) {
                error_log('Calling sdp_update_stock_data');
                sdp_update_stock_data(); // <- Put a breakpoint inside this function
            } else {
                error_log('Function not found: sdp_update_stock_data');
            }
        } else {
            error_log('Nonce verification failed');
        }
    }
    ?>
    <form method="post">
        <?php wp_nonce_field('fetch_missing_days_action', 'fetch_missing_days_nonce'); ?>
        <p>Please select the date range to fetch missing days.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Date From</th>
                <td>
                    <input type="date" name="date_from" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required>
                </td>
            </tr>
            <tr>
                <th scope="row">Date To</th>
                <td>
                    <input type="date" name="date_to" value="<?php echo date('Y-m-d'); ?>" required>
                </td>
            </tr>
        </table>
        <p>Click the button below to fetch missing days.</p>
        <?php submit_button('Fetch Missing Days'); ?>
    </form>
    <?php
    // Handle Fetch Missing Days
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_missing_days_nonce'])) {
        if (wp_verify_nonce($_POST['fetch_missing_days_nonce'], 'fetch_missing_days_action')) {
            $date_from = sanitize_text_field($_POST['date_from']);
            $date_to = sanitize_text_field($_POST['date_to']);

            // Fetch missing days logic here
            $date_from = isset($_POST['date_from']) ? date('Y-m-d', strtotime($_POST['date_from'])) : date('Y-m-d', strtotime('-999 days'));
            $date_to   = isset($_POST['date_to']) ? date('Y-m-d', strtotime($_POST['date_to'])) : date('Y-m-d');

            global $wpdb;
            $tickers = $wpdb->get_results("SELECT id, symbol FROM {$wpdb->prefix}stock_tickers");

            if (empty($tickers)) {
                echo '<div class="error"><p>No tracked tickers found.</p></div>';
            } else {
                $api_handler = new SDP_API_Handler();

                foreach ($tickers as $ticker) {
                    $data = $api_handler->fetch_historical_data($ticker->symbol, $date_from, $date_to);

                    if (is_wp_error($data)) {
                        error_log("Error for {$ticker->symbol}: " . $data->get_error_message());
                        continue;
                    }

                    foreach ($data as $stock_day) {
                        $date = date('Y-m-d', strtotime($stock_day['date']));

                        $existing_record = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d AND date = %s",
                                $ticker->id,
                                $date
                            )
                        );

                        if (!$existing_record || !update_existing_record($ticker->id, $stock_day, $date)) {
                            new_record($stock_day, $ticker->id, $date);
                        }

                        error_log("Saved {$ticker->symbol} - $date");
                    }
                    sleep(.2);
                }
                // For example, you can call the API to fetch data for the specified date range
                error_log("Fetching missing days from $date_from to $date_to");
            }
    }
}
    ?>

    <form method="post">
        <?php wp_nonce_field('add_ticker_action', 'add_ticker_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">Ticker Symbols</th>
                <td>
                    <input type="text" name="ticker_symbols" required placeholder="Example: AAPL,GOOG,MSFT">
                    <p class="description">Enter one or more ticker symbols separated by commas.</p>
                </td>
            </tr>
        </table>

        <?php submit_button('Add Tickers');

        ?>
    </form>

    <h3>Current Tickers</h3>

    <?php
    global $wpdb;

    // pagination
    $items_per_page = 20;
    $current_page = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
    $offset = ($current_page - 1) * $items_per_page;

    // total pages
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_tickers");
    $total_pages = ceil($total_items / $items_per_page);

    // Fetch paginated results without search query
    $tickers = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_tickers 
            ORDER BY symbol ASC 
            LIMIT %d OFFSET %d",
            $items_per_page,
            $offset
        )
    );

    if ($tickers):
    ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Symbol</th>
                    <th>Added On</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickers as $ticker): ?>
                    <tr>
                        <td><?php echo esc_html($ticker->id); ?></td>
                        <td><?php echo esc_html($ticker->symbol); ?></td>
                        <td><?php echo esc_html($ticker->created_at); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination Links -->
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('« Prev'),
                    'next_text' => __('Next »'),
                    'total' => $total_pages,
                    'current' => $current_page
                ]);
                ?>
            </div>
        </div>


    <?php else: ?>
        <p>No tickers found. Please add some!</p>
    <?php endif; ?>
</div>

<!-- View the Historical Data -->

<h2>View Historical Data</h2>

<form method="get" action="">
    <table class="form-table">
        <tr>
            <th scope="row">Select Ticker</th>
            <td>
                <select name="view_ticker_id" id="ticker-select">
                    <option value="">-- Select Ticker --</option>
                    <?php
                    global $wpdb;
                    $tickers = $wpdb->get_results("SELECT id, symbol FROM {$wpdb->prefix}stock_tickers ORDER BY symbol ASC");
                    $selected_ticker_id = isset($_GET['view_ticker_id']) ? intval($_GET['view_ticker_id']) : '';

                    foreach ($tickers as $ticker):
                        printf(
                            '<option value="%d" %s>%s</option>',
                            esc_attr($ticker->id),
                            selected($selected_ticker_id, $ticker->id, false),
                            esc_html($ticker->symbol)
                        );
                    endforeach;
                    ?>
                </select>
            </td>
        </tr>
    </table>
</form>

<script>
    document.getElementById("ticker-select").addEventListener("change", function() {
        const selectedValue = this.value;
        if (!selectedValue) return;

        const url = new URL(window.location.href);
        url.searchParams.set("view_ticker_id", selectedValue); // Update or add view_ticker_id

        window.location.href = url.toString(); // Reload page with updated parameters
    });
</script>

<?php
// Display historical data if view_ticker_id is set
if (!empty($_GET['view_ticker_id'])):
    echo '<h3>Historical Data</h3>';
    $ticker_id = intval($_GET['view_ticker_id']);
    $prices = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d ORDER BY date DESC",
        $ticker_id
    ));
else:
    echo '<p>Select a ticker to view historical data.</p>';
    return;
endif;
?>

<table class="widefat striped">
    <thead>
        <tr>
            <th>Date</th>
            <th>Open</th>
            <th>High</th>
            <th>Low</th>
            <th>Close</th>
            <th>Adj Close</th>
            <th>Volume</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($prices as $price): ?>
            <tr>
                <td><?php echo esc_html($price->date); ?></td>
                <td><?php echo esc_html($price->open); ?></td>
                <td><?php echo esc_html($price->high); ?></td>
                <td><?php echo esc_html($price->low); ?></td>
                <td><?php echo esc_html($price->close); ?></td>
                <td><?php echo esc_html($price->adj_close); ?></td>
                <td><?php echo esc_html(number_format($price->volume)); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="tablenav">
    <div class="tablenav-pages">
        <?php
        echo paginate_links([
            'base' => add_query_arg(['view_ticker_id' => $ticker_id, 'prices_paged' => '%#%']),
            'format' => '',
            'prev_text' => __('« Prev'),
            'next_text' => __('Next »'),
            'total' => $total_pages,
            'current' => $current_page
        ]);
        ?>
    </div>
</div>


<!-- Add Historical Pulling -->
<h2>Manual Historical Data Pull</h2>
<form method="post">
    <?php wp_nonce_field('manual_pull_action', 'manual_pull_nonce'); ?>

    <table class="form-table">
        <tr>
            <th scope="row">Select Ticker</th>
            <td>
                <select name="manual_ticker_id" required>
                    <option value="">-- Select Ticker --</option>
                    <?php
                    global $wpdb;
                    $tickers = $wpdb->get_results("SELECT id, symbol FROM {$wpdb->prefix}stock_tickers ORDER BY symbol ASC");
                    foreach ($tickers as $ticker):
                        echo '<option value="' . esc_attr($ticker->id) . '">' . esc_html($ticker->symbol) . '</option>';
                    endforeach;
                    ?>
                </select>
            </td>
        </tr>
    </table>

    <?php submit_button('Fetch Historical Data'); ?>
</form>

<form method="post">
    <?php wp_nonce_field('bulk_pull_action', 'bulk_pull_nonce'); ?>
    <input type="submit" name="bulk_pull_submit" class="button button-primary" value="Bulk Pull All Ticker History">
</form>

<?php

// Handlers


// handle new tickers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ticker_nonce'])) {
    if (wp_verify_nonce($_POST['add_ticker_nonce'], 'add_ticker_action')) {

        $symbols_input = sanitize_text_field($_POST['ticker_symbols']);
        $symbols_array = explode(',', $symbols_input);

        foreach ($symbols_array = array_filter(array_map('trim', $symbols_input ? explode(',', $symbols_input) : [])) as $symbol) {
            $symbol = strtoupper($symbol);

            // Check if ticker already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s",
                $symbol
            ));

            // if doesn't exist, check if it's a valid symbol
            // in our market_tickers db
            if (!$exists) {
                $market_ticker = $wpdb->get_var($wpdb->prepare(
                    "SELECT symbol FROM {$wpdb->prefix}market_tickers WHERE symbol = %s",
                    $symbol
                ));

                if (!$market_ticker) {
                    echo '<div class="error"><p>Invalid ticker symbol: ' . esc_html($symbol) . '</p></div>';
                    suggest_marketstack_tickers();
                    continue;
                }
            }

            if (!$exists) {
                $wpdb->insert("{$wpdb->prefix}stock_tickers", [
                    'symbol' => $symbol,
                ]);
            }
        }

        echo '<div class="updated"><p>Tickers added successfully!</p></div>';
        echo '<script>location.reload();</script>';
        exit;
    }
}

// handle manual data pull
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_pull_nonce'])) {
    if (wp_verify_nonce($_POST['manual_pull_nonce'], 'manual_pull_action')) {
        $ticker_id = intval($_POST['manual_ticker_id']);
        $date_from = isset($_POST['date_from']) ? date('Y-m-d', strtotime($_POST['date_from'])) : date('Y-m-d', strtotime('-999 days'));
        $date_to   = isset($_POST['date_to']) ? date('Y-m-d', strtotime($_POST['date_to'])) : date('Y-m-d');

        $ticker_symbol = $wpdb->get_var($wpdb->prepare("SELECT symbol FROM {$wpdb->prefix}stock_tickers WHERE id = %d", $ticker_id));

        $api_handler = new SDP_API_Handler();
        $data = $api_handler->fetch_historical_data($ticker_symbol, $date_from, $date_to);

        if (is_wp_error($data)) {
            echo '<div class="error"><p>Error: ' . esc_html($data->get_error_message()) . '</p></div>';
        } else {
            foreach ($data as $stock_day) {
                // log to console successful days 
                error_log('Successfully processed date: ' . date('Y-m-d', strtotime($stock_day['date'])));
                $date = date('Y-m-d', strtotime($stock_day['date']));

                // Check if the record already exists
                $existing_record = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d AND date = %s",
                        $ticker_id,
                        $date
                    )
                );
                if (!$existing_record || !update_existing_record($ticker_id, $stock_day, $date)) {
                    new_record($stock_day, $ticker_id, $date);
                }
            }
            echo '<div class="updated"><p>Historical data successfully fetched and saved.</p></div>';
        }
    }
}


// handle bulk data pull
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_pull_nonce'])) {
    if (wp_verify_nonce($_POST['bulk_pull_nonce'], 'bulk_pull_action')) {
        $date_from = isset($_POST['date_from']) ? date('Y-m-d', strtotime($_POST['date_from'])) : date('Y-m-d', strtotime('-999 days'));
        $date_to   = isset($_POST['date_to']) ? date('Y-m-d', strtotime($_POST['date_to'])) : date('Y-m-d');

        $tickers = $wpdb->get_results("SELECT id, symbol FROM {$wpdb->prefix}stock_tickers");

        if (empty($tickers)) {
            echo '<div class="error"><p>No tracked tickers found.</p></div>';
        } else {
            $api_handler = new SDP_API_Handler();

            foreach ($tickers as $ticker) {
                $data = $api_handler->fetch_historical_data($ticker->symbol, $date_from, $date_to);

                if (is_wp_error($data)) {
                    error_log("Error for {$ticker->symbol}: " . $data->get_error_message());
                    continue;
                }

                foreach ($data as $stock_day) {
                    $date = date('Y-m-d', strtotime($stock_day['date']));

                    $existing_record = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d AND date = %s",
                            $ticker->id,
                            $date
                        )
                    );

                    if (!$existing_record || !update_existing_record($ticker->id, $stock_day, $date)) {
                        new_record($stock_day, $ticker->id, $date);
                    }

                    error_log("Saved {$ticker->symbol} - $date");
                }
            }

            echo '<div class="updated"><p>Bulk historical data fetch completed successfully.</p></div>';
        }
    }
}

// Functions

// Suggest tickers from levenstein distance from market_tickers
function suggest_marketstack_tickers()
{
    global $wpdb;
    $market_tickers = $wpdb->get_results("SELECT symbol FROM {$wpdb->prefix}market_tickers");

    $suggested_tickers = [];
    $symbols_input = sanitize_text_field($_POST['ticker_symbols']);
    $symbols_array = array_filter(array_map('trim', $symbols_input ? explode(',', $symbols_input) : []));

    foreach ($symbols_array as $symbol) {
        $symbol = strtoupper($symbol);
        $suggested_ticker = null;
        $min_distance = 100;

        foreach ($market_tickers as $market_ticker) {
            $distance = levenshtein($symbol, $market_ticker->symbol);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $suggested_ticker = $market_ticker->symbol;
            }
        }

        if ($suggested_ticker) {
            $suggested_tickers[] = $suggested_ticker;
        }
    }

    if ($suggested_tickers) {
        echo '<p>Suggested tickers: ' . implode(', ', $suggested_tickers) . '</p>';
    } else {
        echo '<p>No suggested tickers found.</p>';
        echo '<p>Check the <a href="https://marketstack.com/search" target="_blank">Marketstack</a> website for valid ticker symbols.</p>';
    }
}

// Check if record already exists
function update_existing_record($ticker_id, $stock_day, $date)
{
    global $wpdb;
    // Update existing record
    try {
        $wpdb->update(
            "{$wpdb->prefix}stock_prices",
            [
                'open' => $stock_day['open'],
                'high' => $stock_day['high'],
                'low' => $stock_day['low'],
                'close' => $stock_day['close'],
                'volume' => $stock_day['volume'],
                'adj_open' => $stock_day['adj_open'],
                'adj_high' => $stock_day['adj_high'],
                'adj_low' => $stock_day['adj_low'],
                'adj_close' => $stock_day['adj_close'],
                'adj_volume' => $stock_day['adj_volume'],
                'split_factor' => $stock_day['split_factor'],
                'dividend' => $stock_day['dividend'],
                'symbol' => $stock_day['symbol'],
                'exchange' => $stock_day['exchange'],
            ],
            [
                'ticker_id' => $ticker_id,
                'date' => $date,
            ],
            [
                '%f',
                '%f',
                '%f',
                '%f',
                '%d',
                '%f',
                '%f',
                '%f',
                '%f',
                '%d',
                '%f',
                '%f',
                '%s',
                '%s'
            ],
            [
                '%d',
                '%s'
            ]
        );
        return True;
    } catch (Exception $e) {
        error_log('Stock Data Plugin Error: ' . $e->getMessage());
        return False;
    }
}

// creates a new record
function new_record($stock_day, $ticker_id, $date)
{
    global $wpdb;
    $wpdb->insert(
        "{$wpdb->prefix}stock_prices",
        [
            'ticker_id' => $ticker_id,
            'date' => $date,
            'open' => $stock_day['open'],
            'high' => $stock_day['high'],
            'low' => $stock_day['low'],
            'close' => $stock_day['close'],
            'volume' => $stock_day['volume'],
            'adj_open' => $stock_day['adj_open'],
            'adj_high' => $stock_day['adj_high'],
            'adj_low' => $stock_day['adj_low'],
            'adj_close' => $stock_day['adj_close'],
            'adj_volume' => $stock_day['adj_volume'],
            'split_factor' => $stock_day['split_factor'],
            'dividend' => $stock_day['dividend'],
            'symbol' => $stock_day['symbol'],
            'exchange' => $stock_day['exchange'],
        ],
        [
            '%d',
            '%s',
            '%f',
            '%f',
            '%f',
            '%f',
            '%d',
            '%f',
            '%f',
            '%f',
            '%f',
            '%d',
            '%f',
            '%s',
            '%s'
        ]
    );
}
