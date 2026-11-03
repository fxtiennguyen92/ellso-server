{{-- Workshop: tên, thời gian, nút + link họp (cố định). $meeting = ['workshop', 'when', 'url'] --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
<tr>
<td align="center" style="background-color:#ecfdf5; border-radius:14px; padding:24px 16px;">
<p style="margin:0 0 4px; font-size:13px; line-height:20px; color:#475569;">{{ $meeting['workshop'] }}</p>
<p style="margin:0 0 16px; font-size:18px; line-height:26px; font-weight:700; color:#0f172a;">{{ $meeting['when'] }}</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 12px;">
<tr>
<td align="center" style="border-radius:10px; background-color:#047857;">
<a href="{{ $meeting['url'] }}" target="_blank" style="display:inline-block; padding:12px 28px; font-family:{{ $font }}; font-size:16px; line-height:22px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:10px;">Vào phòng workshop</a>
</td>
</tr>
</table>
<p style="margin:0; font-size:13px; line-height:20px; color:#475569; word-break:break-all;"><a href="{{ $meeting['url'] }}" target="_blank" style="color:#047857; text-decoration:underline;">{{ $meeting['url'] }}</a></p>
</td>
</tr>
</table>
