<?php
/**
 * Logins — browser / in-app webview diagnostics.
 *
 * Reads wp_dogology_login_events (written by Dogology_Auth::login_student) to
 * answer: which browsers do students log in with, and how many are stuck in an
 * embedded webview (LINE/FB/IG) where the YouTube iframe player tends to fail?
 * Pair the "in-app" rows here with the player's video-diag beacons (wp dl-diag)
 * to confirm "can't play video" reports.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$t_logins = $wpdb->prefix . 'dogology_login_events';
$t_users  = $wpdb->prefix . 'dogology_users';

// Table may not exist yet if the upgrade hasn't run (fresh deploy, no admin
// page load). Detect and show a friendly notice instead of a SQL error.
$table_exists = $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $t_logins)
) === $t_logins;

// --- Filters (all GET, read-only page — no nonce needed for a pure report) ---
$days_opt   = isset($_GET['days']) ? sanitize_key($_GET['days']) : '30';
$valid_days = array('7' => 7, '30' => 30, '90' => 90, 'all' => 0);
if (!isset($valid_days[$days_opt])) {
    $days_opt = '30';
}
$days       = $valid_days[$days_opt];
$inapp_only = !empty($_GET['inapp']);
$search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

// Shared WHERE builder.
$where  = array('1=1');
$params = array();
if ($days > 0) {
    $where[]  = 'l.logged_in_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
    $params[] = $days;
}
if ($inapp_only) {
    $where[] = 'l.is_inapp = 1';
}
if ($search !== '') {
    $where[]  = '(u.display_name LIKE %s OR u.email LIKE %s)';
    $like     = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $like;
    $params[] = $like;
}
$where_sql = implode(' AND ', $where);

// --- Data ---
$dist = array();
$total_logins = 0;
$inapp_logins = 0;
$rows = array();
$total_rows = 0;
$total_pages = 0;
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 50;

if ($table_exists) {
    // Distribution (respects day + search filters, ignores the in-app toggle so
    // the summary always shows the full split).
    $dist_where  = array('1=1');
    $dist_params = array();
    if ($days > 0) {
        $dist_where[]  = 'l.logged_in_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
        $dist_params[] = $days;
    }
    if ($search !== '') {
        $dist_where[]  = '(u.display_name LIKE %s OR u.email LIKE %s)';
        $like          = '%' . $wpdb->esc_like($search) . '%';
        $dist_params[] = $like;
        $dist_params[] = $like;
    }
    $dist_where_sql = implode(' AND ', $dist_where);
    $dist_sql = "SELECT l.browser, l.is_inapp, COUNT(*) AS n
                 FROM $t_logins l LEFT JOIN $t_users u ON u.id = l.user_id
                 WHERE $dist_where_sql
                 GROUP BY l.browser, l.is_inapp
                 ORDER BY n DESC";
    $dist = $dist_params
        ? $wpdb->get_results($wpdb->prepare($dist_sql, $dist_params))
        : $wpdb->get_results($dist_sql);
    foreach ($dist as $d) {
        $total_logins += (int) $d->n;
        if ((int) $d->is_inapp === 1) {
            $inapp_logins += (int) $d->n;
        }
    }

    // Count for pagination (respects all filters incl. in-app toggle).
    $count_sql = "SELECT COUNT(*)
                  FROM $t_logins l LEFT JOIN $t_users u ON u.id = l.user_id
                  WHERE $where_sql";
    $total_rows = (int) ($params
        ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
        : $wpdb->get_var($count_sql));
    $total_pages = (int) ceil($total_rows / $per_page);
    $offset = ($page - 1) * $per_page;

    $list_sql = "SELECT l.logged_in_at, l.user_id, l.browser, l.is_inapp, l.ip, l.ua,
                        u.display_name, u.email
                 FROM $t_logins l LEFT JOIN $t_users u ON u.id = l.user_id
                 WHERE $where_sql
                 ORDER BY l.logged_in_at DESC
                 LIMIT %d OFFSET %d";
    $list_params = array_merge($params, array($per_page, $offset));
    $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params));
}

$inapp_pct = $total_logins > 0 ? round($inapp_logins * 100 / $total_logins, 1) : 0;

// Helper for building filter URLs while preserving other params.
$base_args = array('page' => 'dogology-learning-logins');
if ($search !== '') {
    $base_args['s'] = $search;
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Logins — Browser Diagnostics</h1>
    <hr class="wp-header-end">

    <?php if (!$table_exists): ?>
        <div class="notice notice-warning">
            <p>
                The login-events table doesn't exist yet. It's created automatically
                on the first admin page load after the plugin upgrades — if you're
                seeing this, the upgrade hasn't run. Try deactivating and reactivating
                the plugin, or run <code>wp dl-logins list</code> once.
            </p>
        </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <p class="description" style="margin-bottom:16px;">
        Each successful student login records its browser environment. <strong>In-app</strong>
        means an embedded webview (LINE, Facebook, Instagram, TikTok) — the environment
        where the YouTube video player most often fails. Cross-reference in-app users
        against playback failures (<code>wp dl-diag list</code>).
    </p>

    <!-- Filter bar -->
    <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
        <div>
            <strong>Range:</strong>
            <?php
            $ranges = array('7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time');
            $links = array();
            foreach ($ranges as $key => $label) {
                $args = $base_args;
                $args['days'] = $key;
                if ($inapp_only) {
                    $args['inapp'] = 1;
                }
                $url = admin_url('admin.php?' . http_build_query($args));
                if ($key === $days_opt) {
                    $links[] = '<strong>' . esc_html($label) . '</strong>';
                } else {
                    $links[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
                }
            }
            echo implode(' &nbsp;|&nbsp; ', $links);
            ?>
        </div>
        <div>
            <?php
            $toggle_args = $base_args;
            $toggle_args['days'] = $days_opt;
            if (!$inapp_only) {
                $toggle_args['inapp'] = 1;
            }
            $toggle_url = admin_url('admin.php?' . http_build_query($toggle_args));
            ?>
            <a href="<?php echo esc_url($toggle_url); ?>" class="button <?php echo $inapp_only ? 'button-primary' : ''; ?>">
                <?php echo $inapp_only ? '✓ In-app only' : 'Show in-app only'; ?>
            </a>
        </div>
        <form method="get" style="margin-left:auto;">
            <input type="hidden" name="page" value="dogology-learning-logins">
            <input type="hidden" name="days" value="<?php echo esc_attr($days_opt); ?>">
            <?php if ($inapp_only): ?><input type="hidden" name="inapp" value="1"><?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name or email">
            <button type="submit" class="button">Search</button>
            <?php if ($search !== ''): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dogology-learning-logins&days=' . $days_opt . ($inapp_only ? '&inapp=1' : ''))); ?>" class="button-link">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="display:flex; gap:20px; align-items:flex-start;">

        <!-- LEFT: recent logins -->
        <div style="flex:2; min-width:0;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:150px;">When</th>
                        <th>Student</th>
                        <th style="width:150px;">Browser</th>
                        <th style="width:120px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td title="<?php echo esc_attr($r->logged_in_at); ?>">
                                    <?php echo esc_html(date_i18n('j M Y, H:i', strtotime($r->logged_in_at))); ?>
                                </td>
                                <td>
                                    <strong><?php echo $r->display_name ? esc_html($r->display_name) : '(deleted #' . intval($r->user_id) . ')'; ?></strong>
                                    <?php if ($r->email): ?>
                                        <div style="color:#646970; font-size:12px;"><?php echo esc_html($r->email); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $r->is_inapp === 1): ?>
                                        <span style="background:#fcf0e6; color:#bd5a00; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; border:1px solid #f5c99b;"
                                              title="<?php echo esc_attr($r->ua); ?>">
                                            ⚠ <?php echo esc_html($r->browser); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#1e7e34;" title="<?php echo esc_attr($r->ua); ?>">
                                            <?php echo esc_html($r->browser); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-variant-numeric:tabular-nums; color:#646970; font-size:12px;">
                                    <?php echo esc_html($r->ip); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No logins recorded for this filter.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format_i18n($total_rows); ?> logins</span>
                        <?php
                        $pag_args = $base_args;
                        $pag_args['days'] = $days_opt;
                        if ($inapp_only) {
                            $pag_args['inapp'] = 1;
                        }
                        echo paginate_links(array(
                            'base'      => admin_url('admin.php') . '?' . http_build_query($pag_args) . '&paged=%#%',
                            'format'    => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total'     => $total_pages,
                            'current'   => $page,
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: distribution summary -->
        <div style="flex:1; min-width:280px;">
            <div class="postbox" style="padding:15px; border-top:4px solid #bd5a00;">
                <h2 class="hndle" style="margin-top:0;">In-app webviews</h2>
                <p style="font-size:32px; font-weight:700; margin:0; line-height:1; color:<?php echo $inapp_pct >= 20 ? '#bd5a00' : '#1e7e34'; ?>;">
                    <?php echo esc_html($inapp_pct); ?>%
                </p>
                <p style="color:#646970; margin:6px 0 0;">
                    <?php echo number_format_i18n($inapp_logins); ?> of <?php echo number_format_i18n($total_logins); ?> logins
                    <?php echo $days > 0 ? 'in the last ' . intval($days) . ' days' : 'all time'; ?>.
                    These are the at-risk video sessions.
                </p>
            </div>

            <div class="postbox" style="padding:15px;">
                <h2 class="hndle" style="margin-top:0;">Browser distribution</h2>
                <table class="widefat striped" style="border:none;">
                    <tbody>
                        <?php if (!empty($dist)): ?>
                            <?php foreach ($dist as $d):
                                $n = (int) $d->n;
                                $pct = $total_logins > 0 ? round($n * 100 / $total_logins, 1) : 0;
                                $is_inapp = (int) $d->is_inapp === 1;
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($is_inapp): ?><span title="In-app webview">⚠</span> <?php endif; ?>
                                        <?php echo esc_html($d->browser ?: '(unknown)'); ?>
                                    </td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;">
                                        <?php echo number_format_i18n($n); ?> &middot; <?php echo esc_html($pct); ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td>No data yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-bottom:0;">⚠ = embedded webview, not a standalone browser.</p>
            </div>
        </div>
    </div>
</div>
