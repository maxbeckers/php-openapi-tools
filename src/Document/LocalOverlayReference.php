<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Document;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;

final class LocalOverlayReference
{
    public static function isSupportedSelf(string $reference): bool
    {
        return self::isRelativePath($reference) || self::isAbsoluteHttpIdentity($reference);
    }

    private static function isAbsoluteHttpIdentity(string $reference): bool
    {
        if (preg_match("~\\Ahttps?://(\\[[0-9a-f:.]+\\]|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::([0-9]{1,5}))?((?:/(?:[A-Za-z0-9._\\-!$&'()*+,;=:@/\\x7e]|%[0-9a-fA-F]{2})*)?)\\z~iD", $reference, $match) !== 1
            || str_contains($match[1], '..') || ($match[2] !== '' && (int) $match[2] > 65535)) {
            return false;
        }
        if (str_starts_with($match[1], '[')
            && filter_var(substr($match[1], 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        foreach (explode('/', $match[3]) as $segment) {
            if ($segment !== '.' && $segment !== '..' && in_array(rawurldecode($segment), ['.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    public static function isRelativePath(string $reference): bool
    {
        if ($reference === '' || str_contains($reference, '//')
            || preg_match("~^(?:[A-Za-z0-9._\\-!$&'()*+,;=:@/\\x7e]|%[0-9a-fA-F]{2})+$~D", $reference) !== 1
            || preg_match('/%(?:2f|5c)/i', $reference) === 1) {
            return false;
        }
        $decoded = rawurldecode($reference);
        if (preg_match('/^[\\/\\\\]|^[^\\/\\\\]*:|[\x00-\x1f\x7f]/', $decoded) === 1) {
            return false;
        }
        foreach (explode('/', $reference) as $segment) {
            if ($segment !== '.' && $segment !== '..' && in_array(rawurldecode($segment), ['.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    public static function extendsInput(string $reference, ?string $self, string $overlayPath, string $inputPath, ?string $inputSelf = null): bool
    {
        if (self::isAbsoluteHttpIdentity($reference) || ($self !== null && self::isAbsoluteHttpIdentity($self))) {
            if ($inputSelf === null || !self::isAbsoluteHttpIdentity($inputSelf)) {
                return false;
            }
            if (self::isAbsoluteHttpIdentity($reference)) {
                $target = new Uri($reference);
            } elseif ($self !== null && self::isAbsoluteHttpIdentity($self) && str_starts_with($reference, '//')) {
                $absoluteReference = (new Uri($self))->getScheme() . ':' . $reference;
                if (!self::isAbsoluteHttpIdentity($absoluteReference)) {
                    return false;
                }
                $target = new Uri($absoluteReference);
            } elseif ($self !== null && self::isAbsoluteHttpIdentity($self) && self::isHttpRelativeReference($reference)) {
                $target = UriResolver::resolve(new Uri($self), new Uri($reference));
            } else {
                return false;
            }

            return self::normalizeHttpIdentity($target) === self::normalizeHttpIdentity(new Uri($inputSelf));
        }
        if (!self::isRelativePath($reference) || ($self !== null && !self::isRelativePath($self))) {
            return false;
        }
        $referenceParts = explode('/', $reference);
        if (in_array(end($referenceParts), ['', '.', '..'], true)) {
            return false;
        }
        $overlayPath = realpath($overlayPath);
        if ($overlayPath === false) {
            return false;
        }
        $segments = [];
        if ($self !== null) {
            $parts = explode('/', $self);
            $last = end($parts);
            if ($last !== '' && $last !== '.' && $last !== '..') {
                array_pop($parts);
            }
            self::appendSegments($segments, $parts);
        }
        self::appendSegments($segments, $referenceParts);

        $directory = dirname($overlayPath);
        $resolvedPath = $directory . DIRECTORY_SEPARATOR . rawurldecode(implode('/', $segments));
        if (realpath($resolvedPath) === $inputPath) {
            return true;
        }
        if ($inputSelf === null || !self::isRelativePath($inputSelf)) {
            return false;
        }
        $inputParts = explode('/', $inputSelf);
        if (in_array(end($inputParts), ['', '.', '..'], true)) {
            return false;
        }

        return self::absolutePath($directory, $segments) === self::absolutePath(dirname($inputPath), $inputParts);
    }

    private static function isHttpRelativeReference(string $reference): bool
    {
        if (self::isRelativePath($reference)) {
            return true;
        }
        if (!str_starts_with($reference, '/') || str_starts_with($reference, '//')) {
            return false;
        }
        $path = substr($reference, 1);

        return $path === '' || self::isRelativePath($path);
    }

    private static function normalizeHttpIdentity(UriInterface $uri): string
    {
        $host = $uri->getHost();
        if (str_starts_with($host, '[')) {
            $packed = inet_pton(substr($host, 1, -1));
            if ($packed === false) {
                throw new \LogicException('Validated IPv6 identity has an invalid host.');
            }
            $host = inet_ntop($packed);
            if ($host === false) {
                throw new \LogicException('Unable to normalize IPv6 identity.');
            }
            $uri = $uri->withHost('[' . $host . ']');
        }

        return (string) UriNormalizer::normalize($uri);
    }

    /** @param list<string> $segments */
    private static function absolutePath(string $directory, array $segments): string
    {
        $base = array_map(rawurlencode(...), explode(DIRECTORY_SEPARATOR, $directory));
        $minimum = DIRECTORY_SEPARATOR === '\\' && str_starts_with($directory, '\\\\') ? 4 : 1;
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($base) > $minimum) {
                    array_pop($base);
                }
            } else {
                $base[] = preg_replace_callback('/%[0-9a-f]{2}/i', static function (array $match): string {
                    $character = rawurldecode($match[0]);

                    return preg_match('/^[A-Za-z0-9._~-]$/D', $character) === 1 ? $character : strtoupper($match[0]);
                }, $segment);
            }
        }

        return implode(DIRECTORY_SEPARATOR, $base);
    }

    /**
     * @param list<string> $segments
     * @param list<string> $parts
     */
    private static function appendSegments(array &$segments, array $parts): void
    {
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } else {
                    $segments[] = '..';
                }
            } else {
                $segments[] = $part;
            }
        }
    }
}
