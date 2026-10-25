/**
 * Search and sort toolbar for galleries rendered with the "toolbar" option
 *
 * Works on the figures the server rendered (data-title, data-filename, data-date, data-search), so it
 * covers all pages of a paginated gallery. The pages are rebuilt in the browser; without JavaScript the
 * gallery is shown as rendered and the toolbar stays hidden.
 */

/**
 * Normalize text for matching: lower case, no accents
 *
 * @param {string} text
 * @returns {string}
 */
function galleryNormalize(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Filter items by a search query and sort them
 *
 * @param {{idx: number, title: string, file: string, date: number, text: string}[]} items
 *        text must already be normalized with galleryNormalize()
 * @param {string} query space separated terms, all of them have to match
 * @param {string} key '' (original order), 'title', 'date' or 'file'
 * @param {string} dir 'asc' or 'desc'
 * @returns {Array} a new, filtered and sorted array
 */
function gallerySearchSort(items, query, key, dir) {
    const terms = galleryNormalize(query).split(/\s+/).filter(Boolean);
    const list = items.filter(function (item) {
        return terms.every(function (term) {
            return item.text.indexOf(term) !== -1;
        });
    });

    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    const sign = dir === 'desc' ? -1 : 1;
    const compare = {
        title: function (a, b) {
            return collator.compare(a.title, b.title);
        },
        file: function (a, b) {
            return collator.compare(a.file, b.file);
        },
        date: function (a, b) {
            return a.date - b.date;
        }
    }[key] || function (a, b) {
        return a.idx - b.idx;
    };

    list.sort(function (a, b) {
        if (key === 'date' && (!a.date || !b.date)) {
            // images without a date always go last, whatever the direction
            if (!a.date && !b.date) return a.idx - b.idx;
            return a.date ? -1 : 1;
        }
        const result = compare(a, b);
        if (result) return sign * result;
        return collator.compare(a.file, b.file) || a.idx - b.idx;
    });
    return list;
}

/**
 * Read the remembered sort choice of a gallery
 *
 * The choice lives in the visitor's browser only (localStorage). Storage can be missing or blocked, so
 * every access is guarded and the gallery simply starts in its default order.
 *
 * @param {string} key storage key
 * @returns {{by: string, dir: string}|null}
 */
function galleryLoadSort(key) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(key));
        if (saved && typeof saved.by === 'string' && typeof saved.dir === 'string') return saved;
    } catch (e) {
        // no storage or broken value
    }
    return null;
}

/**
 * Remember the sort choice of a gallery, or forget it when it is the default
 *
 * @param {string} key storage key
 * @param {string} by sort field, '' for the default order
 * @param {string} dir 'asc' or 'desc'
 */
function gallerySaveSort(key, by, dir) {
    try {
        if (by === '' && dir === 'asc') {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify({by: by, dir: dir}));
        }
    } catch (e) {
        // no storage: nothing to remember
    }
}

jQuery(function () {
    jQuery('.plugin-gallery').has('.gallery-toolbar').each(function () {
        const $gallery = jQuery(this);
        const $toolbar = $gallery.find('.gallery-toolbar');
        const $search = $toolbar.find('.gallery-search');
        const $sortBy = $toolbar.find('.gallery-sortby');
        const $sortDir = $toolbar.find('.gallery-sortdir');
        const $count = $toolbar.find('.gallery-count');
        const $none = $gallery.find('.gallery-none');
        const pageSize = parseInt($gallery.attr('data-paginate'), 10) || 0;
        const galleryID = $gallery.attr('id').replace(/^plugin__gallery_/, '');
        const gridStyle = $gallery.find('.gallery-page').first().attr('style');
        // one remembered choice per gallery: page + gallery ID
        const storageKey = 'gallerycollab.sort.' + (window.JSINFO && JSINFO.id ? JSINFO.id : window.location.pathname) +
            '.' + galleryID;

        // everything the server rendered, in its original order
        const items = $gallery.find('figure.gallery-image').map(function (idx) {
            const $fig = jQuery(this);
            return {
                el: this,
                idx: idx,
                title: $fig.attr('data-title') || '',
                file: $fig.attr('data-filename') || '',
                date: parseInt($fig.attr('data-date'), 10) || 0,
                text: galleryNormalize($fig.attr('data-search'))
            };
        }).get();

        let rendered = false;

        /**
         * Rebuild the pages for the current search and sort settings
         *
         * @param {number} page the page to show
         */
        function render(page) {
            const list = gallerySearchSort(items, $search.val(), $sortBy.val(), $sortDir.val());
            const pages = [];
            for (let i = 0; i < list.length; i += (pageSize || list.length)) {
                pages.push(list.slice(i, i + (pageSize || list.length)));
            }
            page = Math.max(0, Math.min(page, pages.length - 1));

            // take the figures out first so removing the old pages does not destroy them (and their handlers)
            jQuery(items.map(function (item) {
                return item.el;
            })).detach();
            $gallery.find('.gallery-page, .gallery-page-selector').remove();

            pages.forEach(function (pageItems, n) {
                const $page = jQuery('<div class="gallery-page">')
                    .attr('id', 'gallery__' + galleryID + '_' + n)
                    .toggle(n === page);
                if (gridStyle) $page.attr('style', gridStyle + (n === page ? '' : ';display:none'));
                pageItems.forEach(function (item) {
                    $page.append(item.el);
                });
                $none.before($page); // keeps the pages in order, in front of the "no match" message
            });

            if (pages.length > 1) {
                const $selector = jQuery('<div class="gallery-page-selector">')
                    .append(jQuery('<span>').text($toolbar.attr('data-pages') + ' '));
                pages.forEach(function (_, n) {
                    $selector.append(
                        jQuery('<a>').attr('href', '#gallery__' + galleryID + '_' + n)
                            .text(n + 1).toggleClass('active', n === page),
                        ' '
                    );
                });
                $gallery.append($selector.show());
            }

            $count.text($toolbar.attr('data-count')
                .replace('%1', list.length).replace('%2', items.length));
            $none.prop('hidden', list.length > 0);

            // lightbox navigation has to follow the new order and skip filtered images
            if (rendered && window.galleryLightbox) window.galleryLightbox.refresh();
            rendered = true;
        }

        // page selector links are rebuilt on every render, so listen on the gallery
        $gallery.on('click', '.gallery-page-selector a', function (e) {
            e.preventDefault();
            const $self = jQuery(this);
            $self.siblings().removeClass('active');
            $self.addClass('active');
            $gallery.find('.gallery-page').hide();
            $gallery.find(this.hash).show();
        });

        let timer = null;
        $search.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                render(0);
            }, 150);
        });
        $search.on('keydown', function (e) {
            if (e.key === 'Escape' && $search.val() !== '') {
                $search.val('');
                render(0);
            }
        });
        $sortBy.add($sortDir).on('change', function () {
            gallerySaveSort(storageKey, $sortBy.val(), $sortDir.val());
            render(0);
        });

        // restore the remembered choice, ignoring values this gallery does not offer
        const saved = galleryLoadSort(storageKey);
        if (saved) {
            if ($sortBy.find('option').filter(function () {
                return this.value === saved.by;
            }).length) $sortBy.val(saved.by);
            if (saved.dir === 'asc' || saved.dir === 'desc') $sortDir.val(saved.dir);
        }

        $toolbar.css('display', 'flex');
        render(0);
    });
});
