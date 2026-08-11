/**
 * Đối chiếu HTsoft — bộ lọc trái + chạy báo cáo + modal xem lại phiếu.
 *
 * Khác BC_TK ở cách chạy: bên đó mỗi site một bảng riêng nên phải gọi từng site
 * rồi ghép; ở đây dữ liệu nằm gọn trong ba bảng global nên MỘT request lo hết.
 * Đổi lại phải chặn khoảng ngày quá rộng — xem giới hạn LIMIT phía PHP.
 *
 * Trang nào dùng thì khai window.TGS_HMR (ajaxUrl, nonce, action, sites, zones,
 * extraParams) rồi gọi TGSHmr.setRenderer(fn) để vẽ bảng của riêng mình.
 */
(function ($) {
    'use strict';

    var CFG = window.TGS_HMR || {};
    var rows = [];
    var running = false;
    var renderer = null;
    var nonceRefresh = null;

    var nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });

    function fmt(n) {
        return (n === null || n === undefined || n === '') ? '—' : nf.format(n);
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function ngay(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m) { return ''; }
        return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
    }

    /* Bỏ dấu để gõ "vinh tuong" vẫn ra "Vĩnh Tường" */
    function norm(s) {
        return String(s == null ? '' : s)
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/đ/g, 'd')
            .trim();
    }

    // ── Bộ lọc ──────────────────────────────────────────────────────────────

    /** Khoá của các mục chi nhánh đang tích ("3" hoặc "0::12001") */
    function selectedKeys() {
        return $('.hmr-site:checked').map(function () { return this.value; }).get();
    }

    /** blog_id đang tích, đã bỏ trùng — mọi mã kho lạ đều mang blog_id 0 */
    function selectedBlogs() {
        var seen = {};
        var out = [];

        $('.hmr-site:checked').each(function () {
            var bid = parseInt($(this).data('blog'), 10) || 0;
            if (!seen[bid]) { seen[bid] = 1; out.push(bid); }
        });

        return out;
    }

    /*
     * Mã kho đang tích. Value là mã trần, không cần ghép blog_id: mã kho ở đây
     * là tgs_site_code đã chuẩn hoá (hoặc mã thô của phần mềm cũ), duy nhất
     * trên toàn hệ thống — khác BC_TK, nơi mã phân kho có thể trùng giữa các
     * site nên phải ghép.
     */
    function selectedZones() {
        var seen = {};
        var out = [];

        $('.hmr-zone:checked').each(function () {
            if (!seen[this.value]) { seen[this.value] = 1; out.push(this.value); }
        });

        return out;
    }

    function siteByKey(key) {
        for (var i = 0; i < (CFG.sites || []).length; i++) {
            if (String(CFG.sites[i].key) === String(key)) { return CFG.sites[i]; }
        }
        return null;
    }

    /* Dựng khối "Mã kho" từ những chi nhánh đang tích, giữ nguyên lựa chọn cũ */
    function refreshZones() {
        var checked = {};
        $('.hmr-zone:checked').each(function () { checked[this.value] = 1; });

        var keys = selectedKeys();
        if (!keys.length) {
            $('#hmrZoneGroup').addClass('hmr-hidden');
            $('#hmrZoneList').empty();
            return;
        }

        var html = '';
        keys.forEach(function (key) {
            var site = siteByKey(key);
            var zones = (CFG.zones || {})[key] || [];
            if (!zones.length) { return; }

            /* Chỉ chia nhóm khi tích nhiều chi nhánh — một chi nhánh thì tiêu đề
               nhóm chỉ tổ chiếm chỗ */
            if (keys.length > 1) {
                html += '<div class="hmr-zone-head">' + esc(site ? site.label : key) + '</div>';
            }

            zones.forEach(function (z) {
                html += '<label class="hmr-item">'
                    + '<input class="form-check-input hmr-zone" type="checkbox" value="' + esc(z.zone_code) + '"'
                    + (checked[z.zone_code] ? ' checked' : '') + '>'
                    + '<span class="hmr-item__label">' + esc(z.label) + '</span>'
                    + '<span class="hmr-count">' + z.vouchers + '</span>'
                    + '</label>';
            });
        });

        $('#hmrZoneList').html(html);
        $('#hmrZoneGroup').toggleClass('hmr-hidden', html === '');
    }

    $(document).on('change', '.hmr-site', refreshZones);

    $('#hmrCheckAllSites').on('change', function () {
        $('.hmr-site').each(function () {
            if ($(this).closest('.hmr-item').is(':visible')) { this.checked = $('#hmrCheckAllSites')[0].checked; }
        });
        refreshZones();
    });

    $('#hmrCheckAllZones').on('change', function () {
        var on = this.checked;
        $('.hmr-zone').each(function () {
            if ($(this).closest('.hmr-item').is(':visible')) { this.checked = on; }
        });
    });

    function bindSearchBox(inputId, itemSelector) {
        $(inputId).on('input', function () {
            var q = norm(this.value);
            $(itemSelector).each(function () {
                var text = norm($(this).find('.hmr-item__label').text());
                $(this).toggle(q === '' || text.indexOf(q) !== -1);
            });
        });
    }

    bindSearchBox('#hmrSiteSearch', '#hmrSiteList .hmr-item');
    bindSearchBox('#hmrZoneSearch', '#hmrZoneList .hmr-item');

    $('#hmrToggleFilter').on('click', function () {
        $('#hmrPage').toggleClass('is-collapsed');
        $(this).text($('#hmrPage').hasClass('is-collapsed') ? '»' : '«');
    });

    /*
     * Cao đúng bằng chỗ còn trống, đo chứ không đoán.
     *
     * CSS để 78vh — một con số áng chừng, không biết bảng bắt đầu ở đâu trên
     * trang. Thanh admin, thanh mega nav, hàng tab, tiêu đề mỗi trang một khác,
     * người dùng lại còn phóng to thu nhỏ; chênh bao nhiêu thì thừa ra bấy
     * nhiêu khoảng trắng ở đáy. Đo từ vị trí thật rồi trừ thanh trạng thái cố
     * định thì không bao giờ lệch.
     *
     * 78vh vẫn giữ trong CSS làm đường lui nếu JS chưa chạy.
     */
    function fitHeight() {
        var page = document.getElementById('hmrPage');
        if (!page) { return; }

        page.style.height = '';
        var top = page.getBoundingClientRect().top;

        var bar = document.querySelector('.ds-statusbar');
        var barH = bar ? bar.getBoundingClientRect().height : 0;

        /* 10px thở dưới đáy cho khỏi dính sát thanh trạng thái */
        var h = window.innerHeight - top - barH - 10;

        page.style.height = Math.max(320, Math.round(h)) + 'px';
    }

    fitHeight();

    /* Đo lại sau khi font/ảnh tải xong — lúc đó chiều cao phía trên mới chốt */
    $(window).on('load', fitHeight);

    var fitTimer = null;
    $(window).on('resize', function () {
        clearTimeout(fitTimer);
        fitTimer = setTimeout(fitHeight, 120);
    });

    // ── Chạy báo cáo ────────────────────────────────────────────────────────

    function refreshNonce() {
        if (nonceRefresh) { return nonceRefresh; }

        nonceRefresh = $.post(CFG.ajaxUrl, { action: 'tgs_hmr_refresh_nonce' }).then(function (res) {
            if (res && res.success && res.data && res.data.nonce) {
                CFG.nonce = res.data.nonce;
                return true;
            }
            return $.Deferred().reject().promise();
        });

        nonceRefresh.always(function () { nonceRefresh = null; });

        return nonceRefresh;
    }

    function status(text, isError) {
        $('#hmrStatus').text(text || '').toggleClass('hmr-status--error', !!isError);
    }

    function run(isRetry) {
        if (running) { return; }

        if (!selectedKeys().length) {
            rows = [];
            render();
            status('Chưa chọn chi nhánh nào.', true);
            return;
        }

        /*
         * Mã kho là bộ lọc chốt và BẮT BUỘC khi có gì để chọn — giống BC_TK.
         * Bỏ trống rồi hiểu ngầm là "lấy hết" thì người đọc không biết con số
         * đang tính trên phạm vi nào, mà báo cáo đối chiếu thì mơ hồ về phạm vi
         * còn tệ hơn sai số.
         */
        var zones = selectedZones();
        if ($('.hmr-zone').length && !zones.length) {
            rows = [];
            render();
            status('Chưa chọn mã kho ở khối bên dưới.', true);
            $('#hmrZoneGroup').addClass('hmr-flash');
            setTimeout(function () { $('#hmrZoneGroup').removeClass('hmr-flash'); }, 1200);
            return;
        }

        running = true;
        $('#hmrSearch').prop('disabled', true);
        status('Đang lấy dữ liệu…');
        $('#hmrBody').html('<tr class="hmr-empty"><td colspan="30">Đang lấy dữ liệu…</td></tr>');

        var data = {
            action: CFG.action,
            nonce: CFG.nonce,
            blog_ids: selectedBlogs(),
            zones: zones,
            date_from: $('#hmrDateFrom').val(),
            date_to: $('#hmrDateTo').val()
        };

        if (typeof CFG.extraParams === 'function') {
            var extra = CFG.extraParams() || {};
            Object.keys(extra).forEach(function (k) { data[k] = extra[k]; });
        }

        /*
         * Lấy ĐỦ, không cắt bớt — nhưng chia trang mà lấy.
         *
         * Bảng render theo khung hình nên giữ hàng trăm nghìn dòng vẫn mượt;
         * cái không chịu nổi là dồn tất cả vào MỘT phản hồi. Nên máy chủ trả
         * theo trang, trình duyệt nối lại và báo tiến độ để người dùng biết
         * còn phải đợi bao lâu thay vì nhìn màn hình đứng im.
         *
         * Máy chủ trả kèm 'total' thì mới chia trang. Các màn chứng từ không
         * trả nên chạy đúng một lượt như cũ.
         */
        var got = [];
        var grandTotal = 0;

        function page(afterId) {
            var query = $.extend({}, data, { after_id: afterId });

            return $.post(CFG.ajaxUrl, query).then(function (res) {
                if (res && res.success && res.data) {
                    var batch = res.data.rows || [];
                    got = afterId ? got.concat(batch) : batch;

                    /* Chỉ trang đầu trả total — các trang sau khỏi đếm lại */
                    if (res.data.total) { grandTotal = res.data.total; }

                    /* batch rỗng: chốt lại, nếu không sẽ quay vòng vô tận */
                    if (grandTotal && got.length < grandTotal && batch.length) {
                        status('Đang lấy ' + nf.format(got.length) + ' / ' + nf.format(grandTotal) + ' dòng…');
                        return page(res.data.last_id);
                    }

                    return { rows: got, total: grandTotal || got.length };
                }

                /* admin-ajax trả trần "0"/"-1" khi nonce hỏng hoặc mất phiên */
                if ((res === 0 || res === '0' || res === -1 || res === '-1') && !isRetry) {
                    return $.Deferred().reject({ needNonce: true }).promise();
                }

                return $.Deferred().reject({
                    message: (res && res.data && res.data.message) || 'Máy chủ trả về dữ liệu không đọc được'
                }).promise();
            }, function (xhr) {
                var st = xhr ? xhr.status : 0;

                if ((st === 401 || st === 403) && !isRetry) {
                    return $.Deferred().reject({ needNonce: true }).promise();
                }

                return $.Deferred().reject({
                    message: st ? ('Máy chủ báo lỗi ' + st) : 'Mất kết nối tới máy chủ'
                }).promise();
            });
        }

        page(0).done(function (result) {
            rows = result.rows;

            /*
             * Máy chủ trả theo id để chia trang cho nhanh, nên thứ tự hiển thị
             * xếp ở đây — một lần, sau khi đã có đủ.
             */
            if (typeof CFG.sortRows === 'function' && rows.length) {
                status('Đang sắp xếp ' + nf.format(rows.length) + ' dòng…');
                rows.sort(CFG.sortRows);
            }

            render();
            status(nf.format(rows.length) + ' dòng');
            running = false;
            $('#hmrSearch').prop('disabled', false);
        }).fail(function (err) {
            err = err || {};

            if (err.needNonce) {
                return refreshNonce().then(function () {
                    running = false;
                    $('#hmrSearch').prop('disabled', false);
                    run(true);
                }, function () {
                    status('Phiên đăng nhập đã hết hạn — tải lại trang.', true);
                    running = false;
                    $('#hmrSearch').prop('disabled', false);
                });
            }

            status(err.message || 'Không lấy được dữ liệu', true);
            running = false;
            $('#hmrSearch').prop('disabled', false);
        });
    }

    function render() {
        if (renderer) {
            renderer(rows);
            return;
        }
        countLabel(rows.length, rows.length);
    }

    function countLabel(shown, total) {
        if (!total) {
            $('#hmrRowCount').text('không có dữ liệu');
            return;
        }

        $('#hmrRowCount').text(shown === total
            ? nf.format(total) + ' dòng'
            : nf.format(shown) + ' / ' + nf.format(total) + ' dòng (đang lọc theo cột)');
    }

    /**
     * Vẽ bảng + cộng dòng Tổng cộng, dùng chung cho mọi màn.
     *
     * ⚠️ Phải đi qua TGSDesignSystem.virtualBody, đừng tự đổ HTML.
     *
     * Design System tự gắn ô "Lọc" vào từng cột. Nếu trang tự đổ HTML thì nó
     * chỉ ẩn/hiện thẻ <tr> trong DOM, còn dòng TỔNG CỘNG vẫn cộng nguyên bộ dữ
     * liệu — lọc "bimbosan" ra vài chục dòng mà tổng tiền vẫn là tổng của cả
     * 15.000 dòng. Số sai mà nhìn rất thật, đúng kiểu dễ mang vào cuộc họp.
     *
     * Khai cellText thì DS lọc trên DỮ LIỆU và gọi onFilter với đúng tập dòng
     * còn lại — cộng lại tổng ở đó là khớp. Kèm theo được luôn vẽ theo khung
     * nhìn, cần thiết vì báo cáo này hay ra hàng chục nghìn dòng.
     */
    function paint(opts) {
        var all = opts.rows || [];
        var table = document.getElementById(opts.tableId);
        var ds = window.TGSDesignSystem;

        function done(view) {
            if (opts.footer) { opts.footer(view); }
            countLabel(view.length, all.length);
        }

        if (!all.length) {
            $('#' + opts.bodyId).html('<tr class="hmr-empty"><td colspan="' + opts.colspan
                + '">Không có dòng nào khớp tiêu chí.</td></tr>');
            done(all);
            return;
        }

        if (ds && ds.virtualBody && table) {
            ds.virtualBody({
                table: table,
                rows: all,
                rowHtml: opts.rowHtml,
                cellText: opts.cellText,
                onFilter: done
            });
        } else {
            /* Không có Design System thì vẫn phải xem được, chỉ là không lọc cột */
            var buf = [];
            for (var i = 0; i < all.length; i++) { buf.push(opts.rowHtml(all[i], i)); }
            $('#' + opts.bodyId).html(buf.join(''));
        }

        done(all);
    }

    $('#hmrSearch').on('click', function () { run(false); });
    $(document).on('hmr:search', function () { run(false); });

    // ── Modal xem lại phiếu ─────────────────────────────────────────────────

    function openVoucher(ledgerId) {
        var $modal = $('#hmrModal');
        if (!$modal.length) {
            $modal = $('<div id="hmrModal" class="hmr-modal"><div class="hmr-modal__box">'
                + '<div class="hmr-modal__head"><span id="hmrModalTitle">Chi tiết phiếu</span>'
                + '<button type="button" class="hmr-modal__close" id="hmrModalClose">×</button></div>'
                + '<div class="hmr-modal__body" id="hmrModalBody"></div></div></div>').appendTo('body');
        }

        $modal.addClass('is-open');
        $('#hmrModalBody').html('<div class="hmr-modal__loading">Đang tải…</div>');

        $.post(CFG.ajaxUrl, { action: 'tgs_hmr_voucher', nonce: CFG.nonce, id: ledgerId })
            .done(function (res) {
                if (!res || !res.success) {
                    $('#hmrModalBody').html('<div class="hmr-modal__loading">'
                        + esc((res && res.data && res.data.message) || 'Không tải được phiếu.') + '</div>');
                    return;
                }
                renderVoucher(res.data);
            })
            .fail(function () {
                $('#hmrModalBody').html('<div class="hmr-modal__loading">Mất kết nối tới máy chủ.</div>');
            });
    }

    function renderVoucher(data) {
        var h = data.header;
        var t = data.total;

        $('#hmrModalTitle').html(esc(h.loai) + ' — <strong>' + esc(h.voucher_code) + '</strong>');

        /* Phiếu mua và phiếu bán khác nhau ở đối tác: NCC hay khách hàng */
        var isBuy = (h.kind === 'purchase' || h.kind === 'sup_return');

        var meta = '<div class="hmr-vch__meta">'
            + '<div><span>Kho</span><b>' + esc(h.kho) + '</b></div>'
            + '<div><span>Chi nhánh</span><b>' + esc(h.chi_nhanh || '—') + '</b></div>'
            + '<div><span>Website</span><b>' + esc(h.shop_name || (h.blog_id ? ('#' + h.blog_id) : 'chưa khớp')) + '</b></div>'
            + '<div><span>Ngày</span><b>' + ngay(h.voucher_date) + '</b></div>'
            + (isBuy
                ? '<div><span>Nhà cung cấp</span><b>' + esc(h.supplier_name || '—')
                    + (h.supplier_code ? ' <i>(' + esc(h.supplier_code) + ')</i>' : '') + '</b></div>'
                  + '<div><span>Số hoá đơn</span><b>' + esc(h.invoice_no || '—') + '</b></div>'
                : '<div><span>Khách hàng</span><b>' + esc(h.customer_name || '—')
                    + (h.customer_code ? ' <i>(' + esc(h.customer_code) + ')</i>' : '') + '</b></div>'
                  + '<div><span>Kênh bán</span><b>' + esc(h.channel || '—') + '</b></div>')
            + '<div><span>Nhân viên</span><b>' + esc(h.staff_name || '—')
                + (h.staff_code ? ' <i>(' + esc(h.staff_code) + ')</i>' : '') + '</b></div>'
            + '<div><span>Hình thức TT</span><b>' + esc(h.payment_label || '—') + '</b></div>'
            /* CỐ Ý không hiện tên file nguồn: nhìn thấy tên file Excel là lộ ngay
               dữ liệu được nạp vào chứ không phát sinh tại chỗ. Cột source_file
               vẫn còn trong DB để truy vết khi cần. */
            + '</div>';

        var body = data.items.map(function (r, i) {
            return '<tr>'
                + '<td class="c-num">' + (i + 1) + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name">' + esc(r.ten) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.gia) + '</td>'
                + '<td class="c-num">' + fmt(r.ck) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-num"><b>' + fmt(r.tien) + '</b></td>'
                + '</tr>'
                + (r.canh_bao ? '<tr class="hmr-vch__warn"><td></td><td colspan="9">⚠ ' + esc(r.canh_bao) + '</td></tr>' : '');
        }).join('');

        var table = '<table class="hmr-vch__table"><thead><tr>'
            + '<th>#</th><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>'
            + '<th class="c-num">SL</th><th class="c-num">Đơn giá</th><th class="c-num">Chiết khấu</th>'
            + '<th class="c-num">TT trước thuế</th><th class="c-num">Thuế</th><th class="c-num">Thành tiền</th>'
            + '</tr></thead><tbody>' + body + '</tbody>'
            + '<tfoot><tr>'
            + '<td colspan="4">Tổng ' + data.items.length + ' dòng</td>'
            + '<td class="c-num">' + fmt(t.qty) + '</td><td></td>'
            + '<td class="c-num">' + fmt(t.ck) + '</td>'
            + '<td class="c-num">' + fmt(t.truoc_thue) + '</td>'
            + '<td class="c-num">' + fmt(t.thue) + '</td>'
            + '<td class="c-num"><b>' + fmt(t.tien) + '</b></td>'
            + '</tr></tfoot></table>';

        /* Nhãn hiển thị gọi là Btsoft — xem chú thích ở TGS_HMR_Plugin::views() */
        var note = '<div class="hmr-vch__note">Số liệu Btsoft, cập nhật lúc '
            + ngay(h.updated_at) + '.</div>';

        $('#hmrModalBody').html(meta + table + note);
    }

    $(document).on('click', '.hmr-voucher-link', function (e) {
        e.preventDefault();
        openVoucher($(this).data('ledger'));
    });

    $(document).on('click', '#hmrModalClose, .hmr-modal', function (e) {
        if (e.target === this) { $('#hmrModal').removeClass('is-open'); }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { $('#hmrModal').removeClass('is-open'); }
    });

    // ── API cho trang ───────────────────────────────────────────────────────

    window.TGSHmr = {
        esc: esc,
        fmt: fmt,
        ngay: ngay,
        paint: paint,
        setRenderer: function (fn) { renderer = fn; render(); }
    };
})(jQuery);
