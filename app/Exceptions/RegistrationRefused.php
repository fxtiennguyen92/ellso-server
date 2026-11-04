<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Không đăng ký / huỷ đăng ký được chương trình huấn luyện hoặc workshop.
 * code (API): membership_required · payment_required · full · registration_closed · cancel_closed · cancel_not_allowed.
 */
class RegistrationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function membershipRequired(): self
    {
        return new self('membership_required', 'Bạn cần có gói thành viên còn hạn để đăng ký.', 403);
    }

    public static function paymentRequired(int $price): self
    {
        return new self('payment_required', 'Phí tham gia ' . number_format($price, 0, ',', '.') . ' ₫ — thanh toán trên app sẽ sớm có. Vui lòng liên hệ Ellso để đăng ký.', 402);
    }

    public static function full(): self
    {
        return new self('full', 'Đã đủ số người đăng ký.');
    }

    public static function closed(): self
    {
        return new self('registration_closed', 'Đã hết thời gian đăng ký.');
    }

    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->reason];
    }
}
