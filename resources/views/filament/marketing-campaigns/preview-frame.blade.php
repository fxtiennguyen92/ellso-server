@php($rich = fn ($t) => \App\Support\CampaignPreview::rich($t))
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@500;600&family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap&subset=vietnamese" rel="stylesheet">
<style>
    /* Token theo ellso-design-system (chế độ sáng) */
    :root {
        --emerald-500: #10b981; --emerald-700: #047857; --mint-50: #ecfdf5; --spark-500: #f59e0b;
        --ink-900: #0f172a; --ink-600: #475569; --surface: #ffffff; --surface-alt: #f8fafc; --line: #e2e8f0;
        --display: Lexend, 'Be Vietnam Pro', system-ui, sans-serif;
        --sans: 'Be Vietnam Pro', system-ui, -apple-system, 'Segoe UI', sans-serif;
    }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 24px 16px 32px; background: #eef2f6; font-family: var(--sans); color: var(--ink-900);
           display: flex; flex-wrap: wrap; gap: 32px; justify-content: center; align-items: flex-start; }
    .col { display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .cap { font-size: 13px; line-height: 20px; color: var(--ink-600); font-weight: 600; }
    .phone { width: 360px; height: 740px; border-radius: 44px; background: var(--surface); overflow: hidden; position: relative;
             box-shadow: 0 0 0 10px #0f172a, 0 24px 48px rgba(15,23,42,.25); display: flex; flex-direction: column; }
    .status { height: 36px; flex: none; display: flex; align-items: center; justify-content: space-between; padding: 0 26px;
              font-size: 13px; font-weight: 600; }
    .scroll { flex: 1; overflow-y: auto; scrollbar-width: none; }
    .scroll::-webkit-scrollbar { display: none; }

    /* Home */
    .home-head { padding: 8px 16px 12px; display: flex; align-items: center; justify-content: space-between; }
    .hello { font-size: 13px; color: var(--ink-600); }
    .name { font-family: var(--display); font-weight: 600; font-size: 20px; line-height: 28px; }
    .avatar { width: 40px; height: 40px; border-radius: 9999px; background: var(--emerald-700); color: #fff; font-weight: 600;
              display: flex; align-items: center; justify-content: center; font-size: 14px; }
    .search { margin: 0 16px; height: 44px; border-radius: 14px; border: 1px solid var(--line); background: var(--surface-alt);
              display: flex; align-items: center; gap: 8px; padding: 0 14px; color: #94a3b8; font-size: 15px; }
    .card-wrap { padding: 16px 16px 0; }
    .card { position: relative; border-radius: 24px; overflow: hidden; aspect-ratio: 16 / 9; background: var(--mint-50);
            box-shadow: 0 6px 0 rgba(4,120,87,.18); outline: 2px solid var(--emerald-500); outline-offset: 3px; }
    .card img, .hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .noimg { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--emerald-700);
             font-size: 13px; text-align: center; padding: 12px; }
    .dots { display: flex; gap: 6px; justify-content: center; padding: 10px 0 4px; }
    .dots span { width: 6px; height: 6px; border-radius: 9999px; background: var(--line); }
    .dots span.on { width: 18px; background: var(--emerald-700); }
    .ghost { margin: 16px 16px 0; border-radius: 20px; background: var(--surface-alt); border: 1px solid var(--line); }
    .ghost.a { height: 120px; } .ghost.b { height: 160px; }
    .section-t { margin: 20px 16px 0; font-family: var(--display); font-weight: 600; font-size: 18px; }

    /* Chi tiết */
    .hero { position: relative; aspect-ratio: 16 / 9; background: var(--mint-50); flex: none; }
    .back { position: absolute; top: 12px; left: 12px; width: 36px; height: 36px; border-radius: 9999px; background: rgba(255,255,255,.92);
            display: flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(15,23,42,.2); }
    .body { padding: 20px 20px 24px; display: flex; flex-direction: column; gap: 16px; }
    .eyebrow { font-size: 12px; line-height: 16px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--emerald-700); }
    h1 { margin: 6px 0 0; font-family: var(--display); font-weight: 600; font-size: 24px; line-height: 32px; }
    .chip { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 10px; border-radius: 9999px;
            background: var(--mint-50); color: var(--emerald-700); font-size: 12px; font-weight: 600; }
    .summary { margin: 0; font-size: 16px; line-height: 26px; color: var(--ink-600); }
    .divider { height: 1px; background: var(--line); }
    .p { margin: 0; font-size: 15px; line-height: 24px; }
    .h { margin: 4px 0 0; font-family: var(--display); font-weight: 600; font-size: 18px; line-height: 26px; }
    .blk-t { margin: 0 0 10px; font-family: var(--display); font-weight: 600; font-size: 17px; line-height: 24px; }
    .steps, .bullets { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
    .steps li, .bullets li { display: flex; gap: 12px; font-size: 15px; line-height: 24px; }
    .num { flex: none; width: 26px; height: 26px; border-radius: 9999px; background: var(--emerald-700); color: #fff; font-size: 13px;
           font-weight: 600; display: flex; align-items: center; justify-content: center; margin-top: -1px; }
    .tick { flex: none; width: 22px; height: 22px; border-radius: 9999px; background: var(--mint-50); color: var(--emerald-700);
            display: flex; align-items: center; justify-content: center; margin-top: 1px; }
    figure { margin: 0; } figure img { width: 100%; border-radius: 16px; display: block; }
    figcaption { margin-top: 6px; font-size: 13px; line-height: 20px; color: var(--ink-600); }
    .promo { border: 1.5px dashed var(--emerald-500); background: var(--mint-50); border-radius: 18px; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
    .promo-l { font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--emerald-700); }
    .promo-row { display: flex; align-items: center; gap: 10px; }
    .code { flex: 1; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 22px; font-weight: 700; letter-spacing: 2px; }
    .copy { height: 36px; padding: 0 14px; border-radius: 9999px; background: var(--surface); border: 1px solid var(--emerald-500);
            color: var(--emerald-700); font-weight: 600; font-size: 13px; display: flex; align-items: center; }
    .promo-d { font-size: 13px; line-height: 20px; color: var(--ink-600); }
    .callout { border-radius: 16px; padding: 14px 16px; display: flex; gap: 10px; font-size: 14px; line-height: 22px; }
    .callout.info { background: var(--surface-alt); border: 1px solid var(--line); }
    .callout.warning { background: #fff7e6; border: 1px solid #f5d9a8; }
    .callout b.t { display: block; margin-bottom: 2px; }
    .cta-bar { flex: none; padding: 12px 16px 20px; border-top: 1px solid var(--line); background: var(--surface); }
    .cta { height: 48px; border-radius: 14px; background: var(--emerald-700); color: #fff; font-weight: 600; font-size: 16px;
           display: flex; align-items: center; justify-content: center; }
    .empty { font-size: 14px; color: var(--ink-600); background: var(--surface-alt); border: 1px dashed var(--line); border-radius: 14px; padding: 14px; text-align: center; }
</style>
</head>
<body>

<div class="col">
    <div class="phone" aria-label="Home">
        <div class="status"><span>9:41</span><span>●●● ▮</span></div>
        <div class="scroll">
            <div class="home-head">
                <div><div class="hello">Chào buổi tối,</div><div class="name">Nguyễn An</div></div>
                <div class="avatar">NA</div>
            </div>
            <div class="search">⌕ Tìm khoá học, tài liệu…</div>
            <div class="card-wrap">
                <div class="card">
                    @if ($banner)
                        <img src="{{ $banner }}" alt="{{ $title }}">
                    @else
                        <div class="noimg">Banner 1280 × 720 sẽ hiện ở đây</div>
                    @endif
                </div>
            </div>
            @if ($dots > 1)
                <div class="dots">@for ($i = 0; $i < $dots; $i++)<span class="{{ $i === 0 ? 'on' : '' }}"></span>@endfor</div>
            @endif
            <div class="section-t">Khoá học của bạn</div>
            <div class="ghost b"></div>
            <div class="ghost a"></div>
        </div>
    </div>
    <div class="cap">Trang chủ · thẻ banner (viền xanh chỉ để đánh dấu)</div>
</div>

<div class="col">
    <div class="phone" aria-label="Chi tiết chiến dịch">
        <div class="scroll">
            <div class="hero">
                @if ($banner)
                    <img src="{{ $banner }}" alt="{{ $title }}">
                @else
                    <div class="noimg">Banner</div>
                @endif
                <div class="back">‹</div>
            </div>
            <div class="body">
                <div>
                    <div class="eyebrow">Chiến dịch</div>
                    <h1>{{ $title }}</h1>
                </div>
                @if ($endsLabel)<div class="chip">◷ {{ $endsLabel }}</div>@endif
                @if ($summary !== '')<p class="summary">{{ $summary }}</p>@endif
                <div class="divider"></div>

                @forelse ($blocks as $b)
                    @switch($b['type'])
                        @case('paragraph')
                            <p class="p">{{ $rich($b['text']) }}</p>
                            @break
                        @case('heading')
                            <div class="h">{{ $b['text'] }}</div>
                            @break
                        @case('steps')
                            <div>
                                @if ($b['title'])<div class="blk-t">{{ $b['title'] }}</div>@endif
                                <ol class="steps">
                                    @foreach ($b['items'] as $i => $item)<li><span class="num">{{ $i + 1 }}</span><span>{{ $rich($item) }}</span></li>@endforeach
                                </ol>
                            </div>
                            @break
                        @case('bullets')
                            <div>
                                @if ($b['title'])<div class="blk-t">{{ $b['title'] }}</div>@endif
                                <ul class="bullets">
                                    @foreach ($b['items'] as $item)<li><span class="tick">✓</span><span>{{ $rich($item) }}</span></li>@endforeach
                                </ul>
                            </div>
                            @break
                        @case('image')
                            <figure>
                                <img src="{{ $b['url'] }}" alt="{{ $b['caption'] ?? '' }}">
                                @if ($b['caption'])<figcaption>{{ $b['caption'] }}</figcaption>@endif
                            </figure>
                            @break
                        @case('promo_code')
                            <div class="promo">
                                <div class="promo-l">Mã khuyến mãi</div>
                                <div class="promo-row"><span class="code">{{ $b['code'] }}</span><span class="copy">Sao chép</span></div>
                                <div class="promo-d">
                                    {{ $b['description'] ?: 'Giảm ' . $b['discount'] }}
                                    @if ($b['ends_at']) · HSD {{ \Illuminate\Support\Carbon::parse($b['ends_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') }}@endif
                                </div>
                                @if ($b['note'])<div class="promo-d">{{ $b['note'] }}</div>@endif
                            </div>
                            @break
                        @case('callout')
                            <div class="callout {{ $b['tone'] }}">
                                <span>{{ $b['tone'] === 'warning' ? '!' : 'i' }}</span>
                                <div>@if ($b['title'])<b class="t">{{ $b['title'] }}</b>@endif{{ $rich($b['text']) }}</div>
                            </div>
                            @break
                    @endswitch
                @empty
                    <div class="empty">Chưa có nội dung. Thêm khối ở mục "Nội dung chi tiết".</div>
                @endforelse
            </div>
        </div>
        @if ($cta)<div class="cta-bar"><div class="cta">{{ $cta }}</div></div>@endif
    </div>
    <div class="cap">Bấm vào banner · trang chi tiết chiến dịch</div>
</div>

</body>
</html>
