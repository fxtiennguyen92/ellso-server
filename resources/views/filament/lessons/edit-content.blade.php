@php
    use Filament\Support\Facades\FilamentAsset;

    $lesson = $this->lesson();
    $status = $lesson->contentStatus();
    $courses = $lesson->usedInCourses();
@endphp

<x-filament-panels::page>
    <style>
        /* Thanh trạng thái + nút */
        .le-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .le-bar-left { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; font-size: .875rem; color: var(--gray-500); }
        .le-bar-right { display: flex; align-items: center; gap: .5rem; }
        .le-note { margin-top: .5rem; font-size: .8125rem; color: var(--gray-500); }
        .le-editor { min-height: 24rem; padding: .5rem 0 2rem; }

        /* Editor.js: dùng hết chiều rộng khung, chừa lề trái cho nút + và ⋮⋮ */
        .le-editor .ce-block__content,
        .le-editor .ce-toolbar__content { max-width: none; margin: 0 0 0 3.5rem; }
        .le-editor .codex-editor__redactor { padding-bottom: 6rem !important; }
        .le-editor .ce-header { font-weight: 600; }
        .le-editor h2.ce-header { font-size: 1.5rem; }
        .le-editor h3.ce-header { font-size: 1.25rem; }
        .le-editor h4.ce-header { font-size: 1.0625rem; }

        /* Chế độ tối */
        .dark .le-editor { color: var(--gray-100); }
        .dark .le-editor .ce-popover,
        .dark .le-editor .ce-inline-toolbar,
        .dark .le-editor .ce-conversion-toolbar {
            --color-background: var(--gray-800);
            --color-border: var(--gray-700);
            --color-text-primary: var(--gray-100);
            --color-text-secondary: var(--gray-400);
            --color-background-item-hover: var(--gray-700);
            --color-background-item-focus: var(--gray-700);
            --color-shadow: rgb(0 0 0 / .4);
        }
        .dark .le-editor .ce-toolbar__plus,
        .dark .le-editor .ce-toolbar__settings-btn { color: var(--gray-300); }
        .dark .le-editor .ce-toolbar__plus:hover,
        .dark .le-editor .ce-toolbar__settings-btn:hover { background: var(--gray-700); }
        .dark .le-editor .ce-block--selected .ce-block__content { background: var(--gray-800); }
        .dark .le-editor .cdx-input,
        .dark .le-editor .ce-code__textarea,
        .dark .le-editor .tc-table { background: transparent; color: inherit; border-color: var(--gray-700); }
    </style>

    {{-- Component Alpine "lessonEditor" nạp bất đồng bộ (FilamentAsset → resources/js/dist/components/lesson-editor.js) --}}
    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('lesson-editor') }}"
        x-data="lessonEditor({
            initialData: @js($lesson->content_draft ?? ['blocks' => []]),
        })"
    >
        <x-filament::section>
            <div class="le-bar">
                <div class="le-bar-left">
                    {{-- Trạng thái trên server (cập nhật sau mỗi lần lưu / xuất bản) --}}
                    <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
                    {{-- Trạng thái lưu trên trình duyệt --}}
                    <span x-text="statusLabel()"></span>
                </div>

                <div class="le-bar-right">
                    <x-filament::button
                        color="gray"
                        icon="heroicon-m-arrow-down-tray"
                        x-on:click="save()"
                        x-bind:disabled="status === 'saving'"
                    >
                        Lưu nháp
                    </x-filament::button>

                    <x-filament::button
                        color="success"
                        icon="heroicon-m-rocket-launch"
                        x-on:click="saveAndPublish()"
                        x-bind:disabled="status === 'saving'"
                    >
                        Xuất bản
                    </x-filament::button>
                </div>
            </div>

            @if ($courses->isNotEmpty() || $lesson->published_at)
                <div class="le-note">
                    @if ($lesson->published_at)
                        Xuất bản lần cuối: {{ $lesson->published_at->format('d/m/Y H:i') }}.
                    @endif
                    @if ($courses->isNotEmpty())
                        Đang dùng ở: {{ $courses->pluck('title')->join(', ') }}.
                    @endif
                </div>
            @endif
        </x-filament::section>

        {{-- wire:ignore: Livewire không vẽ lại vùng soạn thảo sau mỗi lần lưu, để Editor.js giữ nguyên trạng thái --}}
        <x-filament::section style="margin-top: 1.5rem">
            <div wire:ignore class="le-editor" data-lesson-editor-holder></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>