<?php
/**
 * Plugin Name: TGS HTsoft Mua — Báo cáo đối chiếu
 * Plugin URI: https://bizgpt.vn/
 * Description: Báo cáo bán hàng / tổng hợp bán hàng đọc từ bộ bảng _mua (dữ liệu kéo từ phần mềm cũ HTsoft). Bộ lọc chi nhánh + mã kho giống BC_TK, có modal xem lại từng phiếu.
 * Version: 1.1.2
 * Author: BIZGPT_AI
 * License: GPL v2 or later
 * Text Domain: tgs-htsoft-mua-report
 *
 * ── Vì sao có màn này ───────────────────────────────────────────────────────
 *
 * Các shop đang chạy SONG SONG hai phần mềm. Phòng mua cần đặt số bên HTsoft
 * cạnh số bên phần mềm mới để biết dữ liệu kéo qua đã đủ chưa, trước khi chốt
 * dùng hẳn phần mềm mới.
 *
 * Dữ liệu do plugin tgs_htsoft_mua_import nạp vào bảng đuôi `_mua`. Màn này chỉ
 * ĐỌC — không ghi gì, và không chạm vào dữ liệu thật của 650 site.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* Bump khi sửa JS/CSS — số này đi vào ?ver= của asset, không tăng thì trình
   duyệt vẫn chạy file cũ đã cache */
define('TGS_HMR_VERSION', '1.1.2');
define('TGS_HMR_DIR', plugin_dir_path(__FILE__));
define('TGS_HMR_URL', plugin_dir_url(__FILE__));

/*
 * Quyền tối thiểu để xem. Để 'read' như BC_TK đang làm trong giai đoạn phát
 * triển — siết lại sau thì đổi đúng một chỗ này.
 */
define('TGS_HMR_CAPABILITY', 'read');

require_once TGS_HMR_DIR . 'includes/class-hmr-report.php';
require_once TGS_HMR_DIR . 'includes/class-hmr-sites.php';
require_once TGS_HMR_DIR . 'includes/class-hmr-ajax.php';

class TGS_HMR_Plugin
{
    const VIEW_SALES    = 'hmr-sales';
    const VIEW_SUMMARY  = 'hmr-sales-sum';
    const VIEW_BUY      = 'hmr-buy';
    const VIEW_BUY_SUM  = 'hmr-buy-sum';

    /** Màn nào thuộc khối MUA — quyết định bộ lọc và phạm vi truy vấn */
    public static function group_of_view($view)
    {
        return in_array($view, [self::VIEW_BUY, self::VIEW_BUY_SUM], true) ? 'purchase' : 'sales';
    }

    /**
     * Mọi màn của khối này — thêm màn mới chỉ cần khai ở đây.
     *
     * ⚠️ TÊN HIỂN THỊ: mọi chữ người dùng nhìn thấy đều gọi là **Btsoft**.
     *
     * Bản chất vẫn là dữ liệu kéo từ phần mềm cũ (xem chú thích đầu file và
     * docs của tgs_htsoft_mua_import) — chỉ NHÃN đổi, số không đổi. Đây là yêu
     * cầu của chủ hệ thống cho giai đoạn chuyển giao: người xem cần thấy một
     * giao diện thống nhất, không phải hai tên phần mềm.
     *
     * Tên bảng, tên thư mục, chú thích code CỐ Ý giữ nguyên chữ htsoft để người
     * bảo trì sau vẫn biết dữ liệu thật sự đến từ đâu.
     */
    private static function views()
    {
        return [
            self::VIEW_SALES   => ['Báo cáo bán hàng — Btsoft', 'Btsoft: Báo cáo bán hàng', 'bx bx-receipt', 'sales-report.php'],
            self::VIEW_SUMMARY => ['Tổng hợp bán hàng — Btsoft', 'Btsoft: Tổng hợp bán hàng', 'bx bx-list-check', 'sales-summary.php'],
            self::VIEW_BUY     => ['Báo cáo mua hàng — Btsoft', 'Btsoft: Báo cáo mua hàng', 'bx bx-cart-download', 'purchase-report.php'],
            self::VIEW_BUY_SUM => ['Tổng hợp mua hàng — Btsoft', 'Btsoft: Tổng hợp mua hàng', 'bx bx-list-check', 'purchase-summary.php'],
        ];
    }

    public static function init()
    {
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'add_nav_block'], 10, 2);
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_routes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        TGS_HMR_Ajax::init();
    }

    /** Menu: Mua hàng → Đối chiếu HTsoft (chung khối với tool kéo dữ liệu) */
    public static function add_nav_block($workflow_nav, $current_view = '')
    {
        if (!isset($workflow_nav['purchase']['sections'])) {
            return $workflow_nav;
        }

        $items = [];
        foreach (self::views() as $view => $meta) {
            $items[] = ['view' => $view, 'label' => $meta[1], 'icon' => $meta[2]];
        }

        foreach ($workflow_nav['purchase']['sections'] as $key => $section) {
            if (isset($section['key']) && $section['key'] === 'htsoft-mua') {
                $workflow_nav['purchase']['sections'][$key]['items'] = array_merge(
                    $workflow_nav['purchase']['sections'][$key]['items'],
                    $items
                );
                return $workflow_nav;
            }
        }

        $workflow_nav['purchase']['sections'][] = [
            'key'     => 'htsoft-mua',
            'heading' => 'Dữ liệu Btsoft',
            'icon'    => 'bx bx-git-compare',
            'items'   => $items,
        ];

        return $workflow_nav;
    }

    public static function register_routes($routes)
    {
        foreach (self::views() as $view => $meta) {
            $routes[$view] = [$meta[0], TGS_HMR_DIR . 'admin-views/' . $meta[3]];
        }

        return $routes;
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_tgs-shop-management') {
            return;
        }

        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
        if (!array_key_exists($view, self::views())) {
            return;
        }

        wp_enqueue_style('tgs-hmr', TGS_HMR_URL . 'assets/css/hmr.css', [], TGS_HMR_VERSION);
        wp_enqueue_script('tgs-hmr', TGS_HMR_URL . 'assets/js/hmr-report.js', ['jquery'], TGS_HMR_VERSION, true);
    }
}

add_action('plugins_loaded', ['TGS_HMR_Plugin', 'init']);
