<?php

if (!defined('ABSPATH')) {
    exit;
}

class SDP_API_Handler {

    private $api_key;
    private $base_url = 'https://api.marketstack.com/v2/';

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

    public function fetch_all_historical_data($ticker_id) {
        // get ticker symbol from the database
        global $wpdb;
        $ticker = $wpdb->get_var($wpdb->prepare("SELECT symbol FROM {$wpdb->prefix}stock_tickers WHERE id = %d", $ticker_id));
        if (is_wp_error($ticker) || empty($ticker)) {
            return new WP_Error('api_error', 'Invalid ticker ID or ticker not found');
        }
        $endpoint = 'eod';
        $params = [
            'access_key' => $this->api_key,
            'symbols' => strtoupper($ticker),
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

        // loop until we get all eods based on total count
        $all_data = [];
        $total_count = 2000;
        // $total = $body['pagination']['total'];
        // API not working right now, so we will use a fixed total count
        $current_count = count($body['data']);
        $all_data = array_merge($all_data, $body['data']);
        $offset = 0;
        while ($current_count < $total_count) {
            $offset += 1000; // Increment offset by the limit
            $params['offset'] = $offset;
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

            $all_data = array_merge($all_data, $body['data']);
            $current_count += count($body['data']);
        }
        // Return all data
        if (empty($all_data)) {
            return new WP_Error('api_error', 'No historical data found for ticker: ' . $ticker);
        }

        return $all_data;
    }
    
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

    public function fetch_marketstack_tickers() {
        // Ensure API key is present
        if (!$this->api_key) {
            error_log('No API key found in fetch_marketstack_tickers()');
            return new WP_Error('api_error', 'No API key provided');
        }
    
        // Initialize variables
        $all_tickers = [];
        $page = 1;
        $per_page = 1000; // Adjusted to match the pagination in the response
        $max_pages = 100; // Prevent infinite loops
        $has_more = true;
    
        try {
            while ($page <= $max_pages && $has_more) {
                $endpoint = 'tickerslist';
                $params = [
                    'access_key' => $this->api_key,
                    'limit' => $per_page,
                    'offset' => ($page - 1) * $per_page
                ];
    
                $url = $this->base_url . $endpoint . '?' . http_build_query($params);
    
                $response = wp_remote_get($url, [
                    'timeout' => 60,
                    'headers' => [
                        'Accept' => 'application/json'
                    ]
                ]);
    
                if (is_wp_error($response)) {
                    error_log('Marketstack Tickers Fetch Error: ' . $response->get_error_message());
                    return new WP_Error('api_error', 'Failed to fetch tickers: ' . $response->get_error_message());
                }
    
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
    
                if ($status_code !== 200) {
                    error_log('Marketstack API Error: ' . print_r($data, true));
                    return new WP_Error('api_error', 'HTTP error when fetching tickers. Status: ' . $status_code);
                }
    
                // Check if data exists and use 'data' key
                if (!isset($data['data']) || !is_array($data['data'])) {
                    error_log('No ticker data found in response');
                    break;
                }
    
                // Merge tickers, now using 'ticker' key
                $current_page_tickers = $data['data'];
                $all_tickers = array_merge($all_tickers, $current_page_tickers);
    
                // Check pagination to determine if more pages exist
                $pagination = $data['pagination'];
                $has_more = ($pagination['offset'] + $pagination['count']) < $pagination['total'];
                $page++;
    
                sleep(1); // Respect API rate limits
            }
    
            error_log('Total Tickers Fetched: ' . count($all_tickers));
    
            // Cache the results for 1 week
            set_transient('sdp_marketstack_tickers', $all_tickers, WEEK_IN_SECONDS);
    
            return $all_tickers;
    
        } catch (Exception $e) {
            error_log('Marketstack Tickers Fetch Exception: ' . $e->getMessage());
            return new WP_Error('api_error', 'Exception when fetching tickers: ' . $e->getMessage());
        }
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

        return $body;
    }

    /**
     * Validate if the API key is working
     *
     * @return bool
     */
    // Modify the validation function in class-sdp-api.php
    public function is_api_key_valid($api_key = null) {
        // If no API key provided, try to get the stored key
        if ($api_key === null) {
            $api_key = sdp_get_marketstack_api_key();
        }
    
        // Check if we have a cached validation result
        $validation_data = get_transient('sdp_marketstack_api_key_validation');
        
        // If we have a cached validation and it's recent, return the cached result
        if ($validation_data !== false) {
            return $validation_data;
        }
    
        // If no cached result, perform validation
        $ticker = 'AAPL'; // Using Apple as a standard test ticker
        $endpoint = 'eod/latest';
        $params = [
            'access_key' => $api_key,
            'symbols' => $ticker,
            'limit' => 1
        ];
    
        $url = $this->base_url . $endpoint . '?' . http_build_query($params);
    
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        // Error checking
        if (is_wp_error($response)) {
            // Cache the failure result for 1 hour
            set_transient('sdp_marketstack_api_key_validation', false, HOUR_IN_SECONDS);
            return false;
        }
    
        // Get response details
        $status_code = wp_remote_retrieve_response_code($response);
        $decoded_body = json_decode(wp_remote_retrieve_body($response), true);
    
        // Validate successful response
        $is_valid = ($status_code === 200 && 
                     isset($decoded_body['data']) && 
                     !empty($decoded_body['data']));
    
        // Cache the validation result for 1 week
        set_transient('sdp_marketstack_api_key_validation', $is_valid, WEEK_IN_SECONDS);
    
        return $is_valid;
    }

    /**
     * 
     * Fetch company information for a specific ticker
     * 
     * @param string $ticker
     * @return array|WP_Error
     * 
     */
    public function get_company_info($ticker) {
        $endpoint = 'tickerinfo';
        $params = [
            'access_key' => $this->api_key,
            'ticker' => strtoupper($ticker),
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
}
