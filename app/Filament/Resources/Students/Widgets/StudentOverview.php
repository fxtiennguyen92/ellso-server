<?php

namespace App\Filament\Resources\Students\Widgets;

use App\Models\AppSession;
use App\Models\User;
use App\Services\LearningProgress;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/** Thẻ tổng quan trên Học bạ: khoá học, tiến độ, gói thành viên, mức dùng app. */
class StudentOverview extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        /** @var User $user */
        $user = $this->record;
        $tz = 'Asia/Ho_Chi_Minh';
        $progress = app(LearningProgress::class);

        $enrollments = $user->enrollments()->with(['learningPath', 'user', 'course'])->get();
        $percents = $enrollments->map(fn ($e) => $progress->summary($e)['percent'])->filter(fn ($p) => $p !== null);
        $done = $enrollments->whereNotNull('completed_at')->count();

        $lessonsDone = $user->lessonProgress()->whereNotNull('completed_at')->count();
        $answers = $user->quizAnswers()->count();
        $correct = $user->quizAnswers()->where('is_correct', true)->count();

        $end = $user->membershipEndsAt();
        $sessions = $user->appSessions()->where('started_at', '>=', now()->subDays(30))->get(['started_at', 'last_seen_at']);
        $minutes = (int) round($sessions->sum(fn (AppSession $s) => $s->durationSeconds()) / 60);
        $last = $user->appSessions()->max('last_seen_at');

        return [
            Stat::make('Khoá học', $enrollments->count() . ' khoá')
                ->description(($enrollments->count() - $done) . ' đang học · ' . $done . ' hoàn thành'
                    . ($percents->isNotEmpty() ? ' · TB ' . (int) round($percents->avg()) . '%' : ''))
                ->icon(Heroicon::OutlinedBookOpen),
            Stat::make('Bài đã hoàn thành', number_format($lessonsDone, 0, ',', '.'))
                ->description($answers ? 'Trả lời đúng ' . round($correct / $answers * 100) . '% câu hỏi (' . $correct . '/' . $answers . ')' : 'Chưa trả lời câu hỏi nào')
                ->icon(Heroicon::OutlinedCheckBadge),
            Stat::make('Gói thành viên', $end ? 'Còn hạn' : 'Chưa có')
                ->description($end ? 'Đến ' . $end->timezone($tz)->format('d/m/Y') : 'Cấp gói ở menu Thao tác')
                ->color($end ? 'success' : 'gray')
                ->icon(Heroicon::OutlinedIdentification),
            Stat::make('Mở app 30 ngày', $sessions->count() . ' lượt')
                ->description(($minutes ? $minutes . ' phút · ' : '') . ($last ? 'gần nhất ' . \Illuminate\Support\Carbon::parse($last)->diffForHumans() : 'chưa mở app'))
                ->icon(Heroicon::OutlinedDevicePhoneMobile),
        ];
    }
}
