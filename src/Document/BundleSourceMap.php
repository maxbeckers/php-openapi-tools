<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

/**
 * Maps pointers in a bundle to recorded source locations without accessing files.
 */
final class BundleSourceMap
{
    /**
     * @return array{source: string, pointer: string, line: ?int, column: ?int}|null
     */
    public function locate(\stdClass|array $bundle, string $pointer): ?array
    {
        if ($pointer !== '' && !str_starts_with($pointer, '/')) {
            throw new \InvalidArgumentException('Bundle location must be a JSON Pointer.');
        }
        if (preg_match('/~(?![01])/', $pointer) === 1) {
            throw new \InvalidArgumentException('Invalid JSON Pointer escape.');
        }
        $extension = $this->member($bundle, 'x-openapi-generator-bundle');
        if (!is_array($extension) && !$extension instanceof \stdClass) {
            return null;
        }
        $root = $this->member($extension, 'rootSource');
        if (!is_string($root) || $root === '') {
            return null;
        }
        $prefix = '/x-openapi-generator-bundle/documents/';
        if (str_starts_with($pointer, $prefix)) {
            $remainder = substr($pointer, strlen($prefix));
            $separator = strpos($remainder, '/');
            $encoded = $separator === false ? $remainder : substr($remainder, 0, $separator);
            $source = str_replace(['~1', '~0'], ['/', '~'], $encoded);
            $original = $separator === false ? '' : substr($remainder, $separator);
            $documents = $this->member($extension, 'documents');
            if ((!is_array($documents) && !$documents instanceof \stdClass) || !$this->hasMember($documents, $source)) {
                return null;
            }
        } elseif ($pointer === '/x-openapi-generator-bundle' || str_starts_with($pointer, '/x-openapi-generator-bundle/')) {
            return null;
        } else {
            $source = $root;
            $original = $pointer;
        }

        $position = null;
        $sourceLocations = $this->member($extension, 'sourceLocations');
        if (is_array($sourceLocations) || $sourceLocations instanceof \stdClass) {
            $locations = $this->member($sourceLocations, $source);
            if (is_array($locations) || $locations instanceof \stdClass) {
                $position = $this->member($locations, $original);
            }
        }
        $line = is_array($position) || $position instanceof \stdClass ? $this->member($position, 'line') : null;
        $column = is_array($position) || $position instanceof \stdClass ? $this->member($position, 'column') : null;
        if (!is_int($line) || $line < 1 || !is_int($column) || $column < 1) {
            $line = $column = null;
        }

        return [
            'source' => $source,
            'pointer' => $original,
            'line' => $line,
            'column' => $column,
        ];
    }

    private function member(\stdClass|array $container, string $key): mixed
    {
        return is_array($container) ? ($container[$key] ?? null) : ($container->$key ?? null);
    }

    private function hasMember(\stdClass|array $container, string $key): bool
    {
        return is_array($container) ? array_key_exists($key, $container) : property_exists($container, $key);
    }
}
