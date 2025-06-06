<?php

if (!defined('ABSPATH')) {
    exit;
}

function sdp_cron_log($message) {
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/cron.log';

    // Create the logs directory if it doesn't exist
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true); // true = recursive
    }

    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

function sdp_update_stock_data()
{
    global $wpdb;
    $date = date('Y-m-d');
    // Get all tickers
    $tickers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_tickers");
    foreach ($tickers as $ticker) {
        $eod_data = sdp_fetch_eod_for_ticker($ticker->symbol);
        if (is_wp_error($eod_data)) {
            continue;
        }
        $date = date('Y-m-d', strtotime($eod_data['date']));

        $existing_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d AND date = %s",
                $ticker->id,
                $date
            )
        );

        if (!$existing_record || !update_existing_record($ticker->id, $eod_data, $date)) {
            new_record($eod_data, $ticker->id, $date);
        }
        sleep(.2);
    }
}

function sdp_fetch_eod_for_ticker($ticker)
{
    $api = new SDP_API_Handler();
    $eod_data = $api->fetch_latest_price($ticker);
    if (is_wp_error($eod_data)) {
        return $eod_data;
    }
    return $eod_data;
}

function sdp_update_stock_metrics() {
    global $wpdb;
    $prices_table = "{$wpdb->prefix}stock_prices";
    $metrics_table = "{$wpdb->prefix}stock_metrics";

    $ticker_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}stock_tickers");

    foreach ($ticker_ids as $ticker_id) {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT date, close FROM $prices_table
            WHERE ticker_id = %d
            ORDER BY date DESC
            LIMIT 2
        ", $ticker_id));

        if (count($rows) === 2) {
            $latest = $rows[0];
            $previous = $rows[1];

            if ($latest->date === $previous->date) {
                continue; // Skip if the dates are the same
            }
            elseif (!$latest->close || !$previous->close || $previous->close == 0) {
                continue; // Skip if the prices are the same
            }
            $percent_change = (($latest->close - $previous->close) / $previous->close) * 100;

            $wpdb->replace($metrics_table, [
                'ticker_id'       => $ticker_id,
                'latest_date'     => $latest->date,
                'latest_close'    => $latest->close,
                'previous_close'  => $previous->close,
                'percent_change'  => round($percent_change, 2),
                'updated_at'      => current_time('mysql'),
            ]);
        }
    }
}
