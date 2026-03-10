/* global jQuery, GHL_SYNC */
(function ($) {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────────────────────

    function ajax(action, data, done, fail) {
        $.post(GHL_SYNC.ajax_url, $.extend({ action: action, _nonce: GHL_SYNC.nonce }, data))
            .done(function (res) {
                if (res.success) { done(res.data); }
                else { (fail || defaultFail)(res.data ? res.data.message : GHL_SYNC.strings.error_generic); }
            })
            .fail(function () { (fail || defaultFail)(GHL_SYNC.strings.error_generic); });
    }

    function defaultFail(msg) { alert(msg); }

    function originBadge(origin) {
        if (origin === 'wordpress') {
            return '<span class="ghl-origin-pill ghl-origin-pill--wp">WordPress</span>';
        }
        return '<span class="ghl-origin-pill ghl-origin-pill--ghl">LaunchLocal</span>';
    }

    function statusBadge(status) {
        var labels = {
            synced:        { cls: 'ghl-badge--ready',  text: 'Synced'       },
            needs_update:  { cls: 'ghl-badge--warn',   text: 'Needs Update' },
            new:           { cls: 'ghl-badge--blue',   text: 'New'          },
            backlog:       { cls: 'ghl-badge--purple', text: 'Backlog'      },
            error:         { cls: 'ghl-badge--error',  text: 'Error'        },
        };
        var b = labels[status] || { cls: 'ghl-badge--warn', text: status };
        return '<span class="ghl-badge ' + b.cls + '"><span class="ghl-badge__dot"></span>' + b.text + '</span>';
    }

    // ── Input toggle (password visibility) ───────────────────────────────────
    $(document).on('click', '.ghl-input-toggle', function () {
        var $btn = $(this);
        var $input = $('#' + $btn.data('target'));
        $input.attr('type', $input.attr('type') === 'password' ? 'text' : 'password');
    });

    // ── Verify connection ─────────────────────────────────────────────────────
    $('#btn-verify').on('click', function () {
        var $btnSvg = '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>';
        var $btn = $(this).prop('disabled', true).html('<div class="ghl-spinner ghl-spinner--inline"></div> ' + GHL_SYNC.strings.verifying);
        var $out = $('#verify-result').show().html('<div class="ghl-loading-state"><div class="ghl-spinner"></div><p>' + GHL_SYNC.strings.verifying + '</p></div>');

        ajax('ghl_verify_connection', {}, function (data) {
            $btn.prop('disabled', false).html($btnSvg + ' Test Connection');
            var html = '';
            if (data.schema_found) {
                html += '<div class="ghl-notice ghl-notice--success"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>Connected. Schema key <strong>' + escHtml(data.schema_key) + '</strong> found.</div>';
            } else {
                html += '<div class="ghl-notice ghl-notice--warn">Connected but schema key <strong>' + escHtml(data.schema_key) + '</strong> not found. Available schemas:</div>';
            }
            if (data.schemas && data.schemas.length) {
                html += '<div style="padding:0 0 12px;"><table class="ghl-table"><thead><tr><th>Key</th><th>Label</th></tr></thead><tbody>';
                data.schemas.forEach(function (s) {
                    html += '<tr><td class="ghl-text--mono">' + escHtml(s.key) + '</td><td>' + escHtml(s.label) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            $out.html(html);
            // Replace entire badge content so no old text lingers.
            $('#connection-badge')
                .attr('class', 'ghl-badge ghl-badge--connected')
                .html('<span class="ghl-badge__dot"></span> Connected');
        }, function (msg) {
            $btn.prop('disabled', false).html($btnSvg + ' Test Connection');
            $out.html('<div class="ghl-notice ghl-notice--error">' + escHtml(msg) + '</div>');
            $('#connection-badge')
                .attr('class', 'ghl-badge ghl-badge--warn')
                .html('<span class="ghl-badge__dot"></span> Check Failed');
        });
    });

    // ── Pending / records list ────────────────────────────────────────────────
    var pendingPage = 1;
    var pendingData = [];
    var pendingPerPage = 20;

    function renderPendingTable(data) {
        // Only show backlog items that originated from LaunchLocal (orphaned GHL records).
        // Pure WordPress-created backlog posts belong on the Back-Sync tab only.
        var allItems = data.items || [];
        pendingData = allItems.filter(function (item) {
            return !(item.status === 'backlog' && item.origin !== 'ghl');
        });
        pendingPage = 1;
        $('#pending-loading, #pending-empty, #pending-placeholder').hide();
        if (!pendingData.length) { $('#pending-empty').show(); return; }
        $('#pending-stats').show();
        $('#stat-total').text(data.total || 0);
        $('#stat-new').text(data.new || 0);
        $('#stat-needs-update').text(data.needs_update || 0);
        $('#stat-synced').text(data.synced || 0);
        $('#stat-drafted').text(data.drafted || 0);
        // Backlog stat: only GHL-origin orphaned records (matching what's in the table).
        var ghlBacklog = allItems.filter(function(i){ return i.status === 'backlog' && i.origin === 'ghl'; }).length;
        $('#stat-backlog').text(ghlBacklog);
        renderPendingPage();
        $('#pending-table-wrap').show();
    }

    function renderPendingPage() {
        var start = (pendingPage - 1) * pendingPerPage;
        var slice = pendingData.slice(start, start + pendingPerPage);
        var rows = '';
        slice.forEach(function (item) {
            rows += '<tr>';
            rows += '<td>' + escHtml(item.title) + '</td>';
            rows += '<td class="ghl-text--mono ghl-text--sm">' + (item.id ? escHtml(item.id) : '<em class="ghl-text--muted">—</em>') + '</td>';
            rows += '<td>' + statusBadgeWithDiff(item.status, item.diffs) + '</td>';
            rows += '<td>' + originBadge(item.origin || 'ghl') + '</td>';
            rows += '</tr>';
        });
        $('#pending-tbody').html(rows);
        renderPagination('#pending-pagination', pendingData.length, pendingPerPage, pendingPage, function (p) {
            pendingPage = p;
            renderPendingPage();
        });
    }

    function renderPagination(sel, total, perPage, current, cb) {
        var pages = Math.ceil(total / perPage);
        var $el = $(sel);
        if (pages <= 1) { $el.hide(); return; }
        $el.show();
        var html = '';
        for (var i = 1; i <= pages; i++) {
            html += '<button class="ghl-page-btn' + (i === current ? ' is-active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        $el.html(html).off('click').on('click', '.ghl-page-btn', function () {
            cb(parseInt($(this).data('page'), 10));
        });
    }

    function loadPendingRecords() {
        $('#pending-loading').show();
        $('#pending-stats, #pending-table-wrap, #pending-empty, #pending-placeholder').hide();
        ajax('ghl_get_pending', {}, function (data) {
            renderPendingTable(data);
        }, function (msg) {
            $('#pending-loading').hide();
            $('#pending-placeholder').show().find('p').text(msg);
        });
    }

    $('#btn-refresh-pending').on('click', loadPendingRecords);

    // ── Run sync ──────────────────────────────────────────────────────────────
    var syncSvg = '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>';

    $('#btn-sync').on('click', function () {
        var batchSize = parseInt($(this).data('batch'), 10) || 0;
        if (!confirm(GHL_SYNC.strings.confirm_sync)) return;

        var $btn = $(this).prop('disabled', true).html('<div class="ghl-spinner ghl-spinner--inline"></div> ' + GHL_SYNC.strings.syncing);
        $('#sync-result-card').show();

        if (batchSize > 0) {
            // ── Batched mode: auto-loop with progress bar ──────────────────
            var offset       = 0;
            var grandTotal   = 0;
            var accCreated   = 0;
            var accUpdated   = 0;
            var accSkipped   = 0;
            var accDrafted   = 0;
            var accErrors    = [];

            function syncProgressHtml() {
                return '<div style="padding:16px 20px;">' +
                    '<p id="sync-progress-label" style="margin:0 0 8px;font-size:13px;color:var(--ghl-gray-600);">Preparing…</p>' +
                    '<div style="background:var(--ghl-gray-100);border-radius:99px;height:10px;overflow:hidden;">' +
                    '<div id="sync-progress-bar" style="background:var(--ghl-blue);height:100%;width:0%;transition:width .3s;border-radius:99px;"></div>' +
                    '</div></div>';
            }

            function syncFinalHtml() {
                var html = '<div class="ghl-stats-row">';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green">' + accCreated + '</span><span class="ghl-stat__label">Created</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--blue">'  + accUpdated + '</span><span class="ghl-stat__label">Updated</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted">' + accSkipped + '</span><span class="ghl-stat__label">Skipped</span></div>';
                if (accDrafted > 0) html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted">' + accDrafted + '</span><span class="ghl-stat__label">Drafted</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red">'   + accErrors.length + '</span><span class="ghl-stat__label">Errors</span></div>';
                html += '</div>';
                if (accErrors.length) {
                    html += '<div class="ghl-log-section ghl-log-section--error" style="padding:0 20px 16px;"><ul class="ghl-log-list">';
                    accErrors.forEach(function(e) { html += '<li>' + escHtml(e) + '</li>'; });
                    html += '</ul></div>';
                }
                return html;
            }

            $('#sync-result-content').html(syncProgressHtml());

            function runBatch() {
                ajax('ghl_run_sync', { batch_size: batchSize, offset: offset }, function(data) {
                    if (!grandTotal && data.total_ghl) grandTotal = data.total_ghl;
                    accCreated += (data.created || 0);
                    accUpdated += (data.updated || 0);
                    accSkipped += (data.skipped || 0);
                    accDrafted += (data.drafted || 0);
                    accErrors   = accErrors.concat(data.errors || []);
                    offset     += batchSize;

                    var pct = grandTotal > 0 ? Math.min(100, Math.round((offset / grandTotal) * 100)) : 100;
                    $('#sync-progress-bar').css('width', pct + '%');
                    $('#sync-progress-label').text('Syncing ' + Math.min(offset, grandTotal) + ' of ' + grandTotal + ' records…');

                    var hasMore = grandTotal > 0 && offset < grandTotal;
                    if (hasMore) {
                        setTimeout(runBatch, 500);
                    } else {
                        $btn.prop('disabled', false).html(syncSvg + ' Run Batch Sync');
                        $('#sync-result-content').html(syncFinalHtml());
                        loadPendingRecords();
                    }
                }, function(msg) {
                    $btn.prop('disabled', false).html(syncSvg + ' Run Batch Sync');
                    $('#sync-result-content').html('<div class="ghl-notice ghl-notice--error">' + escHtml(msg) + '</div>');
                });
            }

            runBatch();

        } else {
            // ── Non-batched mode: single request ──────────────────────────
            $('#sync-result-content').html('<div class="ghl-loading-state"><div class="ghl-spinner"></div><p>' + GHL_SYNC.strings.syncing + '</p></div>');

            ajax('ghl_run_sync', { batch_size: 0, offset: 0 }, function(data) {
                $btn.prop('disabled', false).html(syncSvg + ' Sync All Now');
                var html = '<div class="ghl-stats-row">';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green">' + (data.created || 0) + '</span><span class="ghl-stat__label">Created</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--blue">'  + (data.updated || 0) + '</span><span class="ghl-stat__label">Updated</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted">' + (data.skipped || 0) + '</span><span class="ghl-stat__label">Skipped</span></div>';
                if (data.drafted > 0) html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--muted">' + data.drafted + '</span><span class="ghl-stat__label">Drafted</span></div>';
                html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red">'   + ((data.errors || []).length) + '</span><span class="ghl-stat__label">Errors</span></div>';
                if (data.orphans_cleared > 0) html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--orange">' + data.orphans_cleared + '</span><span class="ghl-stat__label">Links Cleared</span></div>';
                if (data.orphans_deleted > 0) html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red">'    + data.orphans_deleted + '</span><span class="ghl-stat__label">Deleted</span></div>';
                html += '</div>';
                if (data.errors && data.errors.length) {
                    html += '<div class="ghl-log-section ghl-log-section--error" style="padding:0 20px 16px;"><ul class="ghl-log-list">';
                    data.errors.forEach(function(e) { html += '<li>' + escHtml(e) + '</li>'; });
                    html += '</ul></div>';
                }
                $('#sync-result-content').html(html);
                loadPendingRecords();
            }, function(msg) {
                $btn.prop('disabled', false).html(syncSvg + ' Sync All Now');
                $('#sync-result-content').html('<div class="ghl-notice ghl-notice--error">' + escHtml(msg) + '</div>');
            });
        }
    });

    // ── Back-sync: WP posts loader ────────────────────────────────────────────
    function loadWpPosts() {
        $('#wp-posts-loading').show();
        $('#wp-posts-table-wrap, #wp-posts-placeholder').hide();
        ajax('ghl_get_wp_posts', {}, function (data) {
            $('#wp-posts-loading').hide();
            var rows = '';
            (data.items || []).forEach(function (item) {
                rows += '<tr>';
                rows += '<td>' + (item.edit_url ? '<a href="' + escHtml(item.edit_url) + '" target="_blank">' + escHtml(item.title) + '</a>' : escHtml(item.title)) + '</td>';
                rows += '<td class="ghl-text--mono ghl-text--sm">' + escHtml(String(item.post_id)) + '</td>';
                rows += '<td class="ghl-text--mono ghl-text--sm">' + (item.ghl_id ? escHtml(item.ghl_id) : '<em class="ghl-text--muted">—</em>') + '</td>';
                rows += '<td>' + statusBadge(item.back_sync_status) + '</td>';
                rows += '<td>' + originBadge(item.origin || 'wordpress') + '</td>';
                rows += '</tr>';
            });
            if (!rows) rows = '<tr><td colspan="5" style="text-align:center;color:#999;">No showcase posts found.</td></tr>';
            $('#wp-posts-tbody').html(rows);
            $('#wp-posts-table-wrap').show();
        }, function () {
            $('#wp-posts-loading').hide();
            $('#wp-posts-placeholder').show();
        });
    }

    $('#btn-refresh-wp-posts').on('click', loadWpPosts);

    // ── Needs-update diff tooltip ─────────────────────────────────────────────
    // Build a tooltip-aware badge for needs_update status.
    function statusBadgeWithDiff(status, diffs) {
        var b = {
            new:          { cls: 'ghl-badge--blue',    text: 'New'          },
            needs_update: { cls: 'ghl-badge--warn',    text: 'Needs Update' },
            synced:       { cls: 'ghl-badge--success', text: 'Synced'       },
            backlog:      { cls: 'ghl-badge--muted',   text: 'Backlog'      },
            drafted:      { cls: 'ghl-badge--muted',   text: 'Drafted'      },
            error:        { cls: 'ghl-badge--error',   text: 'Error'        },
            created:      { cls: 'ghl-badge--success', text: 'Created'      },
        }[status] || { cls: 'ghl-badge--muted', text: status };

        if ( status === 'needs_update' && diffs && diffs.length ) {
            var tip = diffs.map(function(d) {
                return escHtml(d.field) + ': WP=' + escHtml(d.wp || '—') + ' → LL=' + escHtml(d.ghl || '—');
            }).join('&#10;');
            return '<span class="ghl-badge ' + b.cls + ' ghl-has-tooltip" data-tip="' + tip + '">' + b.text + ' <span style="opacity:.6;font-size:10px;">(' + diffs.length + ')</span></span>';
        }
        return '<span class="ghl-badge ' + b.cls + '">' + b.text + '</span>';
    }

    // Tooltip follow-cursor (delegated, works for dynamically rendered rows).
    var $tip = $('<div class="ghl-tooltip"></div>').appendTo('body');
    $(document).on('mouseenter', '.ghl-has-tooltip', function(e) {
        var text = $(this).data('tip') || '';
        if (!text) return;
        // Replace encoded newlines with actual newlines for display.
        $tip.html('<pre style="margin:0;font:12px/1.6 inherit;white-space:pre-wrap;max-width:340px;">' + text + '</pre>').show();
    }).on('mousemove', '.ghl-has-tooltip', function(e) {
        $tip.css({ left: e.pageX + 14, top: e.pageY + 14 });
    }).on('mouseleave', '.ghl-has-tooltip', function() {
        $tip.hide();
    });

    // ── Back-sync: run (batched) ──────────────────────────────────────────────
    var backSyncBtnHtml = '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg> Run Backlog Sync';

    $('#btn-back-sync').on('click', function () {
        if (!confirm(GHL_SYNC.strings.confirm_backsync)) return;

        // Use the global batch size from Settings. Default 5 if unset.
        var batchSize = (GHL_SYNC.batch_size && parseInt(GHL_SYNC.batch_size, 10) > 0)
            ? parseInt(GHL_SYNC.batch_size, 10)
            : 5;

        var $btn = $(this).prop('disabled', true).html('<div class="ghl-spinner ghl-spinner--inline"></div> Syncing…');
        $('#back-sync-result-card').show();

        var allItems     = [];
        var allErrors    = [];
        var totalCreated = 0;
        var grandTotal   = 0;
        var processed    = 0;

        function updateProgress() {
            var pct = grandTotal > 0 ? Math.round((processed / grandTotal) * 100) : 0;
            $('#back-sync-progress-bar').css('width', pct + '%');
            $('#back-sync-progress-label').text('Uploading ' + processed + ' of ' + grandTotal + ' records…');
        }

        function renderFinalResult() {
            var html = '';
            html += '<div class="ghl-stats-row" style="padding:0 20px;">';
            html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--green">' + totalCreated + '</span><span class="ghl-stat__label">Pushed to LaunchLocal</span></div>';
            html += '<div class="ghl-stat"><span class="ghl-stat__num ghl-text--red">' + allErrors.length + '</span><span class="ghl-stat__label">Errors</span></div>';
            html += '</div>';
            if (allErrors.length) {
                html += '<div class="ghl-log-section ghl-log-section--error" style="padding:0 20px 16px;"><ul class="ghl-log-list">';
                allErrors.forEach(function(e) { html += '<li>' + escHtml(e) + '</li>'; });
                html += '</ul></div>';
            }
            if (allItems.length) {
                html += '<div class="ghl-table-wrap" style="padding:0 20px 16px;"><table class="ghl-table">' +
                    '<thead><tr><th>Title</th><th>Status</th><th>LaunchLocal ID</th></tr></thead><tbody>';
                allItems.forEach(function(item) {
                    html += '<tr><td>' + escHtml(item.title) + '</td>' +
                        '<td>' + statusBadge(item.status) + '</td>' +
                        '<td class="ghl-text--mono ghl-text--sm">' + escHtml(item.ghl_id || item.message || '—') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            return html;
        }

        function progressHtml() {
            return '<div style="padding:16px 20px;">' +
                '<p id="back-sync-progress-label" style="margin:0 0 8px;font-size:13px;color:var(--ghl-gray-600);">Preparing…</p>' +
                '<div style="background:var(--ghl-gray-100);border-radius:99px;height:10px;overflow:hidden;">' +
                '<div id="back-sync-progress-bar" style="background:var(--ghl-blue);height:100%;width:0%;transition:width .3s;border-radius:99px;"></div>' +
                '</div></div>';
        }

        $('#back-sync-result-content').html(progressHtml());

        function runBatch() {
            // No offset — processed posts lose their backlog status server-side
            // so the list naturally shrinks. Always grab the first batchSize items.
            ajax('ghl_run_back_sync', { batch_size: batchSize }, function(data) {
                var batchItems = data.items || [];

                // On first response, learn the total so we can show progress.
                if (!grandTotal && data.total_remaining) {
                    grandTotal = data.total_remaining;
                }

                totalCreated += (data.created || 0);
                allErrors     = allErrors.concat(data.errors || []);
                allItems      = allItems.concat(batchItems);
                processed    += batchItems.filter(function(i){ return i.status !== 'error'; }).length;
                updateProgress();

                if (data.has_more) {
                    // Short pause before next batch.
                    setTimeout(runBatch, 700);
                } else {
                    $btn.prop('disabled', false).html(backSyncBtnHtml);
                    $('#back-sync-result-content').html(renderFinalResult());
                    loadWpPosts();
                }
            }, function(msg) {
                $btn.prop('disabled', false).html(backSyncBtnHtml);
                $('#back-sync-result-content').html('<div class="ghl-notice ghl-notice--error">' + escHtml(msg) + '</div>');
            });
        }

        runBatch();
    });

    // ── User picker ───────────────────────────────────────────────────────────
    var userPage = 1;
    var userSearch = '';
    var userDebounce;

    $('#ghl_publisher_search').on('input', function () {
        clearTimeout(userDebounce);
        var val = $(this).val();
        userDebounce = setTimeout(function () {
            userSearch = val;
            userPage = 1;
            loadUsers(true);
        }, 300);
    });

    $('#ghl_publisher_search').on('focus', function () { loadUsers(true); });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#ghl-user-picker').length) {
            $('#ghl-user-dropdown').hide();
        }
    });

    function loadUsers(reset) {
        if (reset) userPage = 1;
        $('#ghl-user-dropdown').show();
        $('#ghl-user-results').html('<div style="padding:8px 12px;color:#999;">Loading…</div>');
        ajax('ghl_search_users', { search: userSearch, page: userPage }, function (data) {
            var html = '';
            (data.users || []).forEach(function (u) {
                html += '<div class="ghl-user-option" data-id="' + u.id + '" data-label="' + escHtml(u.label) + '">' + escHtml(u.label) + '</div>';
            });
            if (!html) html = '<div style="padding:8px 12px;color:#999;">No users found.</div>';
            if (reset) { $('#ghl-user-results').html(html); }
            else { $('#ghl-user-results').append(html); }
            $('#ghl-user-load-more').toggle(data.total > userPage * 10);
        });
    }

    $(document).on('click', '.ghl-user-option', function () {
        var $opt = $(this);
        $('#ghl_sync_publisher_id').val($opt.data('id'));
        $('#ghl_publisher_search').val($opt.data('label'));
        $('#btn-clear-publisher').show();
        $('#ghl-user-dropdown').hide();
    });

    $('#btn-load-more-users').on('click', function () { userPage++; loadUsers(false); });

    $('#btn-clear-publisher').on('click', function () {
        $('#ghl_sync_publisher_id').val('');
        $('#ghl_publisher_search').val('');
        $(this).hide();
    });

    // ── Field Mapping ─────────────────────────────────────────────────────────
    var currentMap = GHL_SYNC.saved_map || [];

    var typeOptions = [
        { val: 'post',          label: 'post'          },
        { val: 'meta',          label: 'meta'          },
        { val: 'image_single',  label: 'image_single'  },
        { val: 'image_gallery', label: 'image_gallery' },
        { val: 'taxonomy',      label: 'taxonomy'      },
    ];

    function buildTypeSelect(selected) {
        var html = '<select class="ghl-select ghl-select--sm map-type">';
        typeOptions.forEach(function (o) {
            html += '<option value="' + o.val + '"' + (o.val === selected ? ' selected' : '') + '>' + o.label + '</option>';
        });
        return html + '</select>';
    }

    function renderMapRow(row) {
        return '<div class="ghl-map-row">' +
            '<input type="text" class="ghl-input ghl-input--sm map-ghl" value="' + escHtml(row.ghl) + '" placeholder="ghl_field_key">' +
            '<input type="text" class="ghl-input ghl-input--sm map-wp" value="' + escHtml(row.wp) + '" placeholder="wp_field_or_meta_key">' +
            buildTypeSelect(row.type) +
            '<button type="button" class="ghl-btn ghl-btn--outline ghl-btn--sm map-remove">✕</button>' +
            '</div>';
    }

    function renderMapRows() {
        var html = '';
        currentMap.forEach(function (r) { html += renderMapRow(r); });
        $('#map-rows').html(html);
    }

    if ($('#map-rows').length) { renderMapRows(); }

    $('#btn-map-add').on('click', function () {
        currentMap.push({ ghl: '', wp: '', type: 'meta' });
        renderMapRows();
    });

    $('#btn-map-reset').on('click', function () {
        if (!confirm('Reset to defaults?')) return;
        currentMap = JSON.parse(JSON.stringify(GHL_SYNC.default_map));
        renderMapRows();
    });

    $(document).on('click', '.map-remove', function () {
        var idx = $(this).closest('.ghl-map-row').index();
        currentMap.splice(idx, 1);
        renderMapRows();
    });

    function collectMap() {
        var rows = [];
        $('#map-rows .ghl-map-row').each(function () {
            rows.push({
                ghl:  $(this).find('.map-ghl').val().trim(),
                wp:   $(this).find('.map-wp').val().trim(),
                type: $(this).find('.map-type').val(),
            });
        });
        return rows;
    }

    $('#btn-map-save').on('click', function () {
        var $btn = $(this).prop('disabled', true).html(GHL_SYNC.strings.saving);
        var data = collectMap();
        ajax('ghl_save_field_map', { field_map: JSON.stringify(data) }, function (res) {
            $btn.prop('disabled', false).text('Save Mapping');
            currentMap = data;
            $('#map-save-result').show().html('<div class="ghl-notice ghl-notice--success">' + escHtml(res.message) + ' (' + res.count + ' rows)</div>');
            setTimeout(function () { $('#map-save-result').hide(); }, 3000);
        }, function (msg) {
            $btn.prop('disabled', false).text('Save Mapping');
            $('#map-save-result').show().html('<div class="ghl-notice ghl-notice--error">' + escHtml(msg) + '</div>');
        });
    });

    // ── SEO override toggle visibility ────────────────────────────────────────
    $('#ghl_seo_override_toggle').on('change', function () {
        $('#seo-meta-patterns').toggle($(this).is(':checked'));
    });

    // ── Dismiss save notice ───────────────────────────────────────────────────
    setTimeout(function () { $('#save-notice').fadeOut(); }, 3000);

    // ── Auto-load lists on page load ──────────────────────────────────────────
    var activeTab = GHL_SYNC.active_tab || 'sync';

    if (activeTab === 'sync' && $('#btn-refresh-pending').length) {
        loadPendingRecords();
    }

    if (activeTab === 'backsync' && $('#btn-refresh-wp-posts').length) {
        loadWpPosts();
    }

    // ── Utility ───────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

}(jQuery));
