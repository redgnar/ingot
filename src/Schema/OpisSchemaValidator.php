<?php

declare(strict_types=1);

namespace Ingot\Schema;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Ingot\Schema\Vocabulary\DateBoundsVocabulary;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

/**
 * SchemaValidator implementation delegating to opis/json-schema
 * (drafts 06, 07, 2019-09, 2020-12).
 *
 * Leaf errors from the opis error tree are translated into MappingErrors:
 * the error code is "schema.<keyword>" and the pointer targets the offending
 * value inside the validated document.
 *
 * Two keywords come from this library rather than from a draft:
 * `formatMinimum` and `formatMaximum` bound a `"format": "date"` or
 * `"format": "date-time"` string, which
 * standard JSON Schema cannot express at all ({@see DateBoundKeyword}).
 *
 * `properties` gets one too, for the opposite reason. A subschema that refuses a
 * member outright — `{"properties": {"nip": false}}`, which is how a schema says
 * "not this one, not here" — has nothing inside it to report, so opis raises
 * `properties` on the *owning object* and names the member in its arguments. At
 * the object's own pointer that finding says only "something in here is wrong";
 * at the member's it says which. Every other finding in this library points at
 * the thing that is wrong, so this one does too.
 *
 * `additionalProperties` gets one extra step. opis reports it once on the
 * owning object, listing every member it did not evaluate — and it stops
 * counting properties as evaluated as soon as one of them fails, so that list
 * arrives holding members the schema declares, the failing one and its innocent
 * siblings alike. Reported verbatim, a client would read "age is not a property
 * of this object" next to "age must be >= 18", and "email is not allowed here"
 * about a property the schema asked for. Here each member is reported at its own
 * pointer, and only those the failing schema does not declare are reported at
 * all.
 */
final class OpisSchemaValidator implements SchemaValidator
{
    /** The keyword opis raises for members an object schema did not evaluate. */
    private const string UNEXPECTED_MEMBERS = 'additionalProperties';

    /**
     * The keyword opis raises when a member's own subschema refused it without
     * reporting anything of its own — which is what a `false` subschema does.
     */
    private const string REFUSED_MEMBER = 'properties';

    private const string UNEXPECTED_MEMBERS_CODE = 'schema.' . self::UNEXPECTED_MEMBERS;

    private readonly Validator $validator;
    private readonly ErrorFormatter $formatter;
    private readonly SchemaDocumentPool $pool;

    public function __construct(int $maxErrors = 100)
    {
        // The parser is built here rather than taken by default because of the
        // extra vocabulary: `formatMinimum` and `formatMaximum` are what the
        // ecosystem uses to bound a date, and a schema carrying them should be
        // enforced, not silently half-read.
        $this->validator = new Validator(
            new SchemaLoader(new SchemaParser([], [], new DateBoundsVocabulary()), new SchemaResolver()),
            max_errors: $maxErrors,
            stop_at_first_error: false,
        );
        $this->formatter = new ErrorFormatter();
        $this->pool = new SchemaDocumentPool();
    }

    public function validate(mixed $document, Schema $schema): ErrorReport
    {
        // Content-identical schemas resolve to one canonical \stdClass, so the
        // opis loader's identity cache parses each distinct schema only once.
        $error = $this->validator->validate($document, $this->pool->canonical($schema))->error();

        if ($error === null) {
            return ErrorReport::none();
        }

        return ErrorReport::of(...$this->collectLeaves($error));
    }

    /**
     * @return list<MappingError>
     */
    private function collectLeaves(ValidationError $error): array
    {
        /** @var list<ValidationError> $subErrors opis/json-schema lacks generics in its PHPDoc */
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            return $this->translate($error);
        }

        $leaves = [];

        foreach ($subErrors as $subError) {
            $leaves = [...$leaves, ...$this->collectLeaves($subError)];
        }

        return $leaves;
    }

    /**
     * @return list<MappingError> one entry per finding — an unexpected-members
     *                            error yields one entry per member
     */
    private function translate(ValidationError $error): array
    {
        /** @var list<int|string> $path opis/json-schema lacks generics in its PHPDoc */
        $path = $error->data()->fullPath();

        if ($error->keyword() === self::UNEXPECTED_MEMBERS) {
            return $this->unexpectedMembers($error, $path);
        }

        if ($error->keyword() === 'required') {
            return self::missingMembers($error, $path);
        }

        if ($error->keyword() === self::REFUSED_MEMBER && \is_string($error->args()['property'] ?? null)) {
            return self::refusedMember($error, $path);
        }

        return [new MappingError(
            JsonPointer::fromSegments($path),
            \sprintf('schema.%s', $error->keyword()),
            $this->message($error),
            $error->data()->value(),
        )];
    }

    /**
     * opis words `const` as "The data must match the const value", which tells a
     * client the name of a JSON Schema keyword and not the one thing it needs:
     * which value was expected. It hands that value over in the error's
     * arguments, so this says it.
     */
    private function message(ValidationError $error): string
    {
        if ($error->keyword() !== 'const') {
            return $this->formatter->formatErrorMessage($error);
        }

        return \sprintf('The value must be %s.', json_encode($error->args()['const'] ?? null, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<int|string> $path pointer of the object carrying the members
     *
     * @return list<MappingError>
     */
    private function unexpectedMembers(ValidationError $error, array $path): array
    {
        /** @var list<string> $members opis names the members it did not evaluate */
        $members = $error->args()['properties'];
        /** @var array<string, mixed> $object the keyword only ever fires on objects */
        $object = (array) $error->data()->value();
        $declared = self::declaredMembers($error);
        $errors = [];

        foreach ($members as $member) {
            // opis stops counting properties as evaluated once one of them fails
            // its own subschema, so this list arrives holding members the schema
            // declares. Telling a client that a property it was asked for is
            // "not allowed" is worse than saying nothing: the real complaint is
            // the sibling that failed, and it is already in the report.
            if (\in_array($member, $declared, true)) {
                continue;
            }

            $errors[] = new MappingError(
                JsonPointer::fromSegments([...$path, $member]),
                self::UNEXPECTED_MEMBERS_CODE,
                \sprintf('The property "%s" is not allowed here.', $member),
                $object[$member],
            );
        }

        return $errors;
    }

    /**
     * A member its own subschema refused, reported under its own name.
     *
     * `{"properties": {"nip": false}}` is how a schema says a member must not be
     * there — a condition's `else` branch is the shape that needs it — and a
     * `false` subschema has nothing inside it to raise a finding, so opis raises
     * `properties` on the object and puts the member's name in the arguments.
     * Left there, a page would have a message and nowhere to put it.
     *
     * Only the single-member form is unpacked here: opis uses the same keyword
     * for the *parent* of a member that failed its own subschema, and that one
     * arrives with sub-errors, so it never reaches this method.
     *
     * @param list<int|string> $path pointer of the object carrying the member
     *
     * @return list<MappingError>
     */
    private static function refusedMember(ValidationError $error, array $path): array
    {
        /** @var string $member opis names the member its subschema refused */
        $member = $error->args()['property'];
        /** @var array<string, mixed> $object the keyword only ever fires on objects */
        $object = (array) $error->data()->value();

        return [new MappingError(
            JsonPointer::fromSegments([...$path, $member]),
            'schema.' . self::REFUSED_MEMBER,
            \sprintf('The property "%s" is not allowed here.', $member),
            $object[$member] ?? null,
        )];
    }

    /**
     * A missing member is reported under its own name, one finding per member.
     *
     * opis says it once for the object — "the required properties (a, b) are
     * missing" at the object's pointer — which tells a client where to look and
     * not what to look at. Every other finding points at the thing that is
     * wrong, so this one does too: `/lines/1/sku` rather than `/lines/1`. That
     * is what lets a page put the message beside the control that has to be
     * filled in, and it is the same shape as an unexpected member, which is the
     * mirror image of this rule.
     *
     * @param list<int|string> $path pointer of the object missing them
     *
     * @return list<MappingError>
     */
    private static function missingMembers(ValidationError $error, array $path): array
    {
        /** @var list<string> $members opis names the members it did not find */
        $members = $error->args()['missing'] ?? [];
        $errors = [];

        foreach ($members as $member) {
            $errors[] = new MappingError(
                JsonPointer::fromSegments([...$path, $member]),
                'schema.required',
                \sprintf('"%s" is required.', $member),
                null,
            );
        }

        return $errors;
    }

    /**
     * The members the failing object schema declares, taken from that schema
     * itself rather than guessed from the error tree.
     *
     * @return list<int|string>
     */
    private static function declaredMembers(ValidationError $error): array
    {
        $schema = $error->schema()->info()->data();
        $properties = $schema instanceof \stdClass ? $schema->properties ?? null : null;

        return $properties instanceof \stdClass ? array_keys((array) $properties) : [];
    }

}
