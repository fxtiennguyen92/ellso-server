<?php

namespace App\Http\Requests\Auth;

use App\Support\ReferralCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
            'phone' => self::normalizePhone($this->input('phone')),
            'referral_code' => ReferralCode::normalize($this->input('referral_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            // SĐT Việt Nam: 10 số, bắt đầu bằng 0
            'phone' => ['nullable', 'regex:/^0\d{9}$/'],
            'referral_code' => [
                'nullable',
                'string',
                Rule::exists('users', 'referral_code')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ tên.',
            'name.max' => 'Họ tên quá dài.',
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
            'email.unique' => 'Email này đã được đăng ký.',
            'phone.regex' => 'Số điện thoại không hợp lệ (10 số, bắt đầu bằng 0).',
            'referral_code.exists' => 'Mã giới thiệu không hợp lệ.',
        ];
    }

    /** Bỏ khoảng trắng / dấu chấm / gạch; nhận cả dạng +84 / 84 rồi đổi về 0xxxxxxxxx. */
    public static function normalizePhone(mixed $phone): ?string
    {
        $phone = preg_replace('/[\s.\-()]/', '', (string) $phone);

        if ($phone === '') {
            return null;
        }

        if (preg_match('/^\+?84(\d{9})$/', $phone, $m)) {
            return '0' . $m[1];
        }

        return $phone;
    }
}
