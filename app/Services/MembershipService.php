<?php

namespace App\Services;

use App\Enums\MembershipSource;
use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Models\MembershipBundleItem;
use App\Models\MembershipCampaign;
use App\Models\StudentMembership;
use App\Models\User;
use App\Models\UserLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cấp gói thành viên (luôn 1 năm).
 *
 * - grant():            cấp một gói; đang có gói còn hạn thì gói mới bắt đầu ngay sau ngày hết hạn (cộng dồn).
 * - grantForSignup():   tài khoản học viên mới tạo trong thời gian một chiến dịch đang chạy → tặng gói.
 * - grantForPurchase(): mua khoá học / tài liệu nằm trong danh sách "kèm gói" → tặng gói.
 *                       Gọi từ luồng đơn hàng khi thanh toán thành công (chưa có — GĐ5).
 * - backfillCampaign(): tặng cho học viên đã đăng ký trong thời gian chiến dịch nhưng chưa nhận.
 */
class MembershipService
{
    public const MONTHS = 12;

    public function grant(
        User $user,
        MembershipSource $source,
        int $price = 0,
        array $attributes = [],
        ?User $actor = null,
    ): StudentMembership {
        return DB::transaction(function () use ($user, $source, $price, $attributes, $actor) {
            // Khoá dòng user để 2 lần cấp cùng lúc không chồng thời gian
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $currentEnd = $user->memberships()->where('ends_at', '>', now())->max('ends_at');
            $startsAt = $currentEnd ? now()->parse($currentEnd) : now();

            $membership = $user->memberships()->create([
                ...$attributes,
                'source' => $source,
                'price' => $price,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMonths(self::MONTHS),
                'created_by_id' => $actor?->getKey(),
            ]);

            UserLog::record($user, UserLogEvent::MembershipGranted, null, array_filter([
                'source' => $source->value,
                'membership_id' => $membership->id,
                'ends_at' => $membership->ends_at->toIso8601String(),
            ]), $actor);

            return $membership;
        });
    }

    public function grantForSignup(User $user): ?StudentMembership
    {
        if ($user->role !== UserRole::Student) {
            return null;
        }

        $campaign = MembershipCampaign::query()->runningAt($user->created_at ?? now())->oldest('starts_at')->first();

        return $campaign ? $this->grantFromCampaign($user, $campaign) : null;
    }

    public function grantForPurchase(User $user, Model $item, ?int $orderId = null): ?StudentMembership
    {
        if (! MembershipBundleItem::includes($item)) {
            return null;
        }

        return $this->grant($user, MembershipSource::Bundle, 0, [
            'source_item_type' => MembershipBundleItem::typeOf($item),
            'source_item_id' => $item->getKey(),
            'order_id' => $orderId,
        ]);
    }

    /** @return int số học viên vừa được tặng */
    public function backfillCampaign(MembershipCampaign $campaign, ?User $actor = null): int
    {
        $count = 0;

        $this->eligibleForBackfill($campaign)->lazyById(500)->each(function (User $user) use ($campaign, $actor, &$count) {
            $this->grantFromCampaign($user, $campaign, $actor);
            $count++;
        });

        return $count;
    }

    /** Học viên tạo tài khoản trong thời gian chiến dịch nhưng chưa nhận gói của chiến dịch. */
    public function eligibleForBackfill(MembershipCampaign $campaign)
    {
        return User::query()
            ->where('role', UserRole::Student)
            ->whereBetween('created_at', [$campaign->starts_at, $this->clampToNow($campaign->ends_at)])
            ->whereDoesntHave('memberships', fn ($query) => $query->where('membership_campaign_id', $campaign->id));
    }

    private function grantFromCampaign(User $user, MembershipCampaign $campaign, ?User $actor = null): ?StudentMembership
    {
        if ($user->memberships()->where('membership_campaign_id', $campaign->id)->exists()) {
            return null; // mỗi chiến dịch chỉ tặng 1 lần cho mỗi người
        }

        return $this->grant($user, MembershipSource::Campaign, 0, ['membership_campaign_id' => $campaign->id], $actor);
    }

    private function clampToNow(CarbonInterface $at): CarbonInterface
    {
        return $at->isFuture() ? now() : $at;
    }
}
