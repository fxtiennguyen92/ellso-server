{{-- Học bạ › Khoá học › Tiến độ: từng bài trong lộ trình --}}
@php
    $labels = ['completed' => ['Hoàn thành', 'var(--success-600)', '✓'], 'available' => ['Đang mở', 'var(--primary-600)', '○'], 'locked' => ['Chưa mở', 'var(--gray-400)', '🔒']];
    $chapter = null;
@endphp
@if (empty($rows))
    <div style="color:var(--gray-500)">Học viên chưa có lộ trình — sẽ trả lời câu hỏi phân loại trên app, hoặc admin gán lộ trình bằng "Đổi lộ trình".</div>
@else
    <div style="display:flex;gap:16px;align-items:center;margin-bottom:14px">
        <div style="font-size:28px;font-weight:700">{{ $summary['percent'] }}%</div>
        <div style="flex:1">
            <div style="height:8px;border-radius:99px;background:color-mix(in oklab, var(--gray-500) 18%, transparent)">
                <div style="height:8px;border-radius:99px;width:{{ $summary['percent'] }}%;background:var(--primary-500)"></div>
            </div>
            <div style="font-size:13px;color:var(--gray-500);margin-top:4px">{{ $summary['completed'] }}/{{ $summary['total'] }} bài hoàn thành</div>
        </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:2px">
        @foreach ($rows as $row)
            @if ($row['chapter'] !== $chapter)
                @php $chapter = $row['chapter']; @endphp
                <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--gray-500);margin:12px 0 4px">{{ $chapter }}</div>
            @endif
            @php [$label, $color, $icon] = $labels[$row['state']]; @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;border:1px solid color-mix(in oklab, var(--gray-500) 16%, transparent)">
                <span style="width:22px;text-align:center;color:{{ $color }}">{{ $icon }}</span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:14px">{{ $row['title'] }}</div>
                    <div style="font-size:12px;color:var(--gray-500)">
                        {{ $label }}
                        @if ($row['completed_at']) · xong {{ $row['completed_at'] }} @elseif ($row['viewed_at']) · xem gần nhất {{ $row['viewed_at'] }} @endif
                    </div>
                </div>
                @if ($row['quiz_total'])
                    <div style="font-size:12px;text-align:right;color:var(--gray-600)">
                        Câu hỏi đúng {{ $row['quiz_correct'] }}/{{ $row['quiz_total'] }}
                        @if ($row['attempts'])<div style="color:var(--gray-400)">{{ $row['attempts'] }} lần trả lời</div>@endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
