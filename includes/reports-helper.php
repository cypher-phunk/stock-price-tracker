<?php
if (!defined('ABSPATH')) {
  exit;
}


function sdp_update_report_post_fields($post_id)
{
  // Get the linked stock
  $stock_post = get_field('symbol', $post_id);
  if (!$stock_post || !is_a($stock_post[0], 'WP_Post')) return;

  $stock_id = $stock_post[0]->ID;
  $symbol = get_field('ticker_symbol', $stock_id); // Adjust field name if needed

  if (!$symbol) return;

  // Get the report date
  $report_date = get_field('report_date', $post_id);
  if ($report_date) {
    $report_date = DateTime::createFromFormat('m/d/Y', $report_date)->format('Y-m-d');
  }

  // Get the current post date to compare
  $current_post_date = get_post_field('post_date', $post_id);
  // Ensure the post date is the same as the report date
  if ($report_date) {
    $post_date = DateTime::createFromFormat('Y-m-d', $report_date);
    if ($post_date && $post_date->format('Y-m-d') !== date('Y-m-d', strtotime($current_post_date))) {
      $formatted_date = $post_date->format('Y-m-d H:i:s');
      wp_update_post([
        'ID' => $post_id,
        'post_date' => $formatted_date,
        'post_date_gmt' => get_gmt_from_date($formatted_date),
      ]);
    }
  }

  // Fetch close price on report date
  $report_price = sdp_get_close_price_by_date($symbol, $report_date);
  if ($report_price !== null) {
    update_field('close_price_on_report', $report_price, $post_id);
  }

  // Fetch latest close price
  $latest_price = sdp_get_latest_close_price($symbol);

  if ($report_price !== null && $latest_price !== null && $report_price != 0) {
    $percent = (($latest_price - $report_price) / $report_price) * 100;
    $percent = round($percent, 2);
    update_field('percent_change_since_report', $percent . '%', $post_id);
  }
}

function sdp_localize_report_data()
{
  $in_reports = is_post_type_archive('report');
  $is_home = is_front_page();
  if (!$in_reports && !$is_home) return;

  // Query report posts
  $reports = get_posts([
    'post_type' => 'report',
    'post_status' => 'publish',
    'numberposts' => -1,
  ]);

  $data = [];

  foreach ($reports as $report) {
    $stock = get_field('symbol', $report->ID);
    if (!$stock || !is_a($stock[0], 'WP_Post')) continue;
    $researcher_post = get_field('research_company', $report->ID);
    if (!$researcher_post || !is_a($researcher_post[0], 'WP_Post')) continue;
    $researcher = $researcher_post[0]->post_title;
    $report_url = get_permalink($report->ID);
    $thumbnail_url = get_the_post_thumbnail_url($researcher_post[0]->ID, 'post-thumbnail');
    $researcher_html = sprintf(
      '<div style="display:flex;align-items:center;gap:8px;">
        <img src="%s" alt="%s" style="width:32px;height:32px;border-radius:50%%;object-fit:contain;">
        <a href="%s" class="report-link" style="display:none;">%s</a>
        <span>%s</span>
    </div>',
      esc_url($thumbnail_url),
      esc_attr($researcher),
      esc_url($report_url), // ← Hidden link
      esc_html($report_url),
      esc_html($researcher)
    );
    $company_name = get_field('company_name', $stock[0]->ID ?? null);
    $symbol = get_field('ticker_symbol', $stock[0]->ID ?? null);
    $date = get_field('report_date', $report->ID);
    $price = get_field('close_price_on_report', $report->ID);
    $percent = get_field('percent_change_since_report', $report->ID);
    $data[] = [
      $report_url,
      $researcher_html,
      $company_name,
      $symbol,
      $date,
      $price,
      $percent
    ];
  }

  wp_localize_script('gridjs', 'sdpReportsData', [
    'rows' => $data
  ]);
}

function update_report_title_and_slug($post_id) {
    if (get_post_type($post_id) !== 'report') return;

    $research_company = get_field('research_company', $post_id);
    $symbol = get_field('symbol', $post_id);
    $report_date = get_field('report_date', $post_id);

    if (empty($research_company) || empty($symbol) || empty($report_date)) return;

    // Handle relationship vs post object fields
    $symbol_post = is_array($symbol) ? $symbol[0] : $symbol;
    $firm_post   = is_array($research_company) ? $research_company[0] : $research_company;

    $firm_name  = get_the_title($firm_post);
    $stock_name = get_the_title($symbol_post);

    // Normalize date format (handles slashes or dashes)
    $date = date_parse_from_format('m/d/Y', $report_date);
    if (!$date) return; // Skip if date is invalid

    $date_title = sprintf(
        '%02d/%02d/%04d',
        $date['month'],
        $date['day'],
        $date['year']
    );

    $date_slug = sprintf(
        '%02d-%02d-%04d',
        $date['month'],
        $date['day'],
        $date['year']
    );

    $new_title = "{$firm_name} Report on {$stock_name} | {$date_title}";
    $new_slug  = sanitize_title("{$stock_name}-{$firm_name}-{$date_slug}");

    $post = get_post($post_id);

    if ($post->post_title !== $new_title || $post->post_name !== $new_slug) {
        wp_update_post([
            'ID'         => $post_id,
            'post_title' => $new_title,
            'post_name'  => $new_slug,
        ]);
    }

    sdp_update_report_post_fields($post_id);
}

add_action('acf/save_post', 'update_report_title_and_slug', 20);
add_action('pmxi_saved_post', 'update_report_title_and_slug', 20, 1);