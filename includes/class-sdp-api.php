<?php

if (!defined('ABSPATH')) {
    exit;
}

class SDP_API_Handler {

    private $api_key;
    private $base_url = 'http://api.marketstack.com/v1/';

    public function __construct() {
        $this->api_key = sdp_get_marketstack_api_key();
    }

    /**
     * Fetch historical data for a specific ticker
     *
     * @param string $ticker
     * @param string $date_from
     * @param string $date_to
     * @return array|WP_Error
     */
    public function fetch_historical_data($ticker, $date_from, $date_to) {
        $endpoint = 'eod';
        $params = [
            'access_key' => $this->api_key,
            'symbols' => strtoupper($ticker),
            'date_from' => $date_from,
            'date_to' => $date_to,
            'limit' => 1000
        ];

        $url = $this->base_url . $endpoint . '?' . http_build_query($params);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', 'Marketstack API returned status code ' . $status_code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', 'Marketstack API error: ' . $body['error']['message']);
        }

        return $body['data'];
    }

    /**
     * Fetch latest stock price for a specific ticker
     * @param string $ticker
     * 
     * @return array|WP_Error
     */
    public function fetch_latest_price($ticker) {
        $endpoint = 'tickers/' . strtoupper($ticker) . '/eod/latest';
        $params = [
            'access_key' => $this->api_key,
        ];

        $url = $this->base_url . $endpoint . '?' . http_build_query($params);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', 'Marketstack API returned status code ' . $status_code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', 'Marketstack API error: ' . $body['error']['message']);
        }

        return $body['data'];
    }

    /**
     * Validate if the API key is set
     *
     * @return bool
     */
    public function has_valid_api_key() {
        return !empty($this->api_key);
    }

    /**
     * Validate if the API key is working
     *
     * @return bool
     */
    public function is_api_key_valid($api_key) {
        $ticker = 'AAPL';
        $endpoint = 'tickers/' . strtoupper($ticker) . '/eod/latest';
        $params = [
            'access_key' => $api_key,
        ];

        $url = $this->base_url . $endpoint . '?' . http_build_query($params);

        $response = wp_remote_get($url);
        return !is_wp_error($response);
    }
}
