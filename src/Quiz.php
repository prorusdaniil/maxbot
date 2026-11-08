<?php
declare(strict_types=1);

/** LLM вернула тест в неверном формате. */
class QuizGenerationError extends RuntimeException
{
}

/** Тест с вариантами ответа; индексы вариантов начинаются с 0. */
final class Quiz
{
    private const MIN_OPTIONS = 2;
    private const MAX_OPTIONS = 6;

    public const SYSTEM_PROMPT = 'Ты — учитель. По тексту параграфа составь один тестовый вопрос и 4 варианта ответа. '
        . 'Правильных вариантов может быть один или несколько (но не все). '
        . 'Используй только факты из текста. Ответь строго JSON без пояснений и markdown: '
        . '{"question": "...", "options": ["...", "...", "...", "..."], '
        . '"correct": [номера правильных вариантов, начиная с 0]}';

    /**
     * @param list<string> $options
     * @param list<int> $correct
     * @param list<int> $selected
     */
    public function __construct(
        public string $question,
        public array $options,
        public array $correct,
        public array $selected = [],
        public bool $finished = false
    ) {
        sort($this->correct);
        sort($this->selected);
    }

    public static function generate(LlmClient $llm, string $paragraphText): self
    {
        return self::parse($llm->complete(self::SYSTEM_PROMPT, $paragraphText));
    }

    /** Разбирает JSON-ответ модели: {"question", "options", "correct"} (индексы с нуля). */
    public static function parse(string $raw): self
    {
        $data = self::loadJsonObject($raw);

        $question = $data['question'] ?? null;
        $options = $data['options'] ?? null;
        $correct = $data['correct'] ?? null;
        if (!is_string($question) || trim($question) === '') {
            throw new QuizGenerationError('В тесте нет вопроса');
        }
        if (
            !is_array($options)
            || !array_is_list($options)
            || count($options) < self::MIN_OPTIONS
            || count($options) > self::MAX_OPTIONS
            || array_filter($options, fn($option) => !is_string($option) || trim($option) === '')
        ) {
            throw new QuizGenerationError('Неверный список вариантов ответа');
        }
        if (
            !is_array($correct)
            || !array_is_list($correct)
            || !$correct
            || array_filter($correct, fn($index) => !is_int($index) || $index < 0 || $index >= count($options))
            || count(array_unique($correct)) >= count($options)
        ) {
            throw new QuizGenerationError('Неверно указаны правильные ответы');
        }

        return new self(trim($question), array_map('trim', $options), array_values(array_unique($correct)));
    }

    // Модели часто оборачивают JSON в ```-блок или пояснения, поэтому берём срез по скобкам.
    private static function loadJsonObject(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new QuizGenerationError('В ответе модели нет JSON');
        }
        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new QuizGenerationError('Ответ модели не является корректным JSON');
        }
        if (!is_array($data)) {
            throw new QuizGenerationError('Ответ модели не является JSON-объектом');
        }
        return $data;
    }

    public function toggle(int $index): void
    {
        $key = array_search($index, $this->selected, true);
        if ($key === false) {
            $this->selected[] = $index;
        } else {
            unset($this->selected[$key]);
        }
        $this->selected = array_values($this->selected);
        sort($this->selected);
    }

    public function isCorrect(): bool
    {
        return $this->selected === $this->correct;
    }

    public function format(string $title, bool $showResult = false): string
    {
        $lines = ["Тест по теме «{$title}»", '', $this->question, ''];
        foreach ($this->options as $index => $option) {
            $mark = $showResult ? $this->resultMark($index) : '';
            $lines[] = $mark . ($index + 1) . ". $option";
        }
        $lines[] = '';
        $lines[] = $showResult ? $this->resultSummary() : $this->instruction();
        return implode("\n", $lines);
    }

    private function resultMark(int $index): string
    {
        if (in_array($index, $this->correct, true)) {
            return '✅ ';
        }
        return in_array($index, $this->selected, true) ? '❌ ' : '';
    }

    private function resultSummary(): string
    {
        if ($this->isCorrect()) {
            return 'Верно!';
        }
        $correct = implode(', ', array_map(fn($index) => $index + 1, $this->correct));
        return "Есть ошибки. Правильные ответы: $correct.";
    }

    private function instruction(): string
    {
        return 'Правильных вариантов: ' . count($this->correct) . '. Отметь их и нажми «Проверить».';
    }

    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'options' => $this->options,
            'correct' => $this->correct,
            'selected' => $this->selected,
            'finished' => $this->finished,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)$data['question'],
            array_map('strval', $data['options']),
            array_map('intval', $data['correct']),
            array_map('intval', $data['selected'] ?? []),
            (bool)($data['finished'] ?? false)
        );
    }
}
