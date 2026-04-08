@extends('layouts.base')
@section('body')
    <main class="min-h-screen flex items-center justify-center p-4">
        {{ $slot }}
    </main>
    @parent
@endsection
{{-- resync-marker 2026-04-08 --}}
