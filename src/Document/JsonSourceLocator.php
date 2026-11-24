<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

/**
 * Collects locations from JSON already validated with json_decode().
 *
 * @internal
 */
final class JsonSourceLocator
{
    private int $offset = 0;
    private int $line = 1;
    private int $column = 1;
    /** @var array<string, array{line: int, column: int}> */
    private array $locations = [];

    public function __construct(private readonly string $json)
    {
    }

    /** @return array<string, array{line: int, column: int}> */
    public function locations(): array
    {
        $this->offset = 0;
        $this->line = 1;
        $this->column = 1;
        $this->locations = [];
        $this->whitespace();
        $this->value('');

        return $this->locations;
    }

    /** @param array{line: int, column: int}|null $position */
    private function value(string $pointer, ?array $position = null): void
    {
        $this->whitespace();
        $this->locations[$pointer] = $position ?? $this->position();
        $char = $this->json[$this->offset];
        if ($char === '{') {
            $this->advance();
            $this->whitespace();
            $keys = [];
            while ($this->json[$this->offset] !== '}') {
                $position = $this->position();
                $key = $this->string();
                $child = $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], $key);
                if (isset($keys[$key])) {
                    foreach (array_keys($this->locations) as $existing) {
                        if ($existing === $child || str_starts_with($existing, $child . '/')) {
                            unset($this->locations[$existing]);
                        }
                    }
                }
                $keys[$key] = true;
                $this->whitespace();
                $this->advance();
                $this->value($child, $position);
                $this->whitespace();
                if ($this->json[$this->offset] !== ',') {
                    break;
                }
                $this->advance();
                $this->whitespace();
            }
            $this->advance();
        } elseif ($char === '[') {
            $this->advance();
            $this->whitespace();
            $index = 0;
            while ($this->json[$this->offset] !== ']') {
                $this->value($pointer . '/' . $index++);
                $this->whitespace();
                if ($this->json[$this->offset] !== ',') {
                    break;
                }
                $this->advance();
                $this->whitespace();
            }
            $this->advance();
        } elseif ($char === '"') {
            $this->string();
        } else {
            $length = strlen($this->json);
            while ($this->offset < $length && !str_contains(" \t\r\n,]}", $this->json[$this->offset])) {
                $this->advance();
            }
        }
    }

    private function string(): string
    {
        $start = $this->offset;
        $this->advance();
        while ($this->json[$this->offset] !== '"') {
            if ($this->json[$this->offset] === '\\') {
                $this->advance();
            }
            $this->advance();
        }
        $this->advance();

        return json_decode(substr($this->json, $start, $this->offset - $start), false, 512, JSON_THROW_ON_ERROR);
    }

    private function whitespace(): void
    {
        $length = strlen($this->json);
        while ($this->offset < $length && str_contains(" \t\r\n", $this->json[$this->offset])) {
            $this->advance();
        }
    }

    private function advance(): void
    {
        $char = $this->json[$this->offset++];
        if ($char === "\r") {
            ++$this->line;
            $this->column = 1;
        } elseif ($char === "\n") {
            if ($this->offset < 2 || $this->json[$this->offset - 2] !== "\r") {
                ++$this->line;
            }
            $this->column = 1;
        } else {
            ++$this->column;
        }
    }

    /** @return array{line: int, column: int} */
    private function position(): array
    {
        return ['line' => $this->line, 'column' => $this->column];
    }
}
