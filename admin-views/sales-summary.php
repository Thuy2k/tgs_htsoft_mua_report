<?php
/**
 * Đối chiếu HTsoft — Tổng hợp bán hàng: mỗi PHIẾU một dòng.
 *
 * Bấm vào dòng để mở modal xem lại phiếu (đủ header + từng dòng hàng + tổng
 * tiền) — đúng thứ phòng mua cần khi soi một phiếu đáng ngờ.
 */

if (!defined('ABSPATH')) {
    exit;
}

$hmr_boot  = TGS_HMR_Sites::filter_bootstrap();
$hmr_today = current_time('Y-m-d');
?>

<div class="hmr-page" id="hmrPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="hmr-result">
        <div class="hmr-result__head">
            <div class="hmr-headline">
                <strong>Tổng hợp bán hàng</strong>
                <span class="hmr-badge-src">Btsoft</span>
                <span class="hmr-daterange">
                    Từ <input type="date" id="hmrDateFrom" value="<?php echo esc_attr($hmr_today); ?>">
                    đến <input type="date" id="hmrDateTo" value="<?php echo esc_attr($hmr_today); ?>">
                </span>
                <span class="hmr-daterange">
                    Loại
                    <select id="hmrKind">
                        <option value="sale" selected>Phiếu bán hàng</option>
                        <option value="return">Hàng bán trả lại</option>
                        <option value="all">Tất cả</option>
                    </select>
                </span>
            </div>
            <span class="hmr-count-label" id="hmrRowCount">chưa tìm kiếm</span>
        </div>

        <div class="hmr-tablewrap">
            <table class="hmr-table" id="hmrTable">
                <thead>
                    <tr>
                        <th class="c-zone">Kho</th>
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-name">Loại</th>
                        <th class="c-sku">Ngày</th>
                        <th class="c-num">Số món</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num">Chiết khấu</th>
                        <th class="c-num">TT trước thuế</th>
                        <th class="c-num">Thuế</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num">Doanh thu thuần</th>
                        <th class="c-name">Khách hàng</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Hình thức TT</th>
                        <th class="c-sku">Kênh bán hàng</th>
                        <th class="c-unit">Xem</th>
                    </tr>
                </thead>
                <tbody id="hmrBody">
                    <tr class="hmr-empty"><td colspan="17">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td></tr>
                </tbody>
                <?php /* Cộng cho khớp 17 cột: 4 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 6 */ ?>
                <tfoot id="hmrFoot" class="hmr-hidden">
                    <tr>
                        <td colspan="4">Tổng cộng</td>
                        <td class="c-num" id="fMon">0</td>
                        <td class="c-num" id="fQty">0</td>
                        <td class="c-num" id="fCk">0</td>
                        <td class="c-num" id="fTruocThue">0</td>
                        <td class="c-num" id="fThue">0</td>
                        <td class="c-num" id="fTien">0</td>
                        <td class="c-num" id="fDoanhThu">0</td>
                        <td colspan="6"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<script>
    window.TGS_HMR = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_HMR_Ajax::NONCE)); ?>',
        action: 'tgs_hmr_fetch_summary',
        sites: <?php echo wp_json_encode($hmr_boot['sites']); ?>,
        zones: <?php echo wp_json_encode($hmr_boot['zones']); ?>,
        /* Máy chủ trả theo id để chia trang; thứ tự cho người xem dựng lại ở đây */
        sortRows: function (a, b) { return window.TGSHmr.sortByNgayPhieu(a, b); },
        extraParams: function () {
            return { loai: document.getElementById('hmrKind').value };
        }
    };

    jQuery(function ($) {
        var H = window.TGSHmr;
        if (!H) { return; }

        var esc = H.esc, fmt = H.fmt, ngay = H.ngay;

        $('#hmrKind').on('change', function () {
            if ($('.hmr-site:checked').length) { $(document).trigger('hmr:search'); }
        });

        /* Chữ của từng cột — PHẢI khớp thứ tự cột trong <thead>. Design System
           dùng cho cả lọc theo cột lẫn xuất Excel; thiếu là dòng Tổng cộng
           không cộng theo phần đang lọc. */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.pbh || '';
                case 2:  return r.loai || '';
                case 3:  return ngay(r.ngay);
                case 4:  return fmt(r.so_mon);
                case 5:  return fmt(r.qty);
                case 6:  return fmt(r.ck);
                case 7:  return fmt(r.truoc_thue);
                case 8:  return fmt(r.thue);
                case 9:  return fmt(r.tien);
                case 10: return fmt(r.doanh_thu);
                case 11: return r.kh_ten || '';
                case 12: return r.kh_ma || '';
                case 13: return r.nv_ten || '';
                case 14: return r.httt || '';
                case 15: return r.kenh || '';
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '" data-ledger="' + r.id + '"'
                + (r.tra_lai ? ' class="hmr-row-return"' : '') + '>'
                + '<td class="c-zone">' + esc(r.kho) + '</td>'
                + '<td class="c-sku"><a href="#" class="hmr-voucher-link" data-ledger="' + r.id + '">'
                    + esc(r.pbh) + '</a></td>'
                + '<td class="c-name">' + esc(r.loai)
                    + (r.canh_bao ? ' <span class="hmr-warn" title="' + r.canh_bao
                        + ' dòng có số tiền không tự khớp">!</span>' : '') + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-num">' + fmt(r.so_mon) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.ck) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-num">' + fmt(r.tien) + '</td>'
                + '<td class="c-num' + (r.doanh_thu < 0 ? ' neg' : '') + '">' + fmt(r.doanh_thu) + '</td>'
                + '<td class="c-name">' + esc(r.kh_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_ma) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.httt) + '</td>'
                + '<td class="c-sku">' + esc(r.kenh) + '</td>'
                + '<td class="c-unit"><button type="button" class="hmr-mini hmr-voucher-link" data-ledger="'
                    + r.id + '">Xem phiếu</button></td>'
                + '</tr>';
        }

        function footer(rows) {
            var t = { mon: 0, qty: 0, ck: 0, truoc: 0, thue: 0, tien: 0, dt: 0 };
            rows.forEach(function (r) {
                t.mon += (r.so_mon || 0);
                t.qty += (r.qty || 0);
                t.ck += (r.ck || 0);
                t.truoc += (r.truoc_thue || 0);
                t.thue += (r.thue || 0);
                t.tien += (r.tien || 0);
                t.dt += (r.doanh_thu || 0);
            });

            $('#fMon').text(fmt(t.mon));
            $('#fQty').text(fmt(t.qty));
            $('#fCk').text(fmt(t.ck));
            $('#fTruocThue').text(fmt(t.truoc));
            $('#fThue').text(fmt(t.thue));
            $('#fTien').text(fmt(t.tien));
            $('#fDoanhThu').text(fmt(t.dt)).toggleClass('neg', t.dt < 0);
            $('#hmrFoot').toggleClass('hmr-hidden', rows.length === 0);
        }

        H.setRenderer(function (rows) {
            H.paint({
                tableId: 'hmrTable',
                bodyId: 'hmrBody',
                colspan: 17,
                rows: rows,
                rowHtml: rowHtml,
                cellText: cellText,
                footer: footer
            });
        });
    });
</script>
