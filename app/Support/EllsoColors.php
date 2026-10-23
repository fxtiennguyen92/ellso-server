<?php

namespace App\Support;

/**
 * Bảng màu CMS Ellso.
 *
 * Primary: gần đen (lấy từ thang Zinc, dồn các sắc 500–950 về phía tối) để nút chính có màu đen.
 * Trạng thái: độ bão hòa thấp (chroma ≤ 0.13 thay vì ~0.2 của Tailwind) → dịu mắt, hợp nền đen trắng.
 * Giá trị theo định dạng oklch mà Filament 5 dùng; Filament tự chọn màu chữ đủ tương phản trên nút.
 */
final class EllsoColors
{
    /** Đen — nút chính, link, mục menu đang chọn */
    public const Primary = [
        50 => 'oklch(0.985 0 0)',
        100 => 'oklch(0.967 0.001 286.375)',
        200 => 'oklch(0.92 0.004 286.32)',
        300 => 'oklch(0.871 0.006 286.286)',
        400 => 'oklch(0.705 0.015 286.067)',
        500 => 'oklch(0.3 0.006 286.033)',
        600 => 'oklch(0.2 0.006 285.885)',
        700 => 'oklch(0.16 0.005 285.823)',
        800 => 'oklch(0.14 0.004 285.823)',
        900 => 'oklch(0.12 0.003 285.823)',
        950 => 'oklch(0.09 0.002 285.823)',
    ];

    /** Xám trung tính, hơi lạnh — nền, viền, chữ phụ */
    public const Gray = [
        50 => 'oklch(0.985 0 0)',
        100 => 'oklch(0.967 0.001 286.375)',
        200 => 'oklch(0.92 0.004 286.32)',
        300 => 'oklch(0.871 0.006 286.286)',
        400 => 'oklch(0.705 0.015 286.067)',
        500 => 'oklch(0.552 0.016 285.938)',
        600 => 'oklch(0.442 0.017 285.786)',
        700 => 'oklch(0.37 0.013 285.805)',
        800 => 'oklch(0.274 0.006 286.033)',
        900 => 'oklch(0.21 0.006 285.885)',
        950 => 'oklch(0.141 0.005 285.823)',
    ];

    /** Xanh rêu — hoàn thành, đã xuất bản */
    public const Success = [
        50 => 'oklch(0.975 0.01 158)',
        100 => 'oklch(0.95 0.019 158)',
        200 => 'oklch(0.9 0.033 158)',
        300 => 'oklch(0.83 0.052 158)',
        400 => 'oklch(0.74 0.076 158)',
        500 => 'oklch(0.64 0.095 158)',
        600 => 'oklch(0.55 0.095 158)',
        700 => 'oklch(0.47 0.086 158)',
        800 => 'oklch(0.4 0.071 158)',
        900 => 'oklch(0.34 0.057 158)',
        950 => 'oklch(0.25 0.043 158)',
    ];

    /** Vàng đất — chờ duyệt, cần chú ý */
    public const Warning = [
        50 => 'oklch(0.985 0.012 72)',
        100 => 'oklch(0.965 0.023 72)',
        200 => 'oklch(0.93 0.04 72)',
        300 => 'oklch(0.86 0.063 72)',
        400 => 'oklch(0.77 0.092 72)',
        500 => 'oklch(0.67 0.115 72)',
        600 => 'oklch(0.58 0.115 72)',
        700 => 'oklch(0.5 0.104 72)',
        800 => 'oklch(0.43 0.086 72)',
        900 => 'oklch(0.37 0.069 72)',
        950 => 'oklch(0.28 0.052 72)',
    ];

    /** Đỏ gạch — xóa, ẩn, lỗi */
    public const Danger = [
        50 => 'oklch(0.975 0.013 27)',
        100 => 'oklch(0.95 0.026 27)',
        200 => 'oklch(0.9 0.045 27)',
        300 => 'oklch(0.83 0.072 27)',
        400 => 'oklch(0.74 0.104 27)',
        500 => 'oklch(0.64 0.13 27)',
        600 => 'oklch(0.55 0.13 27)',
        700 => 'oklch(0.47 0.117 27)',
        800 => 'oklch(0.4 0.098 27)',
        900 => 'oklch(0.34 0.078 27)',
        950 => 'oklch(0.25 0.059 27)',
    ];

    /** Xanh thép — thông tin */
    public const Info = [
        50 => 'oklch(0.975 0.007 245)',
        100 => 'oklch(0.95 0.015 245)',
        200 => 'oklch(0.9 0.026 245)',
        300 => 'oklch(0.83 0.041 245)',
        400 => 'oklch(0.74 0.06 245)',
        500 => 'oklch(0.64 0.075 245)',
        600 => 'oklch(0.55 0.075 245)',
        700 => 'oklch(0.47 0.068 245)',
        800 => 'oklch(0.4 0.056 245)',
        900 => 'oklch(0.34 0.045 245)',
        950 => 'oklch(0.25 0.034 245)',
    ];
}
