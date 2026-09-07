<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema;

use Ingot\Error\MappingError;
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

    public function testAMissingMemberDoesNotHideWhatAConditionWouldHaveAsked(): void
    {
        // GIVEN a schema with an obligation of its own and one that a condition
        // brings about — the shape a generated schema has, and a document that
        // fails both
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "kind": {"const": "company"},
                    "name": {"type": "string"},
                    "taxNumber": {"type": "string"}
                },
                "required": ["name"],
                "allOf": [
                    {
                        "if": {"required": ["kind"], "properties": {"kind": {"const": "company"}}},
                        "then": {"required": ["taxNumber"]}
                    }
                ]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"kind": "company"}'), $schema);

        // THEN both are reported. opis takes a schema level in phases and stops
        // after the phase that failed, so the missing `name` used to be the
        // whole answer — and a client fixing it would then be told about
        // `taxNumber`, one obligation per attempt
        self::assertCount(2, $report);
        self::assertSame(['/name', '/taxNumber'], array_map(
            static fn(MappingError $error): string => $error->pointer->toString(),
            $report->errors,
        ));
        self::assertSame(['schema.required', 'schema.required'], array_map(
            static fn(MappingError $error): string => $error->code,
            $report->errors,
        ));
    }

    public function testEveryBranchOfAConjunctionThatDidNotHoldIsReported(): void
    {
        // GIVEN two obligations, each under a condition of its own, and a
        // document that brings both about and answers neither
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "company": {"type": "boolean"},
                    "reported": {"type": "boolean"},
                    "taxNumber": {"type": "string"},
                    "caseNumber": {"type": "string"}
                },
                "allOf": [
                    {
                        "if": {"required": ["company"], "properties": {"company": {"const": true}}},
                        "then": {"required": ["taxNumber"]}
                    },
                    {
                        "if": {"required": ["reported"], "properties": {"reported": {"const": true}}},
                        "then": {"required": ["caseNumber"]}
                    }
                ]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"company": true, "reported": true}'), $schema);

        // THEN both, and each once: `allOf` itself stops at the first branch
        // that did not hold, so the second used to be invisible until the first
        // was answered
        self::assertCount(2, $report);
        self::assertSame(['/taxNumber', '/caseNumber'], array_map(
            static fn(MappingError $error): string => $error->pointer->toString(),
            $report->errors,
        ));
    }

    public function testAConjunctionInsideAConjunctionIsAskedToo(): void
    {
        // GIVEN a branch that is itself a conjunction — which is what a
        // generated schema looks like once a rule is composed out of two
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {"a": {"type": "string"}, "b": {"type": "string"}},
                "allOf": [
                    {"allOf": [{"required": ["a"]}, {"required": ["b"]}]}
                ]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN
        self::assertCount(2, $report);
        self::assertSame(['/a', '/b'], array_map(
            static fn(MappingError $error): string => $error->pointer->toString(),
            $report->errors,
        ));
    }

    public function testAnAlternativeIsNotTakenApart(): void
    {
        // GIVEN a document that has to match one of two shapes and matches
        // neither
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "anyOf": [{"required": ["a"]}, {"required": ["b"]}]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN one finding, about the value that fits nothing — and not one per
        // branch. A branch of an `anyOf` that did not hold is a road not taken:
        // reported as a finding of its own it says "add `a`" *and* "add `b`"
        // about a document that needs one of them, which is two instructions
        // that are each wrong
        self::assertCount(1, $report);
        self::assertSame('', $report->errors[0]->pointer->toString());
        self::assertSame('schema.anyOf', $report->errors[0]->code);
        // What each alternative wanted is in the message, where a reader can
        // weigh them: the only thing anybody can do with alternatives
        self::assertSame(
            'The value matches none of the 2 alternatives: (1) /a "a" is required; (2) /b "b" is required',
            $report->errors[0]->message,
        );
    }

    public function testABranchThatNamesSomethingInTheDocumentAroundItIsLeftAlone(): void
    {
        // GIVEN a branch whose rule lives in `$defs` — which is resolved against
        // the document it was written in, so the branch means nothing on its own
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "$defs": {"named": {"required": ["name"]}},
                "required": ["kind"],
                "allOf": [{"$ref": "#/$defs/named"}]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN what opis said, and nothing invented: an incomplete answer is a
        // great deal better than one arrived at by asking a different question
        self::assertCount(1, $report);
        self::assertSame('/kind', $report->errors[0]->pointer->toString());
    }

    public function testABranchWhoseSurroundingsDecideWhatWasEvaluatedIsLeftAlone(): void
    {
        // GIVEN a branch carrying `unevaluatedProperties`, which is answered by
        // annotations the *surroundings* collected: here the `name` its parent
        // declares. Alone, the branch sees nothing evaluated
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "required": ["kind"],
                "properties": {"name": {"type": "string"}},
                "allOf": [{"unevaluatedProperties": false}]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"name": "Ada"}'), $schema);

        // THEN one finding, opis's own — asked on its own, that branch would
        // have called `name` a property that is not allowed
        self::assertCount(1, $report);
        self::assertSame('schema.required', $report->errors[0]->code);
        self::assertSame('/kind', $report->errors[0]->pointer->toString());
    }

    public function testTheSameHoldsForAListAndItsUnevaluatedItems(): void
    {
        // GIVEN the same thing about a list
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "array",
                "minItems": 3,
                "prefixItems": [{"type": "string"}],
                "allOf": [{"unevaluatedItems": false}]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('["a"]'), $schema);

        // THEN one finding, and not a word about the item the branch on its own
        // would have called unevaluated
        self::assertCount(1, $report);
        self::assertSame('schema.minItems', $report->errors[0]->code);
    }

    public function testABranchThatDecidesForItselfWhatWasEvaluatedIsLeftAloneToo(): void
    {
        // GIVEN a branch carrying `unevaluatedProperties` — inside the branch
        // this time, where taking it out of its `allOf` changes which
        // annotations it can see
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "required": ["kind"],
                "properties": {"name": {"type": "string"}},
                "allOf": [{"unevaluatedProperties": false}]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"name": "Ada"}'), $schema);

        // THEN what opis said, and nothing arrived at by asking the branch a
        // question it cannot answer alone
        self::assertCount(1, $report);
        self::assertSame('/kind', $report->errors[0]->pointer->toString());
    }

    public function testABranchIsLeftAloneWhereverInsideItTheReferenceSits(): void
    {
        // GIVEN two branches naming rules out of `$defs` — one inside a list of
        // subschemas, one under a keyword — each asking for a different member,
        // so a branch asked by mistake would be heard
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "$defs": {
                    "named": {"required": ["name"]},
                    "aged": {"required": ["age"]}
                },
                "required": ["id"],
                "allOf": [
                    {"allOf": [{"$ref": "#/$defs/named"}]},
                    {"if": {"required": ["kind"]}, "then": {"$ref": "#/$defs/aged"}}
                ]
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"kind": "x"}'), $schema);

        // THEN the missing `id` is the whole answer: neither branch was asked.
        // A reference means what the document around it says, and whether it
        // even resolves away from that document depends on what the validator
        // happens to have parsed already — which must never decide whether
        // somebody's document is refused
        self::assertCount(1, $report);
        self::assertSame('/id', $report->errors[0]->pointer->toString());
    }

    public function testWhatABranchRepeatsIsSaidOnce(): void
    {
        // GIVEN a single branch that is also the first thing to fail, so the
        // first answer and the branch's own answer are the same finding
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "allOf": [{"required": ["a"]}]}');

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN
        self::assertCount(1, $report);
        self::assertSame('/a', $report->errors[0]->pointer->toString());
        self::assertSame('schema.required', $report->errors[0]->code);
    }

    public function testAChainOfConjunctionsIsFollowedOnlySoFar(): void
    {
        // GIVEN a schema whose conjunctions nest twelve deep, each level asking
        // for a member of its own. Branches multiply, so a chain nobody bounded
        // is a document that costs more to refuse the deeper somebody wrote it
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(self::nestedConjunctions(12));

        // WHEN a document answering none of them is judged
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN eleven levels are named — the ten that were followed, and the
        // one they arrived at — and the twelfth is left to the attempt after
        // this one, which is what a ceiling is for
        self::assertCount(11, $report);
        self::assertSame('/m0', $report->errors[0]->pointer->toString());
        self::assertSame('/m10', $report->errors[10]->pointer->toString());
    }

    public function testTheReportStopsAtTheCeilingItWasGiven(): void
    {
        // GIVEN a validator told to collect one finding, and a document that
        // fails two independent obligations
        $validator = new OpisSchemaValidator(maxErrors: 1);
        $schema = Schema::fromJson('{"type": "object", "allOf": [{"required": ["a"]}, {"required": ["b"]}]}');

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN the second branch is not asked at all: the ceiling is a ceiling
        // on work as much as on findings
        self::assertCount(1, $report);
        self::assertSame('/a', $report->errors[0]->pointer->toString());
    }

    /**
     * `{"required": ["m0"], "allOf": [{"required": ["m1"], "allOf": [ … ]}]}` —
     * a conjunction per level, each asking for a member named after its depth.
     */
    private static function nestedConjunctions(int $levels): string
    {
        $schema = \sprintf('{"required": ["m%d"]}', $levels - 1);

        for ($level = $levels - 2; $level >= 0; --$level) {
            $schema = \sprintf('{"required": ["m%d"], "allOf": [%s]}', $level, $schema);
        }

        return $schema;
    }

    public function testAnAlternativeInsideAMemberIsAboutThatMember(): void
    {
        // GIVEN a member that has to match one of two shapes and matches neither
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "payment": {"anyOf": [{"required": ["card"]}, {"required": ["transfer"]}]}
                }
            }
            JSON);

        // WHEN
        $report = $validator->validate($this->decode('{"payment": {}}'), $schema);

        // THEN the finding is about the payment, not about `card` and not about
        // `transfer`: nothing is missing at either of those, the value is what
        // matches nothing on offer
        self::assertCount(1, $report);
        self::assertSame('/payment', $report->errors[0]->pointer->toString());
        self::assertSame('schema.anyOf', $report->errors[0]->code);
        self::assertStringContainsString('/payment/card "card" is required', $report->errors[0]->message);
        self::assertStringContainsString('/payment/transfer "transfer" is required', $report->errors[0]->message);
    }

    public function testAnAmbiguousDocumentIsToldSoRatherThanShownTheAlternatives(): void
    {
        // GIVEN a `oneOf` that two of the alternatives match
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "oneOf": [{"required": ["a"]}, {"required": ["a"]}]}');

        // WHEN
        $report = $validator->validate($this->decode('{"a": 1}'), $schema);

        // THEN the complaint is the ambiguity. Naming what the alternatives
        // wanted would be absurd here: the document *has* all of it, and what is
        // wrong is that it matches more than one shape
        self::assertCount(1, $report);
        self::assertSame('schema.oneOf', $report->errors[0]->code);
        self::assertSame('The value matches 2 of the alternatives, and it may match only one.', $report->errors[0]->message);
    }

    public function testAMessageNamesOnlySoManyAlternatives(): void
    {
        // GIVEN seven shapes to choose from and a document that fits none
        $validator = new OpisSchemaValidator();
        $shapes = implode(', ', array_map(
            static fn(int $index): string => \sprintf('{"required": ["m%d"]}', $index),
            range(1, 7),
        ));
        $schema = Schema::fromJson(\sprintf('{"type": "object", "anyOf": [%s]}', $shapes));

        // WHEN
        $report = $validator->validate($this->decode('{}'), $schema);

        // THEN five are named and the rest are counted: a reader who cannot
        // weigh five will not weigh twenty, and a message nobody can read is a
        // message that costs memory for nothing
        self::assertCount(1, $report);
        self::assertStringContainsString('none of the 7 alternatives', $report->errors[0]->message);
        self::assertStringContainsString('(5) /m5 "m5" is required', $report->errors[0]->message);
        self::assertStringNotContainsString('(6)', $report->errors[0]->message);
        self::assertStringContainsString('and 2 more', $report->errors[0]->message);
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
    }
}
