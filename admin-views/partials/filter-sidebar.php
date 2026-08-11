<?php
/**
 * Bộ lọc trái dùng chung cho mọi màn của khối đối chiếu HTsoft.
 *
 * Cấu trúc bám BC_TK để phòng mua không phải học lại: khối trên chọn nhiều
 * CHI NHÁNH, khối dưới đổ ra MÃ KHO của đúng những chi nhánh đang tích.
 *
 * @var array $hmr_boot
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<aside class="hmr-filter" id="hmrFilter">
    <div class="hmr-filter__head">
        <span>Tiêu chí tìm kiếm</span>
        <button type="button" class="hmr-collapse" id="hmrToggleFilter" title="Thu gọn bộ lọc">«</button>
    </div>

    <div class="hmr-filter__body">
        <?php if (!empty($hmr_boot['empty'])) : ?>
            <div class="hmr-note">
                Chưa có dữ liệu nào được kéo về.<br>
                Vào <strong>Mua hàng → Dữ liệu Btsoft → Nạp dữ liệu</strong> để nạp file trước.
            </div>
        <?php else : ?>

            <div class="hmr-group hmr-group--sites">
                <div class="hmr-group__title">
                    Chi nhánh
                    <?php
                    /*
                     * Ô tích PHẢI mang class form-check-input: theme đặt
                     * appearance:none cho checkbox rồi vẽ lại dấu tích, nhưng chỉ
                     * cho class đó. Thiếu thì tích được mà không hiện dấu gì.
                     */
                    ?>
                    <label class="hmr-all"><input class="form-check-input" type="checkbox" id="hmrCheckAllSites"> Tất cả</label>
                </div>
                <input type="search" class="hmr-search" id="hmrSiteSearch" placeholder="Lọc chi nhánh…">
                <div class="hmr-list" id="hmrSiteList">
                    <?php
                    /*
                     * value là KHOÁ của mục, không phải blog_id: mã kho chưa
                     * khớp website đều mang blog_id = 0, dùng blog_id làm value
                     * thì mấy chục mã đó thành một ô tích duy nhất.
                     */
                    ?>
                    <?php foreach ($hmr_boot['sites'] as $s) : ?>
                        <label class="hmr-item<?php echo $s['unmatched'] ? ' hmr-item--unmatched' : ''; ?>">
                            <input class="form-check-input hmr-site" type="checkbox"
                                   value="<?php echo esc_attr($s['key']); ?>"
                                   data-blog="<?php echo (int) $s['blog_id']; ?>"
                                   data-type="<?php echo esc_attr($s['type']); ?>">
                            <span class="hmr-item__label" title="<?php echo esc_attr($s['label']); ?>"><?php echo esc_html($s['label']); ?></span>
                            <span class="hmr-count"><?php echo (int) $s['vouchers']; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="hmr-group hmr-group--zones hmr-hidden" id="hmrZoneGroup">
                <div class="hmr-group__title">
                    Mã kho
                    <label class="hmr-all"><input class="form-check-input" type="checkbox" id="hmrCheckAllZones"> Tất cả</label>
                </div>
                <input type="search" class="hmr-search" id="hmrZoneSearch" placeholder="Lọc mã kho…">
                <div class="hmr-list" id="hmrZoneList"></div>
            </div>

        <?php endif; ?>
    </div>

    <div class="hmr-filter__foot">
        <div class="hmr-actions">
            <button type="button" class="hmr-btn hmr-btn--primary" id="hmrSearch">Tìm kiếm</button>
        </div>
        <div class="hmr-status" id="hmrStatus"></div>
    </div>
</aside>
