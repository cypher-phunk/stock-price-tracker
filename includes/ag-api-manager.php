<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SDP_AG_API_Manager {
    private $api_key;

    public function __construct() {
        $encrypted_key = get_option('sdp_ag_api_key');
        $this->api_key = sdp_decrypt_api_key($encrypted_key);
    }

    public function get_api_key() {
        return $this->api_key;
    }

    public function set_api_key($key) {
        $encrypted_key = sdp_encrypt_api_key($key);
        update_option('sdp_ag_api_key', $encrypted_key);
    }
}