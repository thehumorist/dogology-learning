<?php
// Save Logic
if (
    isset($_POST['dogology_learning_settings_nonce']) && check_admin_referer(
        'dogology_learning_save_settings',
        'dogology_learning_settings_nonce'
    )
) {
    // LIFF
    if (isset($_POST['dl_liff_id'])) {
        update_option('dogology_learning_liff_id', sanitize_text_field($_POST['dl_liff_id']));
    }
    if (isset($_POST['dl_channel_secret'])) {
        update_option('dogology_learning_channel_secret', sanitize_text_field($_POST['dl_channel_secret']));
    }

    // Dashboard UI - Global
    if (isset($_POST['dl_logo_url']))
        update_option('dl_logo_url', esc_url_raw($_POST['dl_logo_url']));

    // Dashboard UI - THAI
    if (isset($_POST['dl_dash_title']))
        update_option('dl_dash_title', sanitize_text_field($_POST['dl_dash_title']));
    if (isset($_POST['dl_dash_subtitle']))
        update_option(
            'dl_dash_subtitle',
            sanitize_text_field($_POST['dl_dash_subtitle'])
        );
    if (isset($_POST['dl_empty_title']))
        update_option('dl_empty_title', sanitize_text_field($_POST['dl_empty_title']));
    if (isset($_POST['dl_empty_desc']))
        update_option('dl_empty_desc', sanitize_textarea_field($_POST['dl_empty_desc']));
    if (isset($_POST['dl_btn_text']))
        update_option('dl_btn_text', sanitize_text_field($_POST['dl_btn_text']));

    // Dashboard UI - ENGLISH
    if (isset($_POST['dl_dash_title_en']))
        update_option(
            'dl_dash_title_en',
            sanitize_text_field($_POST['dl_dash_title_en'])
        );
    if (isset($_POST['dl_dash_subtitle_en']))
        update_option(
            'dl_dash_subtitle_en',
            sanitize_text_field($_POST['dl_dash_subtitle_en'])
        );
    if (isset($_POST['dl_empty_title_en']))
        update_option(
            'dl_empty_title_en',
            sanitize_text_field($_POST['dl_empty_title_en'])
        );
    if (isset($_POST['dl_empty_desc_en']))
        update_option(
            'dl_empty_desc_en',
            sanitize_textarea_field($_POST['dl_empty_desc_en'])
        );
    if (isset($_POST['dl_btn_text_en']))
        update_option('dl_btn_text_en', sanitize_text_field($_POST['dl_btn_text_en']));

    // Button Link (Shared or split? Let's keep shared for now unless requested, usually link is same)
    if (isset($_POST['dl_btn_link']))
        update_option('dl_btn_link', sanitize_text_field($_POST['dl_btn_link']));

    echo '<div class="notice notice-success is-dismissible">
    <p>Settings saved.</p>
</div>';
}

// Fetch Options
$liff_id = get_option('dogology_learning_liff_id', '');
$channel_secret = get_option('dogology_learning_channel_secret', '');
$logo_url = get_option('dl_logo_url', '');

// TH Defaults
$dash_title = get_option('dl_dash_title', 'ห้องเรียนของฉัน');
$dash_subtitle = get_option('dl_dash_subtitle', 'ยินดีต้อนรับกลับสู่การเรียนรู้');
$empty_title = get_option('dl_empty_title', 'ยังไม่มีคอร์สเรียน');
$empty_desc = get_option('dl_empty_desc', 'คุณเข้าสู่ระบบสำเร็จแล้ว แต่ยังไม่มีคอร์สที่ลงทะเบียนไว้
เลือกดูคอร์สเรียนที่น่าสนใจเพื่อเริ่มฝึกน้องหมากันเถอะ');
$btn_text = get_option('dl_btn_text', 'ดูคอร์สเรียนทั้งหมด');

// EN Defaults
$dash_title_en = get_option('dl_dash_title_en', 'My Classroom');
$dash_subtitle_en = get_option('dl_dash_subtitle_en', 'Welcome back to learning');
$empty_title_en = get_option('dl_empty_title_en', 'No Courses Found');
$empty_desc_en = get_option('dl_empty_desc_en', 'You have successfully logged in but have no registered courses. Check
out our courses to start training your dog!');
$btn_text_en = get_option('dl_btn_text_en', 'View All Courses');

$btn_link = get_option('dl_btn_link', '/');
?>

<div class="wrap">
    <h1>Dogology Learning Settings</h1>

    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <form method="post">
            <?php wp_nonce_field('dogology_learning_save_settings', 'dogology_learning_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="dl_liff_id">LINE LIFF ID</label></th>
                    <td>
                        <input name="dl_liff_id" type="text" id="dl_liff_id" value="<?php echo esc_attr($liff_id); ?>"
                            class="regular-text" placeholder="1657xxxxxx-AbCdEf" />
                        <p class="description">Endpoint: <code><?php echo home_url('/student-login'); ?></code></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="dl_channel_secret">Channel Secret</label></th>
                    <td>
                        <input name="dl_channel_secret" type="password" id="dl_channel_secret"
                            value="<?php echo esc_attr($channel_secret); ?>" class="regular-text" />
                        <p class="description">Required for Desktop QR Login (Backend Flow).</p>
                    </td>
                </tr>

                <tr>
                    <th colspan="2">
                        <h2 style="margin-bottom:0; padding-top:20px;">🎨 Dashboard Customization</h2>
                        <hr>
                    </th>
                </tr>

                <tr>
                    <th scope="row"><label for="dl_logo_url">Custom Logo URL</label></th>
                    <td>
                        <input name="dl_logo_url" type="text" id="dl_logo_url"
                            value="<?php echo esc_attr($logo_url); ?>" class="large-text" placeholder="https://..." />
                        <p class="description">Leave empty to use default 'Dogology' text logo.</p>
                    </td>
                </tr>

                <!-- Page Title -->
                <tr>
                    <th scope="row"><label>Page Title</label></th>
                    <td>
                        <div style="margin-bottom: 10px;">
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#00AB8E;">TH</span>
                            <input name="dl_dash_title" type="text" value="<?php echo esc_attr($dash_title); ?>"
                                class="regular-text" placeholder="ห้องเรียนของฉัน" />
                        </div>
                        <div>
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#0076BA;">EN</span>
                            <input name="dl_dash_title_en" type="text" value="<?php echo esc_attr($dash_title_en); ?>"
                                class="regular-text" placeholder="My Classroom" />
                        </div>
                    </td>
                </tr>

                <!-- Welcome Message -->
                <tr>
                    <th scope="row"><label>Welcome Message</label></th>
                    <td>
                        <div style="margin-bottom: 10px;">
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#00AB8E;">TH</span>
                            <input name="dl_dash_subtitle" type="text" value="<?php echo esc_attr($dash_subtitle); ?>"
                                class="large-text" placeholder="ยินดีต้อนรับกลับสู่การเรียนรู้" />
                        </div>
                        <div>
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#0076BA;">EN</span>
                            <input name="dl_dash_subtitle_en" type="text"
                                value="<?php echo esc_attr($dash_subtitle_en); ?>" class="large-text"
                                placeholder="Welcome back to learning" />
                        </div>
                    </td>
                </tr>

                <!-- Empty State Title -->
                <tr>
                    <th scope="row"><label>Empty State Title</label></th>
                    <td>
                        <div style="margin-bottom: 10px;">
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#00AB8E;">TH</span>
                            <input name="dl_empty_title" type="text" value="<?php echo esc_attr($empty_title); ?>"
                                class="regular-text" placeholder="ยังไม่มีคอร์สเรียน" />
                        </div>
                        <div>
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#0076BA;">EN</span>
                            <input name="dl_empty_title_en" type="text" value="<?php echo esc_attr($empty_title_en); ?>"
                                class="regular-text" placeholder="No Courses Found" />
                        </div>
                    </td>
                </tr>

                <!-- Empty State Desc -->
                <tr>
                    <th scope="row"><label>Empty State Desc</label></th>
                    <td>
                        <div style="margin-bottom: 10px;">
                            <span
                                style="display:inline-block; width: 30px; font-weight:bold; color:#00AB8E; vertical-align:top;">TH</span>
                            <textarea name="dl_empty_desc" class="large-text"
                                rows="2"><?php echo esc_textarea($empty_desc); ?></textarea>
                        </div>
                        <div>
                            <span
                                style="display:inline-block; width: 30px; font-weight:bold; color:#0076BA; vertical-align:top;">EN</span>
                            <textarea name="dl_empty_desc_en" class="large-text"
                                rows="2"><?php echo esc_textarea($empty_desc_en); ?></textarea>
                        </div>
                    </td>
                </tr>

                <!-- Button Text -->
                <tr>
                    <th scope="row"><label>Action Button Text</label></th>
                    <td>
                        <div style="margin-bottom: 10px;">
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#00AB8E;">TH</span>
                            <input name="dl_btn_text" type="text" value="<?php echo esc_attr($btn_text); ?>"
                                class="regular-text" placeholder="ดูคอร์สเรียนทั้งหมด" />
                        </div>
                        <div>
                            <span style="display:inline-block; width: 30px; font-weight:bold; color:#0076BA;">EN</span>
                            <input name="dl_btn_text_en" type="text" value="<?php echo esc_attr($btn_text_en); ?>"
                                class="regular-text" placeholder="View All Courses" />
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="dl_btn_link">Action Button Link</label></th>
                    <td>
                        <input name="dl_btn_link" type="text" id="dl_btn_link"
                            value="<?php echo esc_attr($btn_link); ?>" class="regular-text" placeholder="/" />
                        <p class="description">Link URL (Shared for both languages)</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
            </p>
        </form>
    </div>
</div>