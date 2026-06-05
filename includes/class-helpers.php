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
    public static function get_embed_url($url, $native_controls = false)
    {
        if (empty($url))
            return '';

        // YouTube
        if (preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[2];
            // playsinline=1 keeps iOS Safari from force-fullscreening on play, which
            // some older Samsung/Android WebViews also need to initialize cleanly.
            // controls: normally 0 because we render our own overlay UI. When
            // $native_controls is true (Samsung Internet fallback — our overlay
            // glitches there) we use controls=1 so YouTube's own player UI drives
            // playback. enablejsapi stays on either way so onStateChange still
            // fires for lesson-completion tracking.
            $controls = $native_controls ? '1' : '0';
            $params = "controls={$controls}&enablejsapi=1&rel=0&modestbranding=1&showinfo=0&playsinline=1";

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

    /**
     * Parse a User-Agent string into a short browser label and an in-app flag.
     *
     * The flag is the diagnostic that matters: embedded webviews (LINE, Facebook,
     * Instagram, TikTok) are the environments where the YouTube iframe postMessage
     * channel routinely fails to come alive — the same failure the player beacons
     * as `yt_api_no_onready`. A "real" browser (Chrome/Safari/etc.) almost never
     * hits that. Order matters: in-app shells masquerade as Chrome/Safari, so test
     * them BEFORE the generic browser checks.
     *
     * @param string $ua Raw User-Agent.
     * @return array{label:string,is_inapp:bool}
     */
    public static function parse_user_agent($ua)
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return array('label' => 'Unknown', 'is_inapp' => false);
        }

        // In-app webviews first — these are the suspects.
        if (preg_match('/\bLine\//i', $ua) || stripos($ua, 'LIFF') !== false) {
            return array('label' => 'LINE in-app', 'is_inapp' => true);
        }
        if (strpos($ua, 'FBAN') !== false || strpos($ua, 'FBAV') !== false || strpos($ua, 'FB_IAB') !== false) {
            return array('label' => 'Facebook in-app', 'is_inapp' => true);
        }
        if (strpos($ua, 'Instagram') !== false) {
            return array('label' => 'Instagram in-app', 'is_inapp' => true);
        }
        if (strpos($ua, 'TikTok') !== false || strpos($ua, 'BytedanceWebview') !== false || strpos($ua, 'musical_ly') !== false) {
            return array('label' => 'TikTok in-app', 'is_inapp' => true);
        }
        // Generic Android System WebView (not a standalone browser).
        if (preg_match('/;\s*wv\)/', $ua)) {
            return array('label' => 'Android WebView', 'is_inapp' => true);
        }

        // Standalone browsers.
        if (strpos($ua, 'Edg') !== false) {
            return array('label' => 'Edge', 'is_inapp' => false);
        }
        if (strpos($ua, 'SamsungBrowser') !== false) {
            return array('label' => 'Samsung Internet', 'is_inapp' => false);
        }
        if (strpos($ua, 'Firefox') !== false || strpos($ua, 'FxiOS') !== false) {
            return array('label' => 'Firefox', 'is_inapp' => false);
        }
        if (strpos($ua, 'CriOS') !== false || (strpos($ua, 'Chrome') !== false && strpos($ua, 'Chromium') === false)) {
            return array('label' => 'Chrome', 'is_inapp' => false);
        }
        if (strpos($ua, 'Safari') !== false && strpos($ua, 'Version/') !== false) {
            $os = (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) ? ' (iOS)' : '';
            return array('label' => 'Safari' . $os, 'is_inapp' => false);
        }

        return array('label' => 'Other', 'is_inapp' => false);
    }

    /**
     * Best-effort real client IP, accounting for Cloudflare in front of WP Rocket.
     * REMOTE_ADDR is Cloudflare's edge IP on this site, so prefer CF's header.
     *
     * @return string
     */
    public static function client_ip()
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // First hop is the original client.
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        }
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        return substr($ip, 0, 45);
    }
}
