# Bundling local references

```shell
vendor/bin/openapi-tools bundle openapi.yaml openapi-bundle.json
```

The command writes a standalone JSON document with reachable local reference
targets preserved as references. External source documents are embedded under
`x-openapi-generator-bundle.documents` using paths relative to the input's
directory as stable keys; reference fragments are rewritten to JSON Pointers
into those documents. Shared targets, cycles, and same-named targets in
different files remain distinct. Source paths and available YAML and JSON line and column
positions are recorded in the extension. Data such as examples and extensions
is not interpreted as reference-bearing OpenAPI structure.
Names such as `$ref` inside schema property and definition maps are treated as
map keys; references within their schema values are still followed.
Local Link `operationRef` values using JSON Pointer fragments are rewritten
to the bundled operation, and its reachable references are processed as well.
Discriminator `mapping` values may name a schema in the entry document's
`components.schemas`, use an explicit local JSON Pointer fragment, or use an
unambiguous relative local URI identifying a whole document. These
are rewritten to bundled schema targets, including their reachable references.
Names from referenced documents resolve against the entry document. A
single-segment value such as `cat.json` is treated as a schema name even if a
local file exists; use `./cat.json` or `cat.json#` to refer to the file.
Missing schema names are rejected rather than probed as files.
OpenAPI 3.2 discriminator `defaultMapping` supports the same bounded target
forms and traversal. The generator still rejects `defaultMapping` because it
does not implement its runtime behavior.
For OpenAPI 3.2, traversal includes additional operations, reusable media
types, item schemas, and nested positional or named encodings. Generator
support for OpenAPI 3.2 features is separate from bundling.
Reference resolution failures identify the referring source file and JSON
Pointer; line numbers are not available.

The generator can consume bundles for the same local reference forms supported
by its resolver, including recursive component schemas. Imported component
names retain their original source-file identities. Diagnostics for embedded
content point into the bundle extension. `BundleSourceMap::locate()` maps such
JSON Pointers to the original relative source path, pointer, and recorded
one-based line and column without reading the original files. JSON columns count
UTF-8 bytes; object properties are located at their keys and array items at
their values. Only exact positions are returned, not approximations; missing
positions are `null`. This also works for single-document bundles. Source-map
metadata is informational and can be altered like any
other bundle content; it does not authenticate the original files. Generator
diagnostics retain bundle locations and include recorded source locations as
informational details when available.
Other tools must support JSON
Pointer references into extensions to consume the bundle.

Bundled recursive schemas outside `components` can be generated when each
recursive target is directly exposed by a root `components.schemas` entry
containing only its `$ref`. For example, a root `Node` entry may reference a
recursive external `#/$defs/Node` or a non-component schema in the entry
document. Recursive targets without such an alias, and
boolean schema targets or boolean source documents, are rejected by the
generator rather than silently dropped. Bundled discriminator targets outside
`components.schemas`, including whole-document targets, also need a direct root
schema alias for generator dispatch; otherwise the generator rejects them.
Bundled specifications must not contain external file references; references
in literal examples and extensions are data and are not followed.

Only local files within the input directory are permitted, with empty or JSON
Pointer fragments. Symlinks resolving outside that directory, remote URIs,
anchors and malformed or missing targets are rejected. No network access is
performed. Schema `$id`, `$schema`, anchor and dynamic/recursive reference
keywords, OpenAPI `$self`, and example
`externalValue` are rejected in reachable reference-bearing contexts; their
URI semantics are not yet preserved. YAML scalar and noncyclic collection
aliases and unambiguous YAML merges are expanded; cycles, ambiguous reused
collection anchors, repeated or explicitly keyed merges, and unavailable YAML
node kinds are rejected. Alias and merge descendants have no source line or
column at their expanded locations. The command
does not claim complete OpenAPI or JSON
Schema validation; validate inputs separately.

The output must have a `.json` extension and must not already exist. Rendering
completes before output is created, but an I/O failure can leave a partial newly
created file. Results are deterministic for the same input documents. The
command requires the tools package dependencies, including YAML parser 1.0.4 or
newer; it adds no dependency to generated PHP.
