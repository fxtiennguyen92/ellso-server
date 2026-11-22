<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trang pháp lý hiển thị trong app: Điều khoản sử dụng (terms), Chính sách bảo mật (privacy).
 * Nội dung viết Markdown đơn giản (## tiêu đề, ### tiêu đề nhỏ, - gạch đầu dòng, 1. đánh số, **đậm**, *nghiêng*, [chữ](link))
 * và các biến {ten_cong_ty}, {dia_chi}, {email_ho_tro} lấy từ Cấu hình hệ thống.
 */
class LegalPage extends Model
{
    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const SLUGS = [self::TERMS, self::PRIVACY];

    protected $fillable = ['slug', 'title', 'content', 'version', 'effective_on', 'updated_by_id'];

    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** Biến dùng được trong nội dung → giá trị hiện tại. */
    public static function variables(): array
    {
        return [
            'ten_cong_ty' => SystemSetting::companyName() ?? 'Ellso',
            'dia_chi' => SystemSetting::companyAddress() ?? 'đang cập nhật',
            'email_ho_tro' => SystemSetting::supportEmail() ?? 'đang cập nhật',
        ];
    }

    public const VARIABLE_LABELS = [
        'ten_cong_ty' => 'Tên công ty',
        'dia_chi' => 'Địa chỉ công ty',
        'email_ho_tro' => 'Email hỗ trợ',
    ];

    /** Nội dung đã thay biến. */
    public function renderedContent(): string
    {
        $vars = self::variables();

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $this->content);
    }

    /** Biến gõ sai (không có trong danh sách) trong một đoạn văn bản. */
    public static function unknownVariablesIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_diff(array_unique($m[1]), array_keys(self::VARIABLE_LABELS)));
    }
}
