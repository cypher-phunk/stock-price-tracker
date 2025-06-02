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
  if (!$in_reports) return;

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
    $thumbnail_url = get_the_post_thumbnail_url($researcher_post[0]->ID, 'thumbnail');
    $researcher_html = sprintf(
      '<div style="display:flex;align-items:center;gap:8px;">
        <img src="%s" alt="%s" style="width:32px;height:32px;border-radius:50%%;object-fit:cover;">
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
