<?php

namespace App\Mail;

use App\Enums\TempPasswordReason;
use App\Models\EmailTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email mật khẩu tạm. Văn bản lấy từ email_templates (sửa trên CMS);
 * mật khẩu, hạn dùng, thông tin yêu cầu và chân trang công ty là phần cố định.
 */
class TempPasswordMail extends Mailable
{
    public EmailTemplate $template;

    /**
     * @param  array{time?: string, device?: string|null}|null  $requestInfo  Thời điểm / thiết bị yêu cầu (quên mật khẩu)
     */
    public function __construct(
        public User $user,
        public string $tempPassword,
        public TempPasswordReason $reason,
        public ?CarbonInterface $expiresAt,
        public ?array $requestInfo = null,
        ?EmailTemplate $template = null,
    ) {
        $this->template = $template ?? EmailTemplate::forKey(self::templateKey($reason));
    }

    public static function templateKey(TempPasswordReason $reason): string
    {
        return $reason->isActivation()
            ? EmailTemplate::TEMP_PASSWORD_ACTIVATION
            : EmailTemplate::TEMP_PASSWORD_RESET;
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $tz = 'Asia/Ho_Chi_Minh';

        return [
            'ten' => $this->user->name,
            'email' => $this->user->email,
            'han_dung' => $this->expiresAt?->copy()->timezone($tz)->format('H:i, d/m/Y') ?? '',
            'so_phut' => $this->expiresAt
                ? (string) max(1, (int) round(now()->diffInMinutes($this->expiresAt)))
                : '',
        ];
    }

    public function envelope(): Envelope
    {
        $support = SystemSetting::supportEmail();

        return new Envelope(
            subject: $this->template->text($this->template->subject, $this->values()),
            replyTo: $support ? [new Address($support, 'Ellso')] : [],
        );
    }

    public function content(): Content
    {
        $values = $this->values();

        return new Content(
            view: 'emails.temp-password',
            text: 'emails.temp-password-text',
            with: [
                't' => $this->template,
                'v' => $values,
                'password' => $this->tempPassword,
                'expiresText' => $values['han_dung'] !== ''
                    ? "Hiệu lực đến {$values['han_dung']} ({$values['so_phut']} phút)"
                    : null,
                'requestInfo' => $this->requestInfo,
                'supportEmail' => SystemSetting::supportEmail(),
                'companyName' => SystemSetting::companyName(),
                'companyAddress' => SystemSetting::companyAddress(),
                'logoUrl' => asset('images/email-logo.png'),
            ],
        );
    }
}
