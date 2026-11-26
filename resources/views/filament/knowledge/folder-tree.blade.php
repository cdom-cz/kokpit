{{--
    The folder tree of the general knowledge list (D-01, D-02), one view for three parts chosen by `part`:
    - panel: the nav with the two fixed entries and the folders (below 1024px a disclosure that shows the selection),
    - node: one folder row with its subfolders (the view includes itself for the recursion),
    - chip: the removable "Složka: path (včetně podsložek)" chip above the table.
    Every name is echoed escaped. Sizes and spacing live in resources/css/kokpit-diff.css under .kokpit-tree.
--}}
@if ($part === 'chip')
    @if ($chipPath !== null)
        <div class="kokpit-tree-chip-row">
            <span class="kokpit-tree-chip">
                <span>{{ __('knowledge.folders.chip', ['path' => $chipPath]) }}</span>
                <button
                    type="button"
                    class="kokpit-tree-chip-remove"
                    wire:click="$set('folder', null)"
                    aria-label="{{ __('knowledge.folders.chip_remove') }}"
                    title="{{ __('knowledge.folders.chip_remove') }}"
                >
                    <x-filament::icon icon="heroicon-m-x-mark" />
                </button>
            </span>
        </div>
    @endif
@elseif ($part === 'node')
    @php
        $id = $node['id'];
        $name = $node['name'];
        $kids = $children[$id] ?? [];
        $isOpen = $node['depth'] === 1 || isset($ancestors[$id]);
        $isSelected = $selected === $id;
        $expandLabel = __('knowledge.folders.expand', ['name' => $name]);
        $collapseLabel = __('knowledge.folders.collapse', ['name' => $name]);
    @endphp
    <li
        wire:key="knowledge-folder-{{ $id }}"
        x-data="{ open: {{ $isOpen ? 'true' : 'false' }}, id: '{{ $id }}' }"
        x-on:kokpit-tree-reveal.window="if (($event.detail?.ids ?? []).includes(id)) { open = true }"
    >
        <div
            @class(['kokpit-tree-row', 'kokpit-tree-row--selected' => $isSelected])
            style="padding-inline-start: {{ min($node['depth'] - 1, 5) * 16 }}px"
        >
            @if ($kids !== [])
                <button
                    type="button"
                    class="kokpit-tree-toggle"
                    x-on:click="open = ! open"
                    aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                    aria-label="{{ $isOpen ? $collapseLabel : $expandLabel }}"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    x-bind:aria-label="open ? @js($collapseLabel) : @js($expandLabel)"
                >
                    <x-filament::icon icon="heroicon-m-chevron-right" />
                </button>
            @else
                <span class="kokpit-tree-spacer" aria-hidden="true"></span>
            @endif

            <button
                type="button"
                class="kokpit-tree-name"
                wire:click="$set('folder', '{{ $id }}')"
                title="{{ $name }}"
                @if ($isSelected) aria-current="true" @endif
            >
                <x-filament::icon icon="heroicon-o-folder" class="kokpit-tree-icon" />
                <span class="kokpit-tree-label">{{ $name }}</span>
            </button>

            <span class="kokpit-tree-count">{{ $node['count'] }}</span>
        </div>

        @if ($kids !== [])
            <ul class="kokpit-tree-list" x-show="open" @unless ($isOpen) style="display: none" @endunless>
                @foreach ($kids as $child)
                    @include('filament.knowledge.folder-tree', ['part' => 'node', 'node' => $child])
                @endforeach
            </ul>
        @endif
    </li>
@else
    <nav class="kokpit-tree" aria-label="{{ __('knowledge.folders.tree_label') }}">
        {{-- wire:ignore.self keeps the open state of the disclosure across re-renders. From 1024px the script opens it for good. --}}
        <details
            class="kokpit-tree-disclosure"
            wire:ignore.self
            x-data="{ sync() { this.$el.open = window.matchMedia('(min-width: 1024px)').matches } }"
            x-init="sync(); window.matchMedia('(min-width: 1024px)').addEventListener('change', () => sync())"
        >
            <summary class="kokpit-tree-summary">{{ __('knowledge.folders.current_selection', ['name' => $selectedName]) }}</summary>

            <div class="kokpit-tree-body">
                <div class="kokpit-tree-heading">
                    <h2 class="kokpit-tree-title">{{ __('knowledge.folders.heading') }}</h2>
                </div>

                <ul class="kokpit-tree-list">
                    <li>
                        <div @class(['kokpit-tree-row', 'kokpit-tree-row--selected' => $selected === null])>
                            <span class="kokpit-tree-spacer" aria-hidden="true"></span>
                            <button
                                type="button"
                                class="kokpit-tree-name"
                                wire:click="$set('folder', null)"
                                @if ($selected === null) aria-current="true" @endif
                            >
                                <x-filament::icon icon="heroicon-o-rectangle-stack" class="kokpit-tree-icon" />
                                <span class="kokpit-tree-label">{{ __('knowledge.folders.all') }}</span>
                            </button>
                        </div>
                    </li>
                    <li>
                        <div @class(['kokpit-tree-row', 'kokpit-tree-row--selected' => $selected === 'none'])>
                            <span class="kokpit-tree-spacer" aria-hidden="true"></span>
                            <button
                                type="button"
                                class="kokpit-tree-name"
                                wire:click="$set('folder', 'none')"
                                @if ($selected === 'none') aria-current="true" @endif
                            >
                                <x-filament::icon icon="heroicon-o-inbox" class="kokpit-tree-icon" />
                                <span class="kokpit-tree-label">{{ __('knowledge.folders.none') }}</span>
                            </button>
                        </div>
                    </li>
                </ul>

                <hr class="kokpit-tree-divider">

                @if (($children[''] ?? []) !== [])
                    <ul class="kokpit-tree-list">
                        @foreach ($children[''] as $root)
                            @include('filament.knowledge.folder-tree', ['part' => 'node', 'node' => $root])
                        @endforeach
                    </ul>
                @else
                    <div class="kokpit-tree-empty">
                        <p class="kokpit-tree-empty-heading">{{ __('knowledge.folders.empty_heading') }}</p>
                        <p class="kokpit-tree-empty">{{ __('knowledge.folders.empty_body') }}</p>
                    </div>
                @endif
            </div>
        </details>
    </nav>
@endif
