# PHP OpenAPI Tools

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://github.com/maxbeckers/php-openapi-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/maxbeckers/php-openapi-tools/actions/workflows/tests.yml)

Standalone CLI tools and PHP APIs for transforming OpenAPI documents.

## Features

- Apply supported subsets of OpenAPI Overlay 1.0, 1.1 and 1.2.
- Bundle documents by resolving supported local references.
- Record source locations for bundled content and map bundled pointers back to
  their original source locations.
- Use the CLI or the PHP API.

## Requirements

- PHP `>=8.2`
- Composer `>=2.0`

## Installation

```shell
composer require --dev maxbeckers/php-openapi-tools
```

## Quick Start

Apply one or more overlays to an OpenAPI document:

```shell
vendor/bin/openapi-tools overlay openapi.yaml overlaid.json --overlay overlay.yaml
```

Bundle the resulting document, including reachable local reference targets:

```shell
vendor/bin/openapi-tools bundle overlaid.json bundle.json
```

The current implementation supports subsets of Overlay 1.0, 1.1 and 1.2 with
RFC 9535 JSONPath and local JSON Pointer references. This is an offline,
local-file interoperability profile: it does not fetch remote documents or
implement general URI and JSON Schema identity semantics. See the [overlay
guide](docs/overlays.md) and [bundling guide](docs/bundling.md) for supported
features and restrictions. Bundles can be consumed by
`php-openapi-generator`; the embedded-document extension is not necessarily
supported by other tools.

## PHP API

For library use, `MaxBeckers\OpenApiTools\Document\OverlayApplier` applies an
overlay to a document object, and `DocumentBundler` bundles a file using
`JsonDocumentReader`. `BundleSourceMap` maps bundled pointers to recorded
original source locations.

## Development

```shell
composer install
composer test
composer cs-check
composer cs
```

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

**Questions or Issues?** Please open an issue on [GitHub](https://github.com/maxbeckers/php-openapi-tools/issues).

---

**Built with ❤️ for PHP developers**
