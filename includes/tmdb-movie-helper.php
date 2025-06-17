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
    if (get_post_type($post_id) === 'series') {
        handle_series_post($post_id); // Handle series post if needed
    }
    // Check if this is a movie post type
    if (get_post_type($post_id) !== 'movie') {
        return;
    }
    // Check if Grab API on Post Save is enabled
    $grab_api_on_post_save = get_field('grab_api_on_post_save', $post_id);
    if ($grab_api_on_post_save !== 'Yes') {
        return; // Not enabled, nothing to do
    }
    update_field('grab_api_on_post_save', 'No', $post_id); // Reset the field to prevent re-triggering
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
        'post_title' => $movie_details['title'] . ' (' . date('Y', strtotime($movie_details['release_date'])) . ')',
    ];
    // Update the post in the database
    wp_update_post($post_data);
}

function handle_series_post($post_id){
    // Check if this is a series post type
    if (get_post_type($post_id) !== 'series') {
        return;
    }
    // Check if Grab API on Post Save is enabled
    $grab_api_on_post_save = get_field('grab_api_on_post_save', $post_id);
    if ($grab_api_on_post_save !== 'Yes') {
        return; // Not enabled, nothing to do
    }
    update_field('grab_api_on_post_save', 'No', $post_id); // Reset the field to prevent re-triggering
    // Get the TMDB ID from the post meta
    $tmdb_id = get_field('tmdb_id', $post_id);
    if (empty($tmdb_id)) {
        return; // No TMDB ID, nothing to do
    }
    // Get the TMDB API key
    $api_handler = new SDP_TMDB_API_Handler();
    $series_details = $api_handler->get_series_details($tmdb_id);
    if (is_wp_error($series_details)) {
        // Handle error (e.g., log it, notify admin, etc.)
        error_log('Error fetching movie details: ' . $series_details->get_error_message());
        return;
    }
    $prefix = 'https://image.tmdb.org/t/p/original/';
    // Update acf fields with movie details
    update_field('series_title', $series_details['name'], $post_id);
    update_field('series_tagline', $series_details['tagline'], $post_id);
    update_field('series_overview', $series_details['overview'], $post_id);
    // Set date from release to end or ongoing based on status
    if ($series_details['status'] === 'Ended') {
        $end_date = $series_details['last_air_date'];
        update_field('series_date', $end_date, $post_id);
    } else {
        $date = $series_details['first_air_date'] . ' - Ongoing';
        update_field('series_date', $date, $post_id);
    }
    update_field('series_poster_path', $prefix . $series_details['poster_path'], $post_id);
    update_field('series_backdrop_path', $prefix . $series_details['backdrop_path'], $post_id);
    update_field('series_episodes', $series_details['number_of_episodes'], $post_id);
    update_field('series_seasons', $series_details['number_of_seasons'], $post_id);
    update_field('series_status', $series_details['status'], $post_id);

    // Update WordPress post title and content
    $post_data = [
        'ID' => $post_id,
        'post_title' => $series_details['name'] . ' (' . date('Y', strtotime($series_details['release_date'])) . ')',
    ];
    // Update the post in the database
    wp_update_post($post_data);
}

// Hook into post save action
add_action('save_post', 'sdp_handle_movie_post', 10, 1);
