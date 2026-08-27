<x-mail::message>
<div style="text-align: center; margin-bottom: 16px;">
<img src="https://ipop360.com/img/ipop360-logo.png" alt="iPop360" width="60" style="max-width: 60px; height: auto;">
</div>

{{ $greeting }}

{{ $intro }}

<x-mail::panel>
@foreach ($bullets as $bullet)
- {{ $bullet }}
@endforeach
</x-mail::panel>

@if ($outro)
{{ $outro }}
@endif

Thanks,<br>
{{ $signOff }}<br>
{{ config('app.name') }}
</x-mail::message>
