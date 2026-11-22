<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\LegalMarkdown;
use Illuminate\Http\JsonResponse;

/**
 * GET /v1/legal/{terms|privacy} (công khai, dùng cả ở màn Đăng ký khi chưa đăng nhập).
 * Trả `{slug, title, version, effective_on, updated_at, blocks}`; blocks cùng định dạng khối bài học (header / paragraph / list).
 */
class LegalController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = LegalPage::query()->where('slug', $slug)->first();
        abort_unless($page, 404, 'Không tìm thấy trang.');

        return response()->json(['data' => [
            'slug' => $page->slug,
            'title' => $page->title,
            'version' => $page->version,
            'effective_on' => $page->effective_on->toDateString(),
            'updated_at' => $page->updated_at?->toIso8601String(),
            'blocks' => LegalMarkdown::toBlocks($page->renderedContent()),
        ]]);
    }
}
