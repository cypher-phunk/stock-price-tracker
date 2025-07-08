<?php

class SDP_PODINDEX_API_Handler {
    private $auth_params;
    private $api_server;

    public function __construct() {
        $api_key = sdp_get_podindex_api_key();
        $api_secret = sdp_get_podindex_api_secret();
        $date = (string) floor(time());
        $this->api_server = 'https://api.podcastindex.org/api/1.0/';
        $this->auth_params = [
            'accept' => 'application/json',
            'User-Agent' => 'A8FPodcasts/1.0',
            'X-Auth-Key' => $api_key,
            'X-Auth-Date' => $date,
            'Authorization' => sha1($api_key . $api_secret . $date),
        ];
    }

    public function is_api_key_valid() {
        if (empty($this->auth_params['X-Auth-Key']) || empty($this->auth_params['X-Auth-Date'])) {
            return new WP_Error('invalid_api_key', 'API key or secret is missing.');
        }
        $response = wp_remote_get($this->api_server . 'search/byterm?q=batman+university', [
            'headers' => $this->auth_params,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['valid'])) {
            return new WP_Error('invalid_api_key', 'API key is invalid.');
        }

        return true;
    }

    public function get_podcast($id) {
        $response = wp_remote_get($this->api_server . 'podcasts/byfeedid?id=' . $id, [
            'headers' => $this->auth_params,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['feed'])) {
            return new WP_Error('podcast_not_found', 'Podcast not found.');
        }

        return $body['feed'] ?? $body['podcast'];
    }

    public function get_episodes($podcast_id) {
        $response = wp_remote_get($this->api_server . 'episodes/byfeedid?id=' . $podcast_id . '&max=1000&fulltext=1', [
            'headers' => $this->auth_params,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['items'])) {
            return new WP_Error('episodes_not_found', 'No episodes found for this podcast.');
        }

        return $body['items'];
    }
}