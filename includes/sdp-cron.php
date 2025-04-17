<?php

if (!defined('ABSPATH')) {
    exit;
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
