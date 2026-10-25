<?php

namespace App\Filament\Resources\PlacementTests\Pages\Concerns;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use App\Services\PlacementScoring;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * Nút "Làm thử": admin trả lời đề ngay trên CMS và xem kết quả chấm (không lưu bài làm).
 * Dùng để kiểm tra điểm của đáp án + ngưỡng mức độ đã hợp lý chưa.
 */
trait SimulatesPlacementTest
{
    protected function simulatedTest(): PlacementTest
    {
        $record = method_exists($this, 'getOwnerRecord') ? $this->getOwnerRecord() : $this->getRecord();

        /** @var PlacementTest $record */
        return $record->fresh()->loadStructure();
    }

    protected function simulateAction(): Action
    {
        return Action::make('simulate')
            ->label('Làm thử')
            ->icon(Heroicon::PlayCircle)
            ->color('gray')
            ->disabled(fn () => $this->simulatedTest()->questions->isEmpty())
            ->tooltip(fn () => $this->simulatedTest()->questions->isEmpty() ? 'Thêm câu hỏi trước' : null)
            ->modalHeading('Làm thử bài kiểm tra')
            ->modalDescription('Trả lời như học viên để xem hệ thống chấm ra mức nào. Bài làm thử không được lưu.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Chấm điểm')
            ->schema(fn () => $this->simulatorFields())
            ->action(function (array $data, Action $action) {
                $test = $this->simulatedTest();
                $answers = [];

                foreach ($test->questions as $question) {
                    $value = $data['q' . $question->id] ?? null;
                    $answers[$question->id] = match ($question->type) {
                        PlacementQuestionType::Ordering => array_map(fn ($item) => (int) $item['id'], array_values((array) $value)),
                        PlacementQuestionType::Single => filled($value) ? [(int) $value] : [],
                        PlacementQuestionType::Multiple => array_map('intval', (array) $value),
                    };
                }

                try {
                    $scoring = app(PlacementScoring::class);
                    $results = $scoring->results($test, $scoring->normalizeAnswers($test, $answers));
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                $this->replaceMountedAction('simulationResult', ['results' => $results]);
            });
    }

    protected function simulationResultAction(): Action
    {
        return Action::make('simulationResult')
            ->modalHeading('Kết quả làm thử')
            ->modalDescription('Thanh điểm hiển thị khoảng điểm có thể đạt và ngưỡng của từng mức.')
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->extraModalFooterActions([
                Action::make('again')
                    ->label('Làm lại')
                    ->icon(Heroicon::ArrowPath)
                    ->action(fn () => $this->replaceMountedAction('simulate')),
            ])
            ->modalContent(function (array $arguments) {
                $test = $this->simulatedTest();
                $results = $arguments['results'] ?? [];
                $levelIds = array_filter(array_column($results, 'level_id'));

                $courses = $test->dimensions
                    ->flatMap->levels
                    ->filter(fn ($level) => in_array($level->id, $levelIds, true))
                    ->flatMap->courses
                    ->where('status', CourseStatus::Published)
                    ->unique('id')
                    ->pluck('title');

                return view('filament.placement-tests.attempt', [
                    'results' => $results,
                    'answers' => [],
                    'levels' => $test->dimensions->mapWithKeys(fn ($d) => [
                        $d->id => $d->levels->sortBy('min_score')->map(fn ($l) => ['name' => $l->name, 'min' => $l->min_score])->values()->all(),
                    ])->all(),
                    'courses' => $courses->all(),
                ]);
            });
    }

    /** Trường trả lời cho từng câu, giống cách app hiển thị. */
    private function simulatorFields(): array
    {
        return $this->simulatedTest()->questions->values()->map(function (PlacementQuestion $q, int $i) {
            $label = 'Câu ' . ($i + 1) . '. ' . $q->prompt;
            $options = $q->options->pluck('label', 'id')->all();

            return match ($q->type) {
                PlacementQuestionType::Single => Radio::make('q' . $q->id)
                    ->label($label)
                    ->helperText($q->hint)
                    ->options($options)
                    ->required(),
                PlacementQuestionType::Multiple => (function () use ($q, $label, $options) {
                    [$min, $max] = $q->choiceBounds();

                    return CheckboxList::make('q' . $q->id)
                        ->label($label)
                        ->helperText($q->hint ?: ($min === $max ? "Chọn {$min} đáp án" : "Chọn từ {$min} đến {$max} đáp án"))
                        ->options($options)
                        ->required()
                        ->minItems($min)
                        ->maxItems($max);
                })(),
                PlacementQuestionType::Ordering => Repeater::make('q' . $q->id)
                    ->label($label)
                    ->helperText($q->hint ?: 'Kéo thả hoặc dùng mũi tên để xếp: trên cùng = hạng 1')
                    ->schema([Hidden::make('id')])
                    ->default(collect($options)->keys()->map(fn ($id) => ['id' => $id])->all())
                    ->itemLabel(fn (array $state) => $options[$state['id'] ?? 0] ?? '')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed(),
            };
        })->all();
    }
}
