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

    /**
     * kind => khối chứng từ.
     *
     * CỐ Ý khai lại thay vì gọi TGS_HMI_DB::kinds() của plugin nạp: hai plugin
     * độc lập, tắt plugin nạp thì báo cáo vẫn phải xem được dữ liệu đã có chứ
     * không lỗi trắng màn. Thêm loại mới thì sửa cả hai chỗ.
     */
    public static function kinds()
    {
        return [
            'sale'       => 'sales',
            'return'     => 'sales',
            'purchase'   => 'purchase',
            'sup_return' => 'purchase',
        ];
    }

    /** Tên loại chứng từ để hiển thị */
    public static function kind_label($kind)
    {
        $labels = [
            'sale'       => 'Phiếu bán hàng',
            'return'     => 'Hàng bán trả lại',
            'purchase'   => 'Phiếu nhập mua',
            'sup_return' => 'Trả nhà cung cấp',
        ];

        return isset($labels[$kind]) ? $labels[$kind] : (string) $kind;
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

        /* Lọc theo MÃ KHO (phân kho), không theo mã chi nhánh — xem chú thích ở
           TGS_HMI_DB khi tạo bảng */
        $zones = isset($filters['zones']) ? array_values(array_filter((array) $filters['zones'], 'strlen')) : [];
        if ($zones) {
            $where[] = "{$alias}.zone_code IN (" . implode(',', array_fill(0, count($zones), '%s')) . ')';
            $params = array_merge($params, $zones);
        }

        /*
         * 'all' nghĩa là cả hai chiều CỦA KHỐI ĐANG XEM, không phải cả bốn loại:
         * màn bán không được lẫn phiếu nhập mua vào.
         */
        $group = (isset($filters['group']) && $filters['group'] === 'purchase') ? 'purchase' : 'sales';
        $kind  = isset($filters['kind']) ? $filters['kind'] : 'all';
        $kinds = self::kinds();

        if (isset($kinds[$kind]) && $kinds[$kind] === $group) {
            $where[] = "{$alias}.kind = %s";
            $params[] = $kind;
        } else {
            $where[] = "{$alias}.doc_group = %s";
            $params[] = $group;
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

        $sql = "SELECT i.*, l.id AS ledger_id, l.voucher_date, l.raw_warehouse, l.site_code AS branch_code
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

        /*
         * Mọi cột lưu trong DB đều DƯƠNG. Chiều đến từ hai nguồn:
         *   kind        — phiếu trả lại
         *   is_negative — dòng phần mềm cũ ghi âm (chiết khấu NCC trong phiếu nhập)
         * Xem quy ước dấu ở đầu TGS_HMI_DB.
         */
        $is_return = ($r['kind'] === 'return' || $r['kind'] === 'sup_return');
        $am        = !empty($r['is_negative']);
        $nguoc     = ($is_return || $am);

        $m = TGS_Money::line($qty, $price, $ck, $thue_pct);

        /* Tiền thuế lấy SỐ ĐÃ LƯU — đó mới là số phần mềm cũ đã kê */
        $thue = round((float) $r['tax_amount']);

        $thanh_tien = round($m['tien_hang_sau_ck'] + $thue);
        $goc        = round($m['tien_hang_truoc_ck'] * (1 + $thue_pct / 100));
        $gia_dvcb   = self::don_gia_lam_tron($goc, $qty, $thanh_tien);
        $ck_hien    = max(0.0, $gia_dvcb * $qty - $thanh_tien);

        /*
         * Dòng chỉ có giá trị, không có số lượng (chiết khấu doanh số, NCC bù
         * giá) — công thức SL × ĐG ra 0 nên phải lấy thẳng số của phần mềm cũ,
         * nếu không khoản đó biến mất khỏi tổng. Xem TGS_HMI_Money.
         */
        $goc_htsoft = (float) $r['amount_htsoft'];
        if ($thanh_tien == 0 && $goc_htsoft != 0) {
            $thanh_tien = round($goc_htsoft);
            $gia_dvcb   = 0.0;
            $ck_hien    = 0.0;
        }

        $unit_qty = (float) $r['unit_quantity'];
        $ratio    = ($unit_qty > 0 && $qty > 0) ? ($qty / $unit_qty) : 1.0;

        return [
            'id'        => intval($r['id']),
            'ledger_id' => intval($r['ledger_id']),
            'kind'      => (string) $r['kind'],
            /* Cột Kho hiện PHÂN KHO, vì đó mới là điểm tồn thật của dòng hàng */
            'kho'       => (string) ($r['zone_raw'] !== '' ? $r['zone_raw'] : $r['zone_code']),
            'chi_nhanh' => (string) ($r['raw_warehouse'] !== '' ? $r['raw_warehouse'] : $r['site_code']),
            'ncc'       => (string) (isset($r['supplier_name']) ? $r['supplier_name'] : ''),
            'ncc_ma'    => (string) (isset($r['supplier_code']) ? $r['supplier_code'] : ''),
            'so_hd'     => (string) (isset($r['invoice_no']) ? $r['invoice_no'] : ''),
            'ghi_chu'   => (string) (isset($r['note']) ? $r['note'] : ''),
            'sku'       => (string) $r['product_sku'],
            'ten'       => (string) $r['product_name'],
            'ngay'      => (string) $r['row_date'],
            'dvcb'      => (string) $r['dvcb'],
            'nhom'      => (string) $r['product_group'],
            'pbh'       => (string) $r['voucher_code'],
            /* Mã lý do của phần mềm cũ nếu file có, không thì suy từ loại phiếu */
            'ly_do'     => (string) ($r['reason'] !== '' ? $r['reason'] : ($is_return ? 'NTH1' : 'XBA')),
            'tra_lai'   => $is_return,
            /* Dòng ghi âm bên phần mềm cũ — hiện dấu hiệu riêng, không lẫn với
               phiếu trả lại vì hai thứ khác nghiệp vụ */
            'am'        => $am,
            'qty'       => $qty,
            'gia'       => $gia_dvcb,
            'gia_dvt'   => round($gia_dvcb * max(1.0, $ratio)),
            'ck'        => $ck_hien,
            'tien'      => $thanh_tien,
            /* Cột duy nhất mang chiều: bán/nhập cộng vào, trả lại và dòng ghi
               âm trừ ra. Các cột còn lại luôn dương cho dễ đọc. */
            'doanh_thu' => $nguoc ? -$thanh_tien : $thanh_tien,
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
            $is_return = ($r['kind'] === 'return' || $r['kind'] === 'sup_return');

            /*
             * ⚠️ Tổng trong DB ĐÃ MANG DẤU (xem quy ước dấu ở TGS_HMI_DB), nên
             * KHÔNG được nhân dấu lần nữa — làm thế là phiếu trả lại hiện thành
             * số dương và cộng vào thay vì trừ ra.
             *
             * Các cột hiển thị lấy trị tuyệt đối cho thống nhất với màn chi
             * tiết; chỉ cột "giá trị thuần" giữ dấu.
             */
            $signed     = round((float) $r['total_amount']);
            $thanh_tien = abs($signed);

            $rows[] = [
                'id'         => intval($r['id']),
                'pbh'        => (string) $r['voucher_code'],
                'kind'       => (string) $r['kind'],
                'loai'       => self::kind_label($r['kind']),
                'tra_lai'    => $is_return,
                'kho'        => (string) ($r['zone_raw'] !== '' ? $r['zone_raw'] : $r['zone_code']),
                'chi_nhanh'  => (string) ($r['raw_warehouse'] !== '' ? $r['raw_warehouse'] : $r['site_code']),
                'ncc'        => (string) $r['supplier_name'],
                'ncc_ma'     => (string) $r['supplier_code'],
                'so_hd'      => (string) $r['invoice_no'],
                'ngay'       => (string) $r['voucher_date'],
                'so_mon'     => intval($r['items_count']),
                'qty'        => abs((float) $r['total_quantity']),
                'ck'         => abs(round((float) $r['total_discount'])),
                'thue'       => abs(round((float) $r['total_tax'])),
                'truoc_thue' => abs(round((float) $r['total_before_tax'])),
                'tien'       => $thanh_tien,
                /* Cột duy nhất mang chiều — lấy thẳng tổng đã ký của phiếu */
                'doanh_thu'  => $signed,
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
                'loai'          => self::kind_label($ledger['kind']),
                'kho'           => (string) ($ledger['zone_raw'] !== '' ? $ledger['zone_raw'] : $ledger['zone_code']),
                'chi_nhanh'     => (string) ($ledger['raw_warehouse'] !== '' ? $ledger['raw_warehouse'] : $ledger['site_code']),
                'supplier_name' => (string) $ledger['supplier_name'],
                'supplier_code' => (string) $ledger['supplier_code'],
                'invoice_no'    => (string) $ledger['invoice_no'],
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
