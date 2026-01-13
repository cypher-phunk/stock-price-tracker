<?php

/**
 * Admin CSV Import Page for Stock Data Plugin
 *
 * This file contains the HTML and PHP code for the admin CSV import
 * page of the Stock Data Plugin.
 * It includes a form to upload CSV files and handles the import
 * process.
 *
 * @category Admin
 * @package  StockDataPlugin
 * @author   RoDojo Web Development <support@rodojo.dev>
 * @license  MIT https://opensource.org/licenses/MIT
 * @version  GIT: <git_id>
 * @php      version 7.4
 * @link     https://rodojo.dev/
 */
?>

<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Explain to the user what the page does
?>
<div class="wrap">
    <h1><?php esc_html_e('Stock Data CSV Import', 'stock-data-plugin'); ?></h1>
    <p><?php esc_html_e('Use this page to import stock data from a CSV file. The CSV should contain the necessary fields for stock data.', 'stock-data-plugin'); ?></p>
    <?php // Display the CSV upload form 
    ?>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('sdp_csv_import', 'sdp_csv_import_nonce'); ?>
        <input type="file" name="sdp_csv_file" accept=".csv" required />
        <input type="submit" name="sdp_csv_import_submit" class="button button-primary" value="<?php esc_attr_e('Import CSV', 'stock-data-plugin'); ?>" />
    </form>
</div>
<?php
if (isset($_POST['sdp_csv_import_submit'])) {

    // Verify nonce
    if (
        ! isset($_POST['sdp_csv_import_nonce']) ||
        ! wp_verify_nonce($_POST['sdp_csv_import_nonce'], 'sdp_csv_import')
    ) {
        wp_die(__('Security check failed.', 'stock-data-plugin'));
    }

    // Validate uploaded file
    if (
        ! isset($_FILES['sdp_csv_file']) ||
        $_FILES['sdp_csv_file']['error'] !== UPLOAD_ERR_OK
    ) {
        wp_die(__('Please upload a valid CSV file.', 'stock-data-plugin'));
    }

    $csv_path = $_FILES['sdp_csv_file']['tmp_name'];

    // Delegate to the Activ8 import service
    $result = import_reports_from_csv($csv_path);

    if (is_wp_error($result)) {
        echo '<div class="notice notice-error"><p>'
            . esc_html($result->get_error_message())
            . '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>'
            . esc_html__('CSV import completed successfully.', 'stock-data-plugin')
            . '</p></div>';
    }
}



function import_reports_from_csv($csv_path)
{
    if (! file_exists($csv_path)) {
        return new WP_Error('csv_missing', 'CSV file not found.');
    }

    $handle = fopen($csv_path, 'r');
    if (! $handle) {
        return new WP_Error('csv_open_failed', 'Unable to open CSV.');
    }

    $header = fgetcsv($handle, 1000, ',');

    // Remove UTF-8 BOM from first header column if present
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }

    $map = array_flip($header);


    while (($row = fgetcsv($handle)) !== false) {
        if (trim($row[$map['historical_flag']]) !== 'Historical') {
            continue;
        }

        $researcher_name = sanitize_text_field(trim($row[$map['researcher_name']]));
        $researcher_type = sanitize_text_field(trim($row[$map['activist_researcher']]));
        $ticker          = sanitize_text_field(strtoupper(trim($row[$map['ticker']])));
        $report_date     = trim($row[$map['reportdts']]);
        $website = trim($row[$map['website']]);
        $report_link = trim($row[$map['report_link']]);

        if (empty($researcher_name) || empty($ticker)) {
            continue;
        }

        $researcher_id = get_or_create_researcher($researcher_name, $researcher_type, $website);
        $stock_id      = get_or_create_stock($ticker);

        // Check if report post exists by title and date
        $existing_report = [];
        $day_reports = get_posts([
            'post_type'   => 'report',
            'post_status' => 'any',
            'numberposts' => -1,
            'date_query'  => [
                [
                    'year'  => date('Y', strtotime($report_date)),
                    'month' => date('n', strtotime($report_date)),
                    'day'   => date('j', strtotime($report_date)),
                ],
            ],
        ]);

        if (!strpos($csv_path, 'FORCE') !== false) {
            foreach ($day_reports as $report) {
                // Extract words from researcher name, excluding common words
                $common_words = ['the', 'research', 'capital', 'ltd', 'management'];
                $researcher_words = array_filter(explode(' ', strtolower($researcher_name)));
                $researcher_words = array_diff($researcher_words, $common_words);
                
                $title_words = array_filter(explode(' ', strtolower($report->post_title)));
                
                // Check if all meaningful researcher words appear in title
                $matches = 0;
                $total_words = count($researcher_words);
                
                if ($total_words > 0) {
                    foreach ($researcher_words as $word) {
                        if (in_array($word, $title_words)) {
                            $matches++;
                        }
                    }
                    
                    // Consider it a match if at least 75% of meaningful words are found
                    if (($matches / $total_words) >= 0.75) {
                        $existing_report = [$report];
                        break;
                    }
                }
            }
        }


        if ($existing_report) {
            // try to update sectors and industries
            sdp_assign_report_sectors_and_industries($existing_report[0]->ID, $stock_id);
            continue;
        }


        create_report(
            $researcher_id,
            $stock_id,
            $report_date,
            $report_link
        );
    }

    fclose($handle);
}

function get_or_create_researcher($name, $type, $website = '')
{
    $existing = get_posts([
        'post_type'   => 'company',
        'title'       => $name,
        'post_status' => 'any',
        'numberposts' => 1,
    ]);

    if ($existing) {
        // Update the researcher type if it's different
        if (get_field('company_type', $existing[0]->ID) !== $type && $type !== '') {
            sdp_assign_company_type($existing[0]->ID, $type);
        }
        // Update the researcher website if it's different
        if (get_field('company_website', $existing[0]->ID) !== $website && $website !== '') {
            update_field('company_website', $website, $existing[0]->ID);
        }
        return $existing[0]->ID;
    }

    $post_id = wp_insert_post([
        'post_type'   => 'company',
        'post_title'  => $name,
        'post_status' => 'private'
    ]);

    if (! is_wp_error($post_id)) {
        update_field('company_website', $website, $post_id);
        sdp_assign_company_type($post_id, $type);
    }

    return $post_id;
}

function get_or_create_stock($ticker)
{
    $existing = get_posts([
        'post_type'  => 'stock',
        'title'      => $ticker,
        'post_status' => 'any',
        'numberposts' => 1,
    ]);

    if ($existing) {
        return $existing[0]->ID;
    }
    add_stock_ticker($ticker);
    // Get the stock post
    $new_stock = get_posts([
        'post_type'  => 'stock',
        'title'      => $ticker,
        'post_status' => 'any',
        'numberposts' => 1,
    ]);
    return $new_stock[0]->ID;
}

function create_report($researcher_id, $stock_id, $date, $report_link = '')
{
    $post_id = wp_insert_post([
        'post_type'   => 'report',
        'post_title'  => get_the_title($researcher_id) . ' Short Report on ' . get_field('ticker_symbol', $stock_id),
        'post_status' => 'private',
        'post_date' => $date,
    ]);

    $report_type = wp_get_post_terms($researcher_id, 'company_type', array('fields' => 'names'));
    $report_type_value = !empty($report_type) && !is_wp_error($report_type) ? $report_type[0] : null;

    if (!is_wp_error($post_id)) {
        if ($report_type_value) {
            sdp_assign_report_type($post_id, $report_type_value);
        }
        update_field('research_company', (is_numeric($researcher_id) && (int) $researcher_id > 0) ? (int) $researcher_id : null, $post_id);
        update_field('symbol', (is_numeric($stock_id) && (int) $stock_id > 0) ? (int) $stock_id : null, $post_id);
        update_field('report_date', $date, $post_id);
        update_field('url_title', get_the_title($researcher_id) . ' Short Report on ' . get_field('ticker_symbol', $stock_id), $post_id);
        update_field('report_url', $report_link, $post_id);
        sdp_assign_report_sectors_and_industries($post_id, $stock_id);
        wp_update_post(['ID' => $post_id]);
    } else {
        return;
    }
}
