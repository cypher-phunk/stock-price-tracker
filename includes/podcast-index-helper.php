<?php

require_once 'api-key-management.php';
require_once 'podcast-index-api-handler.php';

function sdp_save_podindex_api_key($api_key, $api_secret)
{
    if (empty($api_key) || empty($api_secret)) {
        return false;
    }

    // Validate the API key with PodIndex API
    $api_handler = new SDP_PODINDEX_API_Handler();
    if (!$api_handler->is_api_key_valid($api_key, $api_secret)) {
        return false;
    }

    $encrypted_key = sdp_encrypt_api_key($api_key);
    $encrypted_secret = sdp_encrypt_api_key($api_secret);

    if ($encrypted_key === false || $encrypted_secret === false) {
        return false;
    }

    update_option('sdp_podindex_api_key', $encrypted_key);
    update_option('sdp_podindex_api_secret', $encrypted_secret);

    return true;
}

function sdp_get_podindex_api_key()
{
    $encrypted_key = get_option('sdp_podindex_api_key');
    if (empty($encrypted_key)) {
        return false;
    }

    $decrypted_key = sdp_decrypt_api_key($encrypted_key);
    if ($decrypted_key === false) {
        return false;
    }

    return $decrypted_key;
}

function sdp_get_podindex_api_secret()
{
    $encrypted_secret = get_option('sdp_podindex_api_secret');
    if (empty($encrypted_secret)) {
        return false;
    }

    $decrypted_secret = sdp_decrypt_api_key($encrypted_secret);
    if ($decrypted_secret === false) {
        return false;
    }

    return $decrypted_secret;
}

function sdp_validate_podindex_api_key($api_key, $api_secret)
{
    $api_handler = new SDP_PODINDEX_API_Handler();
    $response = $api_handler->is_api_key_valid($api_key, $api_secret);

    if (is_wp_error($response)) {
        return false;
    }

    return true;
}

function sdp_podindex_api_key_settings_page()
{
    // Handle form submission
    if (isset($_POST['sdp_podindex_api_key_nonce']) && wp_verify_nonce($_POST['sdp_podindex_api_key_nonce'], 'sdp_save_podindex_api_key')) {
        $api_key = sanitize_text_field($_POST['sdp_podindex_api_key']);
        $api_secret = sanitize_text_field($_POST['sdp_podindex_api_secret']);
        if (sdp_save_podindex_api_key($api_key, $api_secret)) {
            echo '<div class="notice notice-success"><p>API Key saved.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to save API Key.</p></div>';
        }
    }
    $current_api_key = sdp_get_podindex_api_key();
?>
    <div class="wrap">
        <h1>Podcast Index API Key Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('sdp_save_podindex_api_key', 'sdp_podindex_api_key_nonce'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="sdp_podindex_api_key" value="<?php echo esc_attr($current_api_key); ?>" class="regular-text" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">API Secret</th>
                    <td><input type="text" name="sdp_podindex_api_secret" value="<?php echo esc_attr(sdp_get_podindex_api_secret()); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
    </div>
<?php
}

function sdp_revalidate_podcast_items($post_id)
{
    // Loop through all podcast episodes and revalidate photos
    $podcast_episodes = get_field('podcast_episodes', $post_id);
    foreach ($podcast_episodes as $episode_post) {
        if (is_numeric($episode_post)) {
            $episode_post = get_post($episode_post);
        }
        if (!is_a($episode_post, 'WP_Post')) {
            continue; // Skip if not a valid post object
        }
        // Check if the episode has a featured image
        $image_id = get_post_thumbnail_id($episode_post->ID);
        $cover_image_url = get_field('podcast_episode_image_url', $episode_post->ID);
        if (!empty($cover_image_url)) {
            // No featured image, try to sideload the podcast cover image
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $image_id = media_sideload_image($cover_image_url, $episode_post->ID, null, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($episode_post->ID, $image_id);
            }
        } else {
            // If there's no cover image URL, we can try to use the episode's featured image
            $image_id = get_post_thumbnail_id($post_id);
            if ($image_id) {
                set_post_thumbnail($episode_post->ID, $image_id);
            }
        }
    }
}

function sdp_handle_podcast_post($post_id)
{
    $piid = null;
    if (get_post_type($post_id) !== 'podcast') {
        return;
    }
    $revalidate_items = get_field('podcast_revalidate_items', $post_id);
    if ($revalidate_items === 'Yes') {
        update_field('podcast_revalidate_items', 'No', $post_id);
        sdp_revalidate_podcast_items($post_id);
    }
    $grab_api_on_post_save = get_field('grab_api_on_post_save_podcast', $post_id);
    if ($grab_api_on_post_save !== 'Yes') {
        return; // Not enabled, nothing to do
    }
    update_field('grab_api_on_post_save_podcast', 'No', $post_id);
    $podcast_index_url = get_field('podcast_index_link', $post_id);
    if (empty($podcast_index_url)) {
        return; // No URL provided, nothing to do
    }
    // get the piid from the URL
    if (preg_match('/\/podcast\/(\d+)$/', $podcast_index_url, $matches)) {
        $piid = $matches[1];
        update_field('podcast_piid', $piid, $post_id);
    } else {
        // Could not extract PIID from URL
        return;
    }
    $api_handler = new SDP_PODINDEX_API_Handler();
    $podcast = $api_handler->get_podcast($piid);
    if (is_wp_error($podcast)) {
        // Handle error, e.g., log it or display a notice
        error_log('Failed to fetch podcast: ' . $podcast->get_error_message());
        return;
    }
    $podcast_link = $podcast['link'] ?? '';
    // Check to see if the link redirects to mp3

    // Update post meta with podcast details
    update_field('podcast_title', $podcast['title'], $post_id);
    update_field('podcast_description', $podcast['description'], $post_id);
    update_field('podcast_image', $podcast['image'], $post_id);
    update_field('podcast_itunes_id', $podcast['itunesId'], $post_id);
    update_field('podcast_link', $podcast['link'], $post_id);
    update_field('podcast_author', $podcast['author'], $post_id);
    update_field('podcast_episode_count', intval($podcast['episodeCount']), $post_id);
    // Convert categories array to a comma-separated string of category names
    $categories_string = '';
    if (!empty($podcast['categories']) && is_array($podcast['categories'])) {
        $categories_string = implode(', ', array_values($podcast['categories']));
    }
    update_field($post_id, 'podcast_categories', $categories_string);

    // Sideload poster image if no image exists
    if (!has_post_thumbnail($post_id)) {
        $image_url = $podcast['image'];
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');
        set_post_thumbnail($post_id, $image_id);
    }
    if ($image_id && is_wp_error($image_id)) {
        // Handle error, e.g., log it or display a notice
        error_log('Failed to sideload podcast image: ' . $image_id->get_error_message());
        return;
    }
    remove_action('save_post', __FUNCTION__, 10, 1);
    $post_data = [
        'ID' => $post_id,
        'post_title' => $podcast['title'],
        'post_name' => sanitize_title($podcast['title']),
    ];
    wp_update_post($post_data);
    add_action('save_post', __FUNCTION__, 10, 1);
    check_podcast_episodes($post_id);
}

function check_podcast_episodes($post_id)
{
    $episode_ids = [];
    $episodes = [];
    $podcast_id = get_field('podcast_piid', $post_id);
    $podcast_episode_count = get_field('podcast_episode_count', $post_id);
    // acf relationship field for podcast episodes
    $podcast_episodes = get_field('podcast_episodes', $post_id);
    if (empty($podcast_episodes) || !is_array($podcast_episodes)) {
        $podcast_episodes = [];
    }
    foreach ($podcast_episodes as $podcast_episode_post) {
        if (is_numeric($podcast_episode_post)) {
            $podcast_episode_post = get_post($podcast_episode_post);
        }
        if (!is_a($podcast_episode_post, 'WP_Post')) {
            continue; // Skip if not a valid post object
        }
        // set the episode id from post acf
        $episode_id = get_field('podcast_episode_id', $podcast_episode_post->ID);
        if (empty($episode_id)) {
            continue; // Skip if no episode ID
        }
        // TODO temp fix for episode enclosure URL with params
        $podcast_enclosure_url = get_field('podcast_episode_enclosure_url', $podcast_episode_post->ID);
        if (strpos($podcast_enclosure_url, '?') !== false) {
            $podcast_enclosure_url = strtok($podcast_enclosure_url, '?');
            update_field('podcast_episode_enclosure_url', $podcast_enclosure_url, $podcast_episode_post->ID);
        }
        $episode_ids[] = $episode_id;
    }
    // compare amount of episodes in the relationship field with the podcast episode count
    // if (count($podcast_episodes) >= $podcast_episode_count) {
    //    return; // Already have all episodes, nothing to do
    // }
    $api_handler = new SDP_PODINDEX_API_Handler();
    // Fetch episodes from the Podcast Index API
    $episodes = $api_handler->get_episodes($podcast_id);
    // check episode ids against existing ones
    if (is_wp_error($episodes)) {
        // Handle error, e.g., log it or display a notice
        error_log('Failed to fetch podcast episodes: ' . $episodes->get_error_message());
        return;
    }

    foreach (array_reverse($episodes) as $episode) {
        if (!in_array($episode['id'], $episode_ids)) {
            // This is a new episode, add it to the list
            $podcast_episodes[] = $episode;
            // Create a new post for the episode
            create_podcast_episode_post($episode, $post_id);
        }
    }
}

function create_podcast_episode_post($episode, $podcast_post_id)
{
    // sideload the episode image if it exists
    $post_data = [
        'post_title' => $episode['title'],
        'post_name' => sanitize_title($episode['title']),
        'post_content' => $episode['description'],
        'post_status' => 'publish',
        'post_type' => 'podcast-episode',
    ];
    $post_id = wp_insert_post($post_data);
    if (is_wp_error($post_id)) {
        // Handle error, e.g., log it or display a notice
        error_log('Failed to create podcast episode post: ' . $post_id->get_error_message());
        return;
    }
    $unique_images = (get_field('podcast_unique_images', $podcast_post_id) === 'Yes');
    if (!empty($episode['image']) && $unique_images) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $image_url = $episode['image'];
        $image_id = media_sideload_image($image_url, $post_id);
    } else {
        // Set to podcast cover image if no episode image
        $image_id = get_post_thumbnail_id($podcast_post_id);
    }
    set_post_thumbnail($post_id, $image_id);
    // Set the episode ID as post meta
    $podcast_enclosure_url = $episode['enclosureUrl'] ?? '';
    // if enclosure url has params, remove them
    if (strpos($podcast_enclosure_url, '?') !== false) {
        $podcast_enclosure_url = strtok($podcast_enclosure_url, '?');
    }
    update_field('podcast_episode_id', $episode['id'], $post_id);
    update_field('podcast_episode_title', $episode['title'], $post_id);
    update_field('podcast_episode_description', $episode['description'], $post_id);
    update_field('podcast_episode_date_published', $episode['datePublishedPretty'], $post_id);
    update_field('podcast_episode_duration', $episode['duration'], $post_id);
    update_field('podcast_episode_link', $episode['link'], $post_id);
    update_field('podcast_episode_image_url', $episode['image'], $post_id);
    update_field('podcast_episode_enclosure_url', $podcast_enclosure_url, $post_id);
    update_field('podcast_episode_number', $episode['episode'], $post_id);
    update_field('podcast_episode_podcast', get_post($podcast_post_id), $post_id);

    // Set the podcast relationship
    $podcast = get_post($podcast_post_id);
    if ($podcast && $podcast->post_type === 'podcast') {
        $podcast_episodes = get_field('podcast_episodes', $podcast_post_id);
        if (empty($podcast_episodes) || !is_array($podcast_episodes)) {
            $podcast_episodes = [];
        }
        $podcast_episodes[] = get_post($post_id); // Add the new episode post to the relationship
        update_field('podcast_episodes', $podcast_episodes, $podcast_post_id);
    }
}

function sdp_delete_podcast_episodes($post_id)
{
    if (get_post_type($post_id) !== 'podcast') {
        return;
    }
    $podcast_episodes = get_field('podcast_episodes', $post_id);
    if (empty($podcast_episodes) || !is_array($podcast_episodes)) {
        return; // No episodes to delete
    }
    remove_action('before_delete_post', __FUNCTION__, 10, 1);
    foreach ($podcast_episodes as $episode_post) {
        if (is_numeric($episode_post)) {
            $episode_post = get_post($episode_post);
        }
        if (!is_a($episode_post, 'WP_Post')) {
            continue; // Skip if not a valid post object
        }
        // Delete the episode post
        wp_delete_post($episode_post->ID, true);
    }
    // Remove the relationship field
    delete_field('podcast_episodes', $post_id);
    add_action('before_delete_post', __FUNCTION__, 10, 1);
}

add_action('before_delete_post', 'sdp_delete_podcast_episodes');
add_action('save_post', 'sdp_handle_podcast_post', 10, 1);
