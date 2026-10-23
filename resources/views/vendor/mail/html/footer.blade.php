<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{-- The slot is printed as it is: the stock footer ran it through Markdown, which would reinterpret an asterisk or underscore in a company name. The caller escapes every value. --}}
{!! $slot !!}
</td>
</tr>
</table>
</td>
</tr>
