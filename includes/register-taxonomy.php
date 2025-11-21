<?php

// Register taxonomy for 'company' post type
function register_company_taxonomy() {
    $labels = array(
        'name'              => 'Company Types',
        'singular_name'     => 'Company Type',
        'search_items'      => 'Search Company Types',
        'all_items'         => 'All Company Types',
        'edit_item'         => 'Edit Company Type',
        'update_item'       => 'Update Company Type',
        'add_new_item'      => 'Add New Company Type',
        'new_item_name'     => 'New Company Type Name',
        'menu_name'         => 'Company Types',
    );

    $args = array(
        'hierarchical'      => true, // true = like categories
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'company-type'),
    );

    register_taxonomy('company_type', array('company'), $args);
}
add_action('init', 'register_company_taxonomy');


// Register taxonomy for 'report' post type
function register_report_taxonomy() {
    $labels = array(
        'name'              => 'Report Types',
        'singular_name'     => 'Report Type',
        'search_items'      => 'Search Report Types',
        'all_items'         => 'All Report Types',
        'edit_item'         => 'Edit Report Type',
        'update_item'       => 'Update Report Type',
        'add_new_item'      => 'Add New Report Type',
        'new_item_name'     => 'New Report Type Name',
        'menu_name'         => 'Report Types',
    );

    $args = array(
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'report-type'),
    );

    register_taxonomy('report_type', array('report'), $args);
}
add_action('init', 'register_report_taxonomy');
