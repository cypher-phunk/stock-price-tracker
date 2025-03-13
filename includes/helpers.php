<?php
if (!defined('ABSPATH')) {
    exit;
}

function sdp_log($message) {
    if (WP_DEBUG === true) {
        error_log('[Stock Data Plugin] ' . print_r($message, true));
    }
}
