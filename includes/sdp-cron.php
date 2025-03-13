<?php

if (!defined('ABSPATH')) {
    exit;
}

function sdp_update_stock_data() {
    global $wpdb;
    $date = date('Y-m-d');
    // Get all tickers
    $tickers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_tickers");
    foreach ($tickers as $ticker) {
        $eod_data = sdp_fetch_eod_for_ticker($ticker->symbol);
        if (is_wp_error($eod_data)) {
            continue;
        }
        foreach ($eod_data as $data) {
            $price = [
                'ticker_id' => $ticker->id,
                'date' => $data['date'],
                'open' => $data['open'],
                'high' => $data['high'],
                'low' => $data['low'],
                'close' => $data['close'],
                'volume' => $data['volume'],
                'adj_open' => $data['adj_open'],
                'adj_high' => $data['adj_high'],
                'adj_low' => $data['adj_low'],
                'adj_close' => $data['adj_close'],
                'adj_volume' => $data['adj_volume'],
                'split_factor' => $data['split_factor'],
                'dividend' => $data['dividend'],
                'symbol' => $data['symbol'],
                'exchange' => $data['exchange']
            ];
            
            // Check if the data already exists
            $existing_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}stock_prices WHERE ticker_id = %d AND date = %s",
                $ticker->id, $data['date']
            ));      
            if ($existing_entry) {
                // Update the existing entry
                $wpdb->update(
                    "{$wpdb->prefix}stock_prices",
                    $price,
                    ['id' => $existing_entry->id],
                    ['%d', '%s', '%f', '%f', '%f', '%f', '%d', '%f', '%f'],
                    ['%d']
                );
            } else {
                // Insert new entry
                $wpdb->insert("{$wpdb->prefix}stock_prices", $price, ['%d', '%s', '%f', '%f', '%f', '%f', '%d', '%f', '%f']);
            }
        }
        sleep(1);
    }
}

function sdp_fetch_eod_for_ticker($ticker) {
    $api = new SDP_API_Handler();
    $eod_data = $api->fetch_latest_price($ticker);
    if (is_wp_error($eod_data)) {
        return $eod_data;
    }
    return $eod_data;
}
