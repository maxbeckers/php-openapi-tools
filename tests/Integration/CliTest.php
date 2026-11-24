<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testCommandsAreAvailableFromTheEntryPoint(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/openapi-tools', 'list', '--raw'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertIsString($output);
        self::assertMatchesRegularExpression('/^bundle\s/m', $output);
        self::assertMatchesRegularExpression('/^overlay\s/m', $output);
    }
}
