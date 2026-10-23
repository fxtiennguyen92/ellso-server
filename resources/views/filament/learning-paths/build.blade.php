@php
    use App\Enums\ChapterColor;
    use App\Enums\UnlockMode;

    $chapters = $this->getChapters();
    // Độ lệch ngang của nút bài trên bản đồ (hình con rắn)
    $snake = [0, 34, 52, 34, 0, -34, -52, -34];
    $mapIndex = 0;
    $lessonNumber = 0;
@endphp

<x-filament-panels::page>
    <style>
        .eb-layout { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
        @media (min-width: 1280px) { .eb-layout { grid-template-columns: minmax(0, 1fr) 300px; } }
        .eb-chapters { display: flex; flex-direction: column; gap: 1rem; }
        .eb-chapter-title { display: flex; align-items: center; gap: .5rem; }
        .eb-dot { width: .875rem; height: .875rem; border-radius: 9999px; flex: none; }
        .eb-header-actions { display: flex; align-items: center; gap: .25rem; }
        .eb-swatch { width: 1.5rem; height: 1.5rem; border-radius: 9999px; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--gray-300); cursor: pointer; }
        .eb-lessons { display: flex; flex-direction: column; min-height: 2.5rem; }
        .eb-lesson { display: grid; grid-template-columns: auto auto auto minmax(0, 1fr) auto; align-items: center; gap: .75rem; padding: .5rem .25rem; border-bottom: 1px solid var(--gray-100); background: inherit; }
        .dark .eb-lesson { border-bottom-color: rgb(255 255 255 / .06); }
        .eb-lesson:last-child { border-bottom: 0; }
        .eb-num { width: 1.25rem; text-align: right; font-size: .75rem; color: var(--gray-400); font-variant-numeric: tabular-nums; }
        .eb-icon { width: 2.5rem; height: 2.5rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; flex: none; }
        .eb-icon img, .eb-icon .fi-icon { width: 1.375rem; height: 1.375rem; color: #fff; }
        .eb-name { font-weight: 500; font-size: .875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .eb-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-top: .25rem; }
        .eb-meta button { cursor: pointer; }
        .eb-row-actions { display: flex; align-items: center; gap: .25rem; }
        .eb-chapter-handle, .eb-lesson-handle { cursor: grab; }
        .eb-empty { font-size: .875rem; color: var(--gray-400); padding: .75rem .25rem; }
        .eb-footer { margin-top: .5rem; }
        .eb-map { position: sticky; top: 5rem; }
        .eb-map-chapter { margin: .75rem .75rem .5rem; padding: .5rem .75rem; border-radius: .75rem; color: #fff; font-size: .8125rem; }
        .eb-map-node { display: flex; justify-content: center; margin: .625rem 0; }
        .eb-map-btn { width: 3.25rem; height: 3rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 0 rgb(0 0 0 / .2); }
        .eb-map-btn img, .eb-map-btn .fi-icon { width: 1.5rem; height: 1.5rem; color: #fff; }
        .eb-map-hidden { opacity: .35; outline: 2px dashed var(--gray-400); outline-offset: 3px; box-shadow: none; }
        .eb-ghost { opacity: .3; }
    </style>

    <div class="eb-layout">
        {{-- ── Danh sách chương ─────────────────────────────── --}}
        <div>
            @if ($chapters->isEmpty())
                <x-filament::section>
                    <div class="eb-empty">Lộ trình chưa có chương nào. Bấm <strong>Thêm chương</strong> để bắt đầu.</div>
                </x-filament::section>
            @endif

            {{--
                Kéo thả dùng SortableJS mà Filament đã nạp sẵn (window.Sortable).
                Chương và bài dùng tay nắm khác nhau (.eb-chapter-handle / .eb-lesson-handle)
                để kéo bài không kéo luôn cả chương.
            --}}
            <div
                class="eb-chapters"
                x-init="Sortable.create($el, {
                    handle: '.eb-chapter-handle',
                    draggable: '.eb-chapter',
                    animation: 150,
                    ghostClass: 'eb-ghost',
                    onEnd: (event) => {
                        if (event.oldIndex === event.newIndex) return;
                        $wire.reorderChapters(Array.from($el.querySelectorAll(':scope > .eb-chapter')).map((node) => node.dataset.id));
                    },
                })"
            >
                @foreach ($chapters as $chapter)
                    @php $color = $chapter->color ?? ChapterColor::Sage; @endphp

                    <div class="eb-chapter" data-id="{{ $chapter->id }}" wire:key="chapter-{{ $chapter->id }}">
                        <x-filament::section compact>
                            <x-slot name="heading">
                                <span class="eb-chapter-title">
                                    <x-filament::icon-button
                                        class="eb-chapter-handle"
                                        icon="heroicon-m-bars-2"
                                        color="gray"
                                        size="sm"
                                        label="Kéo để đổi chỗ chương"
                                        tooltip="Kéo để đổi chỗ chương"
                                    />
                                    <span class="eb-dot" style="background: {{ $color->hex() }}"></span>
                                    Chương {{ $loop->iteration }}: {{ $chapter->title }}
                                </span>
                            </x-slot>

                            <x-slot name="afterHeader">
                                <div class="eb-header-actions">
                                    {{-- Chọn màu chương --}}
                                    <x-filament::dropdown placement="bottom-end">
                                        <x-slot name="trigger">
                                            <button type="button" class="eb-swatch" style="background: {{ $color->hex() }}" title="Màu chương: {{ $color->getLabel() }}"></button>
                                        </x-slot>

                                        <x-filament::dropdown.list>
                                            @foreach (ChapterColor::cases() as $option)
                                                <x-filament::dropdown.list.item
                                                    :icon="$option === $color ? 'heroicon-m-check' : null"
                                                    wire:click="setChapterColor({{ $chapter->id }}, '{{ $option->value }}')"
                                                    x-on:click="close()"
                                                >
                                                    <span style="display:inline-flex;align-items:center;gap:.5rem">
                                                        <span class="eb-dot" style="background: {{ $option->hex() }}"></span>
                                                        {{ $option->getLabel() }}
                                                    </span>
                                                </x-filament::dropdown.list.item>
                                            @endforeach
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>

                                    <x-filament-actions::group
                                        :actions="[
                                            ($this->editChapterAction)(['chapter' => $chapter->id]),
                                            ($this->deleteChapterAction)(['chapter' => $chapter->id]),
                                        ]"
                                        icon="heroicon-m-ellipsis-vertical"
                                        color="gray"
                                        tooltip="Thao tác"
                                        dropdown-placement="bottom-end"
                                    />
                                </div>
                            </x-slot>

                            {{-- ── Bài trong chương (kéo thả được, kể cả sang chương khác) ── --}}
                            <div
                                class="eb-lessons"
                                data-chapter="{{ $chapter->id }}"
                                x-init="Sortable.create($el, {
                                    group: 'ellso-path-lessons',
                                    handle: '.eb-lesson-handle',
                                    draggable: '.eb-lesson',
                                    animation: 150,
                                    ghostClass: 'eb-ghost',
                                    onEnd: (event) => {
                                        if (event.from === event.to && event.oldIndex === event.newIndex) return;
                                        const ids = (list) => Array.from(list.querySelectorAll(':scope > .eb-lesson')).map((node) => node.dataset.item);
                                        $wire.moveLessons(event.to.dataset.chapter, ids(event.to), event.from.dataset.chapter, ids(event.from));
                                    },
                                })"
                            >
                                @foreach ($chapter->pathLessons as $item)
                                    @php
                                        $lesson = $item->lesson;
                                        $lessonNumber++;
                                        $status = $lesson?->contentStatus();
                                        $unlock = $item->unlock_mode ?? UnlockMode::AfterPrevious;
                                    @endphp

                                    <div class="eb-lesson" data-item="{{ $item->id }}" wire:key="item-{{ $item->id }}">
                                        {{-- Tay nắm kéo thả ở đầu dòng --}}
                                        <x-filament::icon-button
                                            class="eb-lesson-handle"
                                            icon="heroicon-m-bars-2"
                                            color="gray"
                                            size="sm"
                                            label="Kéo để đổi chỗ"
                                            tooltip="Kéo để đổi chỗ"
                                        />

                                        <span class="eb-num">{{ $lessonNumber }}</span>

                                        <span class="eb-icon" style="background: {{ $color->hex() }}">
                                            @if ($lesson?->iconUrl())
                                                {{-- Icon do giảng viên tải lên (nội dung bài học) --}}
                                                <img src="{{ $lesson->iconUrl() }}" alt="">
                                            @else
                                                <x-filament::icon icon="heroicon-o-book-open" />
                                            @endif
                                        </span>

                                        <div style="min-width:0">
                                            <div class="eb-name">
                                                {{ $lesson?->title ?? '(bài đã bị xóa)' }}
                                            </div>
                                            <div class="eb-meta">
                                                @if ($status)
                                                    <x-filament::badge size="sm" :color="$status->getColor()">
                                                        {{ $status->getLabel() }}
                                                    </x-filament::badge>
                                                @endif

                                                {{-- Bấm để đổi quy tắc mở khóa --}}
                                                <x-filament::badge
                                                    tag="button"
                                                    size="sm"
                                                    :color="$unlock === UnlockMode::Always ? 'info' : 'gray'"
                                                    :icon="$unlock === UnlockMode::Always ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed'"
                                                    tooltip="Bấm để đổi quy tắc mở khóa"
                                                    wire:click="toggleUnlock({{ $item->id }})"
                                                >
                                                    {{ $unlock->shortLabel() }}
                                                </x-filament::badge>

                                                {{-- Bấm để bật / tắt học thử --}}
                                                <x-filament::badge
                                                    tag="button"
                                                    size="sm"
                                                    :color="$item->is_preview ? 'success' : 'gray'"
                                                    :icon="$item->is_preview ? 'heroicon-m-eye' : 'heroicon-m-eye-slash'"
                                                    tooltip="Bấm để bật / tắt học thử"
                                                    wire:click="togglePreview({{ $item->id }})"
                                                >
                                                    {{ $item->is_preview ? 'Học thử' : 'Không học thử' }}
                                                </x-filament::badge>
                                            </div>
                                        </div>

                                        <div class="eb-row-actions">
                                            @if (($this->editContentAction)(['item' => $item->id])->isVisible())
                                                {{ ($this->editContentAction)(['item' => $item->id]) }}
                                            @endif

                                            <x-filament-actions::group
                                                :actions="[
                                                    ($this->moveLessonUpAction)(['item' => $item->id]),
                                                    ($this->moveLessonDownAction)(['item' => $item->id]),
                                                    ($this->editLessonInfoAction)(['item' => $item->id]),
                                                    ($this->removeLessonAction)(['item' => $item->id]),
                                                ]"
                                                icon="heroicon-m-ellipsis-vertical"
                                                color="gray"
                                                tooltip="Thao tác"
                                                dropdown-placement="bottom-end"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($chapter->pathLessons->isEmpty())
                                <div class="eb-empty">Chương chưa có bài. Kéo bài từ chương khác vào đây hoặc bấm Thêm bài.</div>
                            @endif

                            <div class="eb-footer">
                                {{ ($this->addLessonAction)(['chapter' => $chapter->id]) }}
                            </div>
                        </x-filament::section>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Xem trước bản đồ trên app ─────────────────────── --}}
        <div class="eb-map">
            <x-filament::section compact>
                <x-slot name="heading">Xem trước trên app</x-slot>
                <x-slot name="description">Bài mờ (viền đứt) chưa xuất bản → bị ẩn trên app.</x-slot>

                @forelse ($chapters as $chapter)
                    @php $color = $chapter->color ?? ChapterColor::Sage; @endphp

                    <div class="eb-map-chapter" style="background: {{ $color->hex() }}">
                        <strong>Chương {{ $loop->iteration }}</strong><br>{{ $chapter->title }}
                    </div>

                    @foreach ($chapter->pathLessons as $item)
                        @php
                            $lesson = $item->lesson;
                            $hidden = ! $lesson || $lesson->trashed() || ! $lesson->isPublished();
                            $offset = $snake[$mapIndex % count($snake)];
                            $mapIndex++;
                        @endphp

                        <div class="eb-map-node">
                            <div
                                class="eb-map-btn @if ($hidden) eb-map-hidden @endif"
                                style="background: {{ $color->hex() }}; transform: translateX({{ $offset }}px)"
                                title="{{ $lesson?->title }}"
                            >
                                @if ($lesson?->iconUrl())
                                    <img src="{{ $lesson->iconUrl() }}" alt="">
                                @else
                                    <x-filament::icon icon="heroicon-o-book-open" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                @empty
                    <div class="eb-empty">Chưa có gì để xem trước.</div>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>