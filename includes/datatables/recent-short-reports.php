<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {
    if (!is_front_page() && !is_home()) {
        return;
    }

    wp_enqueue_style(
        'sdp-datatables',
        'https://cdn.datatables.net/2.3.6/css/dataTables.dataTables.min.css',
        [],
        null
    );

    wp_enqueue_script(
        'sdp-datatables',
        'https://cdn.datatables.net/2.3.6/js/dataTables.min.js',
        ['jquery'],
        null,
        true
    );

    wp_enqueue_script(
        'luxon',
        'https://cdn.jsdelivr.net/npm/luxon@3.7.2/build/global/luxon.min.js',
        [],
        '3.7.2',
        true
    );

    wp_enqueue_script(
        'sdp-recent-short-reports-datatables',
        SDP_PLUGIN_URL . 'assets/js/recent-short-reports-datatables.js',
        ['sdp-datatables'],
        SDP_PLUGIN_VERSION,
        true
    );

    wp_localize_script('sdp-recent-short-reports-datatables', 'sdpRecentShortReportsDt', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sdp_recent_short_reports_dt'),
    ]);
}, 30);

add_action('wp_ajax_sdp_recent_short_reports_dt', 'sdp_ajax_recent_short_reports_dt');
add_action('wp_ajax_nopriv_sdp_recent_short_reports_dt', 'sdp_ajax_recent_short_reports_dt');

function sdp_ajax_recent_short_reports_dt()
{
    if (!check_ajax_referer('sdp_recent_short_reports_dt', 'nonce', false)) {
        wp_send_json([
            'draw' => isset($_POST['draw']) ? (int)$_POST['draw'] : 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Invalid nonce.'
        ], 403);
    }

    $draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 0;
    $start = isset($_POST['start']) ? max(0, (int)$_POST['start']) : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
    if ($length <= 0) {
        $length = 10;
    }

    $search_value = '';
    if (isset($_POST['search']) && is_array($_POST['search'])) {
        $search_value = isset($_POST['search']['value']) ? sanitize_text_field(wp_unslash($_POST['search']['value'])) : '';
    }

    $order_col = 3; // default report date
    $order_dir = 'DESC';
    if (isset($_POST['order']) && is_array($_POST['order']) && isset($_POST['order'][0]) && is_array($_POST['order'][0])) {
        $order_col = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : $order_col;
        $dir = isset($_POST['order'][0]['dir']) ? strtolower((string)$_POST['order'][0]['dir']) : 'desc';
        $order_dir = $dir === 'asc' ? 'ASC' : 'DESC';
    }

    // Map DataTables column index => WP/ACF sort key
    // 0 Investigator (research_company post title)
    // 1 Company (company_name from stock post)
    // 2 Ticker (ticker_symbol from stock post)
    // 3 Report Date (report_date ACF)
    // 4 Price at Report (close_price_on_report ACF)
    // 5 % Return (percent_change_since_report ACF)
    $orderby = 'date';
    $meta_key = '';
    $records_total = 0;

    switch ($order_col) {
        case 3:
            $orderby = 'meta_value';
            $meta_key = 'report_date';
            break;
        case 4:
            $orderby = 'meta_value_num';
            $meta_key = 'close_price_on_report';
            break;
        case 5:
            $orderby = 'meta_value_num';
            $meta_key = 'percent_change_since_report';
            break;
        default:
            // fallback to publish date
            $orderby = 'date';
            break;
    }

    $base_args = [
        'post_type' => 'report',
        'post_status' => 'publish',
        'posts_per_page' => 50,
        'offset' => max(0, $records_total - 50),
        'orderby' => $orderby,
        'order' => $order_dir,
        'no_found_rows' => false,
    ];

    $q = new WP_Query($base_args);

    $records_total = (int)wp_count_posts('report')->publish;
    $records_filtered = (int)$q->found_posts;

    $data = [];

    foreach ($q->posts as $report) {
        $stock = get_field('symbol', $report->ID);
        $researcher_post = get_field('research_company', $report->ID);
        $report_url = get_permalink($report->ID);

        $researcher_name = '';
        $researcher_url = '';
        $researcher_image = '';
        if ($researcher_post && is_array($researcher_post) && isset($researcher_post[0]) && is_a($researcher_post[0], 'WP_Post')) {
            $researcher_name = $researcher_post[0]->post_title ?? '';
            $researcher_url = get_permalink($researcher_post[0]->ID) ?? '';
            $researcher_image = get_the_post_thumbnail_url($researcher_post[0]->ID, 'post-thumbnail') ?: '';
        }

        $company_name = '';
        $symbol = '';
        $ticker_url = '';
        if ($stock && is_array($stock) && isset($stock[0]) && is_a($stock[0], 'WP_Post')) {
            $company_name = (string)(get_field('company_name', $stock[0]->ID) ?: '');
            $symbol = (string)(get_field('ticker_symbol', $stock[0]->ID) ?: '');
            $ticker_url = get_permalink($stock[0]->ID) ?? '';
        }

        $date_raw = (string)(get_field('report_date', $report->ID) ?: ''); // expected m/d/Y
        $date_display = '';
        if ($date_raw) {
            $dt = DateTime::createFromFormat('m/d/Y', $date_raw);
            if ($dt) {
                $date_display = $dt->format('m/d/y');
            }
        }

        $price = get_field('close_price_on_report', $report->ID);
        $price = is_numeric($price) ? number_format((float)$price, 2, '.', '') : '';

        $percent_raw = get_field('percent_change_since_report', $report->ID);
        $percent = 0.0;
        if (is_string($percent_raw) && preg_match('/-?\d+\.?\d*/', $percent_raw, $matches)) {
            $percent = (float)$matches[0];
        }

        $data[] = [
            $researcher_name,
            $company_name,
            $symbol,
            $date_display,
            $price,
            $percent,
            $report_url,
            $researcher_url,
            $researcher_image,
            $ticker_url
        ];
    }

    wp_send_json([
        'draw' => $draw,
        'recordsTotal' => $records_total,
        'recordsFiltered' => $records_filtered,
        'data' => $data,
    ]);
}
