# ingot

[![CI](https://github.com/redgnar/ingot/actions/workflows/ci.yml/badge.svg)](https://github.com/redgnar/ingot/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.4-777BB4?logo=php&logoColor=white)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max%20%2B%20strict-brightgreen)](phpstan.neon.dist)
[![Mutation testing](https://img.shields.io/badge/Infection-covered%20MSI%20100%25-blue)](infection.json5)
[![License](https://img.shields.io/badge/license-MIT-lightgrey)](composer.json)

Typed handling of JSON definitions for PHP 8.4+: read a JSON document, optionally
validate it against a JSON Schema, and work with a fully typed PHP object tree —
no manual casting, no hand-written mapping code.

One pass, one error format: schema violations, type-mapping problems, and
semantic rule failures all land in a single report, each entry carrying an
absolute JSON Pointer, a machine-readable code, and the offending value.

## Installation

```
composer require ingot/ingot
```

## Quick start

```php
use Ingot\MapperBuilder;
use Ingot\Source;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}

final readonly class Person
{
    /** @param list<string> $tags */
    public function __construct(
        public string $name,
        public int $age,
        public Address $address,
        public array $tags = [],
        public ?string $nickname = null,
    ) {}
}

$mapper = MapperBuilder::create()->build();

// Throwing style — returns Person or throws MappingFailed with the full report
$person = $mapper->map(Person::class, Source::json($json));

// Result style — never throws for data errors
$result = $mapper->tryMap(Person::class, Source::json($json));

if (!$result->isSuccess()) {
    foreach ($result->errors() as $error) {
        // $error->pointer  '/address/city'
        // $error->code     'mapping.type'
        // $error->message  'Expected string, got int.'
    }
}

// And back: typed values → json_encode-ready data (lossless round-trip)
$data = $mapper->normalize($person);
```

## Working with JSON Schema

Bind a schema to a class once — every `map()`/`tryMap()` of that class then
validates the document first, and schema violations land in the same report
as mapping errors (JSON Pointer + `schema.<keyword>` code):

```php
use Ingot\Schema\Schema;

$mapper = MapperBuilder::create()
    ->withSchema(Person::class, Schema::fromFile(__DIR__ . '/schemas/person-1.0.json'))
    ->build();

$result = $mapper->tryMap(Person::class, Source::json('{"name": "Ada", "age": -1, "address": {}}'));

foreach ($result->errors() as $error) {
    // $error->pointer  '/age'
    // $error->code     'schema.minimum'
    // $error->message  'Number must be greater than or equal to 0'
}
```

### Bounding a date

Standard JSON Schema can say how long a string is and what it looks like, but
not that it falls inside a period: there is no `minimum` for strings. Ingot adds
the two keywords the ecosystem settled on, with the meaning
[ajv-formats](https://ajv.js.org/packages/ajv-formats.html) gives them, so one
document is enforced the same way here and in a browser:

```json
{
  "type": "string",
  "format": "date",
  "formatMinimum": "2026-01-01",
  "formatMaximum": "2026-12-31"
}
```

A date outside the range is reported as `schema.formatMinimum` or
`schema.formatMaximum`. Both keywords apply to `"format": "date"` only — full
dates sort as strings exactly as they sort in time, which is what makes the
comparison exact; timestamps with offsets do not, so a bound beside any other
format is refused when the schema is read, along with a bound that is not a
whole calendar date (`2026-02-30` included).

**Every finding points at the thing that is wrong**, and three keywords need help
with that. `required` and `additionalProperties` are reported by JSON Schema per
*object*, so they are unpacked into one finding per member — `/email` rather than
`''`. A member a subschema refused outright (`{"properties": {"nip": false}}`, the
shape a conditional document needs) is named the same way. And `anyOf` / `oneOf`
go the other way: their sub-errors are the roads not taken, so an alternative is
**one** finding at the value that fitted nothing, with what each shape wanted in
the message — reported per branch it would say "add `card`" *and* "add `transfer`"
about a value that needs one of them.

A refused document is also looked at more than once. opis reports a schema level
in phases and stops after the phase that failed, and `allOf` stops at the first
branch that did not hold; so each branch of a conjunction is asked on its own and
the answers merge, which is what makes a document with three independent problems
report three rather than one. Only on refusal — an accepted document pays
nothing — and never for a branch that names something in the document around it
(a `$ref`, `unevaluatedProperties`), which away from that document would be a
different question.

Schemas can also be resolved dynamically — by convention, from plugins, or by
document content (the versioning hook) — and overridden per call:

```php
$mapper = MapperBuilder::create()
    // decide per class (null = no schema, mapping proceeds with type checks only)
    ->withSchemaResolver(
        fn (string $class, mixed $document): ?Schema
            => $document instanceof \stdClass && isset($document->version)
                ? Schema::fromFile(__DIR__ . "/schemas/person-{$document->version}.json")
                : null,
    )
    ->build();

// a per-call override always wins over registered schemas:
$mapper->tryMap(Person::class, Source::json($json)->withSchema(Schema::fromDocument(true)));
```

The reverse direction — generate a JSON Schema (draft 2020-12) from the same
metadata the mapper reads, so the contract cannot drift from behavior. The
generated schema validates documents produced by `normalize()` and can be
shipped to other consumers (e.g. frontend validation with Ajv):

```php
use Ingot\SchemaGen\SchemaGenerator;

$schema = new SchemaGenerator()->generate(Person::class);

file_put_contents(
    'person.schema.json',
    json_encode($schema->document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
);
```

For documents whose shape exists only at runtime (no classes to map to),
validate against a schema and read values through `JsonNode`:

```php
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Tree\JsonNode;

$report = new OpisSchemaValidator()->validate(json_decode($raw), $schema);

if ($report->isEmpty()) {
    $node = JsonNode::of(Source::json($raw));
    $node->get('/customer/birthDate')->dateTime();
    $node->get('/items')->list();
}
```

## Features

- **Hybrid hydration** — constructor parameters first (invariants always run),
  then members the constructor does not cover are set via reflection: public,
  private, and uninitialized readonly properties alike.
- **Rich types** — nested objects, `list<T>` / `array<K, V>` via PHPDoc, backed
  enums, `DateTimeImmutable`, nullable vs optional (both semantics honored),
  strict by default with an opt-in lax coercion table.
- **Date ranges in schemas** — `formatMinimum` / `formatMaximum` beside
  `"format": "date"`, which standard JSON Schema cannot express, spelled the way
  ajv-formats spells them.
- **Validated formats** — `#[Format('uuid')]` (also `uri`, `email`, `date`,
  `date-time`) rejects non-matching strings with a `mapping.format` error;
  on `DateTimeImmutable` members it replaces PHP's lenient date parsing with
  strict RFC 3339 / full-date syntax, and the format flows into generated
  schemas and `normalize()` output.
- **Value constraints** — `#[Constraints(minLength: 3, pattern: '^[A-Z]{3}$', minimum: 0, minItems: 1, ...)]`
  declares the simple JSON Schema validation keywords (string lengths and
  patterns, numeric bounds and `multipleOf`, list item counts and uniqueness,
  map property counts) right on the member; the engine enforces them during
  hydration (`mapping.min_length`, `mapping.pattern`, …) and `SchemaGenerator`
  emits them into the generated schema verbatim, so both surfaces always agree.
- **Discriminated unions** — closed maps declared on the union root
  (`#[Discriminator('type', map: [...])]`), open plugin-registered variants
  (`withVariant()`), and a fallback for unknown variants (`withVariantFallback()`)
  that preserves the raw payload.
- **JSON Schema validation** — bind schemas to classes (`withSchema()`, dynamic
  resolvers, versioning by document content); validation is delegated to
  [opis/json-schema](https://github.com/opis/json-schema) and gates hydration.
- **Semantic validators** — plug rule classes into the mapper per target class
  (`withValidator()`); they receive fully hydrated, type-safe objects and report
  into the same error format.
- **Lossless round-trips** — `#[Extras]` collects unknown keys and `normalize()`
  merges them back flat; union variants re-emit their discriminator.
- **Schema generation** — `SchemaGenerator` emits JSON Schema draft 2020-12 from
  the same metadata the mapper reads, so the schema cannot drift from behavior.
- **Typed access without classes** — `JsonNode` navigates documents whose shape
  exists only at runtime, with the same pointer-carrying errors.
- **PSR-6 caching** — `withCache($pool)` shares mapper metadata across requests
  (bring your own pool, e.g. symfony/cache).

## Complete examples

[`examples/Forms`](examples/Forms) runs the whole pipeline end to end: a form
definition validated by a meta-schema and a semantic rule, hydrated into a
discriminated union (with a fallback preserving unknown plugin fields), a
**data schema derived from the definition** (shippable to the frontend),
submission validation, typed value access via `JsonNode`, and a lossless
save. The definition model carries `#[Constraints]` (pattern-checked ids,
non-empty unique select options, positive length limits) enforced at load
time even without the meta-schema. [`tests/Examples/FormsExampleTest.php`](tests/Examples/FormsExampleTest.php)
keeps it working.

[`examples/Workflow`](examples/Workflow) exercises the graph-shaped side: an
**open** discriminated union whose node types come from a runtime registry
(plugin territory), a fallback preserving unknown node types in lenient mode
vs a strict mode that rejects them, **referential integrity rules** (unique
node ids, no dangling edges) running on the hydrated document, and a lossless
round-trip including vendor extension keys. Node payloads are
constraint-validated (`method` against a pattern, delays bounded to 1–86400 s,
timeouts on a half-second grid, header maps with bounded sizes).
[`tests/Examples/WorkflowExampleTest.php`](tests/Examples/WorkflowExampleTest.php)
keeps it working.

## Attributes

| Attribute | Placement | Meaning |
|---|---|---|
| `#[Discriminator('type', map: [...])]` | union root | discriminator field + closed variant map |
| `#[Name('json_key')]` | parameter / property | JSON key differs from the member name |
| `#[Extras]` | one array member | bag for unknown keys (round-trip) |
| `#[Format('date-time')]` | parameter / property | validated syntax for string and date members: `date-time`, `date`, `uuid`, `uri`, `email` |
| `#[Constraints(...)]` | parameter / property | simple JSON Schema validation keywords, enforced by the engine and emitted into generated schemas: `minLength`/`maxLength`/`pattern` (strings), `minimum`/`maximum`/`exclusiveMinimum`/`exclusiveMaximum`/`multipleOf` (numbers), `minItems`/`maxItems`/`uniqueItems` (lists), `minProperties`/`maxProperties` (maps) |

## Development

Everything runs inside a pinned Docker image (`docker/Dockerfile`); local PHP is
not used. `docker-compose.yml` exposes the same image to PhpStorm.

```
make install   # composer install
make test      # PHPUnit
make ci        # the full pipeline: validate, cs, stan, deptrac, tests, audit, deps, mutation
make bench     # phpbench benchmarks
```

Quality gates: PHPStan level max with strict rules, php-cs-fixer (PER-CS),
Deptrac module boundaries, Infection mutation testing (covered-code MSI 100%),
composer audit and dependency-hygiene checks. CI runs the same gates on
PHP 8.4 and 8.5.

## Status

Pre-1.0, under active development. The public API may still change.
