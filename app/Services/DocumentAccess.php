<?php

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\User;

/**
 * Quyền xem tài liệu — đủ MỘT điều kiện là mở:
 *  free     : miễn phí
 *  plus     : học viên có gói thành viên còn hạn (hết hạn → khoá lại ngay)
 *  course   : học viên đã được mở khoá học đó
 *  lesson   : bài học đó đang mở với học viên (đã ghi danh, không bị khoá theo lộ trình) — không cần học xong
 *  buy      : (chỉ Sách) học viên đã mua
 * Thứ tự điều kiện trả cho app = thứ tự gợi ý trong bảng "Cách mở".
 */
class DocumentAccess
{
    public function __construct(private readonly LearningProgress $progress) {}

    /** Điều kiện mở theo định dạng app (src/data/documents.ts › Access). */
    public function conditions(Document $doc): array
    {
        $doc->loadMissing(['links.course', 'links.lesson']);
        $out = [];

        if ($doc->is_free) {
            $out[] = ['kind' => 'free'];
        }
        foreach ($doc->links->whereNotNull('lesson_id') as $link) {
            $course = $this->courseOfLesson($link);
            $out[] = [
                'kind' => 'lesson',
                'lesson_id' => $link->lesson_id,
                'lesson' => $link->lesson?->title,
                'course_id' => $course?->id,
                'course_title' => $course?->title,
            ];
        }
        foreach ($doc->links->whereNotNull('course_id') as $link) {
            $out[] = ['kind' => 'course', 'course_id' => $link->course_id, 'course_title' => $link->course?->title];
        }
        if ($doc->isPurchasable()) {
            $out[] = ['kind' => 'buy', 'price' => $doc->price];
        }
        if ($doc->requires_membership) {
            $out[] = ['kind' => 'plus'];
        }

        return $out;
    }

    public function canView(?User $user, Document $doc): bool
    {
        if ($doc->is_free) {
            return true;
        }
        if (! $user) {
            return false;
        }
        if ($doc->requires_membership && $user->hasActiveMembership()) {
            return true;
        }
        if ($doc->isPurchasable() && $this->hasBought($user, $doc)) {
            return true;
        }

        $doc->loadMissing(['links.lesson']);
        $courseIds = $doc->links->pluck('course_id')->filter()->all();
        if ($courseIds && CourseEnrollment::query()->where('user_id', $user->id)->whereIn('course_id', $courseIds)->exists()) {
            return true;
        }
        foreach ($doc->links->whereNotNull('lesson_id') as $link) {
            if ($link->lesson) {
                $access = $this->progress->access($user, $link->lesson);
                if ($access['allowed'] && $access['enrollment'] !== null) {
                    return true; // bài đã mở (bài học thử không tính)
                }
            }
        }

        return false;
    }

    public function hasBought(User $user, Document $doc): bool
    {
        return $doc->purchases()->where('user_id', $user->id)->exists();
    }

    private function courseOfLesson(DocumentLink $link): ?\App\Models\Course
    {
        return \App\Models\Course::query()
            ->whereHas('learningPaths.pathLessons', fn ($q) => $q->where('lesson_id', $link->lesson_id))
            ->orderBy('id')
            ->first(['id', 'title']);
    }
}
