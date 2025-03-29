<?php
if (!defined('ABSPATH')) exit;
function sdp_encrypt_api_key($key) {
    $salt = defined('NONCE_SALT') ? NONCE_SALT : wp_salt('auth');
    if (function_exists('openssl_encrypt')) {
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($key, $method, $salt, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    // fallback encryption method
    return base64_encode(
        base64_encode($key) . 
        '::' . 
        hash_hmac('sha256', $key, $salt)
    );
}

function sdp_decrypt_api_key($encrypted_key) {
    $salt = defined('NONCE_SALT') ? NONCE_SALT : wp_salt('auth');
    if (function_exists('openssl_decrypt')) {
        $method = 'aes-256-cbc';
        $decoded = base64_decode($encrypted_key);
        $iv_length = openssl_cipher_iv_length($method);
        $iv = substr($decoded, 0, $iv_length);
        $encrypted = substr($decoded, $iv_length);
        
        return openssl_decrypt($encrypted, $method, $salt, 0, $iv);
    }
    // fallback decryption method
    $parts = base64_decode($encrypted_key);
    list($encoded_key, $stored_hash) = explode('::', $parts);
    $key = base64_decode($encoded_key);
    if (hash_hmac('sha256', $key, $salt) !== $stored_hash) {
        return false;
    }
    
    return $key;
}

// When saving the API key
function sdp_save_marketstack_api_key($key) {
    // Clear any existing validation transient
    delete_transient('sdp_marketstack_api_key_validation');

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

function sdp_validate_api_key($key) {
    $api = new SDP_API_Handler();
    $response = $api->is_api_key_valid('$key');
    if (is_wp_error($response)) {
        return false;
    }
    
    return true;
}

// Function to display admin notices for encryption and API validation
function sdp_display_api_key_notices() {
    // Check if we're on the plugin settings page
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'toplevel_page_stock-data-plugin') {
        return;
    }

    // Encryption Test
    $test_key = 'test_api_key_123';
    try {
        $encrypted_key = sdp_encrypt_api_key($test_key);
        $decrypted_key = sdp_decrypt_api_key($encrypted_key);

        if ($encrypted_key && $decrypted_key && $decrypted_key === $test_key) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Encryption Test:</strong> ✅ Encryption and decryption are working correctly.</p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>Encryption Test:</strong> ❌ Encryption or decryption failed.</p>
            </div>
            <?php
        }
    } catch (Exception $e) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>Encryption Test:</strong> ❌ An error occurred during encryption test: <?php echo esc_html($e->getMessage()); ?></p>
        </div>
        <?php
    }

    // API Key Validation Test
    $current_api_key = sdp_get_marketstack_api_key();
    if ($current_api_key) {
        $validation_data = sdp_get_api_key_validation_status();

        if ($validation_data && $validation_data['is_valid'] === true) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>API Key Test:</strong> ✅ Your Marketstack API key is valid and working.</p>
                <p>Last validated on: <?php echo date('Y-m-d H:i:s', $validation_data['checked_at']); ?></p>
            </div>
            <?php
        } else {
            $api = new SDP_API_Handler();
            $validation_result = $api->is_api_key_valid($current_api_key);

            if ($validation_result) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>API Key Test:</strong> ✅ Your Marketstack API key is valid and working.</p>
                </div>
                <?php
            } else {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>API Key Test:</strong> ❌ Your Marketstack API key appears to be invalid or not working.</p>
                </div>
                <?php
            }
        }
    } else {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>API Key:</strong> ⚠️ No API key has been saved or retrieved.</p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'sdp_display_api_key_notices');

function sdp_save_api_key_validation_status($is_valid) {
    // Store validation status with a timestamp
    $validation_data = [
        'is_valid' => $is_valid,
        'checked_at' => time()
    ];
    update_option('sdp_marketstack_api_key_validation', $validation_data, false);
}

function sdp_get_api_key_validation_status() {
    return get_option('sdp_marketstack_api_key_validation', false);
}

function sdp_clear_api_key_validation_status() {
    delete_option('sdp_marketstack_api_key_validation');
}