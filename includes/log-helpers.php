<?php
if (!defined('ABSPATH')) {
    exit;
}

function sdp_log($message) {
    if (WP_DEBUG === true) {
        return error_log('[Stock Data Plugin] ');
    }
}
