<?php

class SDP_OPEN_LIBRARY_API_Handler
{
    private $api_server;

    public function __construct()
    {
        $this->api_server = 'https://openlibrary.org/';
    }

    public function get_edition($id)
    {
        if (empty($id)) {
            return new WP_Error('invalid_id', 'Edition ID is required.');
        }
        $response = wp_remote_get($this->api_server . 'books/' . $id . '.json');

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['title'])) {
            return new WP_Error('book_not_found', 'Book not found.');
        }

        return $body;
    }

    public function get_work($id)
    {
        if (empty($id)) {
            return new WP_Error('invalid_id', 'Work ID is required.');
        }

        $response = wp_remote_get($this->api_server . $id . '.json');

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['title'])) {
            return new WP_Error('work_not_found', 'Work not found.');
        }

        return $body;
    }

    public function get_edition_image($id)
    {
        $covers_api = 'https://covers.openlibrary.org/b/olid/';

        if (empty($id)) {
            return new WP_Error('invalid_id', 'Edition ID is required.');
        }

        $image_url = $covers_api . $id . '-L.jpg';
        $response = wp_remote_head($image_url);

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        // Get the final URL from the http_response object
        $cover_url = '';
        if (isset($response['http_response']) && method_exists($response['http_response'], 'get_response_object')) {
            $http_response = $response['http_response']->get_response_object();
            if (isset($http_response->url)) {
                $cover_url = $http_response->url;
            }
        }
        return $cover_url;
    }

    public function get_author($id)
    {
        if (empty($id)) {
            return new WP_Error('invalid_id', 'Author ID is required.');
        }

        $response = wp_remote_get($this->api_server . $id . '.json');

        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['name'])) {
            return new WP_Error('author_not_found', 'Author not found.');
        }

        return $body;
    }
}
