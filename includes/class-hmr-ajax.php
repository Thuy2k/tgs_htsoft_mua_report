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

        $kind = isset($_POST['loai']) ? sanitize_text_field($_POST['loai']) : 'sale';
        if (!in_array($kind, ['sale', 'return', 'all'], true)) {
            $kind = 'sale';
        }

        return [
            'blog_ids'  => array_map('intval', $blog_ids),
            'zones'     => array_map('sanitize_text_field', $zones),
            'kind'      => $kind,
            'date_from' => self::date($_POST['date_from'] ?? ''),
            'date_to'   => self::date($_POST['date_to'] ?? ''),
        ];
    }

    private static function date($value)
    {
        $value = trim(sanitize_text_field((string) $value));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : current_time('Y-m-d');
    }

    public static function fetch_sales()
    {
        self::guard();

        $result = TGS_HMR_Report::sales_rows(self::filters());

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success(['rows' => $result['rows']]);
    }

    public static function fetch_summary()
    {
        self::guard();

        $result = TGS_HMR_Report::summary_rows(self::filters());

        wp_send_json_success(['rows' => $result['rows']]);
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
