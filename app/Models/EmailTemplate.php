<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

/**
 * Nội dung email admin chỉnh trên CMS. Mẫu (key) do code định nghĩa và tạo bằng migration;
 * phần cố định (logo, mật khẩu, hạn dùng, chân trang công ty) nằm trong view, không sửa được.
 *
 * Văn bản hỗ trợ biến {ten}, {email}… (xem variables()) và **in đậm**.
 */
class EmailTemplate extends Model
{
    public const TEMP_PASSWORD_ACTIVATION = 'temp_password_activation';

    public const TEMP_PASSWORD_RESET = 'temp_password_reset';

    /** Các trường văn bản admin sửa được. */
    public const TEXT_FIELDS = [
        'subject', 'preheader', 'eyebrow', 'heading', 'intro',
        'steps_title', 'after_text', 'note', 'sign_off', 'footer_reason',
    ];

    protected $fillable = [
        'subject',
        'preheader',
        'eyebrow',
        'heading',
        'intro',
        'steps_title',
        'steps',
        'after_text',
        'note',
        'sign_off',
        'footer_reason',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
        ];
    }

    public static function forKey(string $key): self
    {
        return static::query()->where('key', $key)->firstOrFail();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * Biến dùng được trong mẫu này: tên biến => mô tả (hiện trên CMS).
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $vars = [
            'ten' => 'Họ tên người nhận',
            'email' => 'Email người nhận',
        ];

        if ($this->key === self::TEMP_PASSWORD_RESET) {
            $vars['han_dung'] = 'Thời điểm mật khẩu tạm hết hạn (VD: 21:35, 23/10/2026)';
            $vars['so_phut'] = 'Số phút mật khẩu tạm còn hiệu lực (VD: 30)';
        }

        return $vars;
    }

    /**
     * Dữ liệu mẫu để xem trước / gửi thử.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $ttl = (int) config('ellso.auth.temp_password_ttl_minutes');

        return [
            'ten' => 'Nguyễn Văn An',
            'email' => 'an.nguyen@gmail.com',
            'han_dung' => now()->addMinutes($ttl)->timezone('Asia/Ho_Chi_Minh')->format('H:i, d/m/Y'),
            'so_phut' => (string) $ttl,
        ];
    }

    /**
     * Văn bản thuần đã thay biến (dùng cho tiêu đề, dòng xem trước, bản text).
     *
     * @param  array<string, string>  $values
     */
    public function text(?string $value, array $values): string
    {
        $text = self::replaceVariables((string) $value, $values);

        return str_replace('**', '', $text);
    }

    /**
     * HTML an toàn: escape toàn bộ, rồi chỉ cho phép **in đậm** và xuống dòng.
     *
     * @param  array<string, string>  $values
     */
    public function html(?string $value, array $values): HtmlString
    {
        $html = e(self::replaceVariables((string) $value, $values));
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);

        return new HtmlString(nl2br($html, false));
    }

    /**
     * Các bước (bỏ dòng trống).
     *
     * @return list<string>
     */
    public function stepList(): array
    {
        return array_values(array_filter(
            array_map(fn ($s) => trim((string) (is_array($s) ? (reset($s) ?: '') : $s)), $this->steps ?? []),
            fn ($s) => $s !== '',
        ));
    }

    /**
     * Biến không có trong danh sách cho phép (để CMS báo lỗi khi lưu).
     *
     * @return list<string>
     */
    public function unknownVariablesIn(?string $value): array
    {
        preg_match_all('/\{([a-z_]+)\}/', (string) $value, $m);

        return array_values(array_diff(array_unique($m[1]), array_keys($this->variables())));
    }

    /** @param  array<string, string>  $values */
    private static function replaceVariables(string $text, array $values): string
    {
        $map = [];
        foreach ($values as $name => $val) {
            $map['{' . $name . '}'] = (string) $val;
        }

        return strtr($text, $map);
    }
}
