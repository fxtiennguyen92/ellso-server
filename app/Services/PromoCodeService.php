<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\Course;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra điều kiện và tính tiền giảm của mã khuyến mãi.
 *
 * - evaluate(): xem trước (app gọi khi học viên nhập mã), không ghi gì.
 * - redeem():   dùng khi tạo đơn hàng — khoá dòng mã, kiểm tra lại, ghi lượt dùng.
 *
 * Mỗi đơn chỉ dùng 1 mã. Giảm theo % tính trên tổng giá các khoá thuộc phạm vi áp dụng,
 * có trần max_discount; giảm theo số tiền không vượt quá tổng đó. Làm tròn xuống đồng.
 */
class PromoCodeService
{
    /**
     * @param  Collection<int, Course>  $courses  Các khoá trong đơn (giá lấy từ DB)
     * @return array{promo_code: PromoCode, subtotal: int, eligible_subtotal: int, discount: int, total: int, eligible_course_ids: list<int>}
     *
     * @throws PromoCodeException
     */
    public function evaluate(PromoCode | string | null $code, User $user, Collection $courses): array
    {
        $promo = $code instanceof PromoCode ? $code : PromoCode::findByCode($code);

        if (! $promo) {
            throw new PromoCodeException('not_found', 'Mã khuyến mãi không tồn tại.');
        }

        $promo->loadMissing(['courses:id', 'categories:id']);
        $tz = 'Asia/Ho_Chi_Minh';

        if (! $promo->is_active) {
            throw new PromoCodeException('inactive', 'Mã khuyến mãi hiện không áp dụng.');
        }

        if ($promo->starts_at?->isFuture()) {
            throw new PromoCodeException('not_started', 'Mã khuyến mãi áp dụng từ '
                . $promo->starts_at->copy()->timezone($tz)->format('H:i, d/m/Y') . '.');
        }

        if ($promo->ends_at?->isPast()) {
            throw new PromoCodeException('expired', 'Mã khuyến mãi đã hết hạn.');
        }

        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw new PromoCodeException('usage_limit_reached', 'Mã khuyến mãi đã hết lượt sử dụng.');
        }

        if ($promo->per_user_limit !== null
            && $promo->redemptions()->where('user_id', $user->getKey())->count() >= $promo->per_user_limit) {
            throw new PromoCodeException('user_limit_reached', 'Bạn đã dùng hết lượt của mã khuyến mãi này.');
        }

        $subtotal = (int) $courses->sum('price');
        $eligible = $courses->filter(fn (Course $course) => $promo->appliesToCourse($course));

        if ($eligible->isEmpty()) {
            throw new PromoCodeException('not_applicable', 'Mã khuyến mãi không áp dụng cho khoá học này.');
        }

        if ($promo->min_order_amount !== null && $subtotal < $promo->min_order_amount) {
            throw new PromoCodeException('min_order_not_met', 'Đơn hàng cần tối thiểu '
                . PromoCode::money($promo->min_order_amount) . ' để dùng mã này.');
        }

        $eligibleSubtotal = (int) $eligible->sum('price');

        if ($eligibleSubtotal <= 0) {
            throw new PromoCodeException('nothing_to_discount', 'Khoá học miễn phí, không cần dùng mã khuyến mãi.');
        }

        $discount = $this->discountFor($promo, $eligibleSubtotal);

        return [
            'promo_code' => $promo,
            'subtotal' => $subtotal,
            'eligible_subtotal' => $eligibleSubtotal,
            'discount' => $discount,
            'total' => $subtotal - $discount,
            'eligible_course_ids' => $eligible->pluck('id')->values()->all(),
        ];
    }

    /**
     * Ghi nhận mã được dùng cho một đơn. Khoá dòng mã để không vượt giới hạn khi nhiều người dùng cùng lúc.
     *
     * @param  Collection<int, Course>  $courses
     *
     * @throws PromoCodeException
     */
    public function redeem(PromoCode | string $code, User $user, Collection $courses, ?int $orderId = null): PromoCodeRedemption
    {
        return DB::transaction(function () use ($code, $user, $courses, $orderId) {
            $id = $code instanceof PromoCode ? $code->getKey() : PromoCode::findByCode($code)?->getKey();
            $promo = $id ? PromoCode::query()->lockForUpdate()->find($id) : null;

            $result = $this->evaluate($promo, $user, $courses);

            $redemption = $promo->redemptions()->create([
                'user_id' => $user->getKey(),
                'order_id' => $orderId,
                'order_amount' => $result['subtotal'],
                'discount_amount' => $result['discount'],
            ]);

            $promo->increment('used_count');

            return $redemption;
        });
    }

    public function discountFor(PromoCode $promo, int $eligibleSubtotal): int
    {
        $discount = $promo->discount_type === DiscountType::Percent
            ? (int) floor($eligibleSubtotal * (float) $promo->discount_value / 100)
            : (int) $promo->discount_value;

        if ($promo->discount_type === DiscountType::Percent && $promo->max_discount !== null) {
            $discount = min($discount, $promo->max_discount);
        }

        return max(0, min($discount, $eligibleSubtotal));
    }
}
