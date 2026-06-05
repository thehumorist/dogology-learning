<?php
/**
 * WP-CLI commands for Dogology Learning.
 *
 * Reads and manages the diagnostic event log written by the player when
 * it detects mobile playback failure modes. Only loaded under WP-CLI;
 * has no effect on web requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Dogology_Learning_CLI
{
    const OPTION_KEY = 'dogology_learning_video_diag';

    /**
     * List diagnostic events captured from the player.
     *
     * ## OPTIONS
     *
     * [--evt=<evt>]
     * : Filter to a single event name (e.g. yt_api_no_onready).
     *
     * [--limit=<limit>]
     * : Show only the last N entries, newest first.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp dl-diag list
     *     wp dl-diag list --evt=yt_api_no_onready
     *     wp dl-diag list --limit=10 --format=json
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        $log = $this->load();
        $evt_filter = isset($assoc_args['evt']) ? (string) $assoc_args['evt'] : '';
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 50;
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';

        if ($evt_filter !== '') {
            $log = array_values(array_filter($log, function ($e) use ($evt_filter) {
                return isset($e['evt']) && $e['evt'] === $evt_filter;
            }));
        }

        // Newest first — the option stores oldest-first as an append log.
        $log = array_reverse($log);
        if ($limit > 0) {
            $log = array_slice($log, 0, $limit);
        }

        if (empty($log)) {
            WP_CLI::log('No matching diagnostic events.');
            return;
        }

        $rows = array_map(function ($e) {
            return array(
                'ts' => isset($e['ts']) ? gmdate('Y-m-d H:i:s', (int) $e['ts']) . 'Z' : '',
                'evt' => isset($e['evt']) ? $e['evt'] : '',
                'detail' => isset($e['detail']) ? $e['detail'] : '',
                'ua' => isset($e['ua']) ? $e['ua'] : '',
            );
        }, $log);

        WP_CLI\Utils\format_items($format, $rows, array('ts', 'evt', 'detail', 'ua'));
    }

    /**
     * Show counts grouped by event name.
     *
     * Useful at-a-glance check after a deploy to see whether the failure
     * modes the player code beacons about are actually firing in the wild.
     *
     * ## EXAMPLES
     *
     *     wp dl-diag count
     *
     * @when after_wp_load
     */
    public function count($args, $assoc_args)
    {
        $log = $this->load();
        if (empty($log)) {
            WP_CLI::log('No diagnostic events recorded.');
            return;
        }

        $counts = array();
        foreach ($log as $entry) {
            $evt = isset($entry['evt']) ? $entry['evt'] : '(unknown)';
            $counts[$evt] = isset($counts[$evt]) ? $counts[$evt] + 1 : 1;
        }
        arsort($counts);

        $rows = array();
        foreach ($counts as $evt => $n) {
            $rows[] = array('evt' => $evt, 'count' => $n);
        }
        WP_CLI\Utils\format_items('table', $rows, array('evt', 'count'));
        WP_CLI::log(sprintf('Total: %d events (ring buffer capped at 200).', count($log)));
    }

    /**
     * Clear the diagnostic event log.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp dl-diag clear --yes
     *
     * @when after_wp_load
     */
    public function clear($args, $assoc_args)
    {
        WP_CLI::confirm('Clear all recorded diagnostic events?', $assoc_args);
        delete_option(self::OPTION_KEY);
        WP_CLI::success('Diagnostic log cleared.');
    }

    private function load()
    {
        $log = get_option(self::OPTION_KEY, array());
        return is_array($log) ? $log : array();
    }
}

WP_CLI::add_command('dl-diag', 'Dogology_Learning_CLI');

/**
 * WP-CLI commands for reading the student login-environment log
 * (wp_dogology_login_events). Answers "which browser does this student log in
 * with?" and "how common are in-app webviews across all logins?" — the baseline
 * the anonymous, failure-only video-diag log can't give you.
 */
class Dogology_Learning_Logins_CLI
{
    private function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'dogology_login_events';
    }

    /**
     * List recent login events, newest first.
     *
     * ## OPTIONS
     *
     * [--user=<user_id>]
     * : Filter to a single student (dogology_users.id).
     *
     * [--inapp]
     * : Show only in-app webview events (LINE/Facebook/Instagram/TikTok/WebView).
     *
     * [--type=<type>]
     * : Filter by event type. 'session' = browsing a lesson/video page,
     *   'login' = the login moment.
     * ---
     * options:
     *   - session
     *   - login
     * ---
     *
     * [--limit=<limit>]
     * : Max rows to show.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp dl-logins list
     *     wp dl-logins list --type=session --inapp
     *     wp dl-logins list --user=42
     *     wp dl-logins list --inapp --limit=100
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        global $wpdb;
        $t = $this->table();
        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 50;
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';

        $where = array('1=1');
        $params = array();
        if (isset($assoc_args['user'])) {
            $where[] = 'l.user_id = %d';
            $params[] = (int) $assoc_args['user'];
        }
        if (isset($assoc_args['type'])) {
            $where[] = 'l.event_type = %s';
            $params[] = (string) $assoc_args['type'];
        }
        if (isset($assoc_args['inapp'])) {
            $where[] = 'l.is_inapp = 1';
        }
        $where_sql = implode(' AND ', $where);

        $u = $wpdb->prefix . 'dogology_users';
        $sql = "SELECT l.logged_in_at, l.event_type, l.user_id, u.display_name, u.email,
                       l.browser, l.is_inapp, l.ip, l.ua
                FROM $t l LEFT JOIN $u u ON u.id = l.user_id
                WHERE $where_sql
                ORDER BY l.logged_in_at DESC
                LIMIT %d";
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        if (empty($rows)) {
            WP_CLI::log('No events recorded yet.');
            return;
        }

        $rows = array_map(function ($r) {
            return array(
                'logged_in_at' => $r['logged_in_at'],
                'type'         => $r['event_type'],
                'user_id'      => $r['user_id'],
                'name'         => $r['display_name'],
                'email'        => $r['email'],
                'browser'      => $r['browser'],
                'in_app'       => $r['is_inapp'] ? 'YES' : '',
                'ip'           => $r['ip'],
            );
        }, $rows);

        WP_CLI\Utils\format_items($format, $rows, array('logged_in_at', 'type', 'user_id', 'name', 'email', 'browser', 'in_app', 'ip'));
    }

    /**
     * Browser distribution across recorded events.
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Restrict to events within the last N days.
     *
     * [--type=<type>]
     * : Filter by event type ('session' = browsing, 'login' = login moment).
     *   Omit to include both.
     * ---
     * options:
     *   - session
     *   - login
     * ---
     *
     * ## EXAMPLES
     *
     *     wp dl-logins browsers
     *     wp dl-logins browsers --type=session --days=30
     *
     * @when after_wp_load
     */
    public function browsers($args, $assoc_args)
    {
        global $wpdb;
        $t = $this->table();

        $where = array('1=1');
        $params = array();
        if (isset($assoc_args['days'])) {
            $where[] = 'logged_in_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[] = (int) $assoc_args['days'];
        }
        if (isset($assoc_args['type'])) {
            $where[] = 'event_type = %s';
            $params[] = (string) $assoc_args['type'];
        }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT browser, is_inapp, COUNT(*) AS n
                FROM $t WHERE $where_sql
                GROUP BY browser, is_inapp ORDER BY n DESC";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
                        : $wpdb->get_results($sql, ARRAY_A);

        if (empty($rows)) {
            WP_CLI::log('No events recorded yet.');
            return;
        }

        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['n'];
        }
        $out = array_map(function ($r) use ($total) {
            $n = (int) $r['n'];
            return array(
                'browser' => $r['browser'],
                'in_app'  => $r['is_inapp'] ? 'YES' : '',
                'logins'  => $n,
                'pct'     => $total ? round($n * 100 / $total, 1) . '%' : '0%',
            );
        }, $rows);

        WP_CLI\Utils\format_items('table', $out, array('browser', 'in_app', 'logins', 'pct'));
        WP_CLI::log(sprintf('Total logins: %d', $total));
    }
}

WP_CLI::add_command('dl-logins', 'Dogology_Learning_Logins_CLI');
