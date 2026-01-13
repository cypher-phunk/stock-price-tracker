<?php

if (!defined('ABSPATH')) {
    exit;
}

function add_stock_ticker( $ticker ) 
{
    /**
     * Add stock ticker to stock_tickers table, grab company info, and create stock post.
     * @param string $ticker The stock ticker symbol.
     * @return void
     */

    global $wpdb;

    // Clean the ticker symbol
    $ticker = sanitize_text_field(strtoupper(trim($ticker)));

    $table_name = $wpdb->prefix . 'stock_tickers';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE symbol = %s",
        $ticker
    ));

    if (!$exists) {
        $wpdb->insert(
            $table_name,
            ['symbol' => $ticker],
            ['%s']
        );
    }
    grab_company_info($ticker);
    create_stock_post($ticker);
    create_stock_historical_data($ticker);
    sdp_update_stock_metric($ticker);
    
    // upload to postgres
    $pg_analytics = new SDP_PG();
    $pg_analytics->save_stock_to_postgres($ticker);
    unset($pg_analytics);
}