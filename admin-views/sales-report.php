<?php
/**
 * Đối chiếu HTsoft — Báo cáo bán hàng / Hàng bán trả lại (chi tiết từng dòng).
 *
 * Cột và cách dựng số bám đúng màn cùng tên của BC_TK, để đặt cạnh nhau soi
 * chênh lệch mà không phải quy đổi gì thêm.
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
                <strong>Báo cáo bán hàng</strong>
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
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-sku">Ngày tạo</th>
                        <th class="c-unit">ĐVCB</th>
                        <th class="c-name">Nhóm hàng</th>
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-sku">Lý do</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num" title="Đơn giá theo đơn vị nhỏ nhất, đã gồm thuế">Đơn giá</th>
                        <th class="c-num">Chiết khấu</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num" title="Bán cộng vào, trả lại trừ ra">Doanh thu thuần</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Mã NV</th>
                        <th class="c-name">Khách hàng</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-num">Thuế</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-num">SL ĐVMR</th>
                        <th class="c-num">ĐG ĐVMR</th>
                        <th class="c-sku">Hình thức TT</th>
                        <th class="c-sku">Số lô</th>
                        <th class="c-sku">EXPDATE</th>
                        <th class="c-sku">Kênh bán hàng</th>
                        <th class="c-num">TT trước thuế</th>
                        <th class="c-num" title="Thành tiền nguyên văn trên chứng từ — lệch với cột Thành tiền là do làm tròn">TT gốc</th>
                    </tr>
                </thead>
                <tbody id="hmrBody">
                    <tr class="hmr-empty"><td colspan="28">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td></tr>
                </tbody>
                <?php
                /*
                 * Số ô ở tfoot PHẢI bằng đúng 28 — số cột trong thead.
                 *
                 * Cộng: 8 + 1 + 1 + 1 + 1 + 1 + 5 + 1 + 7 + 1 + 1 = 28
                 *                                ↑
                 *                                Nhân viên…Trả lại (5 cột)
                 *
                 * Thiếu một ô là mọi con số phía sau chỗ hở bị đẩy sang TRÁI một
                 * cột. Số vẫn đúng nên nhìn lướt không thấy sai, chỉ đọc nhầm
                 * cột. Đã xảy ra thật ở màn mua.
                 */
                ?>
                <tfoot id="hmrFoot" class="hmr-hidden">
                    <tr>
                        <td colspan="8">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td></td>
                        <td class="c-num" id="fCk">0</td>
                        <td class="c-num" id="fTien">0</td>
                        <td class="c-num" id="fDoanhThu">0</td>
                        <td colspan="5"></td>
                        <td class="c-num" id="fThue">0</td>
                        <td colspan="7"></td>
                        <td class="c-num" id="fTruocThue">0</td>
                        <td class="c-num" id="fGoc">0</td>
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
        action: 'tgs_hmr_fetch_sales',
        sites: <?php echo wp_json_encode($hmr_boot['sites']); ?>,
        zones: <?php echo wp_json_encode($hmr_boot['zones']); ?>,
        extraParams: function () {
            return { loai: document.getElementById('hmrKind').value };
        }
    };

    jQuery(function ($) {
        var H = window.TGSHmr;
        if (!H) { return; }

        var esc = H.esc, fmt = H.fmt, ngay = H.ngay;

        /* Đổi loại thì số cũ không còn đúng nữa — chạy lại luôn cho khỏi đọc nhầm */
        $('#hmrKind').on('change', function () {
            if ($('.hmr-site:checked').length) { $(document).trigger('hmr:search'); }
        });

        /*
         * Chữ của từng cột — PHẢI khớp thứ tự cột trong <thead>.
         *
         * Design System dùng hàm này cho cả lọc theo cột lẫn xuất Excel. Không
         * khai thì nó lọc bằng cách đọc DOM, mà DOM chỉ có mấy chục dòng đang
         * hiện nên lọc ra thiếu, và dòng Tổng cộng thì vẫn cộng nguyên bộ.
         */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.sku || '';
                case 2:  return r.ten || '';
                case 3:  return ngay(r.ngay);
                case 4:  return r.dvcb || '';
                case 5:  return r.nhom || '';
                case 6:  return r.pbh || '';
                case 7:  return r.ly_do || '';
                case 8:  return fmt(r.qty);
                case 9:  return fmt(r.gia);
                case 10: return fmt(r.ck);
                case 11: return fmt(r.tien);
                case 12: return fmt(r.doanh_thu);
                case 13: return r.nv_ten || '';
                case 14: return r.nv_ma || '';
                case 15: return r.kh_ten || '';
                case 16: return r.kh_ma || '';
                case 17: return r.tra_lai ? 'x' : '';
                case 18: return fmt(r.thue);
                case 19: return r.dvt || '';
                case 20: return fmt(r.sl_dvmr);
                case 21: return fmt(r.gia_dvmr);
                case 22: return r.httt || '';
                case 23: return r.so_lo || '';
                case 24: return ngay(r.exp);
                case 25: return r.kenh || '';
                case 26: return fmt(r.truoc_thue);
                case 27: return fmt(r.tt_htsoft);
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '" data-ledger="' + r.ledger_id + '"'
                + (r.tra_lai ? ' class="hmr-row-return"' : '') + '>'
                + '<td class="c-zone">' + esc(r.kho) + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten) + '">' + esc(r.ten) + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-unit">' + esc(r.dvcb) + '</td>'
                + '<td class="c-name" title="' + esc(r.nhom) + '">' + esc(r.nhom) + '</td>'
                + '<td class="c-sku"><a href="#" class="hmr-voucher-link" data-ledger="' + r.ledger_id + '">'
                    + esc(r.pbh) + '</a></td>'
                + '<td class="c-sku">' + esc(r.ly_do) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.gia) + '</td>'
                + '<td class="c-num">' + fmt(r.ck) + '</td>'
                + '<td class="c-num">' + fmt(r.tien) + '</td>'
                + '<td class="c-num' + (r.doanh_thu < 0 ? ' neg' : '') + '">' + fmt(r.doanh_thu) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.nv_ma) + '</td>'
                + '<td class="c-name">' + esc(r.kh_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_ma) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.sl_dvmr) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_dvmr) + '</td>'
                + '<td class="c-sku">' + esc(r.httt) + '</td>'
                + '<td class="c-sku">' + esc(r.so_lo) + '</td>'
                + '<td class="c-sku">' + ngay(r.exp) + '</td>'
                + '<td class="c-sku">' + esc(r.kenh) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '<td class="c-num' + (Math.abs((r.tt_htsoft || 0) - r.tien) > 1 ? ' hmr-diff' : '') + '"'
                    + (r.canh_bao ? ' title="' + esc(r.canh_bao) + '"' : '') + '>'
                    + fmt(r.tt_htsoft) + '</td>'
                + '</tr>';
        }

        function footer(rows) {
            var t = { qty: 0, ck: 0, tien: 0, dt: 0, thue: 0, truoc: 0, goc: 0 };
            rows.forEach(function (r) {
                t.qty += (r.qty || 0);
                t.ck += (r.ck || 0);
                t.tien += (r.tien || 0);
                t.dt += (r.doanh_thu || 0);
                t.thue += (r.thue || 0);
                t.truoc += (r.truoc_thue || 0);
                t.goc += (r.tt_htsoft || 0);
            });

            $('#fQty').text(fmt(t.qty));
            $('#fCk').text(fmt(t.ck));
            $('#fTien').text(fmt(t.tien));
            $('#fDoanhThu').text(fmt(t.dt)).toggleClass('neg', t.dt < 0);
            $('#fThue').text(fmt(t.thue));
            $('#fTruocThue').text(fmt(t.truoc));
            $('#fGoc').text(fmt(t.goc));
            $('#hmrFoot').toggleClass('hmr-hidden', rows.length === 0);
        }

        H.setRenderer(function (rows) {
            H.paint({
                tableId: 'hmrTable',
                bodyId: 'hmrBody',
                colspan: 28,
                rows: rows,
                rowHtml: rowHtml,
                cellText: cellText,
                footer: footer
            });
        });
    });
</script>
