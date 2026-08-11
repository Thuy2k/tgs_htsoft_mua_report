<?php
/**
 * Dữ liệu cho bộ lọc trái: Chi nhánh → Mã kho.
 *
 * ── KHÁC BC_TK Ở CHỖ NÀO ────────────────────────────────────────────────────
 *
 * BC_TK dựng danh sách từ CẤU HÌNH phân cấp multisite, vì nó đọc dữ liệu thật
 * của từng website. Báo cáo này đọc BẢN SAO từ phần mềm cũ, nên danh sách phải
 * dựng từ CHÍNH DỮ LIỆU đã kéo về:
 *
 *   - Chi nhánh nào chưa kéo dữ liệu thì không hiện, tránh chọn xong ra bảng
 *     trống rồi tưởng mất số.
 *   - Mã kho bên HTsoft không khớp website nào (kho tổng dùng mã kiểu 00-CC)
 *     vẫn phải xem được, nên gom vào một nhóm riêng thay vì giấu đi.
 *
 * Tên chi nhánh vẫn lấy từ cấu hình phân cấp cho giống BC_TK; không có thì lùi
 * về tên site, cuối cùng là mã kho.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HMR_Sites
{
    /** Option do plugin tgs-multisite-hierarchy quản lý */
    const HIERARCHY_OPTION = 'tgs_multisite_hierarchy_data';

    /** Mã giả cho nhóm mã kho chưa khớp website nào */
    const BLOG_NONE = 0;

    private static $hierarchy_cache = null;

    private static function hierarchy()
    {
        if (self::$hierarchy_cache !== null) {
            return self::$hierarchy_cache;
        }

        $raw = get_site_option(self::HIERARCHY_OPTION);
        if (!$raw) {
            $raw = get_option(self::HIERARCHY_OPTION);
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return self::$hierarchy_cache = [
            'sites' => isset($raw['sites']) && is_array($raw['sites']) ? $raw['sites'] : [],
        ];
    }

    private static function is_warehouse($blog_id)
    {
        $h = self::hierarchy();
        return isset($h['sites'][$blog_id]['type']) && $h['sites'][$blog_id]['type'] === 'warehouse';
    }

    /**
     * Chi nhánh + mã kho, dựng từ dữ liệu đã kéo về.
     *
     * @return array ['sites' => [...], 'zones' => [blog_id => [...]]]
     */
    /**
     * @param string $group 'sales' | 'purchase' — mỗi khối một bộ mã kho riêng
     */
    public static function filter_bootstrap($group = 'sales')
    {
        global $wpdb;

        $table = TGS_HMR_Report::table_ledger();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['sites' => [], 'zones' => [], 'empty' => true];
        }

        /*
         * Gộp theo site_code, KHÔNG theo raw_warehouse.
         *
         * Cùng một kho nhưng file HTsoft lúc ghi "08112" lúc ghi "8112" — gộp
         * theo mã thô thì bộ lọc hiện hai dòng cho cùng một kho, mà tích cái nào
         * cũng ra kết quả y hệt (vì lọc theo site_code đã chuẩn hoá). Người dùng
         * nhìn hai dòng giống nhau sẽ tưởng có hai kho.
         *
         * Nhãn lấy MIN(raw_warehouse): dạng còn số 0 đầu, đúng thứ hiện trên
         * phần mềm cũ nên dễ đối chiếu bằng mắt.
         */
        /*
         * Bộ lọc dựng riêng cho từng KHỐI: mã kho của phiếu mua (08-HH) khác
         * hẳn mã kho của phiếu bán (mã shop). Trộn chung thì hai màn nào cũng
         * hiện cả đống mã không dùng được.
         *
         * Chi nhánh gộp theo site_code; mã kho gộp theo zone_code — kho tổng có
         * nhiều phân kho dưới một chi nhánh.
         */
        $group = ($group === 'purchase') ? 'purchase' : 'sales';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT blog_id, site_code, zone_code,
                        MIN(raw_warehouse) AS raw_warehouse,
                        MIN(zone_raw) AS zone_raw,
                        COUNT(*) AS vouchers,
                        MIN(voucher_date) AS first_date,
                        MAX(voucher_date) AS last_date
                   FROM {$table}
                  WHERE doc_group = %s
                  GROUP BY blog_id, site_code, zone_code
                  ORDER BY blog_id ASC, site_code ASC, zone_code ASC",
                $group
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return ['sites' => [], 'zones' => [], 'empty' => true];
        }

        $h = self::hierarchy();
        $sites = [];
        $zones = [];

        foreach ($rows as $row) {
            $blog_id   = intval($row['blog_id']);
            $code      = (string) $row['site_code'];
            $raw       = (string) ($row['raw_warehouse'] !== '' ? $row['raw_warehouse'] : $code);
            $zone      = (string) $row['zone_code'];
            $zone_raw  = (string) ($row['zone_raw'] !== '' ? $row['zone_raw'] : $zone);

            /*
             * Mã kho khớp website → gộp về CHI NHÁNH (một website một dòng),
             * giống BC_TK.
             *
             * Mã kho KHÔNG khớp website → mỗi mã một dòng riêng, KHÔNG gộp
             * chung thành "chưa khớp website". Gộp thì người dùng nhìn thấy đúng
             * một dòng với con số 5.453 phiếu mà không biết gồm những kho nào,
             * trong khi đây chính là danh sách cần soi: kho tổng, kho mới mở,
             * hoặc shop chưa khai tgs_site_code.
             */
            $key = $blog_id > 0 ? (string) $blog_id : '0::' . $code;

            if (!isset($sites[$key])) {
                $sites[$key] = [
                    'key'       => $key,
                    'blog_id'   => $blog_id,
                    'code'      => $blog_id > 0 ? $code : $raw,
                    'name'      => self::site_name($blog_id, $raw, $h),
                    'type'      => $blog_id && self::is_warehouse($blog_id) ? 'warehouse' : 'shop',
                    'unmatched' => $blog_id === 0,
                    'vouchers'  => 0,
                ];
                $zones[$key] = [];
            }

            $sites[$key]['vouchers'] += intval($row['vouchers']);

            $zones[$key][] = [
                'zone_code' => $zone,
                'label'     => $zone_raw,
                'vouchers'  => intval($row['vouchers']),
                'first'     => (string) $row['first_date'],
                'last'      => (string) $row['last_date'],
            ];
        }

        foreach ($sites as $key => $site) {
            $sites[$key]['label'] = $site['code'] !== ''
                ? $site['code'] . ' — ' . $site['name']
                : $site['name'];
        }

        /* Chi nhánh khớp website lên trước; nhóm chưa khớp xếp cuối theo mã, để
           không chen vào giữa danh sách chi nhánh thật */
        uasort($sites, static function ($a, $b) {
            if ($a['unmatched'] !== $b['unmatched']) {
                return $a['unmatched'] ? 1 : -1;
            }
            if ($a['unmatched']) {
                return strnatcasecmp($a['code'], $b['code']);
            }
            return $a['blog_id'] - $b['blog_id'];
        });

        return [
            'sites' => array_values($sites),
            'zones' => $zones,
            'empty' => false,
        ];
    }

    /**
     * Bộ lọc cho màn TỒN KHO — dựng từ bảng tồn, không phải bảng chứng từ.
     *
     * Hai nguồn khác nhau: một kho có thể có tồn mà chưa phát sinh phiếu nào
     * trong khoảng ngày đang xem, và ngược lại. Dùng chung bộ lọc là mất kho.
     */
    public static function filter_bootstrap_stock()
    {
        global $wpdb;

        if (!TGS_HMR_Report::stock_ready()) {
            return ['sites' => [], 'zones' => [], 'empty' => true];
        }

        $rows = $wpdb->get_results(
            'SELECT blog_id, zone_code, MIN(zone_raw) AS zone_raw, MIN(branch_code) AS branch_code,
                    COUNT(*) AS items, MAX(updated_at) AS last_update
               FROM ' . TGS_HMR_Report::table_stock() . '
              GROUP BY blog_id, zone_code
              ORDER BY blog_id ASC, zone_code ASC',
            ARRAY_A
        );

        if (empty($rows)) {
            return ['sites' => [], 'zones' => [], 'empty' => true];
        }

        $h = self::hierarchy();
        $sites = [];
        $zones = [];

        foreach ($rows as $row) {
            $blog_id  = intval($row['blog_id']);
            $zone     = (string) $row['zone_code'];
            $zone_raw = (string) ($row['zone_raw'] !== '' ? $row['zone_raw'] : $zone);
            $branch   = (string) $row['branch_code'];

            /* Kho chưa khớp website: mỗi mã một mục riêng để còn nhìn ra là kho
               nào, y như bên báo cáo chứng từ */
            $key = $blog_id > 0 ? (string) $blog_id : '0::' . $zone;

            if (!isset($sites[$key])) {
                $sites[$key] = [
                    'key'       => $key,
                    'blog_id'   => $blog_id,
                    'code'      => $blog_id > 0 ? ($branch !== '' ? $branch : $zone_raw) : $zone_raw,
                    'name'      => self::site_name($blog_id, $zone_raw, $h),
                    'type'      => $blog_id && self::is_warehouse($blog_id) ? 'warehouse' : 'shop',
                    'unmatched' => $blog_id === 0,
                    'vouchers'  => 0,
                ];
                $zones[$key] = [];
            }

            $sites[$key]['vouchers'] += intval($row['items']);

            $zones[$key][] = [
                'zone_code' => $zone,
                'label'     => $zone_raw,
                'vouchers'  => intval($row['items']),
                'last'      => (string) $row['last_update'],
            ];
        }

        foreach ($sites as $key => $site) {
            $sites[$key]['label'] = $site['code'] !== ''
                ? $site['code'] . ' — ' . $site['name']
                : $site['name'];
        }

        uasort($sites, static function ($a, $b) {
            if ($a['unmatched'] !== $b['unmatched']) {
                return $a['unmatched'] ? 1 : -1;
            }
            if ($a['unmatched']) {
                return strnatcasecmp($a['code'], $b['code']);
            }
            return $a['blog_id'] - $b['blog_id'];
        });

        return ['sites' => array_values($sites), 'zones' => $zones, 'empty' => false];
    }

    private static function site_name($blog_id, $code, $h)
    {
        /* Nhãn ngắn vì mã kho đã đứng ngay trước nó: "12001 — chưa khớp website" */
        if ($blog_id === self::BLOG_NONE) {
            return 'chưa khớp website';
        }

        if (!empty($h['sites'][$blog_id]['name'])) {
            return (string) $h['sites'][$blog_id]['name'];
        }

        $name = get_blog_option($blog_id, 'blogname', '');

        return $name !== '' ? $name : ('Site #' . $blog_id . ($code !== '' ? ' (' . $code . ')' : ''));
    }
}
