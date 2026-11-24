<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Tests\Integration;

use MaxBeckers\OpenApiTools\Command\BundleCommand;
use MaxBeckers\OpenApiTools\Document\BundleSourceMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BundleCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-bundle-' . bin2hex(random_bytes(8));
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

    public function testBundlePreservesIdentityCyclesDataAndProvenance(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{"openapi":"3.1.0","info":{"title":"Test","version":"1"},"paths":{},"components":{"schemas":{"First":{"$ref":"one/models.json#/$defs/Node"},"Second":{"$ref":"two/models.json#/$defs/Node"}}},"x-data":{"$ref":"https://example.test/not-a-reference"}}
JSON);
        $this->put('one/models.json', <<<'JSON'
{"$defs":{"Node":{"type":"object","properties":{"next":{"$ref":"#/$defs/Node"},"other":{"$ref":"../two/models.json#/$defs/Node"}},"example":{"$ref":"literal"}}}}
JSON);
        $this->put('two/models.json', <<<'JSON'
{"$defs":{"Node":{"type":"object","properties":{"back":{"$ref":"../one/models.json#/$defs/Node"}}}}}
JSON);
        $tester = new CommandTester(new BundleCommand());
        $first = $this->directory . DIRECTORY_SEPARATOR . 'bundle1.json';
        $second = $this->directory . DIRECTORY_SEPARATOR . 'bundle2.json';
        foreach ([$first, $second] as $output) {
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        }
        self::assertSame(file_get_contents($first), file_get_contents($second));
        $bundle = json_decode((string) file_get_contents($first), false, 512, JSON_THROW_ON_ERROR);
        $one = $bundle->{'x-openapi-generator-bundle'}->documents->{'one/models.json'}->{'$defs'}->Node;
        $two = $bundle->{'x-openapi-generator-bundle'}->documents->{'two/models.json'}->{'$defs'}->Node;
        self::assertSame('#/x-openapi-generator-bundle/documents/one~1models.json/%24defs/Node', $bundle->components->schemas->First->{'$ref'});
        self::assertSame($bundle->components->schemas->First->{'$ref'}, $one->properties->next->{'$ref'});
        self::assertSame($bundle->components->schemas->Second->{'$ref'}, $one->properties->other->{'$ref'});
        self::assertSame($bundle->components->schemas->First->{'$ref'}, $two->properties->back->{'$ref'});
        self::assertSame('literal', $one->example->{'$ref'});
        self::assertSame('https://example.test/not-a-reference', $bundle->{'x-data'}->{'$ref'});
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $root]));
    }

    public function testYamlAndEscapedPointerAreBundled(): void
    {
        $root = $this->put('api.yaml', <<<'YAML'
openapi: 3.0.0
info: {title: Test, version: "1"}
paths: {}
components:
  schemas:
    Escaped:
      $ref: './models.json#/$defs/a~1b~0c'
YAML);
        $this->put('models.json', '{"$defs":{"a/b~c":{"type":"object","properties":{"empty":{"type":"object","properties":{}}}}}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'result.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#/x-openapi-generator-bundle/documents/models.json/%24defs/a~1b~0c', $bundle->components->schemas->Escaped->{'$ref'});
        self::assertInstanceOf(\stdClass::class, $bundle->{'x-openapi-generator-bundle'}->documents->{'models.json'}->{'$defs'}->{'a/b~c'}->properties->empty->properties);
    }

    public function testBundleMergedYamlInRootAndReferencedDocument(): void
    {
        $root = $this->put('api.yaml', <<<'YAML'
openapi: 3.1.0
info: {title: Test, version: "1"}
paths: {}
x-defaults: &defaults {empty: {}, list: []}
x-result: {<<: *defaults}
components:
  schemas:
    Widget: {$ref: 'models.yaml#/Widget'}
YAML);
        $this->put('models.yaml', <<<'YAML'
base: &base
  type: object
  properties: {name: {type: string}}
other: &other {type: array, items: {type: integer}}
Widget: {<<: [*base, *other]}
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'merged.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $bundle->{'x-result'}->empty);
        self::assertSame([], $bundle->{'x-result'}->list);
        $model = $bundle->{'x-openapi-generator-bundle'}->documents->{'models.yaml'}->Widget;
        self::assertSame('object', $model->type);
        self::assertSame('string', $model->properties->name->type);
        $location = (new BundleSourceMap())->locate($bundle, '/x-openapi-generator-bundle/documents/models.yaml/Widget/properties/name');
        self::assertSame(['source' => 'models.yaml', 'pointer' => '/Widget/properties/name', 'line' => null, 'column' => null], $location);
    }

    public function testBundleRejectsAmbiguousMergedYamlWithoutOutput(): void
    {
        $root = $this->put('api.yaml', <<<'YAML'
openapi: 3.1.0
info: {title: Test, version: "1"}
paths: {}
x-first: &value {item: {}}
x-copy: {<<: *value}
x-second: &value {item: []}
YAML);
        $output = $this->directory . DIRECTORY_SEPARATOR . 'invalid.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        self::assertStringContainsString('node kind is unavailable or ambiguous', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    public function testPathsAndResponsesExtensionsRemainDataWhileNamedMapEntriesAreTraversed(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{
  "openapi":"3.1.0", "info":{"title":"t","version":"1"},
  "paths":{
    "x-path-data":{"$ref":"https://example.test/literal","get":{"responses":{"200":{"$ref":"missing.json"}}}},
    "/test":{"get":{"responses":{
      "x-response-data":{"$ref":"https://example.test/literal"},
      "200":{"$ref":"definitions.json#/response"}
    }}}
  },
  "webhooks":{"x-hook":{"$ref":"definitions.json#/path"}},
  "components":{
    "schemas":{"x-schema":{"$ref":"definitions.json#/schema"}},
    "responses":{"x-response":{"$ref":"definitions.json#/response"}},
    "pathItems":{"x-path":{"$ref":"definitions.json#/path"}}
  }
}
JSON);
        $this->put('definitions.json', '{"schema":{"type":"string"},"response":{"description":"ok"},"path":{"get":{"responses":{"200":{"$ref":"#/response"}}}}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://example.test/literal', $bundle->paths->{'x-path-data'}->{'$ref'});
        self::assertSame('missing.json', $bundle->paths->{'x-path-data'}->get->responses->{'200'}->{'$ref'});
        self::assertSame('https://example.test/literal', $bundle->paths->{'/test'}->get->responses->{'x-response-data'}->{'$ref'});
        $prefix = '#/x-openapi-generator-bundle/documents/definitions.json/';
        self::assertSame($prefix . 'response', $bundle->paths->{'/test'}->get->responses->{'200'}->{'$ref'});
        self::assertSame($prefix . 'response', $bundle->components->responses->{'x-response'}->{'$ref'});
        self::assertSame($prefix . 'schema', $bundle->components->schemas->{'x-schema'}->{'$ref'});
        self::assertSame($prefix . 'path', $bundle->components->pathItems->{'x-path'}->{'$ref'});
        self::assertSame($prefix . 'path', $bundle->webhooks->{'x-hook'}->{'$ref'});
        self::assertSame($prefix . 'response', $bundle->{'x-openapi-generator-bundle'}->documents->{'definitions.json'}->path->get->responses->{'200'}->{'$ref'});
    }

    public function testSchemaMapMembersNamedRefAreTraversedAsSchemas(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{
  "openapi":"3.1.0", "info":{"title":"t","version":"1"}, "paths":{},
  "components":{"schemas":{"Node":{
    "type":"object",
    "properties":{"$ref":{"$ref":"model.json#/$defs/Node"},"other":{"type":"string"}},
    "$defs":{"$ref":{"$ref":"model.json#/$defs/Node"}},
    "patternProperties":{"$ref":true}
  }}}
}
JSON);
        $this->put('model.json', '{"$defs":{"Node":{"type":"object","properties":{"$ref":{"$ref":"#/$defs/Node"}}}}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        $schema = $bundle->components->schemas->Node;
        $ref = '#/x-openapi-generator-bundle/documents/model.json/%24defs/Node';
        self::assertSame($ref, $schema->properties->{'$ref'}->{'$ref'});
        self::assertSame($ref, $schema->{'$defs'}->{'$ref'}->{'$ref'});
        self::assertTrue($schema->patternProperties->{'$ref'});
        self::assertSame($ref, $bundle->{'x-openapi-generator-bundle'}->documents->{'model.json'}->{'$defs'}->Node->properties->{'$ref'}->{'$ref'});
    }

    public function testUnsafeReferenceInsideSchemaMemberNamedRefFails(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"Node":{"properties":{"$ref":{"$ref":"https://example.test/schema"}}}}}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]));
        self::assertStringContainsString('/components/schemas/Node/properties/$ref/$ref', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    public function testLocalOperationRefsAndTheirReachableReferencesAreBundled(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{
  "openapi":"3.1.0", "info":{"title":"t","version":"1"},
  "paths":{"/pets/{id}":{"get":{"responses":{"200":{"description":"ok","links":{
    "local":{"operationRef":"#/paths/~1pets~1%7Bid%7D/get"},
    "external":{"$ref":"links.json#/link"},
    "literal":{"operationId":"getPet","parameters":{"payload":{"operationRef":"https://example.test/literal"}}}
  }}}}}},
  "components":{"links":{"reusable":{"operationRef":"operations.json#/get"}}}
}
JSON);
        $this->put('links.json', '{"link":{"operationRef":"operations.json#/get","x-data":{"operationRef":"missing.json"}}}');
        $this->put('operations.json', '{"get":{"operationId":"linked","responses":{"200":{"$ref":"responses.json#/ok"},"201":{"description":"ok","links":{"back":{"operationRef":"api.json#/paths/~1pets~1%7Bid%7D/get"}}}}}}');
        $this->put('responses.json', '{"ok":{"description":"found"}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        $documents = $bundle->{'x-openapi-generator-bundle'}->documents;
        $links = $bundle->paths->{'/pets/{id}'}->get->responses->{'200'}->links;
        $target = '#/x-openapi-generator-bundle/documents/operations.json/get';
        $rootTarget = '#/paths/~1pets~1%7Bid%7D/get';
        self::assertSame($rootTarget, $links->local->operationRef);
        self::assertSame('#/x-openapi-generator-bundle/documents/links.json/link', $links->external->{'$ref'});
        self::assertSame($target, $documents->{'links.json'}->link->operationRef);
        self::assertSame($target, $bundle->components->links->reusable->operationRef);
        self::assertSame('#/x-openapi-generator-bundle/documents/responses.json/ok', $documents->{'operations.json'}->get->responses->{'200'}->{'$ref'});
        self::assertSame($rootTarget, $documents->{'operations.json'}->get->responses->{'201'}->links->back->operationRef);
        self::assertSame('missing.json', $documents->{'links.json'}->link->{'x-data'}->operationRef);
        self::assertSame('https://example.test/literal', $links->literal->parameters->payload->operationRef);
    }

    public function testInvalidOperationRefsFailWithoutOutput(): void
    {
        $this->put('other.json', '{"operation":false}');
        $values = ['https://example.test/openapi.json#/get', '../outside.json#/get', 'missing.json#/get', '#/missing',
            '#anchor', 'other.json#/operation', '', 42];
        $tester = new CommandTester(new BundleCommand());
        foreach ($values as $index => $value) {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $root = $this->put('input' . $index . '.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"links":{"L":{"operationRef":' . $encoded . '}}}}');
            $output = $this->directory . DIRECTORY_SEPARATOR . 'out' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertStringContainsString('/components/links/L/operationRef', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testYamlScalarAliasReferenceIsBundledWithSourceLocation(): void
    {
        $root = $this->put('api.yaml', <<<'YAML'
openapi: 3.1.0
info: {title: Test, version: "1"}
paths: {}
x-reference: &reference 'model.json#/Thing'
components:
  schemas:
    Thing:
      $ref: *reference
YAML);
        $this->put('model.json', '{"Thing":{"type":"string"}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#/x-openapi-generator-bundle/documents/model.json/Thing', $bundle->components->schemas->Thing->{'$ref'});
        self::assertSame(['source' => 'api.yaml', 'pointer' => '/components/schemas/Thing/$ref', 'line' => 8, 'column' => 7], (new BundleSourceMap())->locate($bundle, '/components/schemas/Thing/$ref'));
    }

    public function testYamlCollectionAliasesAreBundledIndependently(): void
    {
        $root = $this->put('api.yaml', <<<'YAML'
openapi: 3.1.0
info: {title: Test, version: "1"}
paths: {}
components:
  schemas:
    First: &schema
      type: object
      properties:
        nested:
          $ref: 'model.json#/Thing'
        empty: {}
    Second: *schema
YAML);
        $this->put('model.json', '{"Thing":{"type":"string"}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        $schemas = $bundle->components->schemas;
        self::assertSame('#/x-openapi-generator-bundle/documents/model.json/Thing', $schemas->First->properties->nested->{'$ref'});
        self::assertSame($schemas->First->properties->nested->{'$ref'}, $schemas->Second->properties->nested->{'$ref'});
        self::assertInstanceOf(\stdClass::class, $schemas->Second->properties->empty);
        $map = new BundleSourceMap();
        self::assertSame(['source' => 'api.yaml', 'pointer' => '/components/schemas/Second/properties/nested/$ref', 'line' => null, 'column' => null], $map->locate($bundle, '/components/schemas/Second/properties/nested/$ref'));
        self::assertSame(['source' => 'api.yaml', 'pointer' => '/components/schemas/Second', 'line' => 12, 'column' => 5], $map->locate($bundle, '/components/schemas/Second'));
    }

    public function testUnsupportedAndUnsafeReferencesFailWithoutOutput(): void
    {
        $tester = new CommandTester(new BundleCommand());
        $cases = [
            '{"$ref":"https://example.test/schema.json"}',
            '{"$ref":"../outside.json"}',
            '{"$ref":"missing.json"}',
            '{"$ref":"#/missing"}',
            '{"$ref":"#anchor"}',
            '{"$ref":"%68ttps%3A%2F%2Fexample.test/schema.json"}',
            '{"$id":"relative","type":"object"}',
            '{"discriminator":{"propertyName":"kind","mapping":{"a":"other.json"}}}',
        ];
        foreach ($cases as $index => $schema) {
            $root = $this->put('input' . $index . '.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":' . $schema . '}}}');
            $result = $this->directory . DIRECTORY_SEPARATOR . 'out' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
            self::assertFileDoesNotExist($result);
        }
    }

    public function testExplicitDiscriminatorMappingsAreBundledAndTraversed(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{
  "Base":{"discriminator":{"propertyName":"kind","mapping":{"cat":"models.json#/components/schemas/Cat","local":"#/components/schemas/Local","$ref":"models.json#/components/schemas/Cat"}}},
  "Local":{"type":"object"}
}}}
JSON);
        $this->put('models.json', '{"components":{"schemas":{"Cat":{"type":"object","properties":{"name":{"$ref":"types.json#/Name"},"friend":{"$ref":"#/components/schemas/Cat"}}}}}}');
        $this->put('types.json', '{"Name":{"type":"string"}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        $mapping = $bundle->components->schemas->Base->discriminator->mapping;
        self::assertSame('#/x-openapi-generator-bundle/documents/models.json/components/schemas/Cat', $mapping->cat);
        self::assertSame($mapping->cat, $mapping->{'$ref'});
        self::assertSame('#/components/schemas/Local', $mapping->local);
        self::assertSame('#/x-openapi-generator-bundle/documents/types.json/Name', $bundle->{'x-openapi-generator-bundle'}->documents->{'models.json'}->components->schemas->Cat->properties->name->{'$ref'});
        self::assertSame($mapping->cat, $bundle->{'x-openapi-generator-bundle'}->documents->{'models.json'}->components->schemas->Cat->properties->friend->{'$ref'});
    }

    public function testDiscriminatorMappingsRejectUnsupportedTargets(): void
    {
        $tester = new CommandTester(new BundleCommand());
        foreach (['Cat', 'models.json', 'models.json#', 'models.json#anchor', 'https://example.test/a#/Cat', '#/missing', 42] as $index => $reference) {
            $input = ['openapi' => '3.1.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => new \stdClass(),
                'components' => ['schemas' => ['A' => ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['a/b' => $reference]]]]]];
            $root = $this->put('mapping' . $index . '.json', json_encode($input, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'mapping-out' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertStringContainsString('/components/schemas/A/discriminator/mapping/a~1b', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testDiscriminatorSchemaNamesUseEntryDocumentComponents(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{
  "Root":{"discriminator":{"propertyName":"kind","mapping":{"cat":"Cat","odd":"Version.1","self":"Root"}}},
  "External":{"$ref":"model.json#/components/schemas/Base"},
  "Cat":{"type":"object","properties":{"name":{"$ref":"types.json#/Name"}}},
  "Version.1":{"type":"object"}
}}}
JSON);
        $this->put('model.json', '{"components":{"schemas":{"Base":{"discriminator":{"propertyName":"kind","mapping":{"cat":"Cat"}}},"Cat":{"type":"integer"}}}}');
        $this->put('types.json', '{"Name":{"type":"string"}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        $mapping = $bundle->components->schemas->Root->discriminator->mapping;
        self::assertSame('#/components/schemas/Cat', $mapping->cat);
        self::assertSame('#/components/schemas/Version.1', $mapping->odd);
        self::assertSame('#/components/schemas/Root', $mapping->self);
        self::assertSame('#/components/schemas/Cat', $bundle->{'x-openapi-generator-bundle'}->documents->{'model.json'}->components->schemas->Base->discriminator->mapping->cat);
        self::assertSame('#/x-openapi-generator-bundle/documents/types.json/Name', $bundle->components->schemas->Cat->properties->name->{'$ref'});
    }

    public function testExternalReferenceToRootIsPreserved(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":{"$ref":"model.json#/$defs/A"},"B":{"type":"string"}}}}');
        $this->put('model.json', '{"$defs":{"A":{"type":"object","properties":{"b":{"$ref":"api.json#/components/schemas/B"}}}}}');
        $result = dirname($root) . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#/components/schemas/B', $bundle->{'x-openapi-generator-bundle'}->documents->{'model.json'}->{'$defs'}->A->properties->b->{'$ref'});
    }

    public function testReferenceUnderNestedIdScopeFails(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":{"$ref":"model.json#/$defs/A"}}}}');
        $this->put('model.json', '{"$id":"other.json","$defs":{"A":{"type":"string"}}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $result]));
        self::assertStringContainsString('unsupported identity scope', $tester->getDisplay());
        self::assertFileDoesNotExist($result);
    }

    public function testInvalidNestedReferenceReportsItsSourceDocumentAndPointer(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":{"$ref":"model.json#/$defs/A"}}}}');
        $model = $this->put('model.json', '{"$defs":{"A":{"type":"object","properties":{"missing":{"$ref":"missing.json"}}}}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $result]));
        self::assertStringContainsString(realpath($model) . '#/$defs/A/properties/missing/$ref', $tester->getDisplay());
        self::assertStringContainsString('missing.json', $tester->getDisplay());
        self::assertFileDoesNotExist($result);
    }

    public function testSourceLocationsSurviveWithoutOriginalDocuments(): void
    {
        $root = $this->put('api.yaml', "openapi: 3.1.0\ninfo: {title: Test, version: '1'}\npaths: {}\ncomponents:\n  schemas:\n    User:\n      \$ref: 'models/user.yaml#/User'\n");
        $model = $this->put('models/user.yaml', "User:\n  type: object\n  properties:\n    name:\n      type: string\n");
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        unlink($root);
        unlink($model);
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        $map = new BundleSourceMap();
        self::assertSame(
            ['source' => 'models/user.yaml', 'pointer' => '/User/properties/name/type', 'line' => 5, 'column' => 7],
            $map->locate($bundle, '/x-openapi-generator-bundle/documents/models~1user.yaml/User/properties/name/type'),
        );
        $arrayBundle = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            $map->locate($bundle, '/x-openapi-generator-bundle/documents/models~1user.yaml/User/properties/name/type'),
            $map->locate($arrayBundle, '/x-openapi-generator-bundle/documents/models~1user.yaml/User/properties/name/type'),
        );
        self::assertSame(
            ['source' => 'api.yaml', 'pointer' => '/components/schemas/User/$ref', 'line' => 7, 'column' => 7],
            $map->locate($bundle, '/components/schemas/User/$ref'),
        );
        self::assertSame(
            ['source' => 'models/user.yaml', 'pointer' => '/missing', 'line' => null, 'column' => null],
            $map->locate($bundle, '/x-openapi-generator-bundle/documents/models~1user.yaml/missing'),
        );
        self::assertNull($map->locate($bundle, '/x-openapi-generator-bundle/sourceLocations'));
    }

    public function testJsonSourceLocations(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":{"$ref":"model.json#/A"}}}}');
        $this->put('model.json', '{"A":{"type":"string"}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['source' => 'model.json', 'pointer' => '/A/type', 'line' => 1, 'column' => 7],
            (new BundleSourceMap())->locate($bundle, '/x-openapi-generator-bundle/documents/model.json/A/type'),
        );
    }

    public function testSingleDocumentBundleHasSourceLocations(): void
    {
        $root = $this->put('api.json', "{\r\n  \"openapi\": \"3.1.0\",\r\n  \"info\": {\"title\": \"Test\", \"version\": \"1\"},\r\n  \"paths\": {},\r\n  \"components\": {\"schemas\": {\"A\": {\"type\": \"string\"}}}\r\n}");
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['source' => 'api.json', 'pointer' => '/components/schemas/A/type', 'line' => 5, 'column' => 36],
            (new BundleSourceMap())->locate($bundle, '/components/schemas/A/type'),
        );
    }

    public function testOpenApi32ReferencesInNewReferenceBearingFieldsAreBundled(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{
  "openapi":"3.2.0","info":{"title":"t","version":"1"},
  "paths":{"/path":{"additionalOperations":{"COPY":{"responses":{"200":{"$ref":"definitions.json#/response"}}}}}},
  "components": {
    "mediaTypes": {
      "Stream": {
        "itemSchema": {"$ref":"definitions.json#/schema"},
        "example": {"$ref":"literal.json"},
        "prefixEncoding": [{"headers":{"X-One":{"$ref":"definitions.json#/header"}}}],
        "itemEncoding": {"encoding":{"nested":{"headers":{"X-Two":{"$ref":"definitions.json#/header"}}}}}
      },
      "Reusable": {"$ref":"definitions.json#/media"}
    }
  }
}
JSON);
        $this->put('definitions.json', '{"schema":{"type":"object","properties":{"next":{"$ref":"#/schema"}}},"header":{"schema":{"type":"string"}},"response":{"description":"ok"},"media":{"itemSchema":{"$ref":"#/schema"}}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#/x-openapi-generator-bundle/documents/definitions.json/response', $bundle->paths->{'/path'}->additionalOperations->COPY->responses->{'200'}->{'$ref'});
        $media = $bundle->components->mediaTypes->Stream;
        self::assertSame('#/x-openapi-generator-bundle/documents/definitions.json/schema', $media->itemSchema->{'$ref'});
        self::assertSame($media->itemSchema->{'$ref'}, $bundle->{'x-openapi-generator-bundle'}->documents->{'definitions.json'}->schema->properties->next->{'$ref'});
        self::assertSame('literal.json', $media->example->{'$ref'});
        self::assertSame('#/x-openapi-generator-bundle/documents/definitions.json/header', $media->prefixEncoding[0]->headers->{'X-One'}->{'$ref'});
        self::assertSame('#/x-openapi-generator-bundle/documents/definitions.json/header', $media->itemEncoding->encoding->nested->headers->{'X-Two'}->{'$ref'});
        self::assertSame('#/x-openapi-generator-bundle/documents/definitions.json/media', $bundle->components->mediaTypes->Reusable->{'$ref'});
        self::assertSame($media->itemSchema->{'$ref'}, $bundle->{'x-openapi-generator-bundle'}->documents->{'definitions.json'}->media->itemSchema->{'$ref'});
    }

    public function testOpenApi32UnsupportedReferencesFailWithoutOutput(): void
    {
        $cases = [
            '"paths":{"/p":{"additionalOperations":{"COPY":{"responses":{"200":{"$ref":"https://example.test/response"}}}}}}',
            '"components":{"mediaTypes":{"S":{"itemSchema":{"$ref":"https://example.test/schema"}}}}',
            '"components":{"mediaTypes":{"S":{"prefixEncoding":[{"headers":{"X":{"$ref":"https://example.test/header"}}}]}}}',
            '"components":{"mediaTypes":{"S":{"itemEncoding":{"prefixEncoding":[{"headers":{"X":{"$ref":"missing.json"}}}]}}}}',
            '"components":{"schemas":{"A":{"discriminator":{"propertyName":"kind","defaultMapping":"other.json"}}}}',
        ];
        $tester = new CommandTester(new BundleCommand());
        foreach ($cases as $index => $content) {
            $input = '{"openapi":"3.2.0","info":{"title":"t","version":"1"},' . $content . '}';
            json_decode($input, false, 512, JSON_THROW_ON_ERROR);
            $root = $this->put('input-' . $index . '.json', $input);
            $result = $this->directory . DIRECTORY_SEPARATOR . 'out-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
            self::assertStringNotContainsString('Unable to parse', $tester->getDisplay());
            self::assertFileDoesNotExist($result);
        }
    }

    public function testOpenApi32DefaultMappingsAreBundledAndTraversed(): void
    {
        $root = $this->put('api.json', <<<'JSON'
{"openapi":"3.2.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{
  "Root":{"discriminator":{"propertyName":"kind","defaultMapping":"Fallback"}},
  "Remote":{"discriminator":{"propertyName":"kind","defaultMapping":"models.json#/components/schemas/Fallback"}},
  "External":{"$ref":"models.json#/components/schemas/Base"},
  "Fallback":{"type":"object","properties":{"name":{"$ref":"types.json#/Name"}}}
}}}
JSON);
        $model = $this->put('models.json', '{"components":{"schemas":{"Base":{"discriminator":{"propertyName":"kind","defaultMapping":"Fallback"}},"Fallback":{"type":"object","properties":{"next":{"$ref":"#/components/schemas/Fallback"},"only":{"$ref":"types.json#/Only"}}}}}}');
        $types = $this->put('types.json', '{"Name":{"type":"string"},"Only":{"type":"integer"}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        $output2 = $this->directory . DIRECTORY_SEPARATOR . 'out2.json';
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output2]), $tester->getDisplay());
        self::assertSame(file_get_contents($output), file_get_contents($output2));
        unlink($root);
        unlink($model);
        unlink($types);
        $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
        $external = $bundle->{'x-openapi-generator-bundle'}->documents->{'models.json'}->components->schemas;
        self::assertSame('#/components/schemas/Fallback', $bundle->components->schemas->Root->discriminator->defaultMapping);
        self::assertSame('#/components/schemas/Fallback', $external->Base->discriminator->defaultMapping);
        self::assertSame('#/x-openapi-generator-bundle/documents/models.json/components/schemas/Fallback', $bundle->components->schemas->Remote->discriminator->defaultMapping);
        self::assertSame($bundle->components->schemas->Remote->discriminator->defaultMapping, $external->Fallback->properties->next->{'$ref'});
        self::assertSame('#/x-openapi-generator-bundle/documents/types.json/Only', $external->Fallback->properties->only->{'$ref'});
        self::assertSame('integer', $bundle->{'x-openapi-generator-bundle'}->documents->{'types.json'}->Only->type);
        self::assertSame('#/x-openapi-generator-bundle/documents/types.json/Name', $bundle->components->schemas->Fallback->properties->name->{'$ref'});
    }

    public function testDiscriminatorWholeDocumentReferencesAreBundled(): void
    {
        $this->put('models/base.json', '{"discriminator":{"propertyName":"kind","mapping":{"cat":"../cat%20schema.json#"},"defaultMapping":"../cat%20schema.json"}}');
        $this->put('cat schema.json', '{"type":"object","properties":{"next":{"$ref":"#"},"name":{"$ref":"./name.json"}}}');
        $this->put('name.json', '{"type":"string"}');
        $tester = new CommandTester(new BundleCommand());
        foreach (['3.0.4', '3.1.1', '3.2.0'] as $index => $version) {
            $default = $version === '3.2.0' ? ',"defaultMapping":"./cat%20schema.json"' : '';
            $input = '{"openapi":"' . $version . '","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{'
                . '"Pet":{"discriminator":{"propertyName":"kind","mapping":{"cat":"./cat%20schema.json"}' . $default . '}},'
                . '"External":{"$ref":"models/base.json"}'
                . '}}}';
            $root = $this->put('api-' . $index . '.json', $input);
            $output = $this->directory . DIRECTORY_SEPARATOR . 'out-' . $index . '.json';
            $second = $this->directory . DIRECTORY_SEPARATOR . 'out-again-' . $index . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $second]), $tester->getDisplay());
            self::assertSame(file_get_contents($output), file_get_contents($second));
            $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
            $documents = $bundle->{'x-openapi-generator-bundle'}->documents;
            $cat = '#/x-openapi-generator-bundle/documents/cat%20schema.json';
            self::assertSame($cat, $bundle->components->schemas->Pet->discriminator->mapping->cat);
            self::assertSame($cat, $documents->{'models/base.json'}->discriminator->mapping->cat);
            self::assertSame($cat, $documents->{'cat schema.json'}->properties->next->{'$ref'});
            self::assertSame('#/x-openapi-generator-bundle/documents/name.json', $documents->{'cat schema.json'}->properties->name->{'$ref'});
            if ($version === '3.2.0') {
                self::assertSame($cat, $bundle->components->schemas->Pet->discriminator->defaultMapping);
                self::assertSame($cat, $documents->{'models/base.json'}->discriminator->defaultMapping);
            } else {
                self::assertSame('../cat%20schema.json', $documents->{'models/base.json'}->discriminator->defaultMapping);
            }
        }
    }

    public function testDiscriminatorWholeDocumentReferencesRejectUnsafeAndInvalidTargets(): void
    {
        $this->put('invalid.json', '42');
        $this->put('scoped.json', '{"$id":"schema.json","type":"object"}');
        $tester = new CommandTester(new BundleCommand());
        foreach (['./missing.json', '../outside.json', './invalid.json', './scoped.json', './invalid.json#anchor', './%ZZ', './invalid.json?query'] as $index => $reference) {
            $input = ['openapi' => '3.2.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => new \stdClass(),
                'components' => ['schemas' => ['A' => ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['value' => $reference]]]]]];
            $root = $this->put('invalid-input-' . $index . '.json', json_encode($input, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'invalid-output-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertStringContainsString(realpath($root) . '#/components/schemas/A/discriminator/mapping/value', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testUnambiguousWholeDocumentDiscriminatorPathsUseTheReferringDocument(): void
    {
        $this->put('schemas/cat.json', '{"type":"object","properties":{"name":{"$ref":"name.json"}}}');
        $this->put('schemas/name.json', '{"type":"string"}');
        $this->put('base/schema.json', '{"discriminator":{"propertyName":"kind","mapping":{"cat":"child/cat.json"},"defaultMapping":"child/cat.json#"}}');
        $this->put('base/child/cat.json', '{"type":"object","properties":{"value":{"$ref":"#/properties/value"}}}');
        $this->put('cat.json', '{"type":"integer"}');
        $this->put('cat schema.json', '{"type":"string"}');
        $tester = new CommandTester(new BundleCommand());
        foreach (['3.0.4', '3.1.1', '3.2.0'] as $index => $version) {
            $default = $version === '3.2.0' ? ',"defaultMapping":"schemas/cat.json"' : '';
            $root = $this->put('input-' . $index . '.json', '{"openapi":"' . $version . '","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{'
                . '"Pet":{"discriminator":{"propertyName":"kind","mapping":{"cat":"schemas/cat.json","named":"cat.json","file":"cat.json#","encoded":"cat%20schema.json"}' . $default . '}},'
                . '"cat.json":{"type":"boolean"},"External":{"$ref":"base/schema.json"}'
                . '}}}');
            $output = $this->directory . DIRECTORY_SEPARATOR . 'output-' . $index . '.json';
            self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            $bundle = json_decode((string) file_get_contents($output), false, 512, JSON_THROW_ON_ERROR);
            $mapping = $bundle->components->schemas->Pet->discriminator->mapping;
            $documents = $bundle->{'x-openapi-generator-bundle'}->documents;
            self::assertSame('#/x-openapi-generator-bundle/documents/schemas~1cat.json', $mapping->cat);
            self::assertSame('#/components/schemas/cat.json', $mapping->named);
            self::assertSame('#/x-openapi-generator-bundle/documents/cat.json', $mapping->file);
            self::assertSame('#/x-openapi-generator-bundle/documents/cat%20schema.json', $mapping->encoded);
            self::assertSame('#/x-openapi-generator-bundle/documents/schemas~1name.json', $documents->{'schemas/cat.json'}->properties->name->{'$ref'});
            self::assertSame('#/x-openapi-generator-bundle/documents/base~1child~1cat.json', $documents->{'base/schema.json'}->discriminator->mapping->cat);
            self::assertSame('#/x-openapi-generator-bundle/documents/base~1child~1cat.json/properties/value', $documents->{'base/child/cat.json'}->properties->value->{'$ref'});
            if ($version === '3.2.0') {
                self::assertSame($mapping->cat, $bundle->components->schemas->Pet->discriminator->defaultMapping);
                self::assertSame($documents->{'base/schema.json'}->discriminator->mapping->cat, $documents->{'base/schema.json'}->discriminator->defaultMapping);
            }
        }
    }

    public function testAmbiguousNamesDoNotProbeFilesAndUnsafePathsAreRejected(): void
    {
        $this->put('cat.json', '{"type":"object"}');
        $this->put('cat schema.json', '{"type":"object"}');
        $tester = new CommandTester(new BundleCommand());
        foreach (['cat.json', 'https://example.test/a', '%68ttps%3A%2F%2Fexample.test/a', 'missing/cat.json', 'schemas/%ZZ', 'schemas/cat.json?x', 'schemas/cat.json#anchor', '/schemas/cat.json', 'cat schema.json'] as $index => $reference) {
            $input = ['openapi' => '3.2.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => new \stdClass(),
                'components' => ['schemas' => ['A' => ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => $reference]]]]];
            $root = $this->put('bad-input-' . $index . '.json', json_encode($input, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'bad-output-' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertStringContainsString(realpath($root) . '#/components/schemas/A/discriminator/defaultMapping', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testInvalidExternalDefaultMappingReportsSourceAndRejectsScopedTargets(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.2.0","info":{"title":"t","version":"1"},"paths":{},"components":{"schemas":{"A":{"$ref":"model.json#/A"}}}}');
        $model = $this->put('model.json', '{"A":{"discriminator":{"propertyName":"kind","defaultMapping":"scoped.json#/$defs/Target"}}}');
        $this->put('scoped.json', '{"$id":"other.json","$defs":{"Target":{"type":"string"}}}');
        $output = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
        self::assertStringContainsString(realpath($model) . '#/A/discriminator/defaultMapping', $tester->getDisplay());
        self::assertStringContainsString('unsupported identity scope', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
    }

    public function testOpenApi32DefaultMappingRejectsUnsupportedTargets(): void
    {
        $tester = new CommandTester(new BundleCommand());
        foreach (['Missing', 'models.json', '#anchor', '#/missing', 'https://example.test/a#/schema', null, 42] as $index => $reference) {
            $input = ['openapi' => '3.2.0', 'info' => ['title' => 't', 'version' => '1'], 'paths' => new \stdClass(),
                'components' => ['schemas' => ['A' => ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => $reference]]]]];
            $root = $this->put('default' . $index . '.json', json_encode($input, JSON_THROW_ON_ERROR));
            $output = $this->directory . DIRECTORY_SEPARATOR . 'default-out' . $index . '.json';
            self::assertSame(Command::FAILURE, $tester->execute(['input' => $root, 'output' => $output]), $tester->getDisplay());
            self::assertStringContainsString('/components/schemas/A/discriminator/defaultMapping', $tester->getDisplay());
            self::assertFileDoesNotExist($output);
        }
    }

    public function testEarlierVersionsTreat32OnlyFieldsAsData(): void
    {
        $root = $this->put('api.json', '{"openapi":"3.1.0","info":{"title":"t","version":"1"},"paths":{"/p":{"additionalOperations":{"COPY":{"responses":{"200":{"$ref":"not-a-reference.json"}}}}}},"components":{"schemas":{"A":{"discriminator":{"propertyName":"kind","defaultMapping":"not-a-reference.json"}}},"mediaTypes":{"S":{"itemSchema":{"$ref":"not-a-reference.json"}}}}}');
        $result = $this->directory . DIRECTORY_SEPARATOR . 'out.json';
        $tester = new CommandTester(new BundleCommand());
        self::assertSame(Command::SUCCESS, $tester->execute(['input' => $root, 'output' => $result]), $tester->getDisplay());
        $bundle = json_decode((string) file_get_contents($result), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not-a-reference.json', $bundle->components->mediaTypes->S->itemSchema->{'$ref'});
        self::assertSame('not-a-reference.json', $bundle->components->schemas->A->discriminator->defaultMapping);
    }

    private function put(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0777, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
