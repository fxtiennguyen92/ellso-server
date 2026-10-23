<x-mail::message>
# Xin chào {{ $name }},

@if ($isActivation)
Tài khoản Ellso của bạn đã được tạo với email **{{ $email }}**. Đây là mật khẩu tạm để đăng nhập lần đầu:
@else
Chúng tôi vừa nhận được yêu cầu cấp lại mật khẩu cho tài khoản **{{ $email }}**. Đây là mật khẩu tạm của bạn:
@endif

<x-mail::panel>
<div style="font-family: monospace; font-size: 22px; letter-spacing: 2px; text-align: center;">{{ $password }}</div>
</x-mail::panel>

Sau khi đăng nhập bằng mật khẩu này, ứng dụng sẽ yêu cầu bạn đặt mật khẩu mới.

@if ($ttlMinutes)
Mật khẩu tạm có hiệu lực trong **{{ $ttlMinutes }} phút**. Mật khẩu cũ của bạn vẫn dùng được bình thường — nếu bạn không yêu cầu cấp lại mật khẩu, hãy bỏ qua email này.
@endif

Trân trọng,<br>
Đội ngũ Ellso
</x-mail::message>
