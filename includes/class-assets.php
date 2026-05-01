<?php

/**
 * Assets Manager
 */
class Dogology_Learning_Assets
{

    public function __construct()
    {
        // Hook into wp_enqueue_scripts
    }

    public function enqueue_scripts()
    {
        // Only load on learning routes
        if (!get_query_var('dl_route'))
            return;

        wp_enqueue_style(
            'dogology-learning-public',
            DOGOLOGY_LEARNING_URL . 'public/css/dogology-learning.css',
            array(),
            DOGOLOGY_LEARNING_VERSION,
            'all'
        );
    }

    public function enqueue_admin_scripts()
    {
        // Admin styling for Course Builder
        wp_enqueue_style(
            'dogology-learning-admin',
            DOGOLOGY_LEARNING_URL . 'assets/css/admin.css',
            array(),
            DOGOLOGY_LEARNING_VERSION,
            'all'
        );
    }
}
