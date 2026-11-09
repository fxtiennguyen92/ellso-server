@php
    use App\Support\LessonStats;

    $s = $this->stats();
    $pct = fn ($v) => $v === null ? '—' : $v . '%';
    $platformLabels = ['ios' => 'iOS', 'android' => 'Android', 'web' => 'Web'];
@endphp

<x-filament-panels::page>
    <style>
        .ls-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .75rem; }
        .ls-tile { padding: 1rem; border-radius: .75rem; border: 1px solid var(--gray-200); background: var(--gray-50); }
        .dark .ls-tile { border-color: var(--gray-700); background: rgba(255,255,255,.03); }
        .ls-label { font-size: .8125rem; color: var(--gray-500); }
        .ls-value { margin-top: .25rem; font-size: 1.5rem; font-weight: 600; }
        .ls-hint { margin-top: .125rem; font-size: .75rem; color: var(--gray-500); }
        .ls-bar { height: .5rem; border-radius: 999px; background: var(--gray-200); overflow: hidden; }
        .dark .ls-bar { background: var(--gray-700); }
        .ls-bar > span { display: block; height: 100%; border-radius: 999px; background: rgb(16 185 129); }
        .ls-row { display: grid; grid-template-columns: 7rem 1fr 3rem; align-items: center; gap: .75rem; font-size: .875rem; padding: .375rem 0; }
        .ls-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .ls-table th { text-align: left; font-weight: 500; color: var(--gray-500); padding: .5rem; border-bottom: 1px solid var(--gray-200); }
        .ls-table td { padding: .5rem; border-bottom: 1px solid var(--gray-100); vertical-align: top; }
        .dark .ls-table th, .dark .ls-table td { border-color: var(--gray-700); }
        .ls-warn { color: rgb(220 38 38); font-weight: 600; }
        .ls-note { font-size: .8125rem; color: var(--gray-500); }
        .ls-chips { display: flex; flex-wrap: wrap; gap: .5rem; }
        .ls-chip { padding: .25rem .625rem; border-radius: 999px; font-size: .8125rem; background: var(--gray-100); }
        .dark .ls-chip { background: var(--gray-800); }
    </style>

    @if ($s['learners'] === 0 && $s['viewed'] === 0)
        <x-filament::section>
            <p class="ls-note">Chưa có học viên nào mở bài này trên app. Số liệu sẽ hiện khi học viên bắt đầu học.</p>
        </x-filament::section>
    @else
        <div class="ls-grid">
            <div class="ls-tile">
                <div class="ls-label">Học viên đã mở</div>
                <div class="ls-value">{{ $s['viewed'] }}</div>
                <div class="ls-hint">{{ $s['sessions'] }} lượt mở bài</div>
            </div>
            <div class="ls-tile">
                <div class="ls-label">Thời gian học (trung vị)</div>
                <div class="ls-value">{{ LessonStats::duration($s['median_seconds']) }}</div>
                <div class="ls-hint">Mỗi lượt trung bình {{ LessonStats::duration($s['avg_session_seconds']) }}</div>
            </div>
            <div class="ls-tile">
                <div class="ls-label">Đọc hết bài</div>
                <div class="ls-value">{{ $pct($s['read_percent']) }}</div>
                <div class="ls-hint">trên số học viên đã mở</div>
            </div>
            <div class="ls-tile">
                <div class="ls-label">Hoàn thành</div>
                <div class="ls-value">{{ $pct($s['completed_percent']) }}</div>
                <div class="ls-hint">{{ $s['completed'] }} học viên (đọc hết + đúng hết câu hỏi)</div>
            </div>
            <div class="ls-tile">
                <div class="ls-label">Thấy hữu ích</div>
                <div class="ls-value">{{ $pct($s['feedback']['up_percent']) }}</div>
                <div class="ls-hint">{{ $s['feedback']['total'] }} lượt đánh giá</div>
            </div>
        </div>

        <x-filament::section heading="Học viên dừng ở đâu" description="Tỉ lệ học viên cuộn qua mỗi mốc của bài (lấy lần cuộn sâu nhất của mỗi người). Tụt mạnh giữa hai mốc → đoạn đó có thể quá dài hoặc khó.">
            @foreach ($s['drop_off'] as $d)
                <div class="ls-row">
                    <span>{{ $d['mark'] === 100 ? 'Cuối bài' : $d['mark'] . '% bài' }}</span>
                    <div class="ls-bar"><span style="width: {{ $d['percent'] ?? 0 }}%"></span></div>
                    <span>{{ $pct($d['percent']) }}</span>
                </div>
            @endforeach
            @if ($s['platforms'])
                <p class="ls-note" style="margin-top: .75rem">
                    Thiết bị:
                    {{ collect($s['platforms'])->map(fn ($n, $p) => ($platformLabels[$p] ?? $p) . ' ' . $n)->join(' · ') }}
                </p>
            @endif
        </x-filament::section>

        @if ($s['quizzes'])
            <x-filament::section heading="Câu hỏi" description="Đúng ngay lần đầu dưới 50% (tô đỏ) → câu hỏi khó, đề chưa rõ hoặc nội dung trước đó chưa đủ.">
                <table class="ls-table">
                    <thead><tr><th>#</th><th>Câu hỏi</th><th>Học viên trả lời</th><th>Đúng lần đầu</th><th>Số lần đến khi đúng</th></tr></thead>
                    <tbody>
                        @foreach ($s['quizzes'] as $q)
                            <tr>
                                <td>{{ $q['number'] }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($q['question'], 120) }}</td>
                                <td>{{ $q['learners'] }}</td>
                                <td @class(['ls-warn' => $q['first_try_percent'] !== null && $q['first_try_percent'] < 50])>{{ $pct($q['first_try_percent']) }}</td>
                                <td>{{ $q['avg_tries'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        @if ($s['video'])
            <x-filament::section heading="Video">
                <div class="ls-grid">
                    <div class="ls-tile">
                        <div class="ls-label">Thời lượng</div>
                        <div class="ls-value">{{ LessonStats::duration($s['video']['duration_seconds']) }}</div>
                        <div class="ls-hint">{{ $s['video']['count'] }} video trong bài</div>
                    </div>
                    <div class="ls-tile">
                        <div class="ls-label">Học viên có xem</div>
                        <div class="ls-value">{{ $s['video']['viewers'] }}</div>
                    </div>
                    <div class="ls-tile">
                        <div class="ls-label">Xem được trung bình</div>
                        <div class="ls-value">{{ $pct($s['video']['watch_percent']) }}</div>
                        <div class="ls-hint">so với tổng thời lượng</div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Đánh giá cuối bài">
            <p class="ls-note">👍 Hữu ích: {{ $s['feedback']['up'] }} · 👎 Chưa hữu ích: {{ $s['feedback']['down'] }}</p>
            @if ($s['feedback']['reasons'])
                <div class="ls-chips" style="margin-top: .75rem">
                    @foreach ($s['feedback']['reasons'] as $r)
                        <span class="ls-chip">{{ $r['label'] }} · {{ $r['count'] }}</span>
                    @endforeach
                </div>
            @endif
            @if ($s['feedback']['comments'])
                <table class="ls-table" style="margin-top: .75rem">
                    <tbody>
                        @foreach ($s['feedback']['comments'] as $c)
                            <tr>
                                <td style="width: 2rem">{{ $c['rating'] > 0 ? '👍' : '👎' }}</td>
                                <td>{{ $c['comment'] }}</td>
                                <td class="ls-note" style="white-space: nowrap">{{ $c['name'] }} · {{ $c['at']->format('d/m/Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
