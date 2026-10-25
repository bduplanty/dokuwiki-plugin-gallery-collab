/**
 * Collaborative captions: suggestion/review dialog for gallery images
 *
 * Only active for users the server told us about via JSINFO.plugins.gallerycollab_access
 */
jQuery(function () {
    const cfg = JSINFO && JSINFO.plugins && JSINFO.plugins.gallerycollab_access;
    if (!cfg) return;
    const L = LANG.plugins.gallerycollab;

    /**
     * Call the AJAX endpoint
     *
     * @param {string} op
     * @param {string} id media ID
     * @param {Object} data additional parameters
     * @returns {jqXHR}
     */
    function call(op, id, data) {
        return jQuery.ajax({
            url: DOKU_BASE + 'lib/exe/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: jQuery.extend({call: 'gallerycollab', op: op, id: id, sectok: cfg.sectok}, data || {})
        });
    }

    function fail(xhr) {
        const msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText;
        alert(msg);
    }

    /**
     * @param {string[]|undefined} tags
     * @returns {string} comma separated tags, or the "no tags" wording
     */
    function tagText(tags) {
        return tags && tags.length ? tags.join(', ') : L.collab_notags;
    }

    function text(tag, cls, content) {
        return jQuery('<' + tag + '>').addClass(cls || '').text(content);
    }

    /**
     * Show a badge with the number of pending suggestions on the given figures
     *
     * @param {jQuery} $figs figure.gallery-image elements
     */
    function updateBadges($figs) {
        const ids = [];
        $figs.each(function () {
            ids.push(jQuery(this).data('id'));
        });
        if (!ids.length) return;

        call('pending', '', {ids: ids}).done(function (res) {
            $figs.each(function () {
                const $fig = jQuery(this);
                const count = res.pending[$fig.data('id')] || 0;
                $fig.children('.gallery-collab-pending').remove();
                if (!count) return;
                text('span', 'gallery-collab-pending', '🕑 ' + count)
                    .attr('title', (cfg.review ? L.collab_pending_all : L.collab_pending_own).replace('%d', count))
                    .appendTo($fig);
            });
        });
    }

    /**
     * Open the dialog for one image
     *
     * @param {string} id media ID
     */
    function openDialog(id) {
        const $overlay = jQuery('<div class="gallery-collab-overlay">');
        const $dialog = jQuery('<div class="gallery-collab-dialog" role="dialog">').appendTo($overlay);
        const close = function () {
            $overlay.remove();
            jQuery(document).off('keydown.gallerycollab');
            updateBadges(jQuery('.plugin-gallery figure.gallery-image').filter(function () {
                return jQuery(this).data('id') === id;
            }));
        };

        $overlay.on('mousedown', function (e) {
            if (e.target === $overlay[0]) close();
        });
        jQuery(document).on('keydown.gallerycollab', function (e) {
            if (e.key === 'Escape') close();
        });
        $overlay.appendTo('body');

        /** Build the dialog content from a server state */
        function render(state, note) {
            $dialog.empty();
            jQuery('<button type="button" class="gallery-collab-close">').text('×')
                .attr('title', L.collab_close).on('click', close).appendTo($dialog);
            text('h3', '', state.name).appendTo($dialog);
            if (note) text('p', 'gallery-collab-note', note).appendTo($dialog);

            // what is shown right now
            const $cur = jQuery('<dl class="gallery-collab-current">').appendTo($dialog);
            const show = function (label, caption) {
                $cur.append(text('dt', '', label));
                $cur.append(text('dd', '', caption.title || L.collab_nocaption));
                $cur.append(text('dd', '', caption.description || L.collab_nocaption));
                $cur.append(text('dd', 'gallery-collab-tags', L.collab_tags + ': ' + tagText(caption.tags)));
            };
            show(L.collab_current, state.current);
            if (state.overridden) show(L.collab_original, state.original);

            // existing suggestions
            text('h4', '', L.collab_list).appendTo($dialog);
            const $list = jQuery('<ul class="gallery-collab-list">').appendTo($dialog);
            if (!state.suggestions.length) $list.append(text('li', '', L.collab_none));

            let $reviewForm = null;
            state.suggestions.forEach(function (s) {
                const $li = jQuery('<li>').addClass('status-' + s.status).appendTo($list);
                $li.append(text('div', 'meta',
                    s.user + ' · ' + new Date(s.time * 1000).toLocaleString() + ' · ' + L['collab_' + s.status]));
                if (s.title) $li.append(text('div', 'title', s.title));
                if (s.description) $li.append(text('div', 'desc', s.description));
                if (s.tags) $li.append(text('div', 'tags', L.collab_tags + ': ' + tagText(s.tags)));

                const $actions = jQuery('<div class="actions">').appendTo($li);
                const act = function (label, handler) {
                    jQuery('<button type="button">').text(label).on('click', handler).appendTo($actions);
                };
                const run = function (op) {
                    call(op, id, {sid: s.id}).done(function (st) {
                        render(st);
                    }).fail(fail);
                };
                if (cfg.review && s.status === 'pending') {
                    act(L.collab_use, function () {
                        $reviewForm.find('[name=title]').val(s.title || state.current.title);
                        $reviewForm.find('[name=description]').val(s.description || state.current.description);
                        $reviewForm.find('[name=tags]').val((s.tags || state.current.tags || []).join(', '));
                        $reviewForm.data('sid', s.id).find('textarea').focus();
                    });
                    act(L.collab_reject, function () {
                        run('reject');
                    });
                }
                if (cfg.review || s.status === 'pending') {
                    act(L.collab_delete, function () {
                        run('delete');
                    });
                }
            });

            /** Build a title/caption form */
            const form = function (heading, submitLabel, values, onSubmit) {
                const $f = jQuery('<form class="gallery-collab-form">').appendTo($dialog);
                text('h4', '', heading).appendTo($f);
                jQuery('<label>').text(L.collab_f_title)
                    .append(jQuery('<input type="text" name="title" class="edit" maxlength="200">').val(values.title))
                    .appendTo($f);
                jQuery('<label>').text(L.collab_f_desc)
                    .append(jQuery('<textarea name="description" class="edit" rows="3" maxlength="2000">')
                        .val(values.description))
                    .appendTo($f);
                jQuery('<label>').text(L.collab_f_tags)
                    .append(jQuery('<input type="text" name="tags" class="edit">').val((values.tags || []).join(', ')))
                    .appendTo($f);
                jQuery('<button type="submit" class="button">').text(submitLabel).appendTo($f);
                $f.on('submit', function (e) {
                    e.preventDefault();
                    onSubmit($f);
                });
                return $f;
            };

            if (cfg.review) {
                $reviewForm = form(L.collab_review, L.collab_apply, state.current, function ($f) {
                    call('approve', id, {
                        sid: $f.data('sid') || '',
                        title: $f.find('[name=title]').val(),
                        description: $f.find('[name=description]').val(),
                        tags: $f.find('[name=tags]').val()
                    }).done(function (st) {
                        render(st, st.iptc ? L.collab_iptc_ok : L.collab_iptc_no);
                    }).fail(fail);
                });
                if (state.overridden) {
                    jQuery('<button type="button" class="button">').text(L.collab_revert).on('click', function () {
                        call('revert', id).done(function (st) {
                            render(st);
                        }).fail(fail);
                    }).appendTo($reviewForm);
                }
            }

            // title and caption start empty (an empty field means "no change"); the tags start with the current ones
            form(L.collab_suggest, L.collab_submit, {title: '', description: '', tags: state.current.tags}, function ($f) {
                call('suggest', id, {
                    title: $f.find('[name=title]').val(),
                    description: $f.find('[name=description]').val(),
                    tags: $f.find('[name=tags]').val()
                }).done(function (st) {
                    render(st, L.collab_sent);
                }).fail(fail);
            });
        }

        call('get', id).done(function (state) {
            render(state);
        }).fail(function (xhr) {
            close();
            fail(xhr);
        });
    }

    // add a button to every local image
    const $figures = jQuery('.plugin-gallery figure.gallery-image[data-id]');
    $figures.each(function () {
        const $fig = jQuery(this);
        jQuery('<button type="button" class="gallery-collab-open">').text('✎')
            .attr('title', L.collab_open)
            .on('click', function (e) {
                e.preventDefault();
                openDialog($fig.data('id'));
            })
            .appendTo($fig);
    });

    updateBadges($figures);
});
