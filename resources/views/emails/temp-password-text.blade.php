@php($steps = $t->stepList())
@if (filled($t->eyebrow)){!! mb_strtoupper($t->text($t->eyebrow, $v)) !!}
@endif
{!! $t->text($t->heading, $v) !!}

{!! $t->text($t->intro, $v) !!}

Mật khẩu tạm của bạn: {!! $password !!}
{!! $expiresText ?? '(Phân biệt chữ hoa và chữ thường)' !!}
@if (count($steps))

@if (filled($t->steps_title)){!! $t->text($t->steps_title, $v) !!}
@endif
@foreach ($steps as $i => $step)
{!! $i + 1 !!}. {!! $t->text($step, $v) !!}
@endforeach
@endif
@if (filled($t->after_text))

{!! $t->text($t->after_text, $v) !!}
@endif
@if (! empty($requestInfo))

Thông tin yêu cầu
- Thời điểm: {!! $requestInfo['time'] !!}
@if (! empty($requestInfo['device']))
- Thiết bị: {!! $requestInfo['device'] !!}
@endif
@endif
@if (filled($t->note))

{!! $t->text($t->note, $v) !!}
@endif
@if (filled($t->sign_off))

{!! $t->text($t->sign_off, $v) !!}
@endif

---
@if (filled($t->footer_reason)){!! $t->text($t->footer_reason, $v) !!}
@endif
@if ($supportEmail)Cần hỗ trợ? Trả lời email này hoặc viết cho {!! $supportEmail !!}.
@endif
@if ($companyName || $companyAddress){!! collect([$companyName, $companyAddress])->filter()->join(' · ') !!}
@endif
