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
            // playsinline=1 keeps iOS Safari from force-fullscreening on play, which
            // some older Samsung/Android WebViews also need to initialize cleanly.
            $params = 'controls=0&enablejsapi=1&rel=0&modestbranding=1&showinfo=0&playsinline=1';

            // Only advertise origin when home_url()'s host matches the actual request
            // host. Mismatches (reverse proxy, www-vs-apex, http-vs-https behind SSL
            // terminators) silently break the postMessage channel — the iframe loads
            // but onReady never fires, leaving the player stuck behind the poster.
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $req_host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST']) : '';
            if ($home_host && $req_host && strcasecmp($home_host, $req_host) === 0) {
                $params .= '&origin=' . urlencode(home_url());
            }
            return "https://www.youtube.com/embed/{$video_id}?{$params}";
        }

        // Vimeo
        if (preg_match('/(vimeo\.com\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[2];
            return "https://player.vimeo.com/video/{$video_id}?controls=0&api=1";
        }

        return $url;
    }
}
