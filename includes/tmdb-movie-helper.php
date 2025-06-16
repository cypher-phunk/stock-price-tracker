<?php

require_once 'api-key-management.php';
require_once 'tmdb-api-handler.php';

// Save TMDB API Key
function sdp_save_tmdb_api_key($api_key) {
    if (empty($api_key)) {
        return false;
    }

    // Validate the API key with TMDB API
    if (!sdp_validate_tmdb_api_key($api_key)) {
        return false;
    }


    $encrypted_key = sdp_encrypt_api_key($api_key);
    if ($encrypted_key === false) {
        return false;
    }

    update_option('sdp_tmdb_api_key', $encrypted_key);
    return true;
}

// Retrieve TMDB API Key
function sdp_get_tmdb_api_key() {
    $encrypted_key = get_option('sdp_tmdb_api_key');
    if (empty($encrypted_key)) {
        return false;
    }

    $decrypted_key = sdp_decrypt_api_key($encrypted_key);
    if ($decrypted_key === false) {
        return false;
    }

    return $decrypted_key;
}

// Validate TMDB API Key
function sdp_validate_tmdb_api_key($api_key) {
    $api_handler = new SDP_TMDB_API_Handler();
    $response = $api_handler->is_api_key_valid($api_key);
    
    if (is_wp_error($response)) {
        return false;
    }

    return true;
}

// Render the settings page
function sdp_tmdb_api_key_settings_page() {
    // Handle form submission
    if (isset($_POST['sdp_tmdb_api_key_nonce']) && wp_verify_nonce($_POST['sdp_tmdb_api_key_nonce'], 'sdp_save_tmdb_api_key')) {
        $api_key = sanitize_text_field($_POST['sdp_tmdb_api_key']);
        if (sdp_save_tmdb_api_key($api_key)) {
            echo '<div class="notice notice-success"><p>API Key saved.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to save API Key.</p></div>';
        }
    }
    $current_key = sdp_get_tmdb_api_key();
    ?>
    <div class="wrap">
        <h1>TMDB API Key Settings</h1>
        <form method="post">
            <?php wp_nonce_field('sdp_save_tmdb_api_key', 'sdp_tmdb_api_key_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="sdp_tmdb_api_key">TMDB API Key</label></th>
                    <td>
                        <input type="text" name="sdp_tmdb_api_key" id="sdp_tmdb_api_key" value="<?php echo esc_attr($current_key); ?>" class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
    </div>
    <?php
}

// Handle movie post creation/update
function sdp_handle_movie_post($post_id) {
    // Check if this is a movie post type
    if (get_post_type($post_id) !== 'movie') {
        return;
    }

    // Get the TMDB ID from the post meta
    $tmdb_id = get_field('tmdb_id', $post_id);
    if (empty($tmdb_id)) {
        return; // No TMDB ID, nothing to do
    }
    // Get the TMDB API key
    $api_handler = new SDP_TMDB_API_Handler();
    $movie_details = $api_handler->get_movie_details($tmdb_id);
    if (is_wp_error($movie_details)) {
        // Handle error (e.g., log it, notify admin, etc.)
        error_log('Error fetching movie details: ' . $movie_details->get_error_message());
        return;
    }
    $prefix = 'https://image.tmdb.org/t/p/original/';
    // Update acf fields with movie details
    update_field('movie_title', $movie_details['title'], $post_id);
    update_field('movie_tagline', $movie_details['tagline'], $post_id);
    update_field('movie_overview', $movie_details['overview'], $post_id);
    update_field('movie_release_date', $movie_details['release_date'], $post_id);
    update_field('movie_runtime', $movie_details['runtime'], $post_id);
    update_field('movie_poster_path', $prefix . $movie_details['poster_path'], $post_id);
    update_field('movie_backdrop_path', $prefix . $movie_details['backdrop_path'], $post_id);

    // Update WordPress post title and content
    $post_data = [
        'ID' => $post_id,
        'post_title' => $movie_details['title'],
    ];
}

// Hook into post save action
add_action('save_post', 'sdp_handle_movie_post', 10, 1);
