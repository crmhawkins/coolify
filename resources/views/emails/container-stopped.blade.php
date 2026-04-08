<x-emails.layout>
A resource ({{ $containerName }}) has been stopped unexpectedly on {{ $serverName }}.

@if ($url)
Please check what is going on [here]({{ $url }}).
@endif
</x-emails.layout>
{{-- resync-marker 2026-04-08 --}}
