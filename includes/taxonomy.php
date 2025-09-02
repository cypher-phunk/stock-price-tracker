<?php

if (!defined('ABSPATH')) {
  exit;
}

const SDP_ACF_FIELD_MENTIONED_STOCKS = 'mentioned_stocks'; // ACF relationship field on reports/posts returning Stock post IDs.
const SDP_REPORTED_STOCK_TICKER      = 'symbol'; // ACF field for stock ticker ID
const SDP_STOCK_POST_TYPE            = 'stock';
const SDP_REPORT_POST_TYPE           = 'report';
const SDP_APPLY_TO_POST_TYPES        = [SDP_REPORT_POST_TYPE, 'post']; // auto-apply to reports and regular blog posts

const SDP_COMPANY_TABLE              = 'stock_company_info';

/**
 * Register taxonomies: sector, industry
 */
add_action('init', function () {

    $common = [
        'public'            => true,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'show_in_nav_menus' => true,
        'show_tagcloud'     => false,
        'show_admin_column' => true,
        'show_in_rest'      => true, // REST + editor support
        'query_var'         => true,
        'rewrite'           => true,
        'meta_box_cb'       => null, // Hide default meta boxes on posts (we’ll manage automatically)
    ];

    // sector – top-level grouping (flat is fine; hierarchical also OK)
    register_taxonomy('sector', [SDP_STOCK_POST_TYPE, SDP_REPORT_POST_TYPE, 'post'], array_merge($common, [
        'label'        => 'Sectors',
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'sector'],
    ]));

    // industry – individual industry terms; map to a sector via term meta
    register_taxonomy('industry', [SDP_STOCK_POST_TYPE, SDP_REPORT_POST_TYPE, 'post'], array_merge($common, [
        'label'        => 'Industries',
        'hierarchical' => false,
        'rewrite'      => ['slug' => 'industry'],
    ]));
}, 5);

function sdp_get_or_create_sector(string $name): ?WP_Term {
    $name = trim($name);
    if ($name === '') return null;

    $term = get_term_by('name', $name, 'sector');
    if ($term && !is_wp_error($term)) return $term;

    $created = wp_insert_term($name, 'sector', ['slug' => sanitize_title($name)]);
    if (is_wp_error($created)) {
        // Race condition fallback
        $term = get_term_by('name', $name, 'sector');
        return $term && !is_wp_error($term) ? $term : null;
    }
    return get_term($created['term_id'], 'sector');
}

function sdp_get_or_create_industry(string $name, ?WP_Term $sector_term = null): ?WP_Term {
    $name = trim($name);
    if ($name === '') return null;

    $term = get_term_by('name', $name, 'industry');
    if ($term && !is_wp_error($term)) {
        // Ensure mapping if sector provided and not set
        if ($sector_term && !sdp_industry_has_sector($term->term_id, $sector_term->term_id)) {
            update_term_meta($term->term_id, 'sector_term_id', (int)$sector_term->term_id);
        }
        return $term;
    }

    $created = wp_insert_term($name, 'industry', ['slug' => sanitize_title($name)]);
    if (is_wp_error($created)) {
        $term = get_term_by('name', $name, 'industry');
        if ($term && !is_wp_error($term)) {
            if ($sector_term && !sdp_industry_has_sector($term->term_id, $sector_term->term_id)) {
                update_term_meta($term->term_id, 'sector_term_id', (int)$sector_term->term_id);
            }
            return $term;
        }
        return null;
    }

    $term = get_term($created['term_id'], 'industry');
    if ($term && $sector_term) {
        update_term_meta($term->term_id, 'sector_term_id', (int)$sector_term->term_id);
    }
    return $term ?: null;
}

function sdp_industry_has_sector(int $industry_term_id, int $sector_term_id): bool {
    $set = (int) get_term_meta($industry_term_id, 'sector_term_id', true);
    return $set === $sector_term_id;
}

/**
 * STOCK ← DB: read sector/industry for a stock post_id
 */
function sdp_get_stock_sector_industry_from_db(int $stock_post_id): array {
    global $wpdb;
    $table = $wpdb->prefix . SDP_COMPANY_TABLE;

    // Get the ticker_id from the acf field from stock post
    $ticker_id = (int) get_field('ticker_id', $stock_post_id);

    // Example: direct mapping by stock post ID
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT sector, industry FROM {$table} WHERE ticker_id = %d LIMIT 1",
        $ticker_id
    ), ARRAY_A);

    if (!$row) return [null, null];

    $sector   = isset($row['sector'])   ? trim((string)$row['sector'])   : null;
    $industry = isset($row['industry']) ? trim((string)$row['industry']) : null;

    return [$sector ?: null, $industry ?: null];
}

/**
 * Assign sector/industry terms to a given post (merge with existing; idempotent)
 */
function sdp_assign_terms(int $post_id, array $sector_names, array $industry_pairs): void {
    // Build sector terms
    $sector_ids = [];
    foreach (array_filter(array_unique(array_map('trim', $sector_names))) as $s) {
        $t = sdp_get_or_create_sector($s);
        if ($t) $sector_ids[] = (int)$t->term_id;
    }

    // Build industry terms (and map to sectors when known)
    $industry_ids = [];
    foreach ($industry_pairs as $pair) {
        [$industry, $sector_for_industry] = $pair + [null, null];
        if (!$industry) continue;

        $sector_term = null;
        if ($sector_for_industry) {
            $sector_term = sdp_get_or_create_sector($sector_for_industry);
            if ($sector_term) $sector_ids[] = (int)$sector_term->term_id;
        }
        $ind = sdp_get_or_create_industry($industry, $sector_term);
        if ($ind) $industry_ids[] = (int)$ind->term_id;
    }

    $sector_ids   = array_values(array_unique($sector_ids));
    $industry_ids = array_values(array_unique($industry_ids));

    if (!empty($sector_ids)) {
        wp_set_post_terms($post_id, $sector_ids, 'sector', false /* replace existing sectors */);
    } else {
        // If you prefer to keep existing sectors when none found, set true instead.
        wp_set_post_terms($post_id, [], 'sector', false);
    }

    if (!empty($industry_ids)) {
        wp_set_post_terms($post_id, $industry_ids, 'industry', false /* replace existing industries */);
    } else {
        wp_set_post_terms($post_id, [], 'industry', false);
    }
}

/**
 * STOCK: on save, sync sector/industry from DB
 */
add_action('save_post_' . SDP_STOCK_POST_TYPE, function ($post_id) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    static $guard = false; if ($guard) return; $guard = true;

    [$sector, $industry] = sdp_get_stock_sector_industry_from_db((int)$post_id);

    $sectors   = $sector ? [$sector] : [];
    $industries = $industry ? [[$industry, $sector]] : [];

    sdp_assign_terms((int)$post_id, $sectors, $industries);

    $guard = false;
}, 10);

/**
 * REPORTS/BLOG POSTS: on save, aggregate from ACF relationship to Stocks
 */
foreach (SDP_APPLY_TO_POST_TYPES as $ptype) {
    add_action('save_post_' . $ptype, function ($post_id) use ($ptype) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        static $guard = false; if ($guard) return; $guard = true;

        // Collect mentioned stocks
        $stock_ids = [];
        if (function_exists('get_field')) {
            $raw = get_field(SDP_ACF_FIELD_MENTIONED_STOCKS, $post_id, false);
            if (!$raw) {
                $raw = get_field(SDP_REPORTED_STOCK_TICKER, $post_id, false);
            }
            if (is_array($raw)) {
                $stock_ids = array_values(array_unique(array_map('intval', $raw)));
            }
        }

        // Aggregate sectors/industries from each stock
        $sectors    = [];
        $industries = [];

        foreach ($stock_ids as $sid) {
            $stock_sectors   = wp_get_post_terms($sid, 'sector', ['fields' => 'names']);
            $stock_industry_terms = wp_get_post_terms($sid, 'industry');

            if (!is_wp_error($stock_sectors) && $stock_sectors) {
                $sectors = array_merge($sectors, $stock_sectors);
            }

            if (!is_wp_error($stock_industry_terms) && $stock_industry_terms) {
                foreach ($stock_industry_terms as $t) {
                    $linked_sector_id = (int) get_term_meta($t->term_id, 'sector_term_id', true);
                    $linked_sector    = $linked_sector_id ? get_term($linked_sector_id, 'sector') : null;
                    $sector_name      = ($linked_sector && !is_wp_error($linked_sector)) ? $linked_sector->name : null;
                    $industries[] = [$t->name, $sector_name];
                    if ($sector_name) $sectors[] = $sector_name; // ensure post gets sector too
                }
            }
        }

        $sectors    = array_values(array_unique(array_filter(array_map('trim', $sectors))));
        $industries = array_values($industries);

        // Assign to this post
        sdp_assign_terms((int)$post_id, $sectors, $industries);

        $guard = false;
    }, 10);
}

/**
 * Optional: hide taxonomy meta boxes from reports/posts (since we auto-assign)
 * Keep them visible on Stock for debugging or leave hidden—your call.
 */
add_action('add_meta_boxes', function () {
    foreach (SDP_APPLY_TO_POST_TYPES as $ptype) {
        remove_meta_box('tagsdiv-sector',   $ptype, 'side'); // sector
        remove_meta_box('tagsdiv-industry', $ptype, 'side'); // industry
    }
    // If you want to also hide on stocks:
    // remove_meta_box('tagsdiv-sector',   SDP_STOCK_POST_TYPE, 'side');
    // remove_meta_box('tagsdiv-industry', SDP_STOCK_POST_TYPE, 'side');
}, 20);

/**
 * WP-CLI backfills
 *   wp sdp:backfill stocks
 *   wp sdp:backfill content
 */
if (defined('WP_CLI')) {
    WP_CLI::add_command('sdp:backfill', function ($args) {
        [$what] = $args + [null];
        if (!in_array($what, ['stocks', 'content'], true)) {
            WP_CLI::error("Usage: wp sdp:backfill <stocks|content>");
        }

        if ($what === 'stocks') {
            $q = new WP_Query([
                'post_type'      => SDP_STOCK_POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            foreach ($q->posts as $pid) {
                [$sector, $industry] = sdp_get_stock_sector_industry_from_db((int)$pid);
                $sectors    = $sector ? [$sector] : [];
                $industries = $industry ? [[$industry, $sector]] : [];
                sdp_assign_terms((int)$pid, $sectors, $industries);
                WP_CLI::log("Stock #$pid synced.");
            }
            WP_CLI::success('Stocks backfilled.');
        } else {
            $q = new WP_Query([
                'post_type'      => SDP_APPLY_TO_POST_TYPES,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            foreach ($q->posts as $pid) {
                // simulate save -> aggregate from ACF
                $stock_ids = [];
                if (function_exists('get_field')) {
                    $raw = get_field(SDP_ACF_FIELD_MENTIONED_STOCKS, $pid, false);
                    if (!$raw) {
                        $raw = get_field(SDP_REPORTED_STOCK_TICKER, $pid, false);
                    }
                    if (is_array($raw)) $stock_ids = array_values(array_unique(array_map('intval', $raw)));
                }
                $sectors = [];
                $industries = [];

                foreach ($stock_ids as $sid) {
                    $stock_sectors = wp_get_post_terms($sid, 'sector', ['fields' => 'names']);
                    $stock_industry_terms = wp_get_post_terms($sid, 'industry');

                    if (!is_wp_error($stock_sectors) && $stock_sectors) {
                        $sectors = array_merge($sectors, $stock_sectors);
                    }
                    if (!is_wp_error($stock_industry_terms) && $stock_industry_terms) {
                        foreach ($stock_industry_terms as $t) {
                            $linked_sector_id = (int) get_term_meta($t->term_id, 'sector_term_id', true);
                            $linked_sector    = $linked_sector_id ? get_term($linked_sector_id, 'sector') : null;
                            $sector_name      = ($linked_sector && !is_wp_error($linked_sector)) ? $linked_sector->name : null;
                            $industries[] = [$t->name, $sector_name];
                            if ($sector_name) $sectors[] = $sector_name;
                        }
                    }
                }
                $sectors = array_values(array_unique(array_filter(array_map('trim', $sectors))));
                sdp_assign_terms((int)$pid, $sectors, $industries);
                WP_CLI::log("Content #$pid synced.");
            }
            WP_CLI::success('Reports/Posts backfilled.');
        }
    });
}