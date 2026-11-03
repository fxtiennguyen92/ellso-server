{{--
    Kết quả bài kiểm tra đầu vào (dùng cho "Làm thử" và "Xem bài làm").
    $results: [{dimension_id, name, score, min, max, percent, level, level_description, rank, level_count}]
    $levels (tuỳ chọn): [dimension_id => [{name, min (%)}] tăng dần] → vẽ ngưỡng % trên thanh điểm
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
            // Bài làm cũ chưa lưu % → tính lại từ điểm
            $percent = $r['percent'] ?? ($max > $min ? round(($r['score'] - $min) / ($max - $min) * 100, 1) : null);
            $dimLevels = $levels[$r['dimension_id']] ?? [];
            $top = ($r['rank'] ?? null) === 1;
            $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
        @endphp
        <div style="border:1px solid color-mix(in oklab, var(--gray-500) 22%, transparent);border-radius:12px;padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="font-weight:600">{{ $r['name'] }}</div>
                <div style="display:flex;gap:8px;align-items:center">
                    <span style="font-size:13px;opacity:.7">
                        @if ($percent !== null)
                            {{ $fmt($percent) }}% · {{ $r['score'] }} điểm (khoảng {{ $min }} → {{ $max }})
                        @else
                            Bộ câu không có câu nào tính điểm năng lực này
                        @endif
                    </span>
                    <span style="padding:2px 10px;border-radius:999px;font-size:13px;font-weight:600;{{ $top ? 'background:color-mix(in oklab, var(--success-500) 16%, transparent);color:var(--success-700)' : 'background:color-mix(in oklab, var(--primary-500) 12%, transparent);color:var(--primary-700)' }}">
                        {{ $r['level'] ?? 'Chưa có mức' }}
                    </span>
                </div>
            </div>

            @if ($percent !== null)
                @php
                    // Vùng của từng mức: [từ %, đến %), tăng dần; mức thấp nhất luôn bắt đầu từ 0%
                    $zones = [];
                    $sorted = collect($dimLevels)->map(fn ($lv) => ['name' => $lv['name'], 'from' => max(0, min(100, (int) $lv['min']))])->sortBy('from')->values()->all();
                    foreach ($sorted as $i => $lv) {
                        $from = $i === 0 ? 0 : $lv['from'];
                        $to = $sorted[$i + 1]['from'] ?? 100;
                        $zones[] = ['name' => $lv['name'], 'from' => $from, 'to' => max($from, $to), 'reached' => $lv['name'] === ($r['level'] ?? null)];
                    }
                @endphp
                <div style="position:relative;height:10px;border-radius:999px;margin:16px 0 8px;overflow:hidden;background:color-mix(in oklab, var(--gray-500) 14%, transparent)">
                    @foreach ($zones as $i => $z)
                        @if ($i % 2 === 1)
                            <div style="position:absolute;top:0;bottom:0;left:{{ $z['from'] }}%;width:{{ $z['to'] - $z['from'] }}%;background:color-mix(in oklab, var(--gray-500) 12%, transparent)"></div>
                        @endif
                    @endforeach
                    <div style="position:absolute;left:0;top:0;bottom:0;width:{{ $percent }}%;border-radius:999px;background:var(--primary-500)"></div>
                    @foreach ($zones as $i => $z)
                        @if ($i > 0 && $z['from'] > 0 && $z['from'] < 100)
                            <div title="{{ $z['name'] }} từ {{ $z['from'] }}%" style="position:absolute;top:0;bottom:0;width:2px;margin-left:-1px;left:{{ $z['from'] }}%;background:color-mix(in oklab, var(--gray-600) 70%, transparent)"></div>
                        @endif
                    @endforeach
                </div>
                @if ($zones)
                    <div style="display:flex;flex-wrap:wrap;gap:4px 14px;font-size:12px;margin-bottom:4px">
                        @foreach (array_reverse($zones) as $z)
                            <span style="{{ $z['reached'] ? 'font-weight:600;color:var(--primary-700)' : 'opacity:.65' }}">
                                {{ $z['name'] }}
                                <span style="font-variant-numeric:tabular-nums">{{ $z['to'] >= 100 ? '≥ ' . $z['from'] . '%' : $z['from'] . '–' . $z['to'] . '%' }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif

            @if (filled($r['level_description'] ?? null))
                <div style="font-size:14px;opacity:.8;margin-top:6px">{{ $r['level_description'] }}</div>
            @endif
        </div>
    @empty
        <div style="opacity:.7">Bài kiểm tra chưa có năng lực nào.</div>
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
