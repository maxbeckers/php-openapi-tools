<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Tests\Integration;

use MaxBeckers\OpenApiTools\Command\OverlayCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OverlayCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-overlay-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testOrderedYamlOverlayActionsPreserveCollectionShapes(): void
    {
        $input = $this->put('api.yaml', <<<'YAML'
openapi: 3.1.0
info:
  title: Original
  version: "1"
paths:
  /one:
    get: {summary: First}
  /two:
    get: {summary: Second}
emptyObject: {}
emptyArray: []
items:
  - {name: first}
  - {name: second}
YAML);
        $overlay = $this->put('overlay.yaml', <<<'YAML'
overlay: 1.0.0
info: {title: Test, version: "1"}
extends: api.yaml
actions:
  - target: $.paths.*.get
    update: {description: changed}
  - target: $.emptyObject
    update: {added: true}
  - target: $.emptyArray
    update: {name: appended}
  - target: $.items[*]
    remove: true
  - target: $.items
    update: {name: final}
YAML);
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('changed', $data->paths->{'/one'}->get->description);
        self::assertSame('changed', $data->paths->{'/two'}->get->description);
        self::assertTrue($data->emptyObject->added);
        self::assertSame('appended', $data->emptyArray[0]->name);
        self::assertCount(1, $data->items);
        self::assertSame('final', $data->items[0]->name);
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]));
        self::assertStringContainsString('must not already exist', $tester->getDisplay());
    }

    public function testSequentialOverlaysAndJsonPathBracketMembers(): void
    {
        $input = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"Before"},"paths":{"/pets":{"get":{}}}}');
        $first = $this->put('first.json', '{"overlay":"1.0.0","info":{"title":"one","version":"1"},"actions":[{"target":"$.info","update":{"title":"First"}}]}');
        $second = $this->put('second.json', '{"overlay":"1.0.0","info":{"title":"two","version":"1"},"actions":[{"target":"$.info","update":{"title":"Second"}},{"target":"$.paths[\'/pets\'].get","update":{"operationId":"list"}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$first, $second]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Second', $data->info->title);
        self::assertSame('list', $data->paths->{'/pets'}->get->operationId);
    }

    public function testInvalidOverlayNeverWritesOutput(): void
    {
        $input = $this->put('api.json', '{"info":{"title":"x"}}');
        $invalid = [
            '{"overlay":"1.3.0","info":{"title":"x","version":"1"},"actions":[{"target":"$","update":{}}]}',
            '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$..[","update":{}}]}',
            '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$.info.title","remove":true}]}',
            '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$","copy":"$.info"}]}',
        ];
        $tester = new CommandTester(new OverlayCommand());
        foreach ($invalid as $i => $contents) {
            $overlay = $this->put('invalid' . $i . '.json', $contents);
            $output = $this->directory . DIRECTORY_SEPARATOR . 'output' . $i . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]));
            self::assertFileDoesNotExist($output);
        }

    }

    public function testZeroMatchesAndRecursiveArrayMerge(): void
    {
        $input = $this->put('api.json', '{"info":{"tags":["old"],"title":"x"}}');
        $overlay = $this->put('overlay.json', '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$.missing","remove":true},{"target":"$","update":{"info":{"tags":["new"]}}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['old', 'new'], $data->info->tags);
    }

    public function testUnsupportedYamlAliasesAndMergesFailWithoutOutput(): void
    {
        $overlay = $this->put('overlay.json', '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$","update":{}}]}');
        $tester = new CommandTester(new OverlayCommand());
        $inputs = [
            ["first: &v {key: {}}\ncopy: {<<: *v}\nsecond: &v {key: []}\n", 'node kind is unavailable or ambiguous'],
            ["first: &a {key: {}}\nsecond: &b {key: []}\ncopy: {<<: *a, <<: *b}\n", 'node kind is unavailable or ambiguous'],
            ["info: &meta {self: *meta}\n", 'Cyclic YAML alias'],
            ["first: &v {key: first}\na: *v\nsecond: &v [second]\nb: *v\n", 'node kind is unavailable or ambiguous'],
        ];
        foreach ($inputs as $index => [$yaml, $error]) {
            $input = $this->put('api' . $index . '.yaml', $yaml);
            $output = $this->directory . DIRECTORY_SEPARATOR . 'out' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]));
            self::assertStringContainsString($error, $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testMergedYamlInputAndOverlayPreserveShapesAndPrecedence(): void
    {
        $input = $this->put('api.yaml', <<<'YAML'
first: &first {item: {}, nested: {values: []}, choice: first}
second: &second {item: [], other: {}, choice: second}
middle: &middle {<<: *first}
copy: {<<: [*middle, *second]}
YAML);
        $overlay = $this->put('overlay.yaml', <<<'YAML'
overlay: 1.1.0
info: {title: Test, version: "1"}
x-update: &update {added: true}
actions:
  - target: $.copy.item
    update: {<<: *update}
  - target: $.copy.nested.values
    update: [new]
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'merged.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data->copy->item->added);
        self::assertSame('first', $data->copy->choice);
        self::assertInstanceOf(\stdClass::class, $data->copy->other);
        self::assertSame(['new'], $data->copy->nested->values);
        self::assertSame([], $data->first->nested->values);
        self::assertFalse(property_exists($data->first->item, 'added'));
    }

    public function testYamlCollectionAliasesPreserveShapesAndAreIndependent(): void
    {
        $input = $this->put('api.yaml', <<<'YAML'
base: &base
  empty: {}
  items: [one, {nested: []}]
copy: *base
emptySequence: &empty []
otherSequence: *empty
YAML);
        $overlay = $this->put('overlay.yaml', <<<'YAML'
overlay: 1.1.0
info: {title: Test, version: "1"}
actions:
  - target: $.copy.empty
    update: &change {added: true}
  - target: $.copy.items[1].nested
    update: [new]
  - target: $.base.empty
    update: *change
  - target: $.otherSequence
    update: [other]
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data->base->empty->added);
        self::assertTrue($data->copy->empty->added);
        self::assertSame([], $data->base->items[1]->nested);
        self::assertSame(['new'], $data->copy->items[1]->nested);
        self::assertSame([], $data->emptySequence);
        self::assertSame(['other'], $data->otherSequence);
    }

    public function testYamlScalarAliasesInInputAndOverlay(): void
    {
        $input = $this->put('api.yaml', <<<'YAML'
info:
  title: &name Original
  description: *name
values:
  - &flag false
  - *flag
  - &empty null
  - *empty
  - &number 42
  - *number
  - &name First
  - *name
  - &name Second
  - *name
YAML);
        $overlay = $this->put('overlay.yaml', <<<'YAML'
overlay: 1.1.0
info: {title: Test, version: "1"}
actions:
  - target: $.info.title
    update: &replacement Changed
  - target: $.info.description
    update: *replacement
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Changed', $data->info->title);
        self::assertSame('Changed', $data->info->description);
        self::assertSame([false, false, null, null, 42, 42, 'First', 'First', 'Second', 'Second'], $data->values);
    }

    public function testIncompatibleUpdatesAndIncorrectExtendsFailWithoutWriting(): void
    {
        $input = $this->put('api.json', '{"info":{"tags":[]}}');
        $tester = new CommandTester(new OverlayCommand());
        foreach ([
            '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"actions":[{"target":"$","update":{"info":{"tags":{}}}}]}',
            '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"extends":"https://example.com/api.json","actions":[{"target":"$","update":{}}]}',
        ] as $i => $contents) {
            $overlay = $this->put('error' . $i . '.json', $contents);
            $result = $this->directory . DIRECTORY_SEPARATOR . 'error-output' . $i . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]));
            self::assertFileDoesNotExist($result);
        }
    }

    public function testExtendsResolvesPercentEncodedRelativeFileUri(): void
    {
        $input = $this->put('api #1+test.json', '{"info":{"title":"Before"}}');
        $directory = $this->directory . DIRECTORY_SEPARATOR . 'overlays';
        self::assertTrue(mkdir($directory));
        $overlay = $this->put('overlays/overlay.json', '{"overlay":"1.1.0","info":{"title":"x","version":"1"},"extends":"../api%20%231+test.json","actions":[{"target":"$.info.title","update":"After"}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $document = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('After', $document->info->title);
    }

    public function testExtendsDecodesOnlyOnce(): void
    {
        $input = $this->put('api%2Fname.json', '{"info":{"title":"Before"}}');
        $overlay = $this->put('overlay.json', '{"overlay":"1.0.0","info":{"title":"x","version":"1"},"extends":"api%252Fname.json","actions":[{"target":"$.info","update":{"title":"After"}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $document = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('After', $document->info->title);
    }

    public function testExtendsRejectsUnsafeOrNonmatchingUrisWithoutWriting(): void
    {
        $input = $this->put('api.json', '{"info":{"title":"Before"}}');
        $this->put('other.json', '{"info":{"title":"Other"}}');
        $references = [
            'other.json', 'https://example.test/api.json', '//example.test/api.json',
            '/api.json', 'api.json?version=1', 'api.json#fragment', 'api.json#',
            'api%2Ejson%', 'api%2Ejson%00', '%2Fapi.json', '%5Capi.json', 'https%3A%2F%2Fexample.test',
            'api.json/', 'api.json/.', 'api.json/..', '%2e/api.json', 'api[1].json',
            'api%2F..%2Fapi.json', '%43%3Aapi.json', 'api.json%0A', 'api .json',
        ];
        $tester = new CommandTester(new OverlayCommand());
        foreach ($references as $index => $reference) {
            $overlay = $this->put('extends' . $index . '.json', json_encode([
                'overlay' => '1.1.0',
                'info' => ['title' => 'x', 'version' => '1'],
                'extends' => $reference,
                'actions' => [['target' => '$', 'update' => new \stdClass()]],
            ], JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'extends-output' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertStringContainsString('extends must identify the local input document', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testOverlay11UpdatesCopiesAndRemovesNodesInSequence(): void
    {
        $input = $this->put('api.json', <<<'JSON'
{"source":{"get":{"description":"original"},"tags":["one"]},"destination":{},"items":[1,2],"values":[{"v":1},{"v":2}],"name":"before","nothing":null,"quoted":{"a\"b":{},"c'd":{},"snow☃":{}}}
JSON);
        $overlay = $this->put('overlay.json', <<<'JSON'
{"overlay":"1.1.0","info":{"title":"test","version":"1"},"actions":[
  {"target":"$.destination","copy":"$.source"},
  {"target":"$.source.get.description","update":"changed"},
  {"target":"$.items","update":[3,4]},
  {"target":"$.items[0]","remove":true},
  {"target":"$.items[0]","update":7},
  {"target":"$.name","copy":"$.source.get.description"},
  {"target":"$.nothing","update":false},
  {"target":"$.values[*].v","update":9},
  {"target":"$.quoted[\"a\\\"b\"]","update":{"found":true}},
  {"target":"$.quoted['c\\'d']","update":{"found":true}},
  {"target":"$.quoted[\"snow\\u2603\"]","update":{"found":true}}
]}
JSON);
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('original', $data->destination->get->description);
        self::assertSame('changed', $data->name);
        self::assertSame([7, 3, 4], $data->items);
        self::assertFalse($data->nothing);
        self::assertSame(9, $data->values[0]->v);
        self::assertSame(9, $data->values[1]->v);
        self::assertTrue($data->quoted->{'a"b'}->found);
        self::assertTrue($data->quoted->{"c'd"}->found);
        self::assertTrue($data->quoted->{"snow\u{2603}"}->found);
    }

    public function testOverlay11FailuresIdentifyOverlayAndActionWithoutWriting(): void
    {
        $input = $this->put('api.json', '{"items":[{"x":1},3],"source":{"value":true},"target":{}}');
        $actions = [
            '{"target":"$.target","copy":"$.missing"}',
            '{"target":"$.target","copy":"$.items[*]"}',
            '{"target":"$.items[*]","update":{}}',
            '{"target":"$.target","update":{},"copy":"$.source"}',
            '{"target":"$.target","copy":42}',
            '{"target":"$.target[\"bad\\\\q\"]","update":{}}',
            '{"target":"$.source.value","update":{}}',
        ];
        $tester = new CommandTester(new OverlayCommand());
        foreach ($actions as $i => $action) {
            $overlay = $this->put('failure' . $i . '.json', '{"overlay":"1.1.0","info":{"title":"x","version":"1"},"actions":[' . $action . ']}');
            $result = $this->directory . DIRECTORY_SEPARATOR . 'failure-output' . $i . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertStringContainsString($overlay, $tester->getDisplay());
            self::assertStringContainsString('action 0', $tester->getDisplay());
            self::assertFileDoesNotExist($result);
        }
    }

    public function testVersionSpecificArrayUpdateAndNoMatches(): void
    {
        $input = $this->put('api.json', '{"items":[1]}');
        $tester = new CommandTester(new OverlayCommand());
        foreach (['1.0.0' => [1, [2, 3]], '1.1.0' => [1, 2, 3]] as $version => $expected) {
            $overlay = $this->put('overlay' . $version . '.json', '{"overlay":"' . $version . '","info":{"title":"x","version":"1"},"actions":[{"target":"$.items","update":[2,3]},{"target":"$.missing","remove":true}]}');
            $result = $this->directory . DIRECTORY_SEPARATOR . 'out' . $version . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertSame($expected, json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR)['items']);
        }
    }

    public function testCopyUsesSnapshotAndRemoveTakesPrecedence(): void
    {
        $input = $this->put('api.json', '{"entries":[{"values":[1]},{"values":[2]}],"delete":{},"keep":{}}');
        $overlay = $this->put('overlay.json', <<<'JSON'
{"overlay":"1.1.0","info":{"title":"x","version":"1"},"actions":[
  {"target":"$.entries[*].values","copy":"$.entries[1].values"},
  {"target":"$.delete","remove":true,"update":{"ignored":true},"copy":"$.keep"},
  {"target":"$.missing","copy":"$.missing"}
]}
JSON);
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([1, 2], $data['entries'][0]['values']);
        self::assertSame([2, 2], $data['entries'][1]['values']);
        self::assertArrayNotHasKey('delete', $data);
    }

    public function testRfc9535SelectorsAndOverlappingRemovals(): void
    {
        $input = $this->put('api.json', <<<'JSON'
{"items":[{"name":"a","active":true},{"name":"b","active":false},{"name":"c","active":true},{"name":"d","active":false}],"tree":{"nested":{"nested":{"v":1}}},"copy":[]}
JSON);
        $overlay = $this->put('overlay.json', <<<'JSON'
{"overlay":"1.1.0","info":{"title":"x","version":"1"},"actions":[
  {"target":"$.items[?@.active == true]","update":{"selected":true}},
  {"target":"$.copy","copy":"$.items[-1].name"},
  {"target":"$.items[3,0,3]","remove":true},
  {"target":"$..nested","remove":true}
]}
JSON);
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['b', 'c'], array_column($data['items'], 'name'));
        self::assertTrue($data['items'][1]['selected']);
        self::assertSame(['d'], $data['copy']);
        self::assertSame([], $data['tree']);
    }

    public function testRfc9535SlicesFunctionsAndEscapedNormalizedPaths(): void
    {
        $input = $this->put('api.json', <<<'JSON'
{"items":[{"name":"one","tags":["x"]},{"name":"two","tags":[]},{"name":"three","tags":["a","b"]}],"data":{"a'b":{"list":[1,2,3]}}}
JSON);
        $overlay = $this->put('overlay.json', <<<'JSON'
{"overlay":"1.1.0","info":{"title":"x","version":"1"},"actions":[
  {"target":"$.items[?count(@.tags[*]) >= 2]","update":{"flag":true}},
  {"target":"$.items[::-1].name","update":"changed"},
  {"target":"$.data[\"a'b\"].list[0:3:2]","remove":true}
]}
JSON);
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $result, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['items'][2]['flag']);
        self::assertSame(['changed', 'changed', 'changed'], array_column($data['items'], 'name'));
        self::assertSame([2], $data['data']["a'b"]['list']);
    }

    public function testOverlay12ReusableActionsApplyInOrderAndSupportOverrides(): void
    {
        $input = $this->put('api.json', '{"a":{"items":[]},"b":{"items":[]},"source":{"v":"copied"},"destination":{},"obsolete":1}');
        $overlay = $this->put('overlay.yaml', <<<'YAML'
overlay: 1.2.0
info: {title: Test, version: "1"}
components:
  actions:
    'append/v1~beta':
      description: This is component documentation
      fields:
        description: default action description
        update: [one]
    copy:
      fields: {copy: '$.source'}
    empty: {}
    'space name':
      fields: {update: {flag: true}}
actions:
  - $ref: '#/components/actions/append~1v1~0beta'
    target: $.a.items
  - $ref: '#/components/actions/append~1v1~0beta'
    target: $.b.items
    description: override
    update: [two]
  - $ref: '#/components/actions/copy'
    target: $.destination
  - $ref: '#/components/actions/empty'
    target: $.a
  - $ref: '#/components/actions/space%20name'
    target: $.b
  - target: $.obsolete
    remove: true
  - target: $.source
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['one'], $data['a']['items']);
        self::assertSame(['two'], $data['b']['items']);
        self::assertTrue($data['b']['flag']);
        self::assertSame(['v' => 'copied'], $data['destination']);
        self::assertArrayNotHasKey('obsolete', $data);
    }

    public function testOverlay12ReusableActionsRejectUnsupportedReferencesAndFields(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $cases = [
            ['{"$ref":"other.json#/components/actions/a","target":"$.value"}', '{}'],
            ['{"$ref":"#/components/actions/a/b","target":"$.value"}', '{}'],
            ['{"$ref":"#/components/actions/a~2b","target":"$.value"}', '{}'],
            ['{"$ref":"#/components/actions/%ZZ","target":"$.value"}', '{}'],
            ['{"$ref":"#/components/actions/missing","target":"$.value"}', '{}'],
            ['{"$ref":"#/components/actions/a","target":"$.value"}', '{"a":{"fields":{"target":"$","update":{}}}}'],
            ['{"$ref":"#/components/actions/a","target":"$.value"}', '{"a":{"fields":[]}}'],
            ['{"$ref":"#/components/actions/a"}', '{"a":{"fields":{"update":{}}}}'],
            ['{"$ref":"#/components/actions/a","target":"$.value","unknown":true}', '{"a":{"fields":{"update":{}}}}'],
        ];
        $tester = new CommandTester(new OverlayCommand());
        foreach ($cases as $index => [$action, $components]) {
            $overlay = $this->put('invalid12-' . $index . '.json', '{"overlay":"1.2.0","info":{"title":"t","version":"1"},"components":{"actions":' . $components . '},"actions":[' . $action . ']}');
            $output = $this->directory . DIRECTORY_SEPARATOR . 'invalid12-output-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertStringContainsString('action 0', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testOverlay12LocalSelfChangesExtendsBase(): void
    {
        $input = $this->put('api #1.json', '{"value":{}}');
        $tester = new CommandTester(new OverlayCommand());
        $cases = [
            ['virtual/overlay.json', '../api%20%231.json'],
            ['virtual/', '../api%20%231.json'],
            ['virtual/..', 'api%20%231.json'],
            ['./overlay.json', 'api%20%231.json'],
            ['missing/../virtual/overlay.json', '../api%20%231.json'],
        ];
        foreach ($cases as $index => [$self, $extends]) {
            $overlay = $this->put('self-' . $index . '.json', json_encode([
                'overlay' => '1.2.0',
                '$self' => $self,
                'extends' => $extends,
                'info' => ['title' => 't', 'version' => '1'],
                'components' => ['actions' => ['add' => ['fields' => ['update' => ['applied' => true]]]]],
                'actions' => [['$ref' => '#/components/actions/add', 'target' => '$.value']],
            ], JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'self-output-' . $index . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($data->value->applied);
        }
    }

    public function testExtendsMatchesInputLocalSelfIdentity(): void
    {
        $input = $this->put('api.json', '{"$self":"identity/spec%20v1.json","value":{}}');
        $first = $this->put('identity-first.json', <<<'JSON'
{"overlay":"1.2.0","$self":"virtual/overlay.json","info":{"title":"t","version":"1"},"extends":"../identity/spec%20v1.json","actions":[{"target":"$","update":{"$self":"changed.json"}},{"target":"$.value","update":{"first":true}}]}
JSON);
        $second = $this->put('identity-second.json', <<<'JSON'
{"overlay":"1.2.0","info":{"title":"t","version":"1"},"extends":"identity/./%73pec%20v1.json","actions":[{"target":"$.value","update":{"second":true}}]}
JSON);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'identity-result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$first, $second]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data->value->first);
        self::assertTrue($data->value->second);
        self::assertSame('changed.json', $data->{'$self'});
    }

    public function testExtendsDoesNotMatchUnrelatedOrUnsupportedInputSelf(): void
    {
        $tester = new CommandTester(new OverlayCommand());
        $cases = [
            ['identity/spec.json', 'identity/other.json'],
            ['identity/spec%2Bv1.json', 'identity/spec+v1.json'],
            ['https://example.test/spec.json', 'identity/spec.json'],
            ['identity/%2e%2e/spec.json', 'spec.json'],
            [null, 'identity/spec.json'],
        ];
        foreach ($cases as $index => [$self, $reference]) {
            $input = $this->put('identity-input-' . $index . '.json', json_encode(['$self' => $self, 'value' => new \stdClass()], JSON_THROW_ON_ERROR));
            $overlay = $this->put('identity-overlay-' . $index . '.json', json_encode([
                'overlay' => '1.2.0', 'info' => ['title' => 't', 'version' => '1'],
                'extends' => $reference, 'actions' => [['target' => '$.value', 'update' => new \stdClass()]],
            ], JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'identity-error-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertStringContainsString('extends must identify the local input document', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testOverlay12RejectsUnsupportedSelfAndNonmatchingExtends(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $tester = new CommandTester(new OverlayCommand());
        $cases = [
            ['https://example.test/overlay#', null], ['/overlay.json', null], ['overlay.json#fragment', null],
            ['overlay.json?query', null], ['%2e%2e/overlay.json', null], ['dir//overlay.json', null], ['invalid[1]', null],
            ['', null], [null, null], [123, null], ['virtual/overlay.json', 'api.json'],
        ];
        foreach ($cases as $index => [$self, $extends]) {
            $overlayData = [
                'overlay' => '1.2.0', '$self' => $self,
                'info' => ['title' => 't', 'version' => '1'],
                'actions' => [['target' => '$.value', 'update' => ['applied' => true]]],
            ];
            if ($extends !== null) {
                $overlayData['extends'] = $extends;
            }
            $overlay = $this->put('bad-self-' . $index . '.json', json_encode($overlayData, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'bad-self-output-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertStringContainsString($extends === null ? 'Overlay $self must' : 'extends must identify', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testAbsoluteHttpIdentitiesMatchWithoutRetrieval(): void
    {
        $tester = new CommandTester(new OverlayCommand());
        $cases = [
            ['https://example.invalid/openapi/spec.json', 'https://example.invalid/overlays/change.json', '../openapi/spec.json'],
            ['https://example.invalid/openapi/spec.json', null, 'HTTPS://EXAMPLE.INVALID:443/openapi/./spec.json'],
            ['http://example.invalid/~user/api%2Bv1.json', 'http://example.invalid/overlays/', '../%7Euser/api%2bv1.json'],
            ['https://example.invalid/', null, 'https://example.invalid'],
            ['https://example.invalid/a//b', null, 'https://example.invalid/a//b'],
            ['https://[2001:db8::1]/openapi/spec.json', 'https://[2001:db8::1]/overlays/change.json', '../openapi/spec.json'],
            ['https://[2001:db8::1]/api.json', null, 'HTTPS://[2001:0DB8:0:0:0:0:0:1]:443/api.json'],
            ['http://[::ffff:192.0.2.1]/api.json', null, 'http://[::ffff:c000:201]:80/api.json'],
            ['https://example.invalid/openapi/spec.json', 'https://example.invalid/overlays/change.json', '/openapi/spec.json'],
            ['https://example.invalid/~api/spec.json', 'https://example.invalid/overlays/change.json', '/%7Eapi/./spec.json'],
            ['https://[2001:db8::1]/', 'https://[2001:0db8:0:0:0:0:0:1]/overlays/change.json', '/'],
            ['https://example.invalid/openapi/spec.json', 'https://other.invalid/change.json', '//EXAMPLE.INVALID:443/openapi/./spec.json'],
            ['http://[2001:db8::1]/api.json', 'http://example.invalid/overlay.json', '//[2001:0db8:0:0:0:0:0:1]:80/api.json'],
            ['https://example.invalid/', 'https://other.invalid/overlay.json', '//example.invalid'],
        ];
        foreach ($cases as $index => [$inputSelf, $overlaySelf, $extends]) {
            $input = $this->put('absolute-input-' . $index . '.json', json_encode(['$self' => $inputSelf, 'value' => new \stdClass()], JSON_THROW_ON_ERROR));
            $data = ['overlay' => '1.2.0', 'info' => ['title' => 't', 'version' => '1'],
                'extends' => $extends, 'actions' => [['target' => '$.value', 'update' => ['matched' => true]]]];
            if ($overlaySelf !== null) {
                $data['$self'] = $overlaySelf;
            }
            $overlay = $this->put('absolute-overlay-' . $index . '.json', json_encode($data, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'absolute-output-' . $index . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertTrue(json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR)->value->matched);
        }
    }

    public function testAbsoluteHttpIdentitiesRejectMismatchesAndUnsupportedForms(): void
    {
        $tester = new CommandTester(new OverlayCommand());
        $cases = [
            ['https://example.invalid/api.json', 'https://other.invalid/api.json', null],
            ['https://example.invalid/a%2Fb', 'https://example.invalid/a/b', null],
            ['https://example.invalid/a%2Bb', 'https://example.invalid/a+b', null],
            ['https://example.invalid/a//b', 'https://example.invalid/a/b', null],
            ['https://example.invalid/api.json', 'http://example.invalid/api.json', null],
            ['https://example.invalid/api.json', 'https://example.invalid/api.json?', null],
            ['https://example.invalid/api.json', 'https://example.invalid/api.json#', null],
            ['https://example.invalid/api.json', 'https://example.invalid/%2e/api.json', null],
            ['https://example.invalid/api.json', 'https://user@example.invalid/api.json', null],
            ['https://example.invalid/api.json', 'https://example.invalid:99999/api.json', null],
            ['https://example.invalid/api.json', '../api.json', 'https://example.invalid/overlays/overlay.json?query'],
            [null, 'https://example.invalid/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://[2001:db8::2]/api.json', null],
            ['https://[2001:db8::1]/api.json', 'http://[2001:db8::1]/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://[2001:db8::1]:444/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://[2001:db8::1]/api.json?', null],
            ['https://[2001:db8::1]/api.json', 'https://[2001:db8::1]/api.json#', null],
            ['https://[2001:db8::1]/api.json', 'https://user@[2001:db8::1]/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://[2001:db8:::1]/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://[fe80::1%25eth0]/api.json', null],
            ['https://[2001:db8::1]/api.json', 'https://2001:db8::1/api.json', null],
            ['https://[2001:db8::1]/api.json', '../api.json', 'https://[2001:db8:::1]/overlays/overlay.json'],
            ['https://example.invalid/api.json', '//example.invalid/api.json', null],
            ['https://example.invalid/api.json', '//example.invalid/api.json', 'relative/overlay.json'],
            ['https://example.invalid/api.json', '//other.invalid/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid:444/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid/api.json', 'http://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//user@example.invalid/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid/api.json?', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid/api.json#', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid/%2e/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '//example.invalid:99999/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '///example.invalid/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://[2001:db8::1]/api.json', '//[2001:db8:::1]/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '/api.json?', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '/api.json#', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '/%2e/api.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '/%2fapi.json', 'https://example.invalid/overlays/change.json'],
            ['https://example.invalid/api.json', '/api.json', null],
            ['https://example.invalid/api.json', '/api.json', 'relative/overlay.json'],
            ['https://example.invalid/api.json', '/other.json', 'https://example.invalid/overlays/change.json'],
        ];
        foreach ($cases as $index => [$inputSelf, $extends, $overlaySelf]) {
            $input = $this->put('remote-bad-input-' . $index . '.json', json_encode(['$self' => $inputSelf, 'value' => new \stdClass()], JSON_THROW_ON_ERROR));
            $data = ['overlay' => '1.2.0', 'info' => ['title' => 't', 'version' => '1'],
                'extends' => $extends, 'actions' => [['target' => '$.value', 'update' => new \stdClass()]]];
            if ($overlaySelf !== null) {
                $data['$self'] = $overlaySelf;
            }
            $overlay = $this->put('remote-bad-overlay-' . $index . '.json', json_encode($data, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'remote-bad-output-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testAbsoluteOverlaySelfWithoutExtends(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $overlay = $this->put('overlay.json', '{"overlay":"1.2.0","$self":"https://example.invalid/overlays/overlay.json","info":{"title":"t","version":"1"},"actions":[{"target":"$.value","update":{"matched":true}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        self::assertTrue(json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR)->value->matched);
    }

    public function testIpv6OverlaySelfValidationWithoutExtends(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $tester = new CommandTester(new OverlayCommand());
        foreach (['https://[::1]/overlay.json' => true, 'https://[2001:db8:::1]/overlay.json' => false,
            'https://[fe80::1%25eth0]/overlay.json' => false, 'https://[v1.fe]/overlay.json' => false] as $self => $valid) {
            $overlay = $this->put('self-' . md5($self) . '.json', json_encode([
                'overlay' => '1.2.0', '$self' => $self, 'info' => ['title' => 't', 'version' => '1'],
                'actions' => [['target' => '$.value', 'update' => ['matched' => true]]],
            ], JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'out-' . md5($self) . '.json';
            self::assertSame($valid ? Command::SUCCESS : Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            if ($valid) {
                self::assertTrue(json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR)->value->matched);
            } else {
                self::assertStringContainsString('Overlay $self must', $tester->getDisplay());
                self::assertFileDoesNotExist($output);
            }
        }
    }

    public function testIpv6InputIdentityRemainsFixedAcrossOverlays(): void
    {
        $input = $this->put('api.json', '{"$self":"https://[2001:db8::1]/api.json","value":{}}');
        $first = $this->put('first.json', '{"overlay":"1.2.0","info":{"title":"t","version":"1"},"extends":"https://[2001:0db8:0:0:0:0:0:1]/api.json","actions":[{"target":"$","update":{"$self":"changed.json"}},{"target":"$.value","update":{"first":true}}]}');
        $second = $this->put('second.json', '{"overlay":"1.2.0","$self":"https://[2001:db8::1]/overlays/change.json","info":{"title":"t","version":"1"},"extends":"../api.json","actions":[{"target":"$.value","update":{"second":true}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$first, $second]]), $tester->getDisplay());
        $data = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data->value->first);
        self::assertTrue($data->value->second);
        self::assertSame('changed.json', $data->{'$self'});
    }

    public function testRootRelativeExtendsWithoutHttpIdentityCannotMatchLocalFile(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $overlay = $this->put('overlay.json', '{"overlay":"1.2.0","info":{"title":"t","version":"1"},"extends":"/api.json","actions":[{"target":"$.value","update":{"matched":true}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        self::assertStringContainsString('extends must identify the local input document', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    public function testNetworkPathExtendsWithoutHttpIdentityCannotMatchLocalFile(): void
    {
        $input = $this->put('api.json', '{"value":{}}');
        $overlay = $this->put('overlay.json', '{"overlay":"1.2.0","info":{"title":"t","version":"1"},"extends":"//example.invalid/api.json","actions":[{"target":"$.value","update":{"matched":true}}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new OverlayCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
        self::assertStringContainsString('extends must identify the local input document', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    public function testNoOpActionsStillValidateTargetExpressions(): void
    {
        $input = $this->put('api.json', '{"value":{},"primitive":1}');
        $tester = new CommandTester(new OverlayCommand());
        foreach (['1.0.0', '1.1.0', '1.2.0'] as $index => $version) {
            $overlay = $this->put('noop-' . $index . '.json', '{"overlay":"' . $version . '","info":{"title":"t","version":"1"},"actions":[{"target":"$.value"},{"target":"$.missing","remove":false}]}');
            $output = $this->directory . DIRECTORY_SEPARATOR . 'noop-output-' . $index . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]), $tester->getDisplay());
            self::assertSame(['value' => [], 'primitive' => 1], json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR));
        }
        $overlay = $this->put('primitive-noop.json', '{"overlay":"1.0.0","info":{"title":"t","version":"1"},"actions":[{"target":"$.primitive"}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'primitive-noop-output.json';
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]));
        self::assertStringContainsString('Overlay 1.0 targets must select objects or arrays', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
        $overlay = $this->put('bad-noop.json', '{"overlay":"1.2.0","info":{"title":"t","version":"1"},"actions":[{"target":"$..["}]}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'bad-noop-output.json';
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $input, 'output' => $output, '--overlay' => [$overlay]]));
        self::assertStringContainsString('Invalid JSONPath', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    private function put(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
