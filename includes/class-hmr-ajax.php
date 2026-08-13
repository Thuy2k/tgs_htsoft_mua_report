<?php
/**
 * Cổng AJAX của báo cáo đối chiếu HTsoft.
 *
 * Dữ liệu nằm ở BA bảng global nên một truy vấn lo hết mọi chi nhánh — không
 * phải chạy từng site như BC_TK (bên đó mỗi site một bảng riêng).
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HMR_Ajax
{
    const NONCE = 'tgs_hmr_nonce';

    public static function init()
    {
        add_action('wp_ajax_tgs_hmr_fetch_sales', [__CLASS__, 'fetch_sales']);
        add_action('wp_ajax_tgs_hmr_fetch_summary', [__CLASS__, 'fetch_summary']);
        add_action('wp_ajax_tgs_hmr_fetch_stock', [__CLASS__, 'fetch_stock']);
        add_action('wp_ajax_tgs_hmr_voucher', [__CLASS__, 'fetch_voucher']);
        add_action('wp_ajax_tgs_hmr_refresh_nonce', [__CLASS__, 'refresh_nonce']);
    }

    private static function guard()
    {
        if (!is_user_logged_in() || !current_user_can(TGS_HMR_CAPABILITY)) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem báo cáo này.'], 403);
        }

        check_ajax_referer(self::NONCE, 'nonce');
    }

    /** Đọc bộ lọc từ request, chuẩn hoá về đúng kiểu */
    private static function filters()
    {
        $blog_ids = isset($_POST['blog_ids']) ? (array) $_POST['blog_ids'] : [];
        $zones    = isset($_POST['zones']) ? (array) $_POST['zones'] : [];

        /*
         * 'loai' là kind cụ thể hoặc 'all'; 'group' quyết định phạm vi của
         * 'all' — màn bán không được kéo phiếu mua vào và ngược lại.
         */
        $kind = isset($_POST['loai']) ? sanitize_text_field($_POST['loai']) : 'all';
        if (!array_key_exists($kind, TGS_HMR_Report::kinds()) && $kind !== 'all') {
            $kind = 'all';
        }

        $group = (isset($_POST['group']) && $_POST['group'] === 'purchase') ? 'purchase' : 'sales';

        return [
            'blog_ids'  => array_map('intval', $blog_ids),
            'zones'     => array_map('sanitize_text_field', $zones),
            'kind'      => $kind,
            'group'     => $group,
            'date_from' => self::date($_POST['date_from'] ?? ''),
            'date_to'   => self::date($_POST['date_to'] ?? ''),
        ];
    }

    private static function date($value)
    {
        $value = trim(sanitize_text_field((string) $value));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : current_time('Y-m-d');
    }

    /**
     * Trình duyệt gọi lại nhiều lượt, mỗi lượt nối tiếp từ id cuối trang trước.
     *
     * Có `total` trong phản hồi thì hmr-report.js mới lặp; thiếu nó là bảng chỉ
     * nhận đúng trang đầu rồi dừng, mà không báo gì — vẫn ra một bảng trông
     * bình thường nhưng thiếu dữ liệu.
     */
    private static function paged_filters()
    {
        return self::filters() + [
            'after_id' => isset($_POST['after_id']) ? intval($_POST['after_id']) : 0,
        ];
    }

    public static function fetch_sales()
    {
        self::guard();

        $result = TGS_HMR_Report::sales_rows(self::paged_filters());

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success([
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'last_id' => $result['last_id'],
        ]);
    }

    public static function fetch_summary()
    {
        self::guard();

        $result = TGS_HMR_Report::summary_rows(self::paged_filters());

        wp_send_json_success([
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'last_id' => $result['last_id'],
        ]);
    }

    /**
     * Tồn kho theo mặt hàng — không có khoảng ngày.
     *
     * Tồn là ẢNH CHỤP tại lần nạp gần nhất, không phải phát sinh trong kỳ. Cho
     * chọn ngày ở đây chỉ tạo cảm giác lọc được theo thời gian trong khi số
     * chẳng đổi — người đọc sẽ tin nhầm là tồn của đúng ngày đó.
     */
    public static function fetch_stock()
    {
        self::guard();

        /* Ô "Mã hàng" nhận nhiều mã, ngăn bằng dấu phẩy / khoảng trắng / xuống dòng */
        $skus = isset($_POST['skus']) ? sanitize_textarea_field(wp_unslash((string) $_POST['skus'])) : '';
        $skus = preg_split('/[\s,;]+/', $skus, -1, PREG_SPLIT_NO_EMPTY);

        $result = TGS_HMR_Report::stock_rows([
            'blog_ids'  => isset($_POST['blog_ids']) ? array_map('intval', (array) $_POST['blog_ids']) : [],
            'zones'     => isset($_POST['zones']) ? array_map('sanitize_text_field', (array) $_POST['zones']) : [],
            'skus'      => $skus,
            'show_zero' => !empty($_POST['show_zero']),
            /* Trình duyệt gọi lại nhiều lượt, mỗi lượt nối tiếp từ id cuối trang trước */
            'after_id'  => isset($_POST['after_id']) ? intval($_POST['after_id']) : 0,
        ]);

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success([
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'last_id' => $result['last_id'],
        ]);
    }

    public static function fetch_voucher()
    {
        self::guard();

        $detail = TGS_HMR_Report::voucher_detail(isset($_POST['id']) ? intval($_POST['id']) : 0);

        if (!empty($detail['error'])) {
            wp_send_json_error(['message' => $detail['error']]);
        }

        wp_send_json_success($detail);
    }

    /**
     * Nonce hết hạn khi để trang mở lâu (báo cáo hay bị mở cả buổi). Cấp lại
     * cho phiên còn sống thay vì bắt tải lại trang và mất hết lựa chọn lọc.
     */
    public static function refresh_nonce()
    {
        if (!is_user_logged_in() || !current_user_can(TGS_HMR_CAPABILITY)) {
            wp_send_json_error(['message' => 'Phiên đăng nhập đã hết hạn.'], 403);
        }

        wp_send_json_success(['nonce' => wp_create_nonce(self::NONCE)]);
    }
}
