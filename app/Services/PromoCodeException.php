<?php

namespace App\Services;

use RuntimeException;

/** Mã khuyến mãi không dùng được. $reason là mã lý do cho app, message là câu hiển thị cho học viên. */
class PromoCodeException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
