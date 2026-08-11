<?php
/**
 * Truy vấn + dựng số cho báo cáo đối chiếu HTsoft (phòng mua).
 *
 * Nguồn dữ liệu là bộ bảng `_mua` do plugin tgs_htsoft_mua_import kéo về —
 * KHÔNG đụng `local_ledger` của bất kỳ site nào.
 *
 * ── CÁCH DỰNG SỐ PHẢI GIỐNG HỆT BÁO CÁO DỮ LIỆU THẬT ────────────────────────
 *
 * Mục đích của màn này là đặt cạnh BC_TK để soi chênh lệch. Nếu hai bên làm
 * tròn khác nhau thì mọi dòng đều lệch vài đồng, và người đọc phải tự đoán chỗ
 * nào là chênh lệch thật, chỗ nào chỉ là làm tròn. Nên toàn bộ công thức và thứ
 * tự làm tròn ở đây bám đúng TGS_BCTK_Ajax::build_sales_rows():
 *
 *   1. Thành tiền  — chốt trước, đây là tiền THẬT khách trả
 *   2. Đơn giá     — suy từ tiền hàng gốc (trước CK, sau thuế)
 *   3. Chiết khấu  — phần dư: đơn giá × SL − thành tiền
 *
 * Làm tròn cả ba độc lập thì dòng không cộng khít, nhìn như tính sai.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HMR_Report
{
    public static function table_ledger()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_ledger_mua';
    }

    public static function table_item()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_ledger_item_mua';
    }

    /** Lớp tính tiền dùng chung — thiếu thì không dựng số, xem chú thích ở trên */
    public static function money_ready()
    {
        if (class_exists('TGS_Money')) {
            return true;
        }

        $file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-money.php';
        if (file_exists($file)) {
            require_once $file;
        }

        return class_exists('TGS_Money');
    }

    /**
     * Điều kiện lọc dùng chung cho cả ba màn.
     *
     * @return array [$where_sql, $params]
     */
    private static function build_where($alias, array $filters)
    {
        $where  = [];
        $params = [];

        $blogs = isset($filters['blog_ids']) ? array_map('intval', (array) $filters['blog_ids']) : [];
        if ($blogs) {
            $where[] = "{$alias}.blog_id IN (" . implode(',', array_fill(0, count($blogs), '%d')) . ')';
            $params = array_merge($params, $blogs);
        }

        $zones = isset($filters['zones']) ? array_values(array_filter((array) $filters['zones'], 'strlen')) : [];
        if ($zones) {
            $where[] = "{$alias}.site_code IN (" . implode(',', array_fill(0, count($zones), '%s')) . ')';
            $params = array_merge($params, $zones);
        }

        $kind = isset($filters['kind']) ? $filters['kind'] : 'sale';
        if ($kind === 'sale' || $kind === 'return') {
            $where[] = "{$alias}.kind = %s";
            $params[] = $kind;
        }

        $date_col = ($alias === 'i') ? 'row_date' : 'voucher_date';
        $where[] = "{$alias}.{$date_col} BETWEEN %s AND %s";
        $params[] = $filters['date_from'] . ' 00:00:00';
        $params[] = $filters['date_to'] . ' 23:59:59';

        return [implode(' AND ', $where), $params];
    }

    // =========================================================================
    // BÁO CÁO BÁN HÀNG — chi tiết từng dòng hàng
    // =========================================================================

    public static function sales_rows(array $filters)
    {
        global $wpdb;

        if (!self::money_ready()) {
            return ['rows' => [], 'error' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).'];
        }

        $item   = self::table_item();
        $ledger = self::table_ledger();

        list($where_sql, $params) = self::build_where('i', $filters);

        $sql = "SELECT i.*, l.id AS ledger_id, l.voucher_date, l.raw_warehouse
                  FROM {$item} i
                  JOIN {$ledger} l ON l.id = i.ledger_id
                 WHERE {$where_sql}
                 ORDER BY i.row_date DESC, i.voucher_code, i.id
                 LIMIT %d";
        $params[] = isset($filters['limit']) ? intval($filters['limit']) : 20000;

        $raw = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $rows = [];
        foreach ((array) $raw as $r) {
            $rows[] = self::build_row($r);
        }

        return ['rows' => $rows];
    }

    /**
     * Một dòng hàng → các con số hiển thị.
     * Thứ tự làm tròn: thành tiền → đơn giá → chiết khấu (xem đầu file).
     */
    private static function build_row(array $r)
    {
        $qty      = (float) $r['quantity'];
        $price    = (float) $r['price'];
        $ck       = (float) $r['discount_amount'];
        $thue_pct = (float) $r['tax_percent'];
        $is_return = ($r['kind'] === 'return');

        $m = TGS_Money::line($qty, $price, $ck, $thue_pct);

        /* Tiền thuế lấy SỐ ĐÃ LƯU — đó mới là số phần mềm cũ đã kê */
        $thue = round((float) $r['tax_amount']);

        $thanh_tien = round($m['tien_hang_sau_ck'] + $thue);
        $goc        = round($m['tien_hang_truoc_ck'] * (1 + $thue_pct / 100));
        $gia_dvcb   = self::don_gia_lam_tron($goc, $qty, $thanh_tien);
        $ck_hien    = max(0.0, $gia_dvcb * $qty - $thanh_tien);

        $unit_qty = (float) $r['unit_quantity'];
        $ratio    = ($unit_qty > 0 && $qty > 0) ? ($qty / $unit_qty) : 1.0;

        return [
            'id'        => intval($r['id']),
            'ledger_id' => intval($r['ledger_id']),
            'kho'       => (string) ($r['raw_warehouse'] !== '' ? $r['raw_warehouse'] : $r['site_code']),
            'sku'       => (string) $r['product_sku'],
            'ten'       => (string) $r['product_name'],
            'ngay'      => (string) $r['row_date'],
            'dvcb'      => (string) $r['dvcb'],
            'nhom'      => (string) $r['product_group'],
            'pbh'       => (string) $r['voucher_code'],
            /* Mã lý do của phần mềm cũ nếu file có, không thì suy từ loại phiếu */
            'ly_do'     => (string) ($r['reason'] !== '' ? $r['reason'] : ($is_return ? 'NTH1' : 'XBA')),
            'tra_lai'   => $is_return,
            'qty'       => $qty,
            'gia'       => $gia_dvcb,
            'gia_dvt'   => round($gia_dvcb * max(1.0, $ratio)),
            'ck'        => $ck_hien,
            'tien'      => $thanh_tien,
            /* Dòng bán cộng vào, dòng trả lại TRỪ RA */
            'doanh_thu' => $is_return ? -$thanh_tien : $thanh_tien,
            'thue'      => $thue,
            /* Hiệu của hai số ĐÃ làm tròn, để cột này + Thuế = Thành tiền */
            'truoc_thue' => $thanh_tien - $thue,
            'nv_ten'    => (string) $r['staff_name'],
            'nv_ma'     => (string) $r['staff_code'],
            'kh_ten'    => (string) $r['customer_name'],
            'kh_ma'     => (string) $r['customer_code'],
            'dvt'       => (string) ($r['unit_name'] !== '' ? $r['unit_name'] : $r['dvcb']),
            'sl_dvmr'   => $unit_qty > 0 ? $unit_qty : $qty,
            'gia_dvmr'  => (float) $r['unit_price_mr'],
            'httt'      => (string) $r['payment_label'],
            'so_lo'     => (string) $r['lot_code'],
            'exp'       => (string) $r['exp_date'],
            'kenh'      => (string) $r['channel'],
            /* Số nguyên văn của HTsoft — cột để soi chênh lệch do làm tròn */
            'tt_htsoft' => (float) $r['amount_htsoft'],
            'canh_bao'  => (string) $r['warning'],
        ];
    }

    private static function don_gia_lam_tron($tien_goc, $qty, $thanh_tien)
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $gia = round($tien_goc / $qty);

        if ($gia * $qty < $thanh_tien) {
            $gia = ceil($thanh_tien / $qty);
        }

        return (float) $gia;
    }

    // =========================================================================
    // TỔNG HỢP BÁN HÀNG — mỗi phiếu một dòng
    // =========================================================================

    public static function summary_rows(array $filters)
    {
        global $wpdb;

        $ledger = self::table_ledger();
        list($where_sql, $params) = self::build_where('l', $filters);

        $sql = "SELECT l.* FROM {$ledger} l
                 WHERE {$where_sql}
                 ORDER BY l.voucher_date DESC, l.voucher_code
                 LIMIT %d";
        $params[] = isset($filters['limit']) ? intval($filters['limit']) : 20000;

        $raw = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $rows = [];
        foreach ((array) $raw as $r) {
            $is_return  = ($r['kind'] === 'return');
            $thanh_tien = round((float) $r['total_amount']);

            $rows[] = [
                'id'         => intval($r['id']),
                'pbh'        => (string) $r['voucher_code'],
                'loai'       => $is_return ? 'Hàng trả lại' : 'Phiếu bán hàng',
                'tra_lai'    => $is_return,
                'kho'        => (string) ($r['raw_warehouse'] !== '' ? $r['raw_warehouse'] : $r['site_code']),
                'ngay'       => (string) $r['voucher_date'],
                'so_mon'     => intval($r['items_count']),
                'qty'        => (float) $r['total_quantity'],
                'ck'         => round((float) $r['total_discount']),
                'thue'       => round((float) $r['total_tax']),
                'truoc_thue' => round((float) $r['total_before_tax']),
                'tien'       => $thanh_tien,
                'doanh_thu'  => $is_return ? -$thanh_tien : $thanh_tien,
                'kh_ten'     => (string) $r['customer_name'],
                'kh_ma'      => (string) $r['customer_code'],
                'nv_ten'     => (string) $r['staff_name'],
                'nv_ma'      => (string) $r['staff_code'],
                'httt'       => (string) $r['payment_label'],
                'kenh'       => (string) $r['channel'],
                'canh_bao'   => intval($r['warning_count']),
            ];
        }

        return ['rows' => $rows];
    }

    // =========================================================================
    // CHI TIẾT MỘT PHIẾU — cho modal xem lại đơn
    // =========================================================================

    public static function voucher_detail($ledger_id)
    {
        global $wpdb;

        if (!self::money_ready()) {
            return ['error' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).'];
        }

        $ledger_id = intval($ledger_id);
        $ledger = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_ledger() . ' WHERE id = %d',
            $ledger_id
        ), ARRAY_A);

        if (!$ledger) {
            return ['error' => 'Không tìm thấy phiếu.'];
        }

        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT i.*, %d AS ledger_id2 FROM ' . self::table_item() . ' i WHERE i.ledger_id = %d ORDER BY i.id',
            $ledger_id,
            $ledger_id
        ), ARRAY_A);

        $rows = [];
        $total = ['qty' => 0.0, 'ck' => 0.0, 'thue' => 0.0, 'truoc_thue' => 0.0, 'tien' => 0.0];

        foreach ((array) $items as $item) {
            $item['ledger_id']     = $ledger_id;
            $item['raw_warehouse'] = $ledger['raw_warehouse'];
            $row = self::build_row($item);

            $total['qty']        += $row['qty'];
            $total['ck']         += $row['ck'];
            $total['thue']       += $row['thue'];
            $total['truoc_thue'] += $row['truoc_thue'];
            $total['tien']       += $row['tien'];

            $rows[] = $row;
        }

        $shop = '';
        if (intval($ledger['blog_id']) > 0) {
            $shop = get_blog_option(intval($ledger['blog_id']), 'blogname', '');
        }

        return [
            'header' => [
                'id'            => $ledger_id,
                'voucher_code'  => (string) $ledger['voucher_code'],
                'kind'          => (string) $ledger['kind'],
                'loai'          => $ledger['kind'] === 'return' ? 'Phiếu hàng bán trả lại' : 'Phiếu bán hàng',
                'kho'           => (string) ($ledger['raw_warehouse'] !== '' ? $ledger['raw_warehouse'] : $ledger['site_code']),
                'site_code'     => (string) $ledger['site_code'],
                'blog_id'       => intval($ledger['blog_id']),
                'shop_name'     => $shop,
                'voucher_date'  => (string) $ledger['voucher_date'],
                'customer_name' => (string) $ledger['customer_name'],
                'customer_code' => (string) $ledger['customer_code'],
                'staff_name'    => (string) $ledger['staff_name'],
                'staff_code'    => (string) $ledger['staff_code'],
                'payment_label' => (string) $ledger['payment_label'],
                'channel'       => (string) $ledger['channel'],
                'source_file'   => (string) $ledger['source_file'],
                'updated_at'    => (string) $ledger['updated_at'],
            ],
            'items' => $rows,
            'total' => $total,
        ];
    }
}
