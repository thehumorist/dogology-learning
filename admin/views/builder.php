<?php
/**
 * Course Builder view. Rendered by Dogology_Learning_Builder::render().
 *
 * Available in scope:
 * - $course (WP_Post, dogology_course)
 * - $tree (array of ['module' => WP_Post, 'lessons' => WP_Post[]])
 * - $linked_cohorts (array of stdClass with id, name)
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap dl-builder-wrap">
    <p class="dl-builder-back">
        <a href="<?php echo esc_url(admin_url('admin.php?page=dogology-learning-courses')); ?>">← All courses</a>
        <span class="dl-builder-sep">│</span>
        <span>Editing:</span>
        <strong><?php echo esc_html($course->post_title); ?></strong>
    </p>

    <div class="dl-builder-header">
        <div class="dl-builder-header-main">
            <label for="dl-course-title">Title</label>
            <input type="text" id="dl-course-title" class="dl-course-title"
                   value="<?php echo esc_attr($course->post_title); ?>"
                   data-course-id="<?php echo esc_attr($course->ID); ?>">
        </div>
        <div class="dl-builder-header-meta">
            <?php if ($linked_cohorts): ?>
                <div class="dl-linked-cohorts">
                    <span class="dl-label">Linked cohorts:</span>
                    <?php foreach ($linked_cohorts as $lc): ?>
                        <span class="dl-badge">📦 <?php echo esc_html($lc->name); ?></span>
                    <?php endforeach; ?>
                    <span class="dl-hint">Manage in Commerce → Cohorts</span>
                </div>
            <?php else: ?>
                <div class="dl-linked-cohorts dl-linked-cohorts--empty">
                    <span class="dl-hint">No cohorts linked yet. Link one in Commerce → Cohorts so buyers auto-enroll.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dl-builder-tree" data-course-id="<?php echo esc_attr($course->ID); ?>">
        <?php if (empty($tree)): ?>
            <div class="dl-empty-state">
                <p>No modules yet. Start by adding one.</p>
                <button type="button" class="button button-primary dl-add-module">+ Add module</button>
            </div>
        <?php else: ?>
            <div class="dl-modules">
                <?php foreach ($tree as $branch):
                    $module = $branch['module'];
                    $lessons = $branch['lessons'];
                ?>
                    <div class="dl-module" data-module-id="<?php echo esc_attr($module->ID); ?>">
                        <div class="dl-module-header">
                            <span class="dl-drag-handle dl-drag-handle--module" title="Drag to reorder">⣿</span>
                            <span class="dl-module-title"><?php echo esc_html($module->post_title); ?></span>
                            <span class="dl-module-actions">
                                <button type="button" class="button-link dl-edit-module">edit</button>
                                <button type="button" class="button-link dl-delete-module">×</button>
                            </span>
                        </div>
                        <div class="dl-lessons">
                            <?php if ($lessons): ?>
                                <?php foreach ($lessons as $lesson):
                                    $duration = get_post_meta($lesson->ID, '_dogology_duration', true);
                                ?>
                                    <div class="dl-lesson" data-lesson-id="<?php echo esc_attr($lesson->ID); ?>">
                                        <span class="dl-drag-handle dl-drag-handle--lesson" title="Drag to reorder or move between modules">⣿</span>
                                        <span class="dl-lesson-title"><?php echo esc_html($lesson->post_title); ?></span>
                                        <?php if ($duration): ?>
                                            <span class="dl-lesson-duration"><?php echo esc_html($duration); ?></span>
                                        <?php endif; ?>
                                        <span class="dl-lesson-actions">
                                            <button type="button" class="button-link dl-edit-lesson">edit</button>
                                            <button type="button" class="button-link dl-delete-lesson">×</button>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="button" class="button-link dl-add-lesson">+ Add lesson</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="dl-add-module-wrap">
                <button type="button" class="button dl-add-module">+ Add module</button>
            </p>
        <?php endif; ?>
    </div>

    <div class="dl-builder-status" aria-live="polite"></div>
</div>

<!-- Lesson edit drawer (hidden by default; opened from a lesson's [edit] button) -->
<div class="dl-drawer" id="dl-lesson-drawer" hidden aria-hidden="true" role="dialog" aria-labelledby="dl-drawer-title">
    <div class="dl-drawer-backdrop" data-dl-close></div>
    <aside class="dl-drawer-panel">
        <header class="dl-drawer-header">
            <h2 id="dl-drawer-title">Edit lesson</h2>
            <button type="button" class="button-link dl-drawer-close" data-dl-close aria-label="Close">×</button>
        </header>
        <form class="dl-drawer-body" id="dl-lesson-form">
            <input type="hidden" name="lesson_id" value="">

            <div class="dl-field">
                <label for="dl-f-title">Title</label>
                <input type="text" id="dl-f-title" name="title" required>
            </div>

            <div class="dl-field">
                <label for="dl-f-subtitle">Subtitle</label>
                <input type="text" id="dl-f-subtitle" name="subtitle">
            </div>

            <div class="dl-field">
                <label for="dl-f-description">Description</label>
                <textarea id="dl-f-description" name="description" rows="6"></textarea>
                <p class="dl-field-help">Plain or light HTML. Rich-text editor can be added later if needed.</p>
            </div>

            <div class="dl-field-row">
                <div class="dl-field">
                    <label for="dl-f-video_url">Video URL</label>
                    <input type="url" id="dl-f-video_url" name="video_url" placeholder="https://youtu.be/...">
                </div>
                <div class="dl-field dl-field--narrow">
                    <label for="dl-f-duration">Duration</label>
                    <input type="text" id="dl-f-duration" name="duration" placeholder="10:00">
                </div>
            </div>

            <fieldset class="dl-fieldset">
                <legend>Attachment</legend>
                <div class="dl-field">
                    <label for="dl-f-attachment_url">URL</label>
                    <input type="url" id="dl-f-attachment_url" name="attachment_url" placeholder="https://.../file.pdf">
                </div>
                <div class="dl-field-row">
                    <div class="dl-field">
                        <label for="dl-f-attachment_title">Title</label>
                        <input type="text" id="dl-f-attachment_title" name="attachment_title">
                    </div>
                    <div class="dl-field">
                        <label for="dl-f-attachment_cta">CTA</label>
                        <input type="text" id="dl-f-attachment_cta" name="attachment_cta" placeholder="Download">
                    </div>
                </div>
                <div class="dl-field">
                    <label for="dl-f-attachment_subtitle">Subtitle</label>
                    <input type="text" id="dl-f-attachment_subtitle" name="attachment_subtitle">
                </div>
            </fieldset>

            <footer class="dl-drawer-footer">
                <span class="dl-drawer-status" aria-live="polite"></span>
                <button type="button" class="button" data-dl-close>Cancel</button>
                <button type="submit" class="button button-primary">Save</button>
            </footer>
        </form>
    </aside>
</div>
