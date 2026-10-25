{{--
    Kết quả bài kiểm tra đầu vào (dùng cho "Làm thử" và "Xem bài làm").
    $results: [{dimension_id, name, score, min, max, level, level_description, rank, level_count}]
    $levels (tuỳ chọn): [dimension_id => [{name, min}] tăng dần] → vẽ ngưỡng trên thanh điểm
    $courses (tuỳ chọn): tên khoá học được gợi ý
    $answers (tuỳ chọn): bản chụp câu trả lời
--}}
@php
    $levels = $levels ?? [];
    $courses = $courses ?? [];
@endphp
<div style="display:flex;flex-direction:column;gap:12px">
    @forelse ($results as $r)
        @php
            $min = (int) ($r['min'] ?? 0);
            $max = (int) ($r['max'] ?? 0);
            $span = max(1, $max - $min);
            $pct = fn ($v) => max(0, min(100, ($v - $min) / $span * 100));
            $dimLevels = $levels[$r['dimension_id']] ?? [];
            $top = ($r['rank'] ?? null) === 1;
        @endphp
        <div style="border:1px solid color-mix(in oklab, var(--gray-500) 22%, transparent);border-radius:12px;padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="font-weight:600">{{ $r['name'] }}</div>
                <div style="display:flex;gap:8px;align-items:center">
                    <span style="font-size:13px;opacity:.7">{{ $r['score'] }} điểm @if ($max !== $min) (khoảng {{ $min }} → {{ $max }}) @endif</span>
                    <span style="padding:2px 10px;border-radius:999px;font-size:13px;font-weight:600;{{ $top ? 'background:color-mix(in oklab, var(--success-500) 16%, transparent);color:var(--success-700)' : 'background:color-mix(in oklab, var(--primary-500) 12%, transparent);color:var(--primary-700)' }}">
                        {{ $r['level'] ?? 'Chưa có mức' }}
                    </span>
                </div>
            </div>

            @if ($max !== $min)
                <div style="position:relative;height:8px;border-radius:999px;margin:16px 0 {{ $dimLevels ? '22px' : '6px' }};background:color-mix(in oklab, var(--gray-500) 18%, transparent)">
                    <div style="position:absolute;left:0;top:0;bottom:0;width:{{ $pct($r['score']) }}%;border-radius:999px;background:var(--primary-500)"></div>
                    @foreach ($dimLevels as $lv)
                        @if ($lv['min'] > $min && $lv['min'] <= $max)
                            <div title="{{ $lv['name'] }} từ {{ $lv['min'] }}" style="position:absolute;top:-4px;bottom:-4px;width:2px;left:{{ $pct($lv['min']) }}%;background:var(--gray-600)"></div>
                        @endif
                        @if ($lv['min'] <= $max)
                            <div style="position:absolute;top:14px;left:{{ $pct(max($lv['min'], $min)) }}%;font-size:11px;white-space:nowrap;opacity:.65;transform:translateX({{ $lv['min'] > $min ? '-50%' : '0' }})">{{ $lv['name'] }} ≥ {{ $lv['min'] }}</div>
                        @endif
                    @endforeach
                </div>
            @endif

            @if (filled($r['level_description'] ?? null))
                <div style="font-size:14px;opacity:.8;margin-top:6px">{{ $r['level_description'] }}</div>
            @endif
        </div>
    @empty
        <div style="opacity:.7">Bài kiểm tra chưa có phân loại nào.</div>
    @endforelse

    @if ($courses)
        <div style="margin-top:4px">
            <div style="font-weight:600;margin-bottom:6px">Khoá học được gợi ý</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                @foreach ($courses as $title)
                    <span style="padding:4px 10px;border-radius:8px;font-size:13px;background:color-mix(in oklab, var(--gray-500) 12%, transparent)">{{ $title }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($answers))
        <div style="margin-top:8px">
            <div style="font-weight:600;margin-bottom:6px">Câu trả lời</div>
            <ol style="display:flex;flex-direction:column;gap:10px;padding-left:20px;list-style:decimal">
                @foreach ($answers as $a)
                    <li>
                        <div style="font-size:14px">{{ $a['prompt'] }}</div>
                        <div style="font-size:13px;opacity:.75;margin-top:2px">
                            @if (($a['type'] ?? '') === 'ordering')
                                {{ collect($a['labels'])->map(fn ($l, $i) => ($i + 1) . '. ' . $l)->join('  ›  ') }}
                            @else
                                {{ collect($a['labels'])->filter()->join(' · ') }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
