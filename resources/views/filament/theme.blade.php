{{--
    Giao diện CMS theo design system Ellso (ellso-design-system.md).
    Bảng màu primary / gray khai báo ở App\Filament\Support\EllsoTheme; file này chỉ chỉnh những chỗ
    Filament không cho cấu hình: font tiêu đề, nút chính, khoảng cách menu bên trái, thanh tiến độ.
    Deploy không chạy npm build nên CSS viết thẳng ở đây (render hook HEAD_END).
--}}
<style>
    :root {
        --ellso-emerald-500: #10b981;
        --ellso-action: #047857;
        --ellso-action-hover: #065f46;
        --ellso-on-action: #ffffff;
        --ellso-font-display: 'Lexend', 'Be Vietnam Pro', system-ui, sans-serif;
    }

    :root.dark {
        --ellso-action: #34d399;
        --ellso-action-hover: #6ee7b7;
        --ellso-on-action: #04241a;
    }

    /* Tiêu đề trang, khung đăng nhập, hộp thoại: Lexend 600 (h1–h3 của brand) */
    .fi-header-heading,
    .fi-simple-header-heading,
    .fi-modal-heading {
        font-family: var(--ellso-font-display);
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    /* Mô tả dưới tiêu đề trang: chú thích phụ, không cạnh tranh với tiêu đề */
    .fi-header-subheading {
        font-size: 15px;
        line-height: 24px;
        max-width: 72ch;
        color: var(--gray-600);
    }

    :root.dark .fi-header-subheading {
        color: var(--gray-400);
    }

    .fi-section-header-heading,
    .fi-wi-chart-heading {
        font-family: var(--ellso-font-display);
        font-weight: 500;
    }

    /* Số liệu: cùng độ rộng chữ số để các cột số thẳng hàng */
    .fi-wi-stats-overview-stat-value,
    .fi-ta-text-item,
    .fi-in-text-item {
        font-variant-numeric: tabular-nums;
    }

    /* Nút chính: emerald-700 chữ trắng, hover emerald-800 (tối: mint, chữ xanh đậm) */
    .fi-btn.fi-color-primary:not(label):is([class^='fi-bg-color-'], [class*=' fi-bg-color-']) {
        background-color: var(--ellso-action) !important;
        color: var(--ellso-on-action) !important;
        transition: background-color 120ms ease-out;
    }

    .fi-btn.fi-color-primary:not(label):is([class^='fi-bg-color-'], [class*=' fi-bg-color-']):not(.fi-disabled):not([disabled]):hover {
        background-color: var(--ellso-action-hover) !important;
    }

    .fi-btn.fi-color-primary:not(label):is([class^='fi-bg-color-'], [class*=' fi-bg-color-']) .fi-icon,
    .fi-btn.fi-color-primary:not(label):is([class^='fi-bg-color-'], [class*=' fi-bg-color-']) .fi-btn-label,
    .fi-btn.fi-color-primary:not(label):is([class^='fi-bg-color-'], [class*=' fi-bg-color-']) .fi-loading-indicator {
        color: var(--ellso-on-action) !important;
    }

    /* Nút bật / tắt dạng nhóm (VD: Ngày · Tuần · Tháng): chỉ nút đang chọn mang màu hành động */
    .fi-fo-toggle-buttons-input:checked + .fi-btn.fi-color-primary {
        background-color: var(--ellso-action) !important;
        color: var(--ellso-on-action) !important;
    }

    .fi-fo-toggle-buttons-input:checked + .fi-btn.fi-color-primary .fi-icon {
        color: var(--ellso-on-action) !important;
    }

    /* Menu bên trái: nhóm đóng xếp sát như một danh sách, nhóm đang mở tách khỏi nhóm bên cạnh */
    .fi-sidebar-nav-groups {
        row-gap: 8px;
    }

    .fi-sidebar-group:not(.fi-collapsed):has(.fi-sidebar-group-btn) {
        margin-block: 8px;
    }

    .fi-sidebar-group-label {
        font-weight: 500;
    }

    /* Chi tiết riêng của Ellso: thanh tiến độ bo tròn emerald-500 */
    .ellso-progress {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 120px;
    }

    .ellso-progress-track {
        flex: 1;
        height: 6px;
        border-radius: 9999px;
        background: color-mix(in oklab, var(--gray-400) 25%, transparent);
        overflow: hidden;
    }

    .ellso-progress-fill {
        display: block;
        height: 100%;
        border-radius: 9999px;
        background: var(--ellso-emerald-500);
    }

    .ellso-progress-value {
        font-variant-numeric: tabular-nums;
        font-size: 13px;
        color: var(--gray-600);
        min-width: 36px;
        text-align: end;
    }

    :root.dark .ellso-progress-value {
        color: var(--gray-400);
    }
</style>
