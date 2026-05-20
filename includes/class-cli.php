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
