<?php
/**
 * Dogology Learning Helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dogology_Helpers
{
    /**
     * Get Embed URL
     * Converts standard YouTube/Vimeo URLs to embeddable formats
     */
    public static function get_embed_url($url)
    {
        if (empty($url))
            return '';

        // YouTube
        if (preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[2];
            $origin = urlencode(home_url());
            return "https://www.youtube.com/embed/{$video_id}?controls=0&enablejsapi=1&rel=0&modestbranding=1&showinfo=0&origin={$origin}";
        }

        // Vimeo
        if (preg_match('/(vimeo\.com\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[2];
            return "https://player.vimeo.com/video/{$video_id}?controls=0&api=1";
        }

        return $url;
    }
}
