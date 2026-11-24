<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use Flow\JSONPath\RFC9535\StringLiteral;

/**
 * Applies the supported subset of Overlay 1.0, 1.1 and 1.2.
 */
final class OverlayApplier
{
    public function apply(\stdClass $document, \stdClass $overlay): \stdClass
    {
        if (!isset($overlay->overlay) || !is_string($overlay->overlay) || preg_match('/^1\\.[012]\\.\\d+$/D', $overlay->overlay) !== 1) {
            throw new \InvalidArgumentException('Only Overlay 1.0.x, 1.1.x and 1.2.x are supported.');
        }
        $version12 = str_starts_with($overlay->overlay, '1.2.');
        $version11 = $version12 || str_starts_with($overlay->overlay, '1.1.');
        if (!isset($overlay->info) || !$overlay->info instanceof \stdClass
            || !isset($overlay->info->title, $overlay->info->version)
            || !is_string($overlay->info->title) || !is_string($overlay->info->version)
            || !isset($overlay->actions) || !is_array($overlay->actions) || $overlay->actions === []) {
            throw new \InvalidArgumentException('Overlay must contain info.title, info.version and a non-empty actions array.');
        }
        foreach (array_keys(get_object_vars($overlay)) as $field) {
            if (!in_array($field, $version12 ? ['overlay', '$self', 'info', 'extends', 'actions', 'components'] : ['overlay', 'info', 'extends', 'actions'], true) && !str_starts_with($field, 'x-')) {
                throw new \InvalidArgumentException('Unsupported overlay field: ' . $field);
            }
        }
        if ($version12 && property_exists($overlay, '$self')
            && (!is_string($overlay->{'$self'}) || !LocalOverlayReference::isSupportedSelf($overlay->{'$self'}))) {
            throw new \InvalidArgumentException('Overlay $self must be a supported local path or absolute HTTP(S) identity.');
        }
        if ($version12 && property_exists($overlay, 'components')) {
            if (!$overlay->components instanceof \stdClass) {
                throw new \InvalidArgumentException('Overlay components must be an object.');
            }
            foreach (get_object_vars($overlay->components) as $field => $value) {
                if ($field === 'actions' && !$value instanceof \stdClass) {
                    throw new \InvalidArgumentException('Overlay components.actions must be an object.');
                }
                if ($field !== 'actions' && !str_starts_with($field, 'x-')) {
                    throw new \InvalidArgumentException('Unsupported overlay components field: ' . $field);
                }
            }
        }
        foreach ($overlay->actions as $index => $action) {
            try {
                if ($version12 && $action instanceof \stdClass && property_exists($action, '$ref')) {
                    $action = $this->resolveReusableAction($overlay, $action);
                }
                $this->applyAction($document, $action, $index, $version11);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('Overlay action ' . $index . ': ' . $exception->getMessage(), 0, $exception);
            }
        }

        return $document;
    }

    private function resolveReusableAction(\stdClass $overlay, \stdClass $action): \stdClass
    {
        $reference = $action->{'$ref'};
        if (!is_string($reference) || !str_starts_with($reference, '#')
            || preg_match('/%(?![0-9a-f]{2})/i', $reference) === 1) {
            throw new \InvalidArgumentException('Reusable action $ref must be a same-document JSON Pointer.');
        }
        $fragment = rawurldecode(substr($reference, 1));
        $parts = explode('/', $fragment);
        if (count($parts) !== 4 || $parts[0] !== '' || $parts[1] !== 'components' || $parts[2] !== 'actions'
            || preg_match('/~(?![01])/', $parts[3]) === 1) {
            throw new \InvalidArgumentException('Reusable action $ref must point to #/components/actions/{name}.');
        }
        $name = str_replace(['~1', '~0'], ['/', '~'], $parts[3]);
        $components = $overlay->components ?? null;
        $actions = $components instanceof \stdClass ? ($components->actions ?? null) : null;
        if (!$actions instanceof \stdClass || !property_exists($actions, $name) || !$actions->$name instanceof \stdClass) {
            throw new \InvalidArgumentException('Reusable action not found or invalid: ' . $reference);
        }
        $component = $actions->$name;
        foreach (get_object_vars($component) as $field => $value) {
            if (($field === 'description' && !is_string($value))
                || ($field === 'fields' && !$value instanceof \stdClass)
                || (!in_array($field, ['description', 'fields'], true) && !str_starts_with($field, 'x-'))) {
                throw new \InvalidArgumentException('Invalid reusable action field: ' . $field);
            }
        }
        $fields = $component->fields ?? new \stdClass();
        if (property_exists($fields, 'target') || property_exists($fields, '$ref')) {
            throw new \InvalidArgumentException('Reusable action fields cannot contain target or $ref.');
        }
        $resolved = clone $fields;
        foreach (get_object_vars($action) as $field => $value) {
            if ($field !== '$ref') {
                $resolved->$field = $value;
            }
        }

        return $resolved;
    }

    private function applyAction(\stdClass $document, mixed $action, int $index, bool $version11): void
    {
        if (!$action instanceof \stdClass || !isset($action->target) || !is_string($action->target)
            || (property_exists($action, 'remove') && !is_bool($action->remove))
            || (property_exists($action, 'description') && !is_string($action->description))) {
            throw new \InvalidArgumentException('Invalid overlay action at index ' . $index);
        }
        foreach (get_object_vars($action) as $key => $_value) {
            if (!in_array($key, $version11 ? ['target', 'description', 'update', 'remove', 'copy'] : ['target', 'description', 'update', 'remove'], true) && !str_starts_with($key, 'x-')) {
                throw new \InvalidArgumentException('Unsupported overlay action field "' . $key . '" at index ' . $index);
            }
        }
        $hasUpdate = property_exists($action, 'update');
        $hasCopy = property_exists($action, 'copy');
        if ($hasCopy && !is_string($action->copy)) {
            throw new \InvalidArgumentException('Copy must be a JSONPath string.');
        }
        if (empty($action->remove) && $hasUpdate && $hasCopy) {
            throw new \InvalidArgumentException('Update and copy cannot be combined.');
        }
        $paths = $this->select($document, $action->target);
        if ($hasCopy) {
            $sources = $this->select($document, $action->copy);
        }
        if ($paths === []) {
            return;
        }
        if (empty($action->remove) && !$hasUpdate && !$hasCopy) {
            if (!$version11) {
                foreach ($paths as $path) {
                    $value = $this->valueAt($document, $path);
                    if (!$value instanceof \stdClass && !is_array($value)) {
                        throw new \InvalidArgumentException('Overlay 1.0 targets must select objects or arrays at action ' . $index);
                    }
                }
            }

            return;
        }
        $update = $action->update ?? null;
        if (empty($action->remove) && $hasCopy) {
            if (count($sources) !== 1) {
                throw new \InvalidArgumentException('Copy expression must select exactly one node.');
            }
            $update = $this->copy($this->valueAt($document, $sources[0]));
        }
        if ($version11 && empty($action->remove)) {
            $kind = null;
            foreach ($paths as $path) {
                $value = $this->valueAt($document, $path);
                $current = $value instanceof \stdClass ? 'object' : (is_array($value) ? 'array' : 'primitive');
                if ($kind !== null && $kind !== $current) {
                    throw new \InvalidArgumentException('Update targets must all have the same kind.');
                }
                $kind = $current;
            }
        }
        if (!empty($action->remove)) {
            $paths = $this->removalOrder($paths);
        } else {
            $paths = array_reverse($paths);
        }
        foreach ($paths as $path) {
            $this->applyAt($document, $path, $update, !empty($action->remove), $index, $version11);
        }
    }

    /** @return list<list<string|int>> */
    private function select(\stdClass $document, string $query): array
    {
        try {
            $paths = (new JSONPath($document, JSONPath::RFC9535))->findPaths($query);
        } catch (JSONPathException $exception) {
            throw new \InvalidArgumentException('Invalid JSONPath ' . $query . ': ' . $exception->getMessage(), 0, $exception);
        }
        $result = [];
        foreach ($paths as $path) {
            $parts = [];
            $position = 1;
            while ($position < strlen($path)) {
                ++$position;
                if ($path[$position] === "'") {
                    $parts[] = StringLiteral::read($path, $position);
                } else {
                    $end = strpos($path, ']', $position);
                    if ($end === false) {
                        throw new \LogicException('JSONPath returned an invalid normalized path: ' . $path);
                    }
                    $parts[] = (int) substr($path, $position, $end - $position);
                    $position = $end;
                }
                ++$position;
            }
            $result[] = $parts;
        }

        return $result;
    }

    /**
     * @param list<list<string|int>> $paths
     *
     * @return list<list<string|int>>
     */
    private function removalOrder(array $paths): array
    {
        $unique = [];
        foreach ($paths as $path) {
            $unique[json_encode($path, JSON_THROW_ON_ERROR)] = $path;
        }
        $paths = array_values($unique);
        usort($paths, static function (array $a, array $b): int {
            $length = min(count($a), count($b));
            for ($i = 0; $i < $length; ++$i) {
                if ($a[$i] !== $b[$i]) {
                    if (is_int($a[$i]) && is_int($b[$i])) {
                        return $b[$i] <=> $a[$i];
                    }

                    return strcmp((string) $b[$i], (string) $a[$i]);
                }
            }

            return count($b) <=> count($a);
        });

        return $paths;
    }

    /** @param list<string|int> $path */
    private function applyAt(\stdClass $document, array $path, mixed $update, bool $remove, int $index, bool $version11): void
    {
        if ($path === []) {
            if ($remove) {
                throw new \InvalidArgumentException('Cannot remove the document root.');
            }
            if (!$update instanceof \stdClass) {
                throw new \InvalidArgumentException('Overlay root update must be an object at action ' . $index);
            }
            $this->merge($document, $update);

            return;
        }
        $parent = $document;
        foreach (array_slice($path, 0, -1) as $part) {
            $parent = is_array($parent) ? $parent[$part] : $parent->$part;
        }
        $key = $path[count($path) - 1];
        $value = is_array($parent) ? $parent[$key] : $parent->$key;
        if (!$version11 && !$value instanceof \stdClass && !is_array($value)) {
            throw new \InvalidArgumentException('Overlay 1.0 targets must select objects or arrays at action ' . $index);
        }
        if ($remove) {
            if (is_array($parent)) {
                array_splice($parent, (int) $key, 1);
                $this->replaceArray($document, array_slice($path, 0, -1), $parent);
            } else {
                unset($parent->$key);
            }

            return;
        }
        if ($value instanceof \stdClass) {
            if (!$update instanceof \stdClass) {
                throw new \InvalidArgumentException('Overlay object update must be an object at action ' . $index);
            }
            $this->merge($value, $update);
        } elseif (is_array($value)) {
            if ($version11 && is_array($update)) {
                $value = [...$value, ...$this->copy($update)];
            } else {
                $value[] = $this->copy($update);
            }
            $this->replaceArray($document, $path, $value);
        } else {
            if ($update instanceof \stdClass || is_array($update)) {
                throw new \InvalidArgumentException('Overlay primitive update must be a primitive at action ' . $index);
            }
            if (is_array($parent)) {
                $parent[$key] = $update;
                $this->replaceArray($document, array_slice($path, 0, -1), $parent);
            } else {
                $parent->$key = $update;
            }
        }
    }

    /** @param list<string|int> $path */
    private function valueAt(\stdClass $document, array $path): mixed
    {
        $value = $document;
        foreach ($path as $part) {
            $value = is_array($value) ? $value[$part] : $value->$part;
        }

        return $value;
    }

    /**
     * @param list<string|int> $path
     * @param list<mixed> $value
     */
    private function replaceArray(\stdClass $document, array $path, array $value): void
    {
        $node = $document;
        foreach (array_slice($path, 0, -1) as $part) {
            if (is_array($node)) {
                $node = &$node[$part];
            } else {
                $node = &$node->$part;
            }
        }
        $key = $path[count($path) - 1];
        if (is_array($node)) {
            $node[$key] = $value;
        } else {
            $node->$key = $value;
        }
    }

    private function merge(\stdClass $target, \stdClass $update): void
    {
        foreach (get_object_vars($update) as $key => $value) {
            if (!property_exists($target, $key)) {
                $target->$key = $this->copy($value);
            } elseif ($value instanceof \stdClass && $target->$key instanceof \stdClass) {
                $this->merge($target->$key, $value);
            } elseif (is_array($value) && is_array($target->$key)) {
                $target->$key = [...$target->$key, ...$this->copy($value)];
            } elseif (!$value instanceof \stdClass && !is_array($value) && !$target->$key instanceof \stdClass && !is_array($target->$key)) {
                $target->$key = $value;
            } else {
                throw new \InvalidArgumentException('Incompatible overlay update for property: ' . $key);
            }
        }
    }

    private function copy(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $copy->$key = $this->copy($child);
            }

            return $copy;
        }
        if (is_array($value)) {
            return array_map($this->copy(...), $value);
        }

        return $value;
    }
}
