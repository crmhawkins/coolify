{{-- Shared metric bar for CPU / RAM / Disk on the Monitor page.
     Expected $label (string), $value (?float 0-100), $suffix
     (string, usually '%') and optional $extra (string, e.g.
     "2.1G / 8.0G"). Colours go green → amber → red depending
     on severity so the operator gets a one-glance sense of
     "is this hot?" without having to read the number. --}}
@php
    $pct = is_numeric($value) ? max(0, min(100, (float) $value)) : null;
    $barColor = match (true) {
        $pct === null => '#3f3f46',
        $pct >= 90 => '#ef4444',
        $pct >= 75 => '#f59e0b',
        $pct >= 50 => '#eab308',
        default => '#22c55e',
    };
    $animate = $pct !== null && $pct >= 90;
@endphp
<div style="margin-top:0.6rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.6875rem;margin-bottom:0.2rem;">
        <span style="color:#a1a1aa;font-weight:600;">{{ $label }}</span>
        <span style="color:#e4e4e7;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
            @if ($pct === null)
                <span style="color:#52525b;">sin datos</span>
            @else
                {{ $pct }}{{ $suffix ?? '' }}
                @if (! empty($extra))
                    <span style="color:#52525b;">· {{ $extra }}</span>
                @endif
            @endif
        </span>
    </div>
    <div style="width:100%;height:0.5rem;background-color:#27272a;border-radius:9999px;overflow:hidden;">
        @if ($pct !== null)
            <div style="height:100%;width:{{ $pct }}%;background-color:{{ $barColor }};border-radius:9999px;transition:width 0.4s ease-out;{{ $animate ? 'animation:mon-pulse 1.5s ease-in-out infinite;' : '' }}"></div>
        @endif
    </div>
</div>
