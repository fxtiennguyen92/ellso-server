<?php

namespace App\Support;

use App\Enums\TempPasswordReason;
use App\Mail\TempPasswordMail;
use App\Models\EmailTemplate;
use App\Models\User;

/** Dựng email mẫu từ một EmailTemplate (kể cả nội dung chưa lưu) để xem trước / gửi thử trên CMS. */
class EmailTemplatePreview
{
    public static function mail(EmailTemplate $template, ?User $recipient = null): TempPasswordMail
    {
        $sample = $template->sampleValues();

        $user = new User;
        $user->name = $recipient?->name ?? $sample['ten'];
        $user->email = $recipient?->email ?? $sample['email'];

        $isReset = $template->key === EmailTemplate::TEMP_PASSWORD_RESET;

        return new TempPasswordMail(
            user: $user,
            tempPassword: TempPassword::generate(),
            reason: match ($template->key) {
                EmailTemplate::TEMP_PASSWORD_RESET => TempPasswordReason::Forgot,
                EmailTemplate::TEMP_PASSWORD_INVITE => TempPasswordReason::AdminCreated,
                default => TempPasswordReason::Register,
            },
            expiresAt: $isReset ? now()->addMinutes((int) config('ellso.auth.temp_password_ttl_minutes')) : null,
            requestInfo: $isReset
                ? ['time' => now()->timezone('Asia/Ho_Chi_Minh')->format('H:i, d/m/Y'), 'device' => 'iPhone 15 Pro · iOS 18.1']
                : null,
            template: $template,
        );
    }

    /** Template với dữ liệu form chưa lưu (không ghi DB). */
    public static function withFormData(EmailTemplate $record, array $data): EmailTemplate
    {
        $template = $record->replicate();
        $template->key = $record->key;
        $template->fill(array_intersect_key($data, array_flip([...EmailTemplate::TEXT_FIELDS, 'steps'])));

        return $template;
    }
}
