<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema;

use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpisSchemaValidator::class)]
final class OpisSchemaValidatorTest extends TestCase
{
    public function testValidDocumentProducesAnEmptyReport(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "required": ["id"], "properties": {"id": {"type": "string"}}}');
        $document = $this->decode('{"id": "form-1"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testAMissingRequiredPropertyIsReportedUnderItsOwnName(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "required": ["id"]}');
        $document = $this->decode('{}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN it points at the member, not at the object around it: every
        // other finding names the thing that is wrong, and a client that has to
        // show this beside a control needs to know which one
        self::assertCount(1, $report);
        $error = $report->errors[0];
        self::assertSame('schema.required', $error->code);
        self::assertSame('/id', $error->pointer->toString());
        self::assertSame('"id" is required.', $error->message);
    }

    public function testEveryMissingPropertyIsItsOwnFinding(): void
    {
        // GIVEN an object owing three things and given one
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "lines": {
                        "type": "array",
                        "items": {"type": "object", "required": ["sku", "quantity", "unit"]}
                    }
                }
            }
            JSON);
        $document = $this->decode('{"lines": [{"sku": "A-1"}]}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN each is named where it belongs, so a client can mark all three at
        // once rather than being told the entry is incomplete
        self::assertSame(
            ['/lines/0/quantity', '/lines/0/unit'],
            array_map(static fn($error): string => $error->pointer->toString(), $report->errors),
        );
    }

    public function testNestedTypeViolationCarriesTheFullJsonPointer(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "fields": {
                        "type": "array",
                        "items": {
                            "type": "object",
                            "properties": {"name": {"type": "string"}}
                        }
                    }
                }
            }
            JSON);
        $document = $this->decode('{"fields": [{"name": "ok"}, {"name": 42}]}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        $error = $report->errors[0];
        self::assertSame('schema.type', $error->code);
        self::assertSame('/fields/1/name', $error->pointer->toString());
        self::assertSame(42, $error->input);
    }

    public function testCollectsMultipleErrorsInOneReport(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "required": ["id"],
                "properties": {
                    "title": {"type": "string"},
                    "count": {"type": "integer"}
                }
            }
            JSON);
        $document = $this->decode('{"title": 7, "count": "many"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN all problems are aggregated, not just the first one
        $codes = array_map(static fn($error): string => $error->code, $report->errors);
        sort($codes);
        self::assertSame(['schema.required', 'schema.type', 'schema.type'], $codes);
    }

    public function testAnUnexpectedMemberIsReportedWhereItSits(): void
    {
        // GIVEN a closed object schema
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"id": {"type": "string"}}, "additionalProperties": false}');
        $document = $this->decode('{"id": "form-1", "bogus": 1, "other": 2}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN each unexpected member is named at its own pointer, carrying the
        // value that was not asked for — not one lump on the owning object
        self::assertCount(2, $report);
        self::assertSame('/bogus', $report->errors[0]->pointer->toString());
        self::assertSame('schema.additionalProperties', $report->errors[0]->code);
        self::assertSame(1, $report->errors[0]->input);
        self::assertSame('/other', $report->errors[1]->pointer->toString());
    }

    public function testAMemberItsOwnSubschemaRefusedIsReportedWhereItSits(): void
    {
        // GIVEN a schema that refuses one member outright — `false` is how a
        // schema says "not this one, not here", and it is the shape a condition's
        // `else` branch needs
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"id": {"type": "string"}, "nip": false}}');
        $document = $this->decode('{"id": "form-1", "nip": "1234567890"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN it is named at its own pointer, carrying the value that was not
        // allowed. A `false` subschema has nothing inside it to report, so opis
        // raises `properties` on the owning object and names the member in its
        // arguments; left there, whoever reads this would have a complaint and
        // nowhere to put it
        self::assertCount(1, $report);
        self::assertSame('/nip', $report->errors[0]->pointer->toString());
        self::assertSame('schema.properties', $report->errors[0]->code);
        self::assertSame('1234567890', $report->errors[0]->input);
    }

    public function testARefusedMemberInsideAConditionKeepsItsFullPointer(): void
    {
        // GIVEN the shape a conditional definition derives: this member is asked
        // only when another answer says so, and must be absent otherwise
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{
            "type": "object",
            "properties": {
                "lines": {"type": "array", "items": {
                    "type": "object",
                    "properties": {"kind": {"enum": ["dent", "other"]}, "why": {"type": "string"}},
                    "allOf": [{
                        "if": {"properties": {"kind": {"const": "other"}}, "required": ["kind"]},
                        "then": {"required": ["why"]},
                        "else": {"properties": {"why": false}}
                    }]
                }}
            }
        }');
        $document = $this->decode('{"lines": [{"kind": "other"}, {"kind": "dent", "why": "nothing"}]}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN both findings name the entry they are about, which is what lets
        // somebody put a message in the right row of a list
        $found = [];

        foreach ($report->errors as $error) {
            $found[] = $error->pointer->toString() . ' ' . $error->code;
        }

        self::assertSame(['/lines/0/why schema.required', '/lines/1/why schema.properties'], $found);
    }

    public function testOnlyTheKeywordThatRefusesAMemberIsUnpackedThatWay(): void
    {
        // GIVEN a schema whose complaint is about a *different* member than the
        // one it names: `dependentRequired` says "since `a` is here, `b` has to
        // be", and opis reports it with `a` in its arguments — the same argument
        // name a refused member arrives under
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "dependentRequired": {"a": ["b"]}}');
        $document = $this->decode('{"a": 1}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN it is left as it came. Unpacking it would put "the property a is
        // not allowed here" on a member the schema asked for, about a member that
        // is missing — the argument's name is not enough to know what a finding
        // means
        self::assertCount(1, $report);
        self::assertSame('', $report->errors[0]->pointer->toString());
        self::assertSame('schema.dependentRequired', $report->errors[0]->code);
    }

    public function testAMemberThatBrokeItsOwnRuleIsNotAlsoCalledUnexpected(): void
    {
        // GIVEN a declared member with a value its subschema refuses. opis
        // counts a failed member as one it never evaluated, so it appears in
        // the additionalProperties list as well.
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"age": {"type": "number", "minimum": 18}}, "additionalProperties": false}');
        $document = $this->decode('{"age": 7}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN the report names the rule that was broken, and nothing else
        self::assertCount(1, $report);
        self::assertSame('/age', $report->errors[0]->pointer->toString());
        self::assertSame('schema.minimum', $report->errors[0]->code);
    }

    public function testAMemberSittingBesideABrokenOneIsNotCalledUnexpectedEither(): void
    {
        // GIVEN three declared members, one of which breaks its own rule. opis
        // stops counting *any* property as evaluated once one fails, so its
        // innocent siblings arrive in the additionalProperties list too.
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "email": {"type": "string"},
                    "country": {"enum": ["pl", "de"]},
                    "terms": {"const": true}
                },
                "additionalProperties": false
            }
            JSON);
        $document = $this->decode('{"email": "ada@example.com", "country": "pl", "terms": false}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN one complaint, about the member that actually broke a rule —
        // telling a client that a property it was asked for is "not allowed"
        // sends it looking in the wrong place entirely
        self::assertCount(1, $report);
        self::assertSame('/terms', $report->errors[0]->pointer->toString());
        self::assertSame('schema.const', $report->errors[0]->code);
    }

    public function testAnUndeclaredMemberIsStillReportedBesideABrokenOne(): void
    {
        // GIVEN a member that broke its rule and one nobody declared
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"age": {"type": "number", "minimum": 18}}, "additionalProperties": false}');
        $document = $this->decode('{"age": 7, "bogus": 1}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN both are reported: suppressing the whole keyword because
        // something else failed would hide a real mistake
        self::assertCount(2, $report);
        self::assertSame(['/age', '/bogus'], array_map(static fn($error): string => $error->pointer->toString(), $report->errors));
        self::assertSame('schema.additionalProperties', $report->errors[1]->code);
    }

    public function testAConstRefusalSaysWhichValueWasExpected(): void
    {
        // GIVEN a schema that accepts exactly one value — a consent box, a fixed
        // version, a discriminator
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"terms": {"const": true}, "kind": {"const": "invoice"}}}');
        $document = $this->decode('{"terms": false, "kind": "receipt"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN the message names the value, not the keyword: "must match the
        // const value" tells a client the name of a rule and nothing about how
        // to satisfy it
        self::assertSame('schema.const', $report->errors[0]->code);
        self::assertSame('The value must be true.', $report->errors[0]->message);
        self::assertSame('The value must be "invoice".', $report->errors[1]->message);
    }

    public function testASchemaThatDeclaresNothingRefusesEveryMember(): void
    {
        // GIVEN an object schema with no properties at all: everything sent is
        // additional, and there is nothing declared to spare
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "additionalProperties": false}');
        $document = $this->decode('{"anything": 1}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        self::assertSame('/anything', $report->errors[0]->pointer->toString());
        self::assertSame('schema.additionalProperties', $report->errors[0]->code);
    }

    public function testUnexpectedMembersOfANestedObjectKeepTheirFullPointer(): void
    {
        // GIVEN a closed object nested inside another
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "author": {
                        "type": "object",
                        "properties": {"name": {"type": "string"}},
                        "additionalProperties": false
                    }
                }
            }
            JSON);
        $document = $this->decode('{"author": {"name": "Ada", "nickname": "the countess"}}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        self::assertSame('/author/nickname', $report->errors[0]->pointer->toString());
        self::assertSame('schema.additionalProperties', $report->errors[0]->code);
    }

    public function testBooleanFalseSchemaRejectsEverything(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromDocument(false);

        // WHEN
        $report = $validator->validate($this->decode('{"any": "thing"}'), $schema);

        // THEN
        self::assertFalse($report->isEmpty());
    }

    public function testBooleanTrueSchemaAcceptsEverything(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromDocument(true);

        // WHEN
        $report = $validator->validate($this->decode('{"any": "thing"}'), $schema);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
    }
}
