<?php

// CPT Helper Functions

// Add custom column
add_filter('manage_edit-report_columns', 'activ8_add_report_date_column');
function activ8_add_report_date_column($columns) {
    $columns['report_date'] = 'Report Date';
    return $columns;
}

// Show the value in the column
add_action('manage_report_posts_custom_column', 'activ8_show_report_date_column', 10, 2);
function activ8_show_report_date_column($column, $post_id) {
  if ($column === 'report_date') {
      $raw_date = get_field('report_date', $post_id, false); // false = get raw DB value
      if ($raw_date) {
          $date_obj = DateTime::createFromFormat('Ymd', $raw_date);
          echo $date_obj ? $date_obj->format('m/d/Y') : '—';
      } else {
          echo '—';
      }
  }
}


// Make the column sortable
add_filter('manage_edit-report_sortable_columns', 'activ8_make_report_date_sortable');
function activ8_make_report_date_sortable($columns) {
    $columns['report_date'] = 'report_date';
    return $columns;
}

// Sort the posts by the custom field
add_action('pre_get_posts', 'activ8_sort_reports_by_report_date');
function activ8_sort_reports_by_report_date($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->get('post_type') === 'report' && $query->get('orderby') === 'report_date') {
        $query->set('meta_key', 'report_date');
        $query->set('orderby', 'meta_value_num'); // or meta_value_num if it's numeric
        $query->set('order', strtoupper($query->get('order')) === 'ASC' ? 'ASC' : 'DESC');
    }
}

