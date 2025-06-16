<?php

class SDP_TMDB_API_Handler {
    private $api_key;
    private $auth_params;

    public function __construct() {
        $this->api_key = sdp_get_tmdb_api_key();
        $this->auth_params = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'accept' => 'application/json',
        ];
    }

    public function is_api_key_valid($api_key) {
        // Simulate an API call to validate the key
        if (empty($api_key)) {
            return new WP_Error('invalid_api_key', 'API key is empty.');
        }
        $params = [
            'Authorization' => 'Bearer ' . $api_key,
            'accept' => 'application/json',
        ];
        $response = wp_remote_get("https://api.themoviedb.org/3/movie/popular?api_key={$api_key}", [
            'headers' => $params
        ]);
        if (is_wp_error($response) || $response['response']['code'] !== 200) {
            return false;
        }

        $body = json_decode($response['body'], true);
        return isset($body['results']);
    }

    public function get_movie_details($movie_id) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'API key is not set.');
        }

        $url = "https://api.themoviedb.org/3/movie/{$movie_id}";
        $response = wp_remote_get($url, [
            'headers' => $this->auth_params
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if ($response['response']['code'] !== 200) {
            return new WP_Error('api_error', 'Failed to fetch movie details.');
        }
        $body = json_decode($response['body'], true);
        if (empty($body)) {
            return new WP_Error('empty_response', 'No data returned from API.');
        }
        return $body;
    }

}