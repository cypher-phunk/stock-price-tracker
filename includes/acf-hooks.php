<?php

// includes/acf-hooks.php

add_action('wp_ajax_search_tickers', 'search_tickers_callback');
function search_tickers_callback() {
    global $wpdb;
    $search = sanitize_text_field($_GET['q']);
    
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT symbol, name FROM {$wpdb->prefix}market_tickers 
             WHERE symbol LIKE %s OR name LIKE %s 
             LIMIT 20",
            $search . '%', $search . '%'
        )
    );

    wp_send_json($results);
}

add_filter('acf/load_field/name=ticker', 'load_shortlist_tickers');
function load_shortlist_tickers($field) {
    global $wpdb;

    $table = $wpdb->prefix . 'stock_tickers';

    // Pull 500 or fewer curated tickers to avoid performance issues
    $results = $wpdb->get_results("SELECT DISTINCT symbol FROM $table ORDER BY symbol ASC LIMIT 1000");

    $field['choices'] = [];

    if (!empty($results)) {
        foreach ($results as $row) {
            $field['choices'][$row->symbol] = $row->symbol;
        }
    }

    return $field;
}

