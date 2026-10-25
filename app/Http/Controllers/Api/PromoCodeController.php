<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\PromoCodeException;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PromoCodeController extends Controller
{
    /**
     * POST /promo-codes/check — học viên nhập mã ở màn thanh toán, app xem trước số tiền giảm.
     * Body: { code, course_ids: [..] }. Không ghi nhận lượt dùng (ghi khi tạo đơn hàng).
     */
    public function check(Request $request, PromoCodeService $promoCodes): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'course_ids' => ['required', 'array', 'min:1', 'max:50'],
            'course_ids.*' => ['integer', 'distinct'],
        ], [
            'code.required' => 'Vui lòng nhập mã khuyến mãi.',
            'course_ids.required' => 'Vui lòng chọn khoá học.',
        ]);

        $courses = Course::query()
            ->whereKey($data['course_ids'])
            ->where('status', CourseStatus::Published)
            ->get(['id', 'title', 'price', 'category_id']);

        if ($courses->count() !== count($data['course_ids'])) {
            throw ValidationException::withMessages(['course_ids' => 'Khoá học không tồn tại hoặc chưa mở bán.']);
        }

        try {
            $result = $promoCodes->evaluate($data['code'], $request->user(), $courses);
        } catch (PromoCodeException $e) {
            return response()->json([
                'valid' => false,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 422);
        }

        $promo = $result['promo_code'];

        return response()->json([
            'valid' => true,
            'code' => $promo->code,
            'description' => $promo->description,
            'discount_type' => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'max_discount' => $promo->max_discount,
            'subtotal' => $result['subtotal'],
            'discount' => $result['discount'],
            'total' => $result['total'],
            'eligible_course_ids' => $result['eligible_course_ids'],
            'ends_at' => $promo->ends_at?->toIso8601String(),
            'message' => 'Áp dụng mã thành công: giảm ' . number_format($result['discount'], 0, ',', '.') . ' ₫.',
        ]);
    }
}
