<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$posts = get_posts([
    'post_type' => 'sw_content_block',
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
]);

foreach ($posts as $post_id) {
    wp_delete_post($post_id, true);
}

delete_option('swcb_default_layout');
delete_option('swcb_default_design');
delete_option('swcb_default_limit');
