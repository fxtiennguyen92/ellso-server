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
