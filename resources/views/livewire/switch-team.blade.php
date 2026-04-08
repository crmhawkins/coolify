<x-forms.select wire:model.live="selectedTeamId">
    <option value="default" disabled selected>Switch team</option>
    @foreach (auth()->user()->teams as $team)
        <option value="{{ $team->id }}">{{ $team->name }}</option>
    @endforeach
</x-forms.select>
{{-- resync-marker 2026-04-08 --}}
