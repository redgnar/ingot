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
 * `anyOf` and `oneOf` get the opposite treatment to everything else here. Their
 * sub-errors are the roads *not* taken: a document that had to match one of two
 * shapes and matched neither is not two problems, and reported as two it says
 * "add `card`" and "add `transfer`" about a value that needs one of them. So an
 * alternative is **one** finding, at the value no shape fitted, and what each
 * shape wanted goes into the message — where a reader can weigh them, which is
 * the only thing anybody can do with alternatives.
 *
 * And a refused document is looked at more than once, because opis reports a
 * schema level in *phases* — the keywords of one phase together, then nothing
 * more once a phase has failed — and `allOf` stops at the first branch that
 * did not hold. So a document missing a member never heard about the
 * obligations an `allOf` branch would have named, and a document failing two
 * branches heard about one. Every branch of a conjunction is an independent
 * obligation, so each is asked on its own and the answers are merged
 * ({@see conjunction()}). Only when the document is refused: an accepted one
 * has nothing to report and costs exactly what it costs today.
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
     * The two keywords whose sub-errors are alternatives rather than problems.
     *
     * `allOf` is deliberately not here: every one of its branches has to hold,
     * so each that did not is a finding of its own ({@see conjunction()}).
     */
    private const array ALTERNATIVES = ['anyOf', 'oneOf'];

    /** How many alternatives a message names before it stops: a reader who cannot weigh five will not weigh twenty. */
    private const int ALTERNATIVES_NAMED = 5;

    /**
     * The keyword opis raises when a member's own subschema refused it without
     * reporting anything of its own — which is what a `false` subschema does.
     */
    private const string REFUSED_MEMBER = 'properties';

    private const string UNEXPECTED_MEMBERS_CODE = 'schema.' . self::UNEXPECTED_MEMBERS;

    /**
     * How deep a chain of conjunctions is followed. `allOf` inside `allOf` is
     * ordinary in a generated schema; a hundred levels of it is not, and a
     * refused document is not the moment to find out.
     */
    private const int MAX_CONJUNCTION_DEPTH = 10;

    private readonly Validator $validator;
    private readonly ErrorFormatter $formatter;
    private readonly SchemaDocumentPool $pool;
    private readonly int $maxErrors;

    public function __construct(int $maxErrors = 100)
    {
        $this->maxErrors = $maxErrors;
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
        $findings = $this->findings($document, $this->pool->canonical($schema), 0);

        return $findings === [] ? ErrorReport::none() : ErrorReport::of(...$findings);
    }

    /**
     * Everything wrong with this document under this schema — the first answer,
     * plus what each branch of a conjunction says when asked on its own.
     *
     * A branch of an `allOf` applies to the same instance as the schema holding
     * it, which is what makes this a second *question* rather than a second
     * traversal: nothing has to be re-pointed, because the answers already come
     * back at the same absolute pointers.
     *
     * @return list<MappingError>
     */
    private function findings(mixed $document, \stdClass|bool $schema, int $depth): array
    {
        $error = $this->validator->validate($document, $schema)->error();

        if ($error === null) {
            return [];
        }

        $findings = $this->collectLeaves($error);
        $branches = self::conjunction($schema, $depth);

        // The ceiling bounds the work as well as the report: a document that has
        // already said enough is not asked the rest.
        while ($branches !== [] && \count($findings) < $this->maxErrors) {
            $findings = self::merge($findings, $this->findings($document, array_shift($branches), $depth + 1));
        }

        return $findings;
    }

    /**
     * The branches worth asking on their own: the members of an `allOf`, which
     * is the one applicator whose every branch has to hold — so every branch
     * that did not hold is a finding somebody has to act on. `anyOf` and
     * `oneOf` are left alone on purpose: there a branch failing is not a
     * failure at all, and reporting one would be reporting the road not taken.
     *
     * A branch is skipped when it depends on the document around it
     * ({@see standsOnItsOwn()}), because then the answer to it alone would be
     * an answer to a different question. What the schema *holding* the branches
     * says needs no such check: asking a branch cannot make it more permissive,
     * so a parent's own `unevaluatedProperties` — or anything else it carries —
     * has no bearing on whether its branches held.
     *
     * @return list<\stdClass>
     */
    private static function conjunction(\stdClass|bool $schema, int $depth): array
    {
        if ($depth >= self::MAX_CONJUNCTION_DEPTH || !$schema instanceof \stdClass) {
            return [];
        }

        /** @var mixed $allOf */
        $allOf = $schema->allOf ?? null;

        if (!\is_array($allOf)) {
            return [];
        }

        $branches = [];

        /** @var mixed $branch */
        foreach ($allOf as $branch) {
            if ($branch instanceof \stdClass && self::standsOnItsOwn($branch)) {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * Whether this subschema means the same thing away from the document it was
     * written in.
     *
     * Anything spelled with a `$` is resolved against that document — a `$ref`
     * naming a `$defs` entry, an `$id` that moves the base URI, an anchor — and
     * `unevaluatedProperties` / `unevaluatedItems` are answered by annotations
     * the surroundings collected. A branch carrying either, anywhere inside it,
     * is left to opis to report the way it always did: an incomplete answer is
     * a great deal better than a wrong one.
     */
    private static function standsOnItsOwn(\stdClass $branch): bool
    {
        /** @var mixed $value */
        foreach (get_object_vars($branch) as $key => $value) {
            if (str_starts_with((string) $key, '$') || $key === 'unevaluatedProperties' || $key === 'unevaluatedItems') {
                return false;
            }

            foreach ($value instanceof \stdClass ? [$value] : (\is_array($value) ? $value : []) as $nested) {
                if ($nested instanceof \stdClass && !self::standsOnItsOwn($nested)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The first answer, then whatever a branch added to it. A branch that
     * repeats what has already been said adds nothing: the same pointer, the
     * same code and the same value is the same finding, however many ways there
     * were to arrive at it.
     *
     * @param list<MappingError> $findings
     * @param list<MappingError> $more
     *
     * @return list<MappingError>
     */
    private static function merge(array $findings, array $more): array
    {
        foreach ($more as $finding) {
            if (!self::alreadySaid($findings, $finding)) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param list<MappingError> $findings
     */
    private static function alreadySaid(array $findings, MappingError $candidate): bool
    {
        foreach ($findings as $finding) {
            if (self::fingerprint($finding) === self::fingerprint($candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function fingerprint(MappingError $finding): string
    {
        return \sprintf(
            '%s %s %s',
            $finding->pointer->toString(),
            $finding->code,
            json_encode($finding->input, \JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }

    /**
     * @return list<MappingError>
     */
    private function collectLeaves(ValidationError $error): array
    {
        /** @var list<ValidationError> $subErrors opis/json-schema lacks generics in its PHPDoc */
        $subErrors = $error->subErrors();

        // An alternative is one complaint about one value, and its sub-errors are
        // what each shape would have wanted — not things to fix.
        if (\in_array($error->keyword(), self::ALTERNATIVES, true)) {
            return [$this->alternative($error, $subErrors)];
        }

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
     * A value no shape fitted, as one finding.
     *
     * The pointer is the value itself, because that is the thing that is wrong:
     * nothing is missing at `/card` — the *payment* is what does not match
     * anything on offer. What each shape wanted is named in the message, in the
     * order the schema offers them, so a caller can choose one; a `oneOf` that
     * matched more than one says that instead, since then the problem is not
     * that nothing fits but that the document is ambiguous.
     *
     * @param list<ValidationError> $alternatives
     */
    private function alternative(ValidationError $error, array $alternatives): MappingError
    {
        /** @var list<int|string> $path */
        $path = $error->data()->fullPath();
        $matched = $error->args()['matched'] ?? [];

        return new MappingError(
            JsonPointer::fromSegments($path),
            \sprintf('schema.%s', $error->keyword()),
            // opis names the alternatives that matched, and it names them only
            // when more than one did — so anything there at all is the ambiguity
            // rather than a count to compare.
            \is_array($matched) && $matched !== []
                ? \sprintf('The value matches %d of the alternatives, and it may match only one.', \count($matched))
                : $this->whatEachAlternativeWanted($alternatives),
            $error->data()->value(),
        );
    }

    /**
     * @param list<ValidationError> $alternatives
     */
    private function whatEachAlternativeWanted(array $alternatives): string
    {
        $wanted = [];

        foreach (\array_slice($alternatives, 0, self::ALTERNATIVES_NAMED) as $index => $alternative) {
            $said = [];

            foreach ($this->collectLeaves($alternative) as $leaf) {
                // Without the trailing stop, because these are joined into one
                // sentence and "is required.; (2)" reads like a typo.
                $said[] = \sprintf('%s %s', $leaf->pointer->toString(), rtrim($leaf->message, '.'));
            }

            $wanted[] = \sprintf('(%d) %s', $index + 1, implode(' ', $said));
        }

        $more = \count($alternatives) - \count($wanted);

        return \sprintf(
            'The value matches none of the %d alternatives: %s%s',
            \count($alternatives),
            implode('; ', $wanted),
            $more > 0 ? \sprintf('; and %d more', $more) : '',
        );
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
