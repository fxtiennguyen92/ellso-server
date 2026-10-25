<div style="border: 1px solid rgba(148, 163, 184, 0.4); border-radius: 12px; overflow: hidden;">
    {{-- Iframe tách CSS của bản xem trước khỏi CMS; nội dung đã escape trong srcdoc --}}
    <iframe
        srcdoc="{{ $html }}"
        title="Xem trước chiến dịch trên app"
        sandbox=""
        style="display: block; width: 100%; height: 820px; border: 0; background: #eef2f6;"
    ></iframe>
</div>
