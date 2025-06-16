<?php

if (!defined('ABSPATH')) {
    exit;
}

function sdp_get_close_price_by_date($symbol, $date)
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_prices'; // adjust if different
    $result = $wpdb->get_var(
        $wpdb->prepare("SELECT close FROM $table WHERE symbol = %s AND date = %s", $symbol, $date)
    );

    if ($result === null) {
        // If null, grab the closest, earlier date
        $result = $wpdb->get_var(
            $wpdb->prepare("SELECT close FROM $table WHERE symbol = %s AND date < %s ORDER BY date DESC LIMIT 1", $symbol, $date)
        );
    }
    if ($result === null) {
        // If still null, grab the closest, later date
        $result = $wpdb->get_var(
            $wpdb->prepare("SELECT close FROM $table WHERE symbol = %s AND date > %s ORDER BY date ASC LIMIT 1", $symbol, $date)
        );
    }

    return $result !== null ? floatval($result) : null;
}

function sdp_get_latest_close_price($symbol)
{
    global $wpdb;

    $table = $wpdb->prefix . 'stock_prices';
    $result = $wpdb->get_var(
        $wpdb->prepare("SELECT close FROM $table WHERE symbol = %s ORDER BY date DESC LIMIT 1", $symbol)
    );

    return $result !== null ? floatval($result) : null;
}

// Check if record already exists
function sdp_update_existing_record($ticker_id, $stock_day, $date)
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
function sdp_new_record($stock_day, $ticker_id, $date)
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