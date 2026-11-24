<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Tests\Integration;

use MaxBeckers\OpenApiTools\Document\JsonSourceLocator;
use PHPUnit\Framework\TestCase;

final class JsonSourceLocatorTest extends TestCase
{
    public function testLocationsForEscapesArraysDuplicatesAndMixedNewlines(): void
    {
        $json = "{\r\n \"a\\/b~c\": [\r  true,\n  {\"text\":\"quote\\\" and \\\\ slash\"}],\n \"dup\":{\"old\":1},\n \"dup\":{\"new\":2},\n \"\\u006b\":null\n}";
        json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $locator = new JsonSourceLocator($json);
        $locations = $locator->locations();
        self::assertSame($locations, $locator->locations());
        self::assertSame(['line' => 2, 'column' => 2], $locations['/a~1b~0c']);
        self::assertSame(['line' => 3, 'column' => 3], $locations['/a~1b~0c/0']);
        self::assertSame(['line' => 4, 'column' => 4], $locations['/a~1b~0c/1/text']);
        self::assertArrayNotHasKey('/dup/old', $locations);
        self::assertSame(['line' => 6, 'column' => 9], $locations['/dup/new']);
        self::assertSame(['line' => 7, 'column' => 2], $locations['/k']);
    }

    public function testJsonColumnsCountUtf8Bytes(): void
    {
        $json = '{"é":1,"after":[{},42]}';
        json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $locations = (new JsonSourceLocator($json))->locations();
        self::assertSame(['line' => 1, 'column' => 9], $locations['/after']);
        self::assertSame(['line' => 1, 'column' => 21], $locations['/after/1']);
    }
}
