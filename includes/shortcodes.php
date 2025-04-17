<?php

function sdp_register_shortcodes() {
  add_shortcode('report_stock_chart', 'sdp_render_report_stock_chart');
  add_shortcode('stock_chart', 'sdp_render_stock_chart');
}

function sdp_render_stock_chart($atts) {
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
}

// Chart for Single Stock Template
// Different because no report date
function sdp_render_report_stock_chart($atts) {
  $atts = shortcode_atts([
    'ticker_id' => '',
], $atts);

if (!$atts['ticker_id']) return '';

global $wpdb;
$table = "{$wpdb->prefix}stock_metrics";
$row = $wpdb->get_row(
    $wpdb->prepare("SELECT latest_close, percent_change FROM $table WHERE ticker_id = %d", $atts['ticker_id'])
);

if (!$row) return 'Stock data not available.';

return "<div class='stock-metrics'>
    <strong>Latest Close:</strong> {$row->latest_close}<br>
    <strong>Change:</strong> {$row->percent_change}%
</div>";
}
