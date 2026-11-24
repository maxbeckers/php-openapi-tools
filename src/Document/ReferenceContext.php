<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

/**
 * Identifies reference-bearing OpenAPI and JSON Schema locations.
 */
final class ReferenceContext
{
    public static function isContainer(string $context): bool
    {
        return str_ends_with($context, '-map')
            || str_ends_with($context, '-list')
            || $context === 'paths'
            || $context === 'responses';
    }

    public static function child(string $context, string $key, bool $openApi32): string
    {
        if ($context === 'data') {
            return 'data';
        }
        if (str_ends_with($context, '-map') || str_ends_with($context, '-list')) {
            return substr($context, 0, (int) strrpos($context, '-'));
        }
        if (str_starts_with($key, 'x-')) {
            return 'data';
        }

        return match ($context) {
            'document' => match ($key) {
                'components' => 'components',
                'paths' => 'paths',
                'webhooks' => 'path-map',
                default => 'data',
            },
            'paths' => 'path',
            'components' => match ($key) {
                'schemas' => 'schema-map',
                'parameters', 'headers' => 'parameter-map',
                'responses' => 'response-map',
                'requestBodies' => 'body-map',
                'callbacks' => 'callback-map',
                'examples' => 'example-map',
                'securitySchemes' => 'reference-map',
                'links' => 'link-map',
                'pathItems' => 'path-map',
                'mediaTypes' => $openApi32 ? 'media-map' : 'data',
                default => 'data',
            },
            'path' => match ($key) {
                'get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace' => 'operation',
                'query' => $openApi32 ? 'operation' : 'data',
                'additionalOperations' => $openApi32 ? 'operation-map' : 'data',
                'parameters' => 'parameter-list',
                default => 'data',
            },
            'operation' => match ($key) {
                'parameters' => 'parameter-list',
                'responses' => 'responses',
                'requestBody' => 'body',
                'callbacks' => 'callback-map',
                default => 'data',
            },
            'responses' => 'response',
            'callback' => 'path',
            'parameter' => match ($key) {
                'schema' => 'schema',
                'content' => 'media-map',
                'examples' => 'example-map',
                default => 'data',
            },
            'body', 'response' => match ($key) {
                'content' => 'media-map',
                'headers' => 'parameter-map',
                'links' => 'link-map',
                default => 'data',
            },
            'media' => match ($key) {
                'schema' => 'schema',
                'itemSchema' => $openApi32 ? 'schema' : 'data',
                'examples' => 'example-map',
                'encoding' => 'encoding-map',
                'itemEncoding' => $openApi32 ? 'encoding' : 'data',
                'prefixEncoding' => $openApi32 ? 'encoding-list' : 'data',
                default => 'data',
            },
            'encoding' => match ($key) {
                'headers' => 'parameter-map',
                'encoding' => $openApi32 ? 'encoding-map' : 'data',
                'itemEncoding' => $openApi32 ? 'encoding' : 'data',
                'prefixEncoding' => $openApi32 ? 'encoding-list' : 'data',
                default => 'data',
            },
            'schema' => match ($key) {
                'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependencies' => 'schema-map',
                'allOf', 'oneOf', 'anyOf', 'prefixItems' => 'schema-list',
                'items', 'additionalProperties', 'not', 'if', 'then', 'else', 'contains',
                'unevaluatedProperties', 'unevaluatedItems', 'propertyNames', 'contentSchema', 'additionalItems' => 'schema',
                default => 'data',
            },
            default => 'data',
        };
    }
}
