<?php

if (!defined('ABSPATH')) {
    exit;
}

class SDP_Database_Handler {

    private $wpdb;
    private $tickers_table;
    private $prices_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tickers_table = "{$wpdb->prefix}stock_tickers";
        $this->prices_table = "{$wpdb->prefix}stock_prices";
    }

    // Get all tickers
    public function get_all_tickers() {
        return $this->wpdb->get_results("SELECT * FROM {$this->tickers_table} ORDER BY symbol ASC");
    }

    // Get ticker by ID
    public function get_ticker($id) {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tickers_table} WHERE id = %d", $id));
    }

    // Add new ticker
    public function add_ticker($symbol, $market) {
        return $this->wpdb->insert($this->tickers_table, [
            'symbol' => strtoupper(sanitize_text_field($symbol)),
            'market' => strtoupper(sanitize_text_field($market)),
        ], ['%s', '%s']);
    }

    // Insert or update price data
    public function upsert_price($ticker_id, $data) {
        return $this->wpdb->replace($this->prices_table, [
            'ticker_id' => $ticker_id,
            'date'      => $data['date'],
            'open'      => $data['open'],
            'high'      => $data['high'],
            'low'       => $data['low'],
            'close'     => $data['close'],
            'volume'    => $data['volume'],
            'adj_close' => $data['adj_close'],
            'dividend'  => $data['dividend'],
        ], [
            '%d', '%s', '%f', '%f', '%f', '%f', '%d', '%f', '%f'
        ]);
    }

    // Get historical prices for a ticker
    public function get_historical_prices($ticker_id, $limit = 100) {
        return $this->wpdb->get_results($this->wpdb->prepare("
            SELECT * FROM {$this->prices_table}
            WHERE ticker_id = %d
            ORDER BY date DESC
            LIMIT %d", $ticker_id, $limit));
    }

}
