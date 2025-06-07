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

