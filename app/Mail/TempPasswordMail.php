<?php

namespace App\Mail;

use App\Enums\TempPasswordReason;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TempPasswordMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $tempPassword,
        public TempPasswordReason $reason,
        public ?CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->reason->isActivation()
                ? 'Ellso – Mật khẩu đăng nhập lần đầu của bạn'
                : 'Ellso – Mật khẩu tạm để đăng nhập lại',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.temp-password',
            with: [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'password' => $this->tempPassword,
                'isActivation' => $this->reason->isActivation(),
                'ttlMinutes' => $this->expiresAt ? (int) round(now()->diffInMinutes($this->expiresAt)) : null,
            ],
        );
    }
}
