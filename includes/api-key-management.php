<?php
if (!defined('ABSPATH')) exit;
function sdp_encrypt_api_key($key) {
    // Use WordPress salt for additional security
    $salt = defined('NONCE_SALT') ? NONCE_SALT : wp_salt('auth');
    
    // Use OpenSSL for encryption if available
    if (function_exists('openssl_encrypt')) {
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($key, $method, $salt, 0, $iv);
        
        // Store IV with encrypted key
        return base64_encode($iv . $encrypted);
    }
    
    // Fallback encryption method
    return base64_encode(
        base64_encode($key) . 
        '::' . 
        hash_hmac('sha256', $key, $salt)
    );
}

function sdp_decrypt_api_key($encrypted_key) {
    $salt = defined('NONCE_SALT') ? NONCE_SALT : wp_salt('auth');
    
    // OpenSSL decryption
    if (function_exists('openssl_decrypt')) {
        $method = 'aes-256-cbc';
        $decoded = base64_decode($encrypted_key);
        $iv_length = openssl_cipher_iv_length($method);
        $iv = substr($decoded, 0, $iv_length);
        $encrypted = substr($decoded, $iv_length);
        
        return openssl_decrypt($encrypted, $method, $salt, 0, $iv);
    }
    
    // Fallback decryption method
    $parts = base64_decode($encrypted_key);
    list($encoded_key, $stored_hash) = explode('::', $parts);
    $key = base64_decode($encoded_key);
    
    // Verify integrity
    if (hash_hmac('sha256', $key, $salt) !== $stored_hash) {
        return false;
    }
    
    return $key;
}

// When saving the API key
function sdp_save_marketstack_api_key($key) {
    // Encrypt before storing
    $encrypted_key = sdp_encrypt_api_key($key);
    update_option('sdp_marketstack_api_key_encrypted', $encrypted_key, true);
    
    // Remove old unencrypted key if it exists
    delete_option('sdp_marketstack_api_key');
}

// When retrieving the API key
function sdp_get_marketstack_api_key() {
    $encrypted_key = get_option('sdp_marketstack_api_key_encrypted');
    return $encrypted_key ? sdp_decrypt_api_key($encrypted_key) : false;
}

// Validate the API key
function sdp_validate_api_key($key) {
    $api = new SDP_API_Handler();
    $response = $api->fetch_latest_price('AAPL');    
    if (is_wp_error($response)) {
        return false;
    }
    
    return true;
}