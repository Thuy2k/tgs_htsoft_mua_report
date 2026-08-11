<?php
/**
 * Tồn kho theo mặt hàng — giữ nguyên từng kho / phân kho.
 *
 * Nguồn: bảng global_htsoft_stock_zone do plugin tgs_htsoft_purchase_analysis
 * nạp. Màn này chỉ đọc.
 *
 * Không có ô chọn ngày: tồn là ảnh chụp tại lần nạp gần nhất chứ không phải
 * phát sinh trong kỳ — xem chú thích ở TGS_HMR_Ajax::fetch_stock().
 */

if (!defined('ABSPATH')) {
    exit;
}

$hmr_boot = TGS_HMR_Sites::filter_bootstrap_stock();

/* Bật ô lọc mã hàng phía máy chủ — xem chú thích trong filter-sidebar.php */
$hmr_sku_filter = true;
?>

<div class="hmr-page" id="hmrPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="hmr-result">
        <div class="hmr-result__head">
            <div class="hmr-headline">
                <strong>Tồn kho theo mặt hàng</strong>
                <span class="hmr-badge-src">Btsoft</span>
                <label class="hmr-daterange">
                    <input type="checkbox" id="hmrShowZero"> Hiện cả mã tồn 0
                </label>
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
                        <th class="c-name">Alias</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num">SL đi đường</th>
                        <th class="c-num" title="Tồn hiện có cộng hàng đang về">Tồn + đang về</th>
                        <th class="c-num">Tồn min</th>
                        <th class="c-num">Tồn max</th>
                        <th class="c-num" title="Âm nghĩa là đang thừa so với tồn max">SL cần nhập</th>
                        <th class="c-num">Đơn giá</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num">Giá bán lẻ</th>
                        <th class="c-num">Giá bán buôn</th>
                        <th class="c-unit">Theo dõi</th>
                        <th class="c-sku">Cập nhật</th>
                    </tr>
                </thead>
                <tbody id="hmrBody">
                    <tr class="hmr-empty"><td colspan="17">Chọn chi nhánh và mã kho bên trái rồi bấm <strong>Tìm kiếm</strong>.</td></tr>
                </tbody>
                <?php /* Cộng cho khớp 17 cột: 5 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 2 */ ?>
                <tfoot id="hmrFoot" class="hmr-hidden">
                    <tr>
                        <td colspan="5">Tổng cộng</td>
                        <td class="c-num" id="fTon">0</td>
                        <td class="c-num" id="fDiDuong">0</td>
                        <td class="c-num" id="fTonVe">0</td>
                        <td></td>
                        <td class="c-num" id="fTonMax">0</td>
                        <td class="c-num" id="fCanNhap">0</td>
                        <td></td>
                        <td class="c-num" id="fTien">0</td>
                        <td colspan="4"></td>
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
        action: 'tgs_hmr_fetch_stock',
        group: 'stock',
        sites: <?php echo wp_json_encode($hmr_boot['sites']); ?>,
        zones: <?php echo wp_json_encode($hmr_boot['zones']); ?>,
        extraParams: function () {
            return {
                show_zero: document.getElementById('hmrShowZero').checked ? 1 : 0,
                skus: (document.getElementById('hmrSkus') || {}).value || ''
            };
        },
        /* Gom theo kho, trong mỗi kho tồn nhiều xếp trước — giống phần mềm cũ */
        sortRows: function (a, b) {
            if (a.kho !== b.kho) { return a.kho < b.kho ? -1 : 1; }
            return b.ton - a.ton;
        }
    };

    jQuery(function ($) {
        var H = window.TGSHmr;
        if (!H) { return; }

        var esc = H.esc, fmt = H.fmt, ngay = H.ngay;

        $('#hmrShowZero').on('change', function () {
            if ($('.hmr-site:checked').length) { $(document).trigger('hmr:search'); }
        });

        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.sku || '';
                case 2:  return r.ten || '';
                case 3:  return r.alias || '';
                case 4:  return r.dvt || '';
                case 5:  return fmt(r.ton);
                case 6:  return fmt(r.di_duong);
                case 7:  return fmt(r.ton_va_ve);
                case 8:  return fmt(r.ton_min);
                case 9:  return fmt(r.ton_max);
                case 10: return fmt(r.can_nhap);
                case 11: return fmt(r.gia);
                case 12: return fmt(r.tien);
                case 13: return fmt(r.gia_le);
                case 14: return fmt(r.gia_buon);
                case 15: return r.theo_doi ? 'x' : '';
                case 16: return ngay(r.cap_nhat);
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '">'
                + '<td class="c-zone">' + esc(r.kho) + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten) + '">' + esc(r.ten) + '</td>'
                + '<td class="c-name">' + esc(r.alias) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num' + (r.ton < 0 ? ' neg' : '') + '">' + fmt(r.ton) + '</td>'
                + '<td class="c-num">' + fmt(r.di_duong) + '</td>'
                + '<td class="c-num">' + fmt(r.ton_va_ve) + '</td>'
                + '<td class="c-num">' + fmt(r.ton_min) + '</td>'
                + '<td class="c-num">' + fmt(r.ton_max) + '</td>'
                /* Cần nhập > 0 là đang thiếu — tô đỏ để người mua bắt được ngay */
                + '<td class="c-num' + (r.can_nhap > 0 ? ' hmr-diff' : '') + '">' + fmt(r.can_nhap) + '</td>'
                + '<td class="c-num">' + fmt(r.gia) + '</td>'
                + '<td class="c-num">' + fmt(r.tien) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_le) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_buon) + '</td>'
                + '<td class="c-unit">' + (r.theo_doi ? '&#10003;' : '') + '</td>'
                + '<td class="c-sku">' + ngay(r.cap_nhat) + '</td>'
                + '</tr>';
        }

        function footer(rows) {
            var t = { ton: 0, ve: 0, tonve: 0, max: 0, can: 0, tien: 0 };
            rows.forEach(function (r) {
                t.ton += (r.ton || 0);
                t.ve += (r.di_duong || 0);
                t.tonve += (r.ton_va_ve || 0);
                t.max += (r.ton_max || 0);
                t.can += (r.can_nhap || 0);
                t.tien += (r.tien || 0);
            });

            $('#fTon').text(fmt(t.ton));
            $('#fDiDuong').text(fmt(t.ve));
            $('#fTonVe').text(fmt(t.tonve));
            $('#fTonMax').text(fmt(t.max));
            $('#fCanNhap').text(fmt(t.can));
            $('#fTien').text(fmt(t.tien));
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
