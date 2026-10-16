{{-- The Admin-only checklist of the task page. Items are only ticked here; they are added and edited on the edit page. --}}
{{-- Each click sends the target state of the item, never a flip, so a double click or a stale page cannot undo a tick. --}}
<ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:0.5rem;">
    @foreach ($items as $item)
        <li wire:key="task-checklist-item-{{ $item->id }}-{{ $item->is_done ? 'done' : 'open' }}">
            <label style="display:flex;align-items:center;gap:0.75rem;">
                <x-filament::input.checkbox
                    :checked="$item->is_done"
                    :disabled="$readOnly"
                    wire:loading.attr="disabled"
                    wire:click="toggleChecklistItem('{{ $item->id }}', {{ $item->is_done ? 'false' : 'true' }})"
                />
                <span @if ($item->is_done) style="text-decoration:line-through;color:var(--gray-500);" @endif>{{ $item->text }}</span>
            </label>
        </li>
    @endforeach
</ul>
