<?php

if (!defined('ABSPATH')) {
    exit;
}

function sdp_localize_report_data_aggrid() {
  $in_reports = is_post_type_archive('report');
  if (!$in_reports) return;

  // Query report posts
  $reports = get_posts([
    'post_type'   => 'report',
    'post_status' => 'publish',
    'numberposts' => -1,
  ]);

  $rows = [];

  foreach ($reports as $report) {
    $stock = get_field('symbol', $report->ID);
    if (!$stock || !is_a($stock[0], 'WP_Post')) continue;

    $researcher_post = get_field('research_company', $report->ID);
    if (!$researcher_post || !is_a($researcher_post[0], 'WP_Post')) continue;

    $researcher_name = $researcher_post[0]->post_title ?? '';
    $researcher_logo = get_the_post_thumbnail_url($researcher_post[0]->ID, 'post-thumbnail') ?: '';
    $report_url      = get_permalink($report->ID);

    $company_name = get_field('company_name', $stock[0]->ID ?? null) ?: '';
    $symbol       = get_field('ticker_symbol', $stock[0]->ID ?? null) ?: '';

    $date_raw = get_field('report_date', $report->ID); // expected m/d/Y
    $date_iso = '';
    $date_display = '';
    if ($date_raw) {
      $dt = DateTime::createFromFormat('m/d/Y', $date_raw);
      if ($dt) {
        // ISO for sorting, display for UI
        $date_iso     = $dt->format('Y-m-d');
        $date_display = $dt->format('m/d/y');
      }
    }

    $price   = get_field('close_price_on_report', $report->ID);
    $percent = get_field('percent_change_since_report', $report->ID);
    // convert to floats. price is a float string, and percent is float with % appended
    $price = is_numeric($price) ? (float) $price : null;
    $percent = rtrim($percent, '%');
    $percent = is_numeric($percent) ? (float) $percent : null;

    $rows[] = [
      'researcherName' => $researcher_name,
      'researcherLogo' => esc_url_raw($researcher_logo),
      'reportUrl'      => esc_url_raw($report_url),
      'companyName'    => $company_name,
      'symbol'         => $symbol,
      'dateISO'        => $date_iso,      // for sorting/filtering
      'dateDisplay'    => $date_display,  // what the user sees
      'price'          => $price,
      'percent'        => $percent,
    ];
  }

  // Localize to your AG Grid bootstrap script (not 'gridjs' anymore)
  wp_localize_script('reports-grid', 'sdpReportsData', [
    'rows' => $rows,
  ]);
}

add_action('wp_enqueue_scripts', 'sdp_localize_report_data_aggrid', 20);
