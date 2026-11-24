<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

use MaxBeckers\YamlParser\Config\ParsingConfig;
use MaxBeckers\YamlParser\Metadata\MetadataProvider;
use MaxBeckers\YamlParser\YamlParser;

final class JsonDocumentReader
{
    public function read(string $path): mixed
    {
        return $this->readWithLocations($path)[0];
    }

    /**
     * @return array{mixed, array<string, array{line: int, column: int}>}
     */
    public function readWithLocations(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read document: ' . $path);
        }

        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
                $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

                return [$value, (new JsonSourceLocator($contents))->locations()];
            }
            $parser = new YamlParser(config: new ParsingConfig(preserveMetadata: true));
            $data = $parser->parse($contents);

            $locations = [];
            /** @var \SplObjectStorage<\ArrayObject, null> $active */
            $active = new \SplObjectStorage();
            $value = $this->convert($data, $parser->getMetadataProvider(), [], $path, $locations, $active);

            return [$value, $locations];
        } catch (\JsonException | \MaxBeckers\YamlParser\Exception\YamlParserException $e) {
            throw new \RuntimeException('Unable to parse document ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<int|string> $path
     * @param array<string, array{line: int, column: int}> $locations
     * @param \SplObjectStorage<\ArrayObject, null> $active
     */
    private function convert(mixed $value, MetadataProvider $metadata, array $path, string $document, array &$locations, \SplObjectStorage $active): mixed
    {
        $kind = $metadata->getResolvedNodeKind($path);
        if ($kind === null) {
            throw new \RuntimeException('YAML node kind is unavailable or ambiguous at ' . $document . '#' . $this->pointer($path) . '; some aliases and merged nodes are not supported.');
        }
        $position = $metadata->getKeyMetadata($path) ?? $metadata->getMetadata($path);
        if ($position !== null && $position->getLine() !== null && $position->getColumn() !== null) {
            $locations[$this->pointer($path)] = [
                'line' => $position->getLine(),
                'column' => $position->getColumn() + 1,
            ];
        }
        if ($kind === 'scalar') {
            if (is_object($value) || is_array($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
                throw new \RuntimeException('Non-JSON YAML value at ' . $document . '#' . $this->pointer($path));
            }

            return $value;
        }
        if (!$value instanceof \ArrayObject && !is_array($value)) {
            throw new \RuntimeException('Invalid YAML collection at ' . $document . '#' . $this->pointer($path));
        }
        if ($value instanceof \ArrayObject) {
            if ($active->contains($value)) {
                throw new \RuntimeException('Cyclic YAML alias at ' . $document . '#' . $this->pointer($path) . ' cannot be represented in JSON.');
            }
            $active->attach($value);
        }
        $result = $kind === 'mapping' ? new \stdClass() : [];
        try {
            foreach ($value as $key => $child) {
                $converted = $this->convert($child, $metadata, [...$path, $key], $document, $locations, $active);
                if ($result instanceof \stdClass) {
                    $result->{(string) $key} = $converted;
                } else {
                    $result[] = $converted;
                }
            }
        } finally {
            if ($value instanceof \ArrayObject) {
                $active->detach($value);
            }
        }

        return $result;
    }

    /** @param list<int|string> $path */
    private function pointer(array $path): string
    {
        return implode('', array_map(static fn (int|string $part): string => '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $part), $path));
    }
}
