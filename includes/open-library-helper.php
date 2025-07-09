<?php

require_once 'open-library-api-handler.php';

function sdp_handle_book_post($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'book') {
        return;
    }

    $grab_api_on_post_save = get_field('grab_api_on_post_save_book', $post_id);
    if ($grab_api_on_post_save !== 'Yes') {
        return;
    }
    update_field('grab_api_on_post_save_book', 'No', $post_id);

    $open_library_handler = new SDP_OPEN_LIBRARY_API_Handler();

    $book_edition_link = get_field('book_edition_link', $post_id);
    // Get the edition id from the link
    if (empty($book_edition_link)) {
        error_log('No book edition link found for post ID: ' . $post_id);
        return;
    }
    // Extract the edition ID from the link
    $pattern = '/(?:https?:\/\/)?(?:www\.)?openlibrary\.org\/books\/(OL[0-9A-Z]+M)/';
    preg_match($pattern, $book_edition_link, $matches);
    $book_edition_id = $matches[1] ?? null;
    if (!$book_edition_id) {
        error_log('No valid book edition ID found for post ID: ' . $post_id);
        return;
    }
    // Fetch the book edition details
    $edition_details = $open_library_handler->get_edition($book_edition_id);
    $work_details = $open_library_handler->get_work($edition_details['works'][0]['key'] ?? null);
    $author_details = $open_library_handler->get_author($edition_details['authors'][0]['key'] ?? null);
    $cover_image_url = $open_library_handler->get_edition_image($book_edition_id);
    // Update ACF fields with book details
    update_field('book_edition_olid', $book_edition_id, $post_id);
    update_field('book_title', $work_details['title'], $post_id);
    update_field('book_subtitle', $edition_details['subtitle'] ?? '', $post_id);
    update_field('book_authors', $author_details['name'] ?? ['personal_name'] ?? '', $post_id);
    update_field('book_publish_date', $edition_details['publish_date'] ?? '', $post_id);
    update_field('book_cover_image', $cover_image_url, $post_id);
    update_field('book_description', $work_details['description'] ?? '', $post_id);
    update_field('book_subjects', implode(', ', $work_details['subjects'] ?? []), $post_id);
    // Set featured image if cover image is available and currently no image
    if ($cover_image_url && !has_post_thumbnail($post_id)) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $image_id = media_sideload_image($cover_image_url, $post_id, null, 'id');
        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        } else {
            error_log('Error sideloading cover image: ' . $image_id->get_error_message());
        }
    }
    remove_action('save_post', __FUNCTION__, 10, 1);
    $post_data = [
        'ID' => $post_id,
        'post_title' => $edition_details['title'],
        'post_name' => sanitize_title($edition_details['title']),
    ];
    wp_update_post($post_data);
    add_action('save_post', __FUNCTION__, 10, 1);
}

add_action('save_post', 'sdp_handle_book_post');