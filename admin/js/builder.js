/**
 * Course Builder client (Phase 1b).
 *
 * Wires inline add / edit / delete for modules and lessons, plus the
 * lesson drawer. All mutations go through admin-ajax.php with a
 * `dl_builder` nonce. Drag-and-drop reorder arrives in Phase 1c.
 */
(function ($) {
    'use strict';

    if (typeof DL_BUILDER === 'undefined') {
        return;
    }

    const $root   = $('.dl-builder-tree');
    const $status = $('.dl-builder-status');
    const $drawer = $('#dl-lesson-drawer');
    const $form   = $('#dl-lesson-form');
    const $drawerStatus = $drawer.find('.dl-drawer-status');
    const courseId = parseInt($root.data('course-id'), 10);

    function setStatus($el, msg, kind) {
        $el.removeClass('is-success is-error');
        if (kind) $el.addClass('is-' + kind);
        $el.text(msg || '');
    }

    function ajax(action, data) {
        return $.post(DL_BUILDER.ajaxUrl, Object.assign({
            action: action,
            nonce:  DL_BUILDER.nonce,
        }, data || {})).then(function (res) {
            if (!res || !res.success) {
                const msg = (res && res.data && res.data.message) || 'Request failed';
                return $.Deferred().reject(msg).promise();
            }
            return res.data;
        }, function () {
            return $.Deferred().reject('Network error').promise();
        });
    }

    /* ---------- Inline input helpers ---------- */

    // Replace the clicked "+ Add" button with a text input; commit on Enter, cancel on Esc/blur-empty.
    function openInlineInput($trigger, opts) {
        const $wrap = $('<div class="dl-inline-input"></div>');
        if (opts.extraClass) $wrap.addClass(opts.extraClass);
        const $input = $('<input type="text" maxlength="200">').attr('placeholder', opts.placeholder || 'Title…');
        const $save  = $('<button type="button" class="button button-small button-primary">Add</button>');
        const $cancel = $('<button type="button" class="button button-link">Cancel</button>');
        $wrap.append($input, $save, $cancel);
        $trigger.hide().after($wrap);
        $input.focus();

        function cleanup() {
            $wrap.remove();
            $trigger.show();
        }
        function commit() {
            const val = $input.val().trim();
            if (!val) { cleanup(); return; }
            $input.prop('disabled', true);
            $save.prop('disabled', true);
            opts.onSubmit(val).then(function () {
                cleanup();
            }, function (err) {
                $input.prop('disabled', false);
                $save.prop('disabled', false);
                setStatus($status, err, 'error');
                $input.focus();
            });
        }
        $save.on('click', commit);
        $cancel.on('click', cleanup);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cleanup(); }
        });
    }

    /* ---------- Module + lesson rendering ---------- */

    function renderModule(mod) {
        const $mod = $(
            '<div class="dl-module">' +
              '<div class="dl-module-header">' +
                '<span class="dl-drag-handle dl-drag-handle--module" title="Drag to reorder">⣿</span>' +
                '<span class="dl-module-title"></span>' +
                '<span class="dl-module-actions">' +
                  '<button type="button" class="button-link dl-edit-module">edit</button>' +
                  '<button type="button" class="button-link dl-delete-module">×</button>' +
                '</span>' +
              '</div>' +
              '<div class="dl-lessons">' +
                '<button type="button" class="button-link dl-add-lesson">+ Add lesson</button>' +
              '</div>' +
            '</div>'
        );
        $mod.attr('data-module-id', mod.id);
        $mod.find('.dl-module-title').text(mod.title);
        return $mod;
    }

    function renderLesson(les) {
        const $les = $(
            '<div class="dl-lesson">' +
              '<span class="dl-drag-handle dl-drag-handle--lesson" title="Drag to reorder or move between modules">⣿</span>' +
              '<span class="dl-lesson-title"></span>' +
              '<span class="dl-lesson-duration"></span>' +
              '<span class="dl-lesson-actions">' +
                '<button type="button" class="button-link dl-edit-lesson">edit</button>' +
                '<button type="button" class="button-link dl-delete-lesson">×</button>' +
              '</span>' +
            '</div>'
        );
        $les.attr('data-lesson-id', les.id);
        $les.find('.dl-lesson-title').text(les.title);
        const $dur = $les.find('.dl-lesson-duration');
        if (les.duration) $dur.text(les.duration); else $dur.remove();
        return $les;
    }

    /* ---------- Course title autosave ---------- */

    $('.dl-course-title').on('blur', function () {
        const title = $(this).val().trim();
        if (!title) { setStatus($status, 'Title cannot be empty', 'error'); return; }
        ajax('dl_builder_course_update', { course_id: courseId, title: title })
            .then(function () { setStatus($status, 'Saved', 'success'); },
                  function (err) { setStatus($status, err, 'error'); });
    });

    /* ---------- Add module ---------- */

    $root.on('click', '.dl-add-module', function () {
        const $btn = $(this);
        openInlineInput($btn, {
            placeholder: 'Module title',
            extraClass: 'dl-inline-input--module',
            onSubmit: function (title) {
                return ajax('dl_builder_module_create', { course_id: courseId, title: title })
                    .then(function (data) {
                        const $mod = renderModule(data);
                        // Insert before the page-level "+ Add module" wrapper, or into an empty-state container.
                        let $modules = $root.find('.dl-modules');
                        if (!$modules.length) {
                            // Empty state — build the .dl-modules container and the footer button.
                            $root.empty();
                            $modules = $('<div class="dl-modules"></div>').appendTo($root);
                            $('<p class="dl-add-module-wrap"><button type="button" class="button dl-add-module">+ Add module</button></p>').appendTo($root);
                        }
                        $modules.append($mod);
                        initModulesSortable();
                        initLessonSortable($mod.find('.dl-lessons'));
                        setStatus($status, 'Module added', 'success');
                    });
            },
        });
    });

    /* ---------- Add lesson ---------- */

    $root.on('click', '.dl-add-lesson', function () {
        const $btn = $(this);
        const moduleId = parseInt($btn.closest('.dl-module').data('module-id'), 10);
        if (!moduleId) return;
        openInlineInput($btn, {
            placeholder: 'Lesson title',
            onSubmit: function (title) {
                return ajax('dl_builder_lesson_create', { module_id: moduleId, title: title })
                    .then(function (data) {
                        const $les = renderLesson(data);
                        $btn.before($les);
                        setStatus($status, 'Lesson added', 'success');
                    });
            },
        });
    });

    /* ---------- Edit module (inline rename) ---------- */

    $root.on('click', '.dl-edit-module', function () {
        const $mod = $(this).closest('.dl-module');
        const moduleId = parseInt($mod.data('module-id'), 10);
        const $title = $mod.find('.dl-module-title').first();
        if ($title.hasClass('dl-editing')) return;

        const current = $title.text();
        const $input = $('<input type="text" class="dl-module-title-input" maxlength="200">')
            .val(current);
        $title.addClass('dl-editing').empty().append($input);
        $input.focus().select();

        let done = false;
        function cleanup(newText) {
            done = true;
            $input.off();
            $title.removeClass('dl-editing').empty().text(newText);
        }
        function commit() {
            if (done) return;
            const next = $input.val().trim();
            if (next === '' || next === current) { cleanup(current); return; }
            done = true;
            $input.prop('disabled', true);
            ajax('dl_builder_module_update', { module_id: moduleId, title: next })
                .then(function () {
                    $title.removeClass('dl-editing').empty().text(next);
                    setStatus($status, 'Module updated', 'success');
                }, function (err) {
                    $title.removeClass('dl-editing').empty().text(current);
                    setStatus($status, err, 'error');
                });
        }

        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cleanup(current); }
        });
        $input.on('blur', commit);
    });

    /* ---------- Delete module ---------- */

    $root.on('click', '.dl-delete-module', function () {
        const $mod = $(this).closest('.dl-module');
        const moduleId = parseInt($mod.data('module-id'), 10);
        const lessonCount = $mod.find('.dl-lesson').length;
        const msg = lessonCount
            ? 'Delete this module and its ' + lessonCount + ' lesson(s)? This cannot be undone.'
            : 'Delete this empty module?';
        if (!window.confirm(msg)) return;
        ajax('dl_builder_module_delete', { module_id: moduleId })
            .then(function () { $mod.remove(); setStatus($status, 'Module deleted', 'success'); },
                  function (err) { setStatus($status, err, 'error'); });
    });

    /* ---------- Delete lesson ---------- */

    $root.on('click', '.dl-delete-lesson', function () {
        const $les = $(this).closest('.dl-lesson');
        const lessonId = parseInt($les.data('lesson-id'), 10);
        if (!window.confirm('Delete this lesson? This cannot be undone.')) return;
        ajax('dl_builder_lesson_delete', { lesson_id: lessonId })
            .then(function () { $les.remove(); setStatus($status, 'Lesson deleted', 'success'); },
                  function (err) { setStatus($status, err, 'error'); });
    });

    /* ---------- Lesson drawer ---------- */

    let drawerTrigger = null;
    let richEditorReady = false;
    const DESC_ID = 'dl-f-description';

    function canUseRichEditor() {
        return typeof window.wp !== 'undefined' && window.wp.editor &&
               typeof window.wp.editor.initialize === 'function';
    }

    function ensureRichEditor() {
        if (richEditorReady || !canUseRichEditor()) return;
        window.wp.editor.initialize(DESC_ID, {
            tinymce: {
                wpautop: true,
                toolbar1: 'bold italic underline bullist numlist link unlink blockquote undo redo',
                toolbar2: '',
                height: 220,
            },
            quicktags: true,
            mediaButtons: false,
        });
        richEditorReady = true;
    }

    function setDescription(html) {
        if (richEditorReady && window.tinymce) {
            const ed = window.tinymce.get(DESC_ID);
            if (ed && !ed.isHidden()) { ed.setContent(html || ''); return; }
        }
        $('#' + DESC_ID).val(html || '');
    }

    function getDescription() {
        if (richEditorReady && window.tinymce) {
            const ed = window.tinymce.get(DESC_ID);
            if (ed && !ed.isHidden()) return ed.getContent();
        }
        return $('#' + DESC_ID).val();
    }

    function openDrawer(lessonId, $trigger) {
        drawerTrigger = $trigger && $trigger.length ? $trigger.get(0) : null;
        $form.find('input[name="lesson_id"]').val(lessonId);
        // Reset fields while loading.
        $form[0].reset();
        $form.find('input[name="lesson_id"]').val(lessonId);
        setStatus($drawerStatus, 'Loading…');
        $drawer.prop('hidden', false).attr('aria-hidden', 'false');
        // Init TinyMCE on first open (hidden container → 0-height sizing issues, so we defer).
        ensureRichEditor();
        setDescription(''); // clear previous content while AJAX fetches this lesson's

        ajax('dl_builder_lesson_get', { lesson_id: lessonId })
            .then(function (data) {
                $form.find('[name="title"]').val(data.title || '');
                $form.find('[name="subtitle"]').val(data.subtitle || '');
                setDescription(data.description || '');
                $form.find('[name="video_url"]').val(data.video_url || '');
                $form.find('[name="duration"]').val(data.duration || '');
                $form.find('[name="attachment_url"]').val(data.attachment_url || '');
                $form.find('[name="attachment_title"]').val(data.attachment_title || '');
                $form.find('[name="attachment_subtitle"]').val(data.attachment_subtitle || '');
                $form.find('[name="attachment_cta"]').val(data.attachment_cta || '');
                setStatus($drawerStatus, '');
                $form.find('[name="title"]').focus();
            }, function (err) {
                setStatus($drawerStatus, err, 'error');
            });
    }

    function closeDrawer() {
        $drawer.prop('hidden', true).attr('aria-hidden', 'true');
        if (drawerTrigger && typeof drawerTrigger.focus === 'function') {
            drawerTrigger.focus();
        }
        drawerTrigger = null;
    }

    $root.on('click', '.dl-edit-lesson', function () {
        const $btn = $(this);
        const id = parseInt($btn.closest('.dl-lesson').data('lesson-id'), 10);
        if (id) openDrawer(id, $btn);
    });

    $drawer.on('click', '[data-dl-close]', function (e) {
        e.preventDefault();
        closeDrawer();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$drawer.prop('hidden')) {
            closeDrawer();
        }
    });

    /* ---------- Drag-and-drop reorder ---------- */

    function sendReorder(entity, parentId, orderedIds) {
        setStatus($status, 'Reordering…');
        return ajax('dl_builder_reorder', {
            entity:    entity,
            parent_id: parentId,
            order:     orderedIds,
        }).then(
            function () { setStatus($status, 'Order saved', 'success'); },
            function (err) { setStatus($status, err, 'error'); }
        );
    }

    // Collect post IDs from a container's direct child rows.
    function collectIds($container, childSelector, attr) {
        return $container.children(childSelector).map(function () {
            return parseInt($(this).data(attr), 10);
        }).get().filter(Boolean);
    }

    function initLessonSortable($container) {
        if (typeof $.fn.sortable !== 'function') return;
        // Avoid double-init if called again after a new module is added.
        if ($container.data('dl-sortable-ready')) return;

        $container.sortable({
            items: '> .dl-lesson',
            handle: '.dl-drag-handle--lesson',
            connectWith: '.dl-lessons',
            placeholder: 'dl-sortable-placeholder dl-lesson',
            forcePlaceholderSize: true,
            tolerance: 'pointer',
            update: function () {
                // On cross-module drops, both source and destination fire `update`
                // and each sends its own reorder for its current contents. The
                // moved lesson's parent_module gets rewritten by the destination's
                // request (backend always re-parents to parent_id on lesson reorder).
                const $module = $(this).closest('.dl-module');
                const moduleId = parseInt($module.data('module-id'), 10);
                if (!moduleId) return;
                const ids = collectIds($(this), '.dl-lesson', 'lesson-id');
                if (ids.length === 0) return; // backend rejects empty
                sendReorder('lesson', moduleId, ids);
            }
        });
        $container.data('dl-sortable-ready', true);
    }

    function initModulesSortable() {
        const $modules = $root.find('.dl-modules');
        if (!$modules.length || $modules.data('dl-sortable-ready')) return;
        if (typeof $.fn.sortable !== 'function') return;
        $modules.sortable({
            items: '> .dl-module',
            handle: '.dl-drag-handle--module',
            placeholder: 'dl-sortable-placeholder dl-module',
            forcePlaceholderSize: true,
            tolerance: 'pointer',
            update: function () {
                const ids = collectIds($(this), '.dl-module', 'module-id');
                sendReorder('module', courseId, ids);
            }
        });
        $modules.data('dl-sortable-ready', true);
    }

    // Initial pass on page load.
    initModulesSortable();
    $root.find('.dl-lessons').each(function () { initLessonSortable($(this)); });

    $form.on('submit', function (e) {
        e.preventDefault();
        const payload = {
            lesson_id:           $form.find('[name="lesson_id"]').val(),
            title:               $form.find('[name="title"]').val(),
            subtitle:            $form.find('[name="subtitle"]').val(),
            description:         getDescription(),
            video_url:           $form.find('[name="video_url"]').val(),
            duration:            $form.find('[name="duration"]').val(),
            attachment_url:      $form.find('[name="attachment_url"]').val(),
            attachment_title:    $form.find('[name="attachment_title"]').val(),
            attachment_subtitle: $form.find('[name="attachment_subtitle"]').val(),
            attachment_cta:      $form.find('[name="attachment_cta"]').val(),
        };
        setStatus($drawerStatus, 'Saving…');
        ajax('dl_builder_lesson_update', payload)
            .then(function (data) {
                // Update tree row in place.
                const $row = $root.find('.dl-lesson[data-lesson-id="' + data.id + '"]');
                $row.find('.dl-lesson-title').text(data.title);
                let $dur = $row.find('.dl-lesson-duration');
                if (data.duration) {
                    if (!$dur.length) {
                        $dur = $('<span class="dl-lesson-duration"></span>');
                        $row.find('.dl-lesson-title').after($dur);
                    }
                    $dur.text(data.duration);
                } else {
                    $dur.remove();
                }
                setStatus($drawerStatus, 'Saved', 'success');
                setTimeout(closeDrawer, 400);
            }, function (err) {
                setStatus($drawerStatus, err, 'error');
            });
    });
})(jQuery);
