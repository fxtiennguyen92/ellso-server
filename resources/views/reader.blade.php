<!doctype html>
{{-- Trình đọc sách cho app Ellso (WebView / iframe). PDF: cuộn dọc, vẽ trang khi gần tới. EPUB: lật trang (chạm 2 bên / vuốt).
     Gửi tin cho app: {type:'ready'|'progress'|'end'|'error'} qua window.ReactNativeWebView hoặc window.parent. --}}
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=4, viewport-fit=cover">
<title>Ellso Reader</title>
<style>
  :root { --bg: {{ $dark ? '#0b1220' : '#f6f8f7' }}; --ink: {{ $dark ? '#e8eef5' : '#0f172a' }}; --muted: {{ $dark ? '#94a3b8' : '#475569' }};
          --card: {{ $dark ? '#131c2e' : '#ffffff' }}; --line: {{ $dark ? '#1f2a3d' : '#e2e8f0' }}; --accent: {{ $dark ? '#34d399' : '#047857' }}; }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { margin: 0; height: 100%; background: var(--bg); color: var(--ink); font-family: -apple-system, system-ui, "Segoe UI", Roboto, sans-serif; }
  #status { position: fixed; inset: 0; display: flex; flex-direction: column; gap: 14px; align-items: center; justify-content: center; color: var(--muted); font-size: 14px; }
  .spin { width: 28px; height: 28px; border-radius: 50%; border: 3px solid var(--line); border-top-color: var(--accent); animation: s .8s linear infinite; }
  @keyframes s { to { transform: rotate(360deg); } }
  /* PDF */
  #pdf { padding: 16px 14px 120px; display: none; flex-direction: column; align-items: center; gap: 14px; }
  .page { width: 100%; max-width: 760px; background: #fff; border-radius: 6px; box-shadow: 0 1px 2px rgba(15,23,42,.08), 0 6px 18px rgba(15,23,42,.10); overflow: hidden; position: relative; }
  .page canvas { display: block; width: 100%; height: auto; }
  .page .no { position: absolute; right: 8px; bottom: 6px; font-size: 11px; color: #94a3b8; }
  /* EPUB */
  #epub { position: fixed; top: 0; left: 0; right: 0; bottom: 64px; display: none; }
  .tap { position: fixed; top: 0; bottom: 64px; width: 28%; z-index: 5; }
  .tap.l { left: 0; } .tap.r { right: 0; }
  /* Thanh trang / chương */
  #pill { position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%); padding: 6px 14px; border-radius: 999px; background: var(--card);
          border: 1px solid var(--line); color: var(--muted); font-size: 13px; font-weight: 600; display: none; z-index: 6; }
  #end { width: 100%; max-width: 760px; text-align: center; color: var(--muted); font-size: 14px; padding: 18px 8px; display: none; }
  #end b { display: block; color: var(--ink); font-size: 16px; margin-bottom: 4px; }
</style>
</head>
<body>
<div id="status"><div class="spin"></div><div>Đang mở sách…</div></div>
<div id="pdf"></div>
<div id="epub"></div>
<div id="pill"></div>

<script>
  var FILE = @json($file), FORMAT = @json($format), MODE = @json($mode), DARK = @json($dark);
  function post(m) {
    var s = JSON.stringify(m);
    if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(s);
    else if (window.parent && window.parent !== window) window.parent.postMessage({ ellsoReader: m }, '*');
  }
  function fail(msg) {
    document.getElementById('status').innerHTML = '<div>' + msg + '</div>';
    post({ type: 'error', message: msg });
  }
  var pill = document.getElementById('pill');
  function showPill(text) { pill.textContent = text; pill.style.display = 'block'; }
</script>

@if ($format === 'pdf')
<script src="/vendor/reader/pdf-3.11.174.min.js"></script>
<script>
  pdfjsLib.GlobalWorkerOptions.workerSrc = '/vendor/reader/pdf-3.11.174.worker.min.js';
  (async function () {
    try {
      var pdf = await pdfjsLib.getDocument({ url: FILE }).promise;
      var box = document.getElementById('pdf');
      var total = pdf.numPages, shown = 0, endSent = false;
      var first = await pdf.getPage(1), v = first.getViewport({ scale: 1 });
      var ratio = v.height / v.width;
      var pages = [];
      for (var i = 1; i <= total; i++) {
        var el = document.createElement('div');
        el.className = 'page'; el.dataset.n = i;
        el.style.aspectRatio = (1 / ratio).toFixed(4);
        el.innerHTML = '<span class="no">' + i + '</span>';
        box.appendChild(el); pages.push(el);
      }
      var end = document.createElement('div'); end.id = 'end';
      end.innerHTML = MODE === 'preview' ? '<b>Hết phần đọc thử</b>Mở khoá sách để đọc tiếp ' : '<b>Hết sách</b>';
      box.appendChild(end); end.style.display = 'block';
      document.getElementById('status').style.display = 'none';
      box.style.display = 'flex';
      post({ type: 'ready', total: total, unit: 'page' });

      async function render(el) {
        if (el.dataset.done) return; el.dataset.done = '1';
        var page = await pdf.getPage(+el.dataset.n);
        var dpr = Math.min(window.devicePixelRatio || 1, 2.5);
        var scale = (el.clientWidth * dpr) / page.getViewport({ scale: 1 }).width;
        var vp = page.getViewport({ scale: scale });
        var c = document.createElement('canvas'); c.width = vp.width; c.height = vp.height;
        el.insertBefore(c, el.firstChild);
        await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
      }
      var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) render(e.target); });
      }, { rootMargin: '800px 0px' });
      pages.forEach(function (p) { io.observe(p); });

      function onScroll() {
        var mid = window.innerHeight / 2, cur = 1;
        for (var k = 0; k < pages.length; k++) { if (pages[k].getBoundingClientRect().top < mid) cur = k + 1; }
        if (cur !== shown) { shown = cur; showPill('Trang ' + cur + ' / ' + total); post({ type: 'progress', current: cur, total: total }); }
        if (!endSent && end.getBoundingClientRect().top < window.innerHeight) { endSent = true; post({ type: 'end' }); }
      }
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
    } catch (e) { fail('Không mở được sách. Thử lại sau.'); }
  })();
</script>
@else
<script src="/vendor/reader/jszip-3.10.1.min.js"></script>
<script src="/vendor/reader/epub-0.3.93.min.js"></script>
<div class="tap l" id="prev"></div><div class="tap r" id="next"></div>
<script>
  (async function () {
    try {
      var res = await fetch(FILE);
      if (!res.ok) throw new Error('http');
      var book = ePub(await res.arrayBuffer());
      var view = document.getElementById('epub');
      view.style.display = 'block';
      var rendition = book.renderTo('epub', { width: '100%', height: '100%', flow: 'paginated', spread: 'none' });
      rendition.themes.default({
        body: { color: getComputedStyle(document.documentElement).getPropertyValue('--ink') + ' !important', background: 'transparent !important',
                'font-family': '-apple-system, system-ui, "Segoe UI", Roboto, sans-serif !important', 'line-height': '1.7 !important', padding: '8px 18px !important' },
        'h1, h2, h3': { color: 'inherit !important', 'line-height': '1.3 !important' },
        a: { color: getComputedStyle(document.documentElement).getPropertyValue('--accent') + ' !important' },
      });
      rendition.themes.fontSize('112%');
      await book.ready;
      var spineTotal = book.spine.spineItems.filter(function (s) { return s.linear !== 'no'; }).length;
      await rendition.display();
      document.getElementById('status').style.display = 'none';
      post({ type: 'ready', total: spineTotal, unit: 'chapter' });
      var endSent = false;
      rendition.on('relocated', function (loc) {
        var idx = (loc.start && loc.start.index) || 0;
        showPill('Chương ' + (idx + 1) + ' / ' + spineTotal);
        post({ type: 'progress', current: idx + 1, total: spineTotal });
        if (!endSent && loc.atEnd) { endSent = true; post({ type: 'end' }); }
      });
      document.getElementById('prev').onclick = function () { rendition.prev(); };
      document.getElementById('next').onclick = function () { rendition.next(); };
      document.addEventListener('keyup', function (e) { if (e.key === 'ArrowLeft') rendition.prev(); if (e.key === 'ArrowRight') rendition.next(); });
      var x0 = null;
      rendition.on('touchstart', function (e) { x0 = e.changedTouches[0].screenX; });
      rendition.on('touchend', function (e) { if (x0 === null) return; var dx = e.changedTouches[0].screenX - x0; if (Math.abs(dx) > 40) (dx < 0 ? rendition.next() : rendition.prev()); x0 = null; });
    } catch (e) { fail('Không mở được sách. Thử lại sau.'); }
  })();
</script>
@endif
</body>
</html>
