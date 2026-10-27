{{--
    Email mật khẩu tạm. Bố cục bảng + style inline để hiển thị đúng trên Gmail, Outlook, Apple Mail.
    $t = EmailTemplate (văn bản admin sửa trên CMS), $v = giá trị biến.
--}}
@php
    $font = "'Be Vietnam Pro', Arial, Helvetica, sans-serif";
    $display = "Lexend, 'Be Vietnam Pro', Arial, Helvetica, sans-serif";
    $mono = "'SFMono-Regular', Menlo, Consolas, 'Courier New', monospace";
    $steps = $t->stepList();
    $preheader = $t->text($t->preheader, $v);
@endphp
<!DOCTYPE html>
<html lang="vi" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $t->text($t->subject, $v) }}</title>
<style>
    @media only screen and (max-width: 620px) {
        .container { width: 100% !important; }
        .card-pad { padding: 28px 20px !important; }
        .outer-pad { padding: 24px 12px !important; }
        .password { font-size: 22px !important; letter-spacing: 2px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
@if ($preheader !== '')
{{-- Dòng xem trước trong hộp thư (ẩn trong nội dung) --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f8fafc; opacity:0;">{{ $preheader }}&#8202;&#847;&zwnj;&nbsp;&#8202;&#847;&zwnj;&nbsp;&#8202;&#847;&zwnj;&nbsp;&#8202;&#847;&zwnj;&nbsp;&#8202;&#847;&zwnj;&nbsp;&#8202;&#847;&zwnj;&nbsp;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8fafc;">
<tr>
<td align="center" class="outer-pad" style="padding:40px 16px;">

<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">

{{-- Logo --}}
<tr>
<td style="padding:0 8px 24px;">
<img src="{{ $logoUrl }}" width="104" height="40" alt="Ellso" style="display:block; width:104px; height:auto; border:0; outline:none; text-decoration:none; font-family:{{ $font }}; font-size:24px; font-weight:700; color:#10b981;">
</td>
</tr>

{{-- Thẻ nội dung --}}
<tr>
<td class="card-pad" style="background-color:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:40px; font-family:{{ $font }}; color:#0f172a;">

@if (filled($t->eyebrow))
<p style="margin:0 0 8px; font-size:12px; line-height:16px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#047857;">{{ $t->text($t->eyebrow, $v) }}</p>
@endif
<h1 style="margin:0 0 24px; font-family:{{ $display }}; font-size:26px; line-height:36px; font-weight:600; color:#0f172a;">{{ $t->text($t->heading, $v) }}</h1>

<p style="margin:0 0 24px; font-size:16px; line-height:26px; color:#0f172a;">{{ $t->html($t->intro, $v) }}</p>

{{-- Mật khẩu tạm (cố định) --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
<tr>
<td align="center" style="background-color:#ecfdf5; border-radius:14px; padding:24px 16px;">
<p style="margin:0 0 8px; font-size:13px; line-height:20px; color:#475569;">Mật khẩu tạm của bạn</p>
<p class="password" style="margin:0; font-family:{{ $mono }}; font-size:28px; line-height:40px; font-weight:700; letter-spacing:4px; color:#0f172a;">{{ $password }}</p>
<p style="margin:8px 0 0; font-size:13px; line-height:20px; {{ $expiresText ? 'font-weight:600; color:#0f172a;' : 'color:#475569;' }}">{{ $expiresText ?? 'Phân biệt chữ hoa và chữ thường' }}</p>
</td>
</tr>
</table>

@if (count($steps))
@if (filled($t->steps_title))
<p style="margin:0 0 16px; font-size:14px; line-height:20px; font-weight:600; color:#0f172a;">{{ $t->text($t->steps_title, $v) }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
@foreach ($steps as $i => $step)
<tr>
<td width="44" valign="top" style="padding:0 0 {{ $loop->last ? 0 : 12 }}px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td align="center" width="28" height="28" style="width:28px; height:28px; border-radius:14px; background-color:#047857; color:#ffffff; font-family:{{ $font }}; font-size:14px; line-height:28px; font-weight:600;">{{ $i + 1 }}</td>
</tr></table>
</td>
<td valign="top" style="padding:1px 0 {{ $loop->last ? 0 : 12 }}px; font-size:16px; line-height:26px; color:#0f172a;">{{ $t->html($step, $v) }}</td>
</tr>
@endforeach
</table>
@endif

@if (filled($t->after_text))
<p style="margin:0 0 24px; font-size:16px; line-height:26px; color:#0f172a;">{{ $t->html($t->after_text, $v) }}</p>
@endif

{{-- Thông tin yêu cầu (cố định, chỉ khi user tự yêu cầu) --}}
@if (! empty($requestInfo))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
<tr>
<td style="border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
<p style="margin:0 0 4px; font-size:14px; line-height:20px; font-weight:600; color:#0f172a;">Thông tin yêu cầu</p>
<p style="margin:0; font-size:13px; line-height:20px; color:#475569;">Thời điểm: {{ $requestInfo['time'] }}</p>
@if (! empty($requestInfo['device']))
<p style="margin:0; font-size:13px; line-height:20px; color:#475569;">Thiết bị: {{ $requestInfo['device'] }}</p>
@endif
</td>
</tr>
</table>
@endif

@if (filled($t->note) || filled($t->sign_off))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td style="border-top:1px solid #e2e8f0; padding-top:24px;">
@if (filled($t->note))
<p style="margin:0 0 16px; font-size:13px; line-height:20px; color:#475569;">{{ $t->html($t->note, $v) }}</p>
@endif
@if (filled($t->sign_off))
<p style="margin:0; font-size:16px; line-height:26px; color:#0f172a;">{{ $t->html($t->sign_off, $v) }}</p>
@endif
</td>
</tr>
</table>
@endif

</td>
</tr>

{{-- Chân trang --}}
<tr>
<td style="padding:24px 8px 0; font-family:{{ $font }}; font-size:13px; line-height:20px; color:#475569;">
@if (filled($t->footer_reason) || $supportEmail)
<p style="margin:0 0 8px;">
{{ $t->html($t->footer_reason, $v) }}
@if ($supportEmail)
Cần hỗ trợ? Trả lời email này hoặc viết cho <a href="mailto:{{ $supportEmail }}" style="color:#047857; text-decoration:underline;">{{ $supportEmail }}</a>.
@endif
</p>
@endif
@if ($companyName || $companyAddress)
<p style="margin:0 0 8px;">{{ collect([$companyName, $companyAddress])->filter()->join(' · ') }}</p>
@endif
<p style="margin:0; font-style:italic;">Learn to Lead, Innovate to Succeed</p>
</td>
</tr>

</table>

</td>
</tr>
</table>
</body>
</html>
