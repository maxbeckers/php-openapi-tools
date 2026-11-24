<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

/**
 * Bundles local JSON Pointer references without dereferencing their targets.
 */
final class DocumentBundler
{
    private const EXTENSION = 'x-openapi-generator-bundle';

    /** @var array<string, mixed> */
    private array $documents = [];
    /** @var array<string, array<string, array{line: int, column: int}>> */
    private array $locations = [];
    /** @var array<string, true> */
    private array $visited = [];
    /** @var array<string, string> */
    private array $contexts = [];
    private string $root = '';
    private string $rootDirectory = '';
    private bool $openApi32 = false;

    public function __construct(private readonly JsonDocumentReader $reader)
    {
    }

    public function bundle(string $input): \stdClass
    {
        $root = realpath($input);
        if ($root === false || !is_file($root)) {
            throw new \InvalidArgumentException('Input document not found: ' . $input);
        }
        $this->root = $root;
        $this->rootDirectory = dirname($root);
        $this->documents = [];
        $this->locations = [];
        $this->visited = [];
        $this->contexts = [];
        [$this->documents[$root], $this->locations[$root]] = $this->reader->readWithLocations($root);
        $document = $this->documents[$root];
        if (!$document instanceof \stdClass || !isset($document->openapi) || !is_string($document->openapi)
            || preg_match('/^3\\.[012]\\.\\d+$/D', $document->openapi) !== 1) {
            throw new \InvalidArgumentException('Input must be an OpenAPI 3.0, 3.1 or 3.2 document object.');
        }
        if (property_exists($document, self::EXTENSION)) {
            throw new \InvalidArgumentException('Input already contains reserved extension ' . self::EXTENSION);
        }
        if (property_exists($document, '$self')) {
            throw new \InvalidArgumentException('Bundling OpenAPI $self is not supported.');
        }
        $this->openApi32 = str_starts_with($document->openapi, '3.2.');
        $this->walk($this->documents[$root], $root, [], 'document');

        $bundle = new \stdClass();
        $documents = [];
        foreach ($this->documents as $path => $content) {
            if ($path !== $root) {
                $documents[$this->relativePath($path)] = $content;
            }
        }
        ksort($documents, SORT_STRING);
        $bundle->documents = (object) $documents;
        $sources = [];
        foreach ($this->locations as $path => $locations) {
            if ($locations !== []) {
                ksort($locations, SORT_STRING);
                $sources[$this->relativePath($path)] = (object) $locations;
            }
        }
        if ($sources !== []) {
            ksort($sources, SORT_STRING);
            $bundle->sourceLocations = (object) $sources;
        }
        $bundle->rootSource = $this->relativePath($root);
        $document->{self::EXTENSION} = $bundle;

        return $document;
    }

    /** @param list<string> $location */
    private function walk(mixed &$node, string $document, array $location, string $context): void
    {
        if ($context === 'data') {
            return;
        }
        $locationId = $document . '#' . $this->pointer($location);
        if (isset($this->contexts[$locationId]) && $this->contexts[$locationId] !== $context) {
            throw new \InvalidArgumentException('Reference target used in incompatible contexts at ' . $locationId);
        }
        $this->contexts[$locationId] = $context;
        $id = $locationId . '|' . $context;
        if (isset($this->visited[$id])) {
            return;
        }
        $this->visited[$id] = true;
        if ($node instanceof \stdClass) {
            if ($context === 'schema') {
                foreach (['$id', '$schema', '$anchor', '$dynamicAnchor', '$dynamicRef', '$recursiveAnchor', '$recursiveRef'] as $keyword) {
                    if (property_exists($node, $keyword)) {
                        throw new \InvalidArgumentException('Unsupported schema identity keyword ' . $keyword . ' at ' . $document . '#' . $this->pointer($location));
                    }
                }
                if (isset($node->discriminator) && $node->discriminator instanceof \stdClass) {
                    if ($this->openApi32 && property_exists($node->discriminator, 'defaultMapping')) {
                        $node->discriminator->defaultMapping = $this->rewriteDiscriminatorMapping(
                            $node->discriminator->defaultMapping,
                            $document,
                            [...$location, 'discriminator'],
                            'defaultMapping',
                        );
                    }
                    if (property_exists($node->discriminator, 'mapping')) {
                        $mapping = $node->discriminator->mapping;
                        if (!$mapping instanceof \stdClass) {
                            throw new \InvalidArgumentException('Discriminator mapping must be an object at ' . $document . '#' . $this->pointer([...$location, 'discriminator', 'mapping']));
                        }
                        foreach (get_object_vars($mapping) as $label => $reference) {
                            $mapping->$label = $this->rewriteDiscriminatorMapping($reference, $document, [...$location, 'discriminator', 'mapping'], (string) $label);
                        }
                    }
                }
            }
            if (in_array($context, ['reference', 'example', 'link'], true)
                && (property_exists($node, 'externalValue')
                    || ($context !== 'link' && property_exists($node, 'operationRef')))) {
                throw new \InvalidArgumentException('Bundling operationRef or externalValue is not supported at ' . $document . '#' . $this->pointer($location));
            }
            $referenceObject = !ReferenceContext::isContainer($context);
            if ($referenceObject && property_exists($node, '$ref')) {
                $node->{'$ref'} = $this->rewriteReference($node->{'$ref'}, $document, $location, '$ref', $context);
            }
            if ($context === 'link' && property_exists($node, 'operationRef')) {
                $node->operationRef = $this->rewriteReference($node->operationRef, $document, $location, 'operationRef', 'operation');
            }
            foreach (get_object_vars($node) as $key => $_value) {
                if ($referenceObject && $key === '$ref') {
                    continue;
                }
                $childContext = ReferenceContext::child($context, (string) $key, $this->openApi32);
                $this->walk($node->$key, $document, [...$location, (string) $key], $childContext);
            }
        } elseif (is_array($node)) {
            foreach (array_keys($node) as $key) {
                $this->walk($node[$key], $document, [...$location, (string) $key], ReferenceContext::child($context, (string) $key, $this->openApi32));
            }
        }
    }

    /** @param list<string> $location */
    private function rewriteDiscriminatorMapping(mixed $value, string $document, array $location, string $field): string
    {
        $referenceLocation = $document . '#' . $this->pointer([...$location, $field]);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Invalid discriminator mapping at ' . $referenceLocation);
        }
        $entry = $this->documents[$this->root];
        $schemas = $entry instanceof \stdClass && isset($entry->components) && $entry->components instanceof \stdClass
            && isset($entry->components->schemas) && $entry->components->schemas instanceof \stdClass
            ? $entry->components->schemas : null;
        if ($schemas !== null && property_exists($schemas, $value)
            && preg_match('/^[A-Za-z0-9._-]+$/D', $value) === 1) {
            return $this->rewriteReference('#' . $this->uriPointer(['components', 'schemas', $value]), $document, $location, $field, 'schema', $this->root);
        }
        $fragmentPosition = strpos($value, '#');
        $file = $fragmentPosition === false ? $value : substr($value, 0, $fragmentPosition);
        $fragment = $fragmentPosition === false ? '' : rawurldecode(substr($value, $fragmentPosition + 1));
        $unambiguousFile = $file !== ''
            && ($fragmentPosition !== false || preg_match('/^[A-Za-z0-9._-]+$/D', $file) !== 1)
            && preg_match("~^(?:[A-Za-z0-9._\\x7e!$&'()*+,;=:@/-]|%[0-9A-Fa-f]{2})+$~D", $file) === 1;
        if (!str_starts_with($fragment, '/') && !($unambiguousFile && $fragment === '')) {
            throw new \InvalidArgumentException('Discriminator mapping must name an entry-document schema or use an unambiguous local schema reference at ' . $referenceLocation);
        }

        return $this->rewriteReference($value, $document, $location, $field, 'schema');
    }

    /** @param list<string> $location */
    private function rewriteReference(mixed $value, string $document, array $location, string $field, string $context, ?string $baseDocument = null): string
    {
        $referenceLocation = $document . '#' . $this->pointer([...$location, $field]);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Invalid ' . $field . ' at ' . $referenceLocation);
        }
        try {
            [$targetPath, $tokens] = $this->reference($value, $baseDocument ?? $document);
            $this->rejectScopedAncestors($targetPath, $tokens);
            $target = &$this->target($targetPath, $tokens);
            if (!$target instanceof \stdClass && !($context === 'schema' && is_bool($target))) {
                throw new \InvalidArgumentException('Reference target must be an object or boolean schema: ' . $value);
            }
            $this->walk($target, $targetPath, $tokens, $context);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            throw new \InvalidArgumentException('Reference at ' . $referenceLocation . ': ' . $exception->getMessage(), 0, $exception);
        }
        $prefix = $targetPath === $this->root
            ? []
            : [self::EXTENSION, 'documents', $this->relativePath($targetPath)];

        return '#' . $this->uriPointer([...$prefix, ...$tokens]);
    }

    /** @return array{string, list<string>} */
    private function reference(string $reference, string $document): array
    {
        [$file, $fragment] = array_pad(explode('#', $reference, 2), 2, '');
        if (str_contains($file, '?') || preg_match('/%(?![0-9a-f]{2})/i', $reference) === 1
            || preg_match('/^(?:[a-z][a-z0-9+.-]*:|[\\/\\\\\\\\])/i', $file) === 1) {
            throw new \InvalidArgumentException('Only relative local file references are supported: ' . $reference);
        }
        $file = rawurldecode($file);
        if (str_contains($file, "\0") || str_contains($file, '\\')
            || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\\/)/i', $file) === 1) {
            throw new \InvalidArgumentException('Invalid local reference path: ' . $reference);
        }
        $path = $file === '' ? $document : realpath(dirname($document) . DIRECTORY_SEPARATOR . $file);
        if ($path === false || !is_file($path) || !$this->insideRoot($path)) {
            throw new \InvalidArgumentException('Reference is missing or outside the input directory: ' . $reference);
        }
        if (!array_key_exists($path, $this->documents)) {
            [$this->documents[$path], $this->locations[$path]] = $this->reader->readWithLocations($path);
            if ($this->documents[$path] instanceof \stdClass && property_exists($this->documents[$path], '$self')) {
                throw new \InvalidArgumentException('Bundling OpenAPI $self is not supported in: ' . $path);
            }
        }
        $fragment = rawurldecode($fragment);
        if ($fragment !== '' && !str_starts_with($fragment, '/')) {
            throw new \InvalidArgumentException('Only JSON Pointer reference fragments are supported: ' . $reference);
        }
        $tokens = [];
        foreach ($fragment === '' ? [] : explode('/', substr($fragment, 1)) as $token) {
            if (preg_match('/~(?![01])/', $token) === 1) {
                throw new \InvalidArgumentException('Invalid JSON Pointer in reference: ' . $reference);
            }
            $tokens[] = str_replace(['~1', '~0'], ['/', '~'], $token);
        }

        return [$path, $tokens];
    }

    /** @param list<string> $tokens */
    private function rejectScopedAncestors(string $document, array $tokens): void
    {
        $node = $this->documents[$document];
        foreach ($tokens as $token) {
            if ($node instanceof \stdClass) {
                if (property_exists($node, '$id') || property_exists($node, '$self')) {
                    throw new \InvalidArgumentException('Referenced target has an unsupported identity scope in: ' . $document);
                }
                if (!property_exists($node, $token)) {
                    return;
                }
                $node = $node->$token;
            } elseif (is_array($node) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $token) === 1 && array_key_exists((int) $token, $node)) {
                $node = $node[(int) $token];
            } else {
                return;
            }
        }
    }

    /** @param list<string> $tokens */
    private function &target(string $document, array $tokens): mixed
    {
        $target = &$this->documents[$document];
        foreach ($tokens as $token) {
            if ($target instanceof \stdClass && property_exists($target, $token)) {
                $target = &$target->$token;
            } elseif (is_array($target) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $token) === 1 && array_key_exists((int) $token, $target)) {
                $target = &$target[(int) $token];
            } else {
                throw new \InvalidArgumentException('Reference target not found: ' . $document . '#' . $this->pointer($tokens));
            }
        }

        return $target;
    }

    private function insideRoot(string $path): bool
    {
        $root = rtrim($this->rootDirectory, '/\\') . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }

        return str_starts_with($path, $root);
    }

    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(rtrim($this->rootDirectory, '/\\')) + 1));
    }

    /** @param list<string> $tokens */
    private function pointer(array $tokens): string
    {
        return implode('', array_map(static fn (string $token): string => '/' . str_replace(['~', '/'], ['~0', '~1'], $token), $tokens));
    }

    /** @param list<string> $tokens */
    private function uriPointer(array $tokens): string
    {
        return implode('', array_map(static fn (string $token): string => '/' . rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token)), $tokens));
    }
}
