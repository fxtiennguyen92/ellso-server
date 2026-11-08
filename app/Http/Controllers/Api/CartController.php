<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Document;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PromoCodeException;
use App\Services\PromoCodeService;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Giỏ hàng (lưu server, đồng bộ mọi máy của học viên). Chưa có thanh toán.
 * Món mua được: khoá học đã xuất bản có giá > 0 mà chưa ghi danh; sách có giá bán mà chưa mua.
 * Món không còn mua được (đã có quyền, ngừng bán, bị xoá) tự rời giỏ khi xem giỏ.
 *
 * - GET    /cart                       giỏ: {items, count, total, coins: {balance, rate (VND / 1 xu, CMS: Thiết lập › credit_to_vnd_rate)}}
 * - POST   /cart  {type, id}           thêm (đã có thì giữ nguyên) → giỏ
 * - DELETE /cart/{type}/{id}           bỏ ra → giỏ
 * - POST   /cart/promo {code}           xem trước mã khuyến mãi cho giỏ hiện tại (chưa ghi lượt dùng — ghi khi tạo đơn).
 *                                       Mã khuyến mãi hiện chỉ áp dụng cho khoá học (PromoCodeService); sách trong giỏ không được giảm.
 */
class CartController extends Controller
{
    public const MAX_ITEMS = 50;

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->cart($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:' . CartItem::TYPE_COURSE . ',' . CartItem::TYPE_DOCUMENT],
            'id' => ['required', 'integer'],
        ]);
        $user = $request->user();

        $reason = $data['type'] === CartItem::TYPE_COURSE
            ? $this->courseBlocker($user, Course::query()->find($data['id']))
            : $this->documentBlocker($user, Document::query()->published()->find($data['id']));
        if ($reason) {
            return response()->json(['message' => $reason[1], 'code' => $reason[0]], $reason[0] === 'not_found' ? 404 : 422);
        }

        $exists = $user->cartItems()->where('item_type', $data['type'])->where('item_id', $data['id'])->exists();
        if (! $exists && $user->cartItems()->count() >= self::MAX_ITEMS) {
            return response()->json(['message' => 'Giỏ hàng đã đầy.', 'code' => 'cart_full'], 422);
        }
        $user->cartItems()->firstOrCreate(['item_type' => $data['type'], 'item_id' => $data['id']]);

        return response()->json(['data' => $this->cart($user)], $exists ? 200 : 201);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $request->user()->cartItems()->where('item_type', $type)->where('item_id', $id)->delete();

        return response()->json(['data' => $this->cart($request->user())]);
    }

    public function promo(Request $request, PromoCodeService $promoCodes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Vui lòng nhập mã khuyến mãi.']);
        $user = $request->user();
        $cart = $this->cart($user);

        $courseIds = collect($cart['items'])->where('type', CartItem::TYPE_COURSE)->pluck('id');
        if ($courseIds->isEmpty()) {
            return response()->json([
                'valid' => false,
                'reason' => 'no_course',
                'message' => $cart['count'] ? 'Mã khuyến mãi hiện chỉ áp dụng cho khoá học.' : 'Giỏ hàng đang trống.',
            ], 422);
        }

        try {
            $result = $promoCodes->evaluate($data['code'], $user, Course::query()->whereKey($courseIds)->get(['id', 'title', 'price', 'category_id']));
        } catch (PromoCodeException $e) {
            return response()->json(['valid' => false, 'reason' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        $promo = $result['promo_code'];

        return response()->json(['data' => [
            'code' => $promo->code,
            'description' => $promo->description,
            'discount' => (int) $result['discount'],
            'eligible' => collect($result['eligible_course_ids'])->map(fn ($id) => ['type' => CartItem::TYPE_COURSE, 'id' => (int) $id])->values(),
            'subtotal' => $cart['total'],
            'total' => max(0, $cart['total'] - (int) $result['discount']),
            'ends_at' => $promo->ends_at?->toIso8601String(),
        ]]);
    }

    /** @return array{0: string, 1: string}|null [mã, thông báo] khi không thêm được */
    private function courseBlocker(User $user, ?Course $course): ?array
    {
        if (! $course || $course->status !== CourseStatus::Published) {
            return ['not_found', 'Khoá học không tồn tại.'];
        }
        if ($course->isFree()) {
            return ['free', 'Khoá học này miễn phí, không cần mua.'];
        }
        if ($user->enrollments()->where('course_id', $course->id)->exists()) {
            return ['owned', 'Bạn đã có khoá học này.'];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null */
    private function documentBlocker(User $user, ?Document $doc): ?array
    {
        if (! $doc) {
            return ['not_found', 'Tài liệu không tồn tại.'];
        }
        if (! $doc->isPurchasable()) {
            return ['not_for_sale', 'Tài liệu này không bán lẻ.'];
        }
        if ($user->documentPurchases()->where('document_id', $doc->id)->exists()) {
            return ['owned', 'Bạn đã mua tài liệu này.'];
        }

        return null;
    }

    /** Giỏ hiện tại (định dạng src/data/cart.ts › Cart của app); dọn món không còn mua được. */
    private function cart(User $user): array
    {
        $rows = $user->cartItems()->latest('id')->get();
        $courses = Course::query()->with('instructor')
            ->whereIn('id', $rows->where('item_type', CartItem::TYPE_COURSE)->pluck('item_id'))->get()->keyBy('id');
        $docs = Document::query()->published()->with('authorUser')
            ->whereIn('id', $rows->where('item_type', CartItem::TYPE_DOCUMENT)->pluck('item_id'))->get()->keyBy('id');

        $items = [];
        $stale = [];
        foreach ($rows as $row) {
            if ($row->item_type === CartItem::TYPE_COURSE) {
                $course = $courses[$row->item_id] ?? null;
                if ($this->courseBlocker($user, $course)) {
                    $stale[] = $row->id;

                    continue;
                }
                $items[] = [
                    'type' => CartItem::TYPE_COURSE,
                    'id' => $course->id,
                    'kind' => 'course',
                    'title' => $course->title,
                    'subtitle' => $course->instructor?->name,
                    'cover' => null,
                    'cover_url' => $course->cover_image ? MediaStorage::disk()->url($course->cover_image) : null,
                    'price' => (int) $course->price,
                    'added_at' => $row->created_at->toIso8601String(),
                ];
            } else {
                $doc = $docs[$row->item_id] ?? null;
                if ($this->documentBlocker($user, $doc)) {
                    $stale[] = $row->id;

                    continue;
                }
                $items[] = [
                    'type' => CartItem::TYPE_DOCUMENT,
                    'id' => $doc->id,
                    'kind' => $doc->type->value,
                    'title' => $doc->title,
                    'subtitle' => $doc->authorName(),
                    'cover' => $doc->cover_color?->value,
                    'cover_url' => $doc->coverUrl(),
                    'price' => (int) $doc->price,
                    'added_at' => $row->created_at->toIso8601String(),
                ];
            }
        }
        if ($stale) {
            CartItem::query()->whereKey($stale)->delete();
        }

        return [
            'items' => $items,
            'count' => count($items),
            'total' => array_sum(array_column($items, 'price')),
            // Xu dùng để trừ tiền khi thanh toán: app tự tính số xu dùng tối đa = min(số dư, floor(còn phải trả / rate)).
            // Trừ xu thật khi tạo đơn hàng (chưa làm).
            'coins' => [
                'balance' => (int) $user->coin_balance,
                'rate' => SystemSetting::creditToVndRate(),
            ],
        ];
    }
}
