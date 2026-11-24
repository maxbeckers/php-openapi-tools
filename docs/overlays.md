# Applying overlays

The `overlay` command applies a **subset of Overlay 1.0.x, 1.1.x and 1.2.x** to one
YAML or JSON document and writes a new JSON document:

```shell
vendor/bin/openapi-tools overlay openapi.yaml openapi-overlaid.json --overlay first.yaml --overlay second.json
```

Overlays and actions are applied in argument order. The output must not already
exist and must be in the input document's directory. This keeps ordinary
relative file references relative to the same directory; references are not
resolved, rewritten, or bundled. A reference naming the original input file
still names that file, not the output. Review relative URI, `$id`, `$self` and
other document-identity semantics before using the output. Configure the
generator to use the resulting JSON file separately.

Targets and copy expressions are evaluated using the RFC 9535 mode of
`softcreatr/jsonpath`, including filters, recursive descent, unions, slices,
negative indexes and escaped member names. Invalid expressions fail explicitly.
An expression matching no target nodes is a no-op. This does not claim complete
Overlay conformance: the command still restricts some YAML aliases, `extends` and
other document-identity features as described below.

Overlay 1.0 targets must select objects or arrays. Object updates recursively
merge objects and concatenate arrays; incompatible value shapes fail. Array
updates append one value. Removals apply to unique locations, deepest first and
in descending array-index order to avoid index shifts.
For Overlay 1.1 and 1.2, primitive nodes can be replaced or removed, and an array update
is concatenated when the update itself is an array. `copy` selects exactly one
source node from the current document and snapshots it before updating the
targets. Update and copy targets must all have the same kind; an action combining
`update` and `copy` is rejected unless `remove` is true, in which case removal
takes precedence. An action without update, copy or removal is a no-op.
Overlay 1.2 supports reusable actions under `components.actions`, referenced
by same-document JSON Pointers such as `#/components/actions/name`. Component
fields are combined with action-reference fields, which take precedence;
component descriptions are not action descriptions. Overlay 1.2 `$self` supports
nonempty relative local paths and absolute HTTP(S) identities. Actions are processed sequentially. The library API mutates
its input document and can leave it partially modified on failure; the CLI does
not write output if an action fails. If an overlay declares `extends`, it must be
either a relative local URI path naming the input document or an HTTP(S)
identity matching the input document's absolute `$self`. Relative references
resolve against the overlay `$self` when present, otherwise the overlay file.
An absolute-path `extends` such as `/openapi/spec.json` is supported only when
the overlay `$self` is an absolute HTTP(S) identity; it resolves against that
identity's authority and must match the input's absolute `$self`. Network-path
references such as `//host/path` also require an absolute HTTP(S) overlay
`$self`; they inherit its scheme and must match the input's absolute `$self`.
URI dot segments
are resolved before filesystem lookup, including for nonexistent intermediate
directories. Percent-encoded filenames are decoded once; local paths reject
encoded separators and repeated slashes. All supported identities reject
malformed escapes, encoded dot segments, backslashes, queries, fragments and
unescaped non-URI characters. A relative local
`$self` on the original input can also identify it, even when the identity
path does not exist. This identity remains fixed across sequential overlays.
HTTP(S) identities are matched only against the supplied input's absolute
`$self`; they are never fetched. Hosts may be ASCII names, IPv4, or bracketed
IPv6 literals. Equivalent IPv6 address spellings are normalized when comparing
identities; zone identifiers, IPvFuture literals, userinfo, queries and
fragments are unsupported. Other absolute URI
schemes and absolute filesystem paths are unsupported. No network access is
performed. YAML scalar and noncyclic collection aliases are expanded into
independent JSON values. Unambiguous YAML merges, including merge sequences
and chains, are expanded with earlier merge sources taking precedence and
explicit keys overriding merged keys. Cycles, ambiguous reused collection
anchors, repeated or explicitly keyed merges, and other nodes with unavailable
collection metadata are rejected instead of guessing object versus array.
The command does not perform
full OpenAPI or Overlay validation; validate the result separately.

The command never overwrites an existing output, including an input or overlay.
It renders the entire result before creating the output, though an I/O failure
during writing can leave a partial newly created file. It requires the
tools package dependencies, including `maxbeckers/php-yaml-parser` 1.0.4 or
newer.
It does not add a runtime dependency to generated PHP.
