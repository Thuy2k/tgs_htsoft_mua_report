<?php
/**
 * Báo cáo mua hàng / trả nhà cung cấp — chi tiết từng dòng hàng.
 *
 * Khác màn bán ở ba cột đặc thù: Chi nhánh (mã ánh xạ website), Nhà cung cấp và
 * Số HĐ. Cột "Kho" ở đây là PHÂN KHO (08-HH), không phải mã chi nhánh.
 */

if (!defined('ABSPATH')) {
    exit;
}

$hmr_boot  = TGS_HMR_Sites::filter_bootstrap('purchase');
$hmr_today = current_time('Y-m-d');
?>

<div class="hmr-page" id="hmrPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="hmr-result">
        <div class="hmr-result__head">
            <div class="hmr-headline">
                <strong>Báo cáo mua hàng</strong>
                <span class="hmr-badge-src">Btsoft</span>
                <span class="hmr-daterange">
                    Từ <input type="date" id="hmrDateFrom" value="<?php echo esc_attr($hmr_today); ?>">
                    đến <input type="date" id="hmrDateTo" value="<?php echo esc_attr($hmr_today); ?>">
                </span>
                <span class="hmr-daterange">
                    Loại
                    <select id="hmrKind">
                        <option value="all" selected>Tất cả</option>
                        <option value="purchase">Phiếu nhập mua</option>
                        <option value="sup_return">Trả nhà cung cấp</option>
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
                        <th class="c-sku">Chi nhánh</th>
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-sku">Ngày</th>
                        <th class="c-unit">ĐVCB</th>
                        <th class="c-name">Nhóm hàng</th>
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-sku">Lý do</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num" title="Đơn giá TRƯỚC thuế, theo đơn vị nhỏ nhất — đúng như giá nhà cung cấp báo và như phần mềm cũ hiển thị">Đơn giá</th>
                        <th class="c-num" title="Chiết khấu của cả dòng, tính trên tiền TRƯỚC thuế">Chiết khấu</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num" title="Nhập cộng vào, trả nhà cung cấp trừ ra">Giá trị thuần</th>
                        <th class="c-num">Thuế</th>
                        <th class="c-num">TT trước thuế</th>
                        <th class="c-name">Nhà cung cấp</th>
                        <th class="c-sku">Mã NCC</th>
                        <th class="c-sku">Số HĐ</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-num">SL ĐVMR</th>
                        <th class="c-num">ĐG ĐVMR</th>
                        <th class="c-sku">Số lô</th>
                        <th class="c-sku">EXPDATE</th>
                        <th class="c-name">Ghi chú</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-num" title="Thành tiền nguyên văn trên chứng từ — lệch là do làm tròn">TT gốc</th>
                    </tr>
                </thead>
                <tbody id="hmrBody">
                    <tr class="hmr-empty"><td colspan="28">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td></tr>
                </tbody>
                <?php
                /*
                 * Số ô ở tfoot PHẢI bằng đúng 28 — số cột trong thead.
                 *
                 * Cộng: 9 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 11 + 1 = 28
                 *       ↑                                  ↑
                 *       Kho…Lý do                          NCC…Nhân viên
                 *
                 * Thiếu một ô là mọi con số phía sau chỗ hở bị đẩy sang TRÁI một
                 * cột — tổng "TT gốc" nằm dưới "Nhân viên". Số vẫn đúng nên nhìn
                 * lướt không thấy sai, chỉ đọc nhầm cột. Đã xảy ra thật.
                 */
                ?>
                <tfoot id="hmrFoot" class="hmr-hidden">
                    <tr>
                        <td colspan="9">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td></td>
                        <td class="c-num" id="fCk">0</td>
                        <td class="c-num" id="fTien">0</td>
                        <td class="c-num" id="fDoanhThu">0</td>
                        <td class="c-num" id="fThue">0</td>
                        <td class="c-num" id="fTruocThue">0</td>
                        <td colspan="11"></td>
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
        group: 'purchase',
        sites: <?php echo wp_json_encode($hmr_boot['sites']); ?>,
        zones: <?php echo wp_json_encode($hmr_boot['zones']); ?>,
        extraParams: function () {
            return { loai: document.getElementById('hmrKind').value, group: 'purchase' };
        },
        /* Máy chủ trả theo id để chia trang; thứ tự cho người xem dựng lại ở đây */
        sortRows: function (a, b) { return window.TGSHmr.sortByNgayPhieu(a, b); }
    };

    jQuery(function ($) {
        var H = window.TGSHmr;
        if (!H) { return; }

        var esc = H.esc, fmt = H.fmt, ngay = H.ngay;

        $('#hmrKind').on('change', function () {
            if ($('.hmr-site:checked').length) { $(document).trigger('hmr:search'); }
        });

        /* Chữ từng cột — PHẢI khớp thứ tự <thead>; Design System dùng cho cả lọc
           theo cột lẫn xuất Excel, thiếu là dòng Tổng cộng không theo phần lọc */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.chi_nhanh || '';
                case 2:  return r.sku || '';
                case 3:  return r.ten || '';
                case 4:  return ngay(r.ngay);
                case 5:  return r.dvcb || '';
                case 6:  return r.nhom || '';
                case 7:  return r.pbh || '';
                case 8:  return r.ly_do || '';
                case 9:  return fmt(r.qty);
                case 10: return fmt(r.gia);
                case 11: return fmt(r.ck);
                case 12: return fmt(r.tien);
                case 13: return fmt(r.doanh_thu);
                case 14: return fmt(r.thue);
                case 15: return fmt(r.truoc_thue);
                case 16: return r.ncc || '';
                case 17: return r.ncc_ma || '';
                case 18: return r.so_hd || '';
                case 19: return r.tra_lai ? 'x' : '';
                case 20: return r.dvt || '';
                case 21: return fmt(r.sl_dvmr);
                case 22: return fmt(r.gia_dvmr);
                case 23: return r.so_lo || '';
                case 24: return ngay(r.exp);
                case 25: return r.ghi_chu || '';
                case 26: return r.nv_ten || '';
                case 27: return fmt(r.tt_htsoft);
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '" data-ledger="' + r.ledger_id + '"'
                + (r.tra_lai ? ' class="hmr-row-return"' : '') + '>'
                + '<td class="c-zone">' + esc(r.kho) + '</td>'
                + '<td class="c-sku">' + esc(r.chi_nhanh) + '</td>'
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
                + '<td class="c-num' + (r.tien < 0 ? ' neg' : '') + '">' + fmt(r.tien) + '</td>'
                + '<td class="c-num' + (r.doanh_thu < 0 ? ' neg' : '') + '">' + fmt(r.doanh_thu) + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '<td class="c-name" title="' + esc(r.ncc) + '">' + esc(r.ncc) + '</td>'
                + '<td class="c-sku">' + esc(r.ncc_ma) + '</td>'
                + '<td class="c-sku">' + esc(r.so_hd) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.sl_dvmr) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_dvmr) + '</td>'
                + '<td class="c-sku">' + esc(r.so_lo) + '</td>'
                + '<td class="c-sku">' + ngay(r.exp) + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
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
            $('#fTien').text(fmt(t.tien)).toggleClass('neg', t.tien < 0);
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
