<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email link tham gia một buổi workshop (admin bấm "Gửi link cho người đăng ký").
 * Văn bản lấy từ mẫu email "workshop_link" (sửa trên CMS); tên workshop, thời gian và nút vào phòng là phần cố định.
 * Truyền sẵn tên / thời gian / link (không giữ model) để email gửi qua queue (Mail::queue) đúng nội dung lúc bấm gửi.
 */
class WorkshopLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?EmailTemplate $template = null;

    public function __construct(
        public string $name,
        public string $email,
        public string $workshopTitle,
        public string $when,
        public string $meetingUrl,
        ?EmailTemplate $template = null,
    ) {
        $this->template = $template;
    }

    public static function forRecipient(User $user, string $workshopTitle, string $when, string $meetingUrl): self
    {
        return new self($user->name, $user->email, $workshopTitle, $when, $meetingUrl);
    }

    private function template(): EmailTemplate
    {
        return $this->template ??= EmailTemplate::forKey(EmailTemplate::WORKSHOP_LINK);
    }

    /** @return array<string, string> */
    public function values(): array
    {
        return [
            'ten' => $this->name,
            'email' => $this->email,
            'workshop' => $this->workshopTitle,
            'thoi_gian' => $this->when,
            'link' => $this->meetingUrl,
        ];
    }

    public function envelope(): Envelope
    {
        $support = SystemSetting::supportEmail();

        return new Envelope(
            subject: $this->template()->text($this->template()->subject, $this->values()),
            replyTo: $support ? [new Address($support, 'Ellso')] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            text: 'emails.workshop-link-text',
            with: [
                'box' => 'emails.boxes.workshop-link',
                't' => $this->template(),
                'v' => $this->values(),
                'meeting' => ['workshop' => $this->workshopTitle, 'when' => $this->when, 'url' => $this->meetingUrl],
                'requestInfo' => null,
                'supportEmail' => SystemSetting::supportEmail(),
                'companyName' => SystemSetting::companyName(),
                'companyAddress' => SystemSetting::companyAddress(),
                'logoUrl' => asset('images/email-logo.png'),
            ],
        );
    }
}
