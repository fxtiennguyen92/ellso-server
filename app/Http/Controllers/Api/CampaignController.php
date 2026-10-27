<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Support\CampaignContent;
use Illuminate\Http\JsonResponse;

/**
 * Chiến dịch marketing cho app (không cần đăng nhập).
 * - GET /campaigns       : banner đang hiển thị trên Home (đúng thứ tự), app hiện dạng trượt.
 * - GET /campaigns/{id}  : trang chi tiết khi bấm banner (khối nội dung đã chuẩn hoá, xem CampaignContent).
 */
class CampaignController extends Controller
{
    public function index(): JsonResponse
    {
        $campaigns = MarketingCampaign::query()->visible()->limit(10)->get();

        return response()->json([
            'data' => $campaigns->map(fn (MarketingCampaign $c) => $this->summary($c))->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = MarketingCampaign::query()->visible()->with('ctaCourse:id,title')->find($id);

        if (! $campaign) {
            return response()->json(['message' => 'Chiến dịch không tồn tại hoặc đã kết thúc.'], 404);
        }

        return response()->json([
            'data' => [
                ...$this->summary($campaign),
                'blocks' => CampaignContent::resolve($campaign->content),
                'cta' => match ($campaign->cta_type) {
                    MarketingCampaign::CTA_URL => ['type' => 'url', 'label' => $campaign->cta_label, 'url' => $campaign->cta_url],
                    MarketingCampaign::CTA_COURSE => $campaign->ctaCourse
                        ? ['type' => 'course', 'label' => $campaign->cta_label, 'course_id' => $campaign->ctaCourse->id]
                        : null,
                    default => null,
                },
            ],
        ]);
    }

    private function summary(MarketingCampaign $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'summary' => $c->summary,
            'banner' => [
                'url' => $c->bannerUrl(),
                'width' => MarketingCampaign::BANNER_WIDTH,
                'height' => MarketingCampaign::BANNER_HEIGHT,
            ],
            'starts_at' => $c->starts_at->toIso8601String(),
            'ends_at' => $c->ends_at->toIso8601String(),
        ];
    }
}
