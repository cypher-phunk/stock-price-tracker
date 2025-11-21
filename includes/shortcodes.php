<?php

add_action('init', 'register_stock_charts_scripts');

function register_stock_charts_scripts()
{
    wp_register_script(
        'ag-chart',
        'https://cdn.jsdelivr.net/npm/ag-charts-community/dist/umd/ag-charts-community.js',
        [],
        null,
        true
    );

    wp_register_script(
        'ag-chart-enterprise',
        'https://cdn.jsdelivr.net/npm/ag-charts-enterprise@12.0.0/dist/umd/ag-charts-enterprise.min.js',
        [],
        null,
        true
    );

    wp_register_script(
        'stock-chart',
        plugin_dir_url(__DIR__) . 'assets/js/stock-chart.js',
        ['ag-chart'],
        100,
        true
    );

}

function sdp_register_shortcodes()
{
    add_shortcode('report_stock_chart', 'sdp_render_report_stock_chart');
    add_shortcode('stock_chart', 'sdp_render_stock_chart');
}

function sdp_render_report_stock_chart($atts)
{
    wp_enqueue_script('ag-chart');
    wp_enqueue_script('ag-chart-enterprise');
    wp_enqueue_script('stock-chart');
    $post_id = get_the_ID();
    $report_date_raw = get_field('report_date', $post_id);
    if (!$report_date_raw)
        return '<p>Missing report date.</p>';

    $report_date = date('Y-m-d', strtotime($report_date_raw . ' +1 day'));
    $day_before_report = date('Y-m-d', strtotime($report_date . ' -1 day'));
    $stock_post = get_field('symbol', $post_id);
    if (!($stock_post))
        return '<p>Missing stock.</p>';

    $ticker_symbol = get_field('ticker_symbol', $stock_post[0]->ID);
    if (!$ticker_symbol)
        return '<p>Missing ticker symbol.</p>';

    global $wpdb;
    $ticker_id = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s LIMIT 1", $ticker_symbol)
    );
    if (!$ticker_id)
        return '<p>Invalid ticker symbol.</p>';

    $query = $wpdb->prepare(
        "SELECT date, close FROM {$wpdb->prefix}stock_prices
        WHERE ticker_id = %d
        AND date BETWEEN %s AND %s
        ORDER BY date ASC",
        $ticker_id,
        date('Y-m-d', strtotime($report_date . ' -15 days')),
        date('Y-m-d', strtotime($report_date . ' +15 days')),
    );
    $results = $wpdb->get_results($query, ARRAY_A);
    $data = array_map(static function ($r) {
        return [
            'date' => $r['date'],
            'price' => floatval($r['close']),
        ];
    }, $results);
    $report_day_price = floatval($results[array_search($report_date, array_column($results, 'date'))]['close']);
    // Localize data and API
    $ag_api_manager = new SDP_AG_API_Manager();
    $api_key = $ag_api_manager->get_api_key();
    echo '<div id="stock-chart" style="min-height:350px;width:100%;margin:1em 0;"></div>';
    wp_localize_script('stock-chart', 'sdpStockData', $data);
    wp_localize_script('stock-chart', 'sdpAgChartKey', $api_key);
    wp_localize_script('stock-chart', 'sdpReportDate', $report_date);
    wp_localize_script('stock-chart', 'sdpReportDayPrice', $report_day_price);
    wp_localize_script('stock-chart', 'sdpDayBeforeReport', $day_before_report);
}

function sdp_render_report_stock_chart_archive($atts)
{
    $post_id = get_the_ID();
    $report_date_raw = get_field('report_date', $post_id);
    if (!$report_date_raw)
        return '<p>Missing report date.</p>';

    $report_date = date('Y-m-d', strtotime($report_date_raw));
    $stock_post = get_field('symbol', $post_id);
    if (!($stock_post))
        return '<p>Missing stock.</p>';

    $ticker_symbol = get_field('ticker_symbol', $stock_post[0]->ID);
    if (!$ticker_symbol)
        return '<p>Missing ticker symbol.</p>';

    global $wpdb;
    $ticker_id = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM {$wpdb->prefix}stock_tickers WHERE symbol = %s LIMIT 1", $ticker_symbol)
    );
    if (!$ticker_id)
        return '<p>Invalid ticker symbol.</p>';

    $query = $wpdb->prepare(
        "SELECT date, close FROM {$wpdb->prefix}stock_prices
       WHERE ticker_id = %d
       ORDER BY date ASC",
        $ticker_id
    );
    $results = $wpdb->get_results($query);
    if (!$results)
        return '<p>No price data found.</p>';

    // Prep Piecewise Data
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
}

// Chart for Single Stock Template
// Different because no report date

function sdp_render_stock_chart($atts)
{
    wp_enqueue_script('ag-chart');
    wp_enqueue_script('ag-chart-enterprise');
    wp_enqueue_script('stock-chart');

    // return if no /stock/ in url
    if (strpos($_SERVER['REQUEST_URI'], '/stock/') === false) {
        return;
    }

    global $wpdb;


    $atts = shortcode_atts([
        'symbol' => '',
    ], $atts);

    $symbol = strtoupper(trim($atts['symbol']));
    if (!$symbol)
        return 'No stock symbol provided.';

    // Get ticker_id from symbol
    $ticker_id = $wpdb->get_var($wpdb->prepare("
        SELECT id FROM {$wpdb->prefix}stock_tickers
        WHERE symbol = %s
        LIMIT 1
    ", $symbol));

    if (!$ticker_id)
        return 'Invalid stock symbol.';

    // Fetch metrics
    // TODO Implement in the new function
    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT latest_close, percent_change FROM {$wpdb->prefix}stock_metrics
        WHERE ticker_id = %d
    ", $ticker_id));

    // Fetch full chart data
    $query = $wpdb->prepare(
        "SELECT date, close FROM {$wpdb->prefix}stock_prices
         WHERE ticker_id = %d
         ORDER BY date ASC",
        $ticker_id
    );
    $results = $wpdb->get_results($query, ARRAY_A);
    $data = array_map(static function ($r) {
        return [
            'date' => $r['date'],
            'price' => floatval($r['close']),
        ];
    }, $results);
    if (!$results || !$data)
        return '<p>No price data/results found.</p>';

    echo '<div id="stock-chart" style="min-height:350px;width:100%;margin:1em 0;"></div>';
    wp_localize_script('stock-chart', 'sdpStockData', $data);
    $ag_api_manager = new SDP_AG_API_Manager();
    wp_localize_script('stock-chart', 'sdpAgChartKey', $ag_api_manager->get_api_key());
}

function sdp_render_stock_chart_archive($atts)
{
    global $wpdb;

    $atts = shortcode_atts([
        'symbol' => '',
    ], $atts);

    $symbol = strtoupper(trim($atts['symbol']));
    if (!$symbol)
        return 'No stock symbol provided.';

    // Get ticker_id from symbol
    $ticker_id = $wpdb->get_var($wpdb->prepare("
        SELECT id FROM {$wpdb->prefix}stock_tickers
        WHERE symbol = %s
        LIMIT 1
    ", $symbol));

    if (!$ticker_id)
        return 'Invalid stock symbol.';

    // Fetch metrics
    // TODO Implement in the new function
    $metrics = $wpdb->get_row($wpdb->prepare("
        SELECT latest_close, percent_change FROM {$wpdb->prefix}stock_metrics
        WHERE ticker_id = %d
    ", $ticker_id));

    // Fetch full chart data
    $query = $wpdb->prepare(
        "SELECT date, close FROM {$wpdb->prefix}stock_prices
         WHERE ticker_id = %d
         ORDER BY date ASC",
        $ticker_id
    );
    $results = $wpdb->get_results($query);
    if (!$results)
        return '<p>No price data found.</p>';

    $data_up = [];
    $data_down = [];

    for ($i = 0; $i < count($results) - 1; $i++) {
        $curr = $results[$i];
        $next = $results[$i + 1];

        $curr_point = ['x' => $curr->date, 'y' => (float) $curr->close];
        $next_point = ['x' => $next->date, 'y' => (float) $next->close];

        if ($next->close >= $curr->close) {
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


    $chart_id = 'stockChart_' . $ticker_id;

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
                    min: new Date("<?php echo date('Y-m-d', strtotime('-30 days')); ?>").getTime(),
                    max: new Date("<?php echo date('Y-m-d'); ?>").getTime()
                },
                colors: ['#00E396']
            };

            new ApexCharts(document.querySelector("#<?php echo esc_attr($chart_id); ?>"), options).render();
        });
    </script>
<?php
    return ob_get_clean();
}
