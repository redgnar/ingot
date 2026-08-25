<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema\Vocabulary;

use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use Opis\JsonSchema\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A range in time, which standard JSON Schema cannot express: `formatMinimum`
 * and `formatMaximum` beside `"format": "date"` or `"format": "date-time"`, with
 * the meaning ajv-formats gives them — so one document is enforced the same way
 * here and in a browser.
 *
 * The two formats are compared differently, and the tests below are what pins
 * that: dates as text, because they sort that way, and date-times as the moments
 * they name, because an offset means they do not.
 */
final class DateBoundsTest extends TestCase
{
    private const string SCHEMA = '{
        "type": "object",
        "properties": {
            "when": {
                "type": "string",
                "format": "date",
                "formatMinimum": "2026-01-01",
                "formatMaximum": "2026-12-31"
            }
        }
    }';

    /**
     * @return \Generator<string, array{string, string|null}>
     */
    public static function dates(): \Generator
    {
        yield 'a date inside the range' => ['2026-06-15', null];
        yield 'the first day allowed' => ['2026-01-01', null];
        yield 'the last day allowed' => ['2026-12-31', null];

        yield 'the day before the range' => ['2025-12-31', 'schema.formatMinimum'];
        yield 'the day after the range' => ['2027-01-01', 'schema.formatMaximum'];
        yield 'a year too early' => ['2025-06-15', 'schema.formatMinimum'];

        // A value that is not a date is `format`'s complaint, and only its own:
        // being out of range as well would be two complaints about one mistake.
        yield 'not a date at all' => ['tomorrow', 'schema.format'];
        yield 'a day that does not exist' => ['2026-02-30', 'schema.format'];
        yield 'the right day in the wrong shape' => ['2026-6-15', 'schema.format'];
        yield 'a timestamp where a date belongs' => ['2026-06-15T10:00:00Z', 'schema.format'];
    }

    #[DataProvider('dates')]
    public function testTheRangeIsEnforced(string $date, ?string $code): void
    {
        // GIVEN a schema bounding a date on both sides
        $validator = new OpisSchemaValidator();

        // WHEN
        $report = $validator->validate(self::document($date), Schema::fromJson(self::SCHEMA));

        // THEN
        if ($code === null) {
            self::assertTrue($report->isEmpty(), \sprintf('Expected "%s" to be accepted.', $date));

            return;
        }

        self::assertFalse($report->isEmpty(), \sprintf('Expected "%s" to be refused.', $date));
        self::assertSame($code, $report->errors[0]->code);
        self::assertSame('/when', $report->errors[0]->pointer->toString());
    }

    public function testTheRefusalSaysWhichEndWasMissedAndWhere(): void
    {
        // GIVEN a schema bounding a date on both sides
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(self::SCHEMA);

        // WHEN a date falls off either end
        $tooEarly = $validator->validate(self::document('2025-12-31'), $schema);
        $tooLate = $validator->validate(self::document('2027-01-01'), $schema);

        // THEN each message names the bound that was missed, so a client can
        // repeat it to whoever is filling the form in
        self::assertStringContainsString('earlier', $tooEarly->errors[0]->message);
        self::assertStringContainsString('2026-01-01', $tooEarly->errors[0]->message);
        self::assertStringContainsString('later', $tooLate->errors[0]->message);
        self::assertStringContainsString('2026-12-31', $tooLate->errors[0]->message);
    }

    public function testOnlyOneEndNeedsToBeGiven(): void
    {
        // GIVEN a range open at the top
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"when": {"type": "string", "format": "date", "formatMinimum": "2026-01-01"}}}');

        // WHEN / THEN nothing is too late, and yesterday is still too early
        self::assertTrue($validator->validate(self::document('2999-01-01'), $schema)->isEmpty());
        self::assertFalse($validator->validate(self::document('2025-12-31'), $schema)->isEmpty());
    }

    public function testAValueOfAnotherTypeIsLeftToTheKeywordsThatJudgeTypes(): void
    {
        // GIVEN a bounded date and a number where the date belongs
        $validator = new OpisSchemaValidator();

        // WHEN
        $report = $validator->validate(json_decode('{"when": 2026}', false, flags: \JSON_THROW_ON_ERROR), Schema::fromJson(self::SCHEMA));

        // THEN the complaint is about the type, once
        self::assertSame(['schema.type'], array_map(static fn($error): string => $error->code, $report->errors));
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function boundsThatAreNotDates(): \Generator
    {
        yield 'a word' => ['yesterday'];
        yield 'a date with a time after it' => ['2026-01-01T10:00:00Z'];
        yield 'a date with a word before it' => ['about 2026-01-01'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'a month that does not exist' => ['2026-13-01'];
        yield 'a date missing its zeroes' => ['2026-1-1'];
        yield 'nothing at all' => [''];
    }

    #[DataProvider('boundsThatAreNotDates')]
    public function testABoundThatIsNotAWholeCalendarDateIsRefused(string $bound): void
    {
        // GIVEN a schema whose bound no date could ever be compared against
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(\sprintf('{"type": "string", "format": "date", "formatMinimum": "%s"}', $bound));

        // WHEN / THEN the schema itself is the mistake, and it is reported as one
        $this->expectException(ParseException::class);

        $validator->validate('2026-06-15', $schema);
    }

    public function testABoundThatIsNotEvenAStringIsRefused(): void
    {
        // GIVEN a bound written as a number, which is a mistake in the schema
        // rather than something to compare a date against
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "date", "formatMinimum": 2026}');

        // WHEN / THEN
        $this->expectException(ParseException::class);

        $validator->validate('2026-06-15', $schema);
    }

    public function testABoundBesideAFormatThatSaysNothingAboutTimeIsRefused(): void
    {
        // GIVEN a bound beside a format these keywords cannot mean anything for
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "email", "formatMinimum": "2026-01-01"}');

        // WHEN
        $refusal = null;

        try {
            $validator->validate('someone@example.com', $schema);
        } catch (ParseException $caught) {
            $refusal = $caught;
        }

        // THEN it is refused for the reason it is actually wrong. The bound is a
        // perfectly good date; what it has no meaning beside is the format, and
        // saying so is the difference between a fixable message and a puzzle.
        self::assertInstanceOf(ParseException::class, $refusal);
        self::assertStringContainsString('only means anything beside', $refusal->getMessage());
    }

    /**
     * @return \Generator<string, array{string, string, string}>
     */
    public static function malformedBounds(): \Generator
    {
        yield 'a date-time where a date belongs' => ['date', '2026-01-01T00:00:00Z', 'YYYY-MM-DD'];
        yield 'a date where a date-time belongs' => ['date-time', '2026-01-01', 'RFC 3339'];
    }

    #[DataProvider('malformedBounds')]
    public function testTheRefusalNamesTheShapeThatWasWanted(string $format, string $bound, string $expected): void
    {
        // GIVEN a bound written in the other format's shape
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(\sprintf('{"type": "string", "format": "%s", "formatMinimum": "%s"}', $format, $bound));

        // WHEN
        $refusal = null;

        try {
            $validator->validate('2026-06-15', $schema);
        } catch (ParseException $caught) {
            $refusal = $caught;
        }

        // THEN the message asks for the shape this format wants, not the other
        // one — the two are a sentence apart and swapping them would send
        // somebody to fix the wrong end of their schema
        self::assertInstanceOf(ParseException::class, $refusal);
        self::assertStringContainsString($expected, $refusal->getMessage());
    }

    public function testADateBoundBesideADateTimeIsRefused(): void
    {
        // GIVEN a date where the format asks for a moment: the two are not
        // interchangeable, and comparing one against the other would quietly
        // pick a time of day nobody wrote
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "date-time", "formatMinimum": "2026-01-01"}');

        // WHEN / THEN
        $this->expectException(ParseException::class);

        $validator->validate('2026-01-01T10:00:00Z', $schema);
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function daysThatDoNotExist(): \Generator
    {
        yield 'a thirteenth month' => ['2026-13-01T00:00:00Z'];
        // The one PHP does not refuse: it rolls this over into the second of
        // March and would compare against a moment nobody wrote.
        yield 'the thirtieth of February' => ['2026-02-30T00:00:00Z'];
        yield 'a leap day in a year that has none' => ['2026-02-29T00:00:00Z'];
        yield 'a thirty-first of April' => ['2026-04-31T12:00:00+02:00'];
        // Shaped right, day exists, and still no moment: the hour, the minute
        // and the offset each have a range of their own.
        yield 'an offset no zone has' => ['2026-06-15T12:00:00+99:00'];
        yield 'a twenty-fifth hour' => ['2026-06-15T25:00:00Z'];
        yield 'a sixtieth minute' => ['2026-06-15T12:60:00Z'];
    }

    public function testALeapDayInAYearThatHasOneIsAPerfectlyGoodBound(): void
    {
        // GIVEN a bound on the one day that exists only every fourth year. Which
        // year it is decides whether the day is there at all, so this is what
        // says the year is being read as the year.
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "date-time", "formatMinimum": "2028-02-29T00:00:00Z"}');

        // WHEN / THEN
        self::assertTrue($validator->validate('2028-03-01T00:00:00Z', $schema)->isEmpty());
        self::assertFalse($validator->validate('2028-02-28T00:00:00Z', $schema)->isEmpty());
    }

    #[DataProvider('daysThatDoNotExist')]
    public function testADateTimeBoundShapedRightWithADayThatDoesNotExistIsRefused(string $bound): void
    {
        // GIVEN a bound that passes for one at a glance
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(\sprintf('{"type": "string", "format": "date-time", "formatMinimum": "%s"}', $bound));

        // WHEN / THEN the shape is not the whole of it: there is no such day, so
        // there is no moment to compare anything against
        $this->expectException(ParseException::class);

        $validator->validate('2026-06-15T12:00:00Z', $schema);
    }

    public function testADateTimeBoundWithoutAnOffsetIsRefused(): void
    {
        // GIVEN a bound that names a reading on a wall rather than a moment
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "date-time", "formatMinimum": "2026-01-01T00:00:00"}');

        // WHEN / THEN
        $this->expectException(ParseException::class);

        $validator->validate('2026-01-01T10:00:00Z', $schema);
    }

    /**
     * @return \Generator<string, array{string, string|null}>
     */
    public static function moments(): \Generator
    {
        yield 'inside the range' => ['2026-06-15T12:00:00Z', null];
        yield 'the first moment allowed' => ['2026-01-01T00:00:00Z', null];
        yield 'the last moment allowed' => ['2026-12-31T23:59:59Z', null];
        yield 'the same moment with a fraction' => ['2026-06-15T12:00:00.250Z', null];
        yield 'a lowercase t and z, which RFC 3339 allows' => ['2026-06-15t12:00:00z', null];

        yield 'a second too early' => ['2025-12-31T23:59:59Z', 'schema.formatMinimum'];
        yield 'a second too late' => ['2027-01-01T00:00:00Z', 'schema.formatMaximum'];

        // The whole reason a date-time is not compared as text. As strings,
        // "2026-01-01T00:30:00+01:00" sorts after the lower bound; as moments it
        // is half an hour before it, and it is the moment that counts.
        yield 'inside the range by the clock, outside it by the offset' => ['2026-01-01T00:30:00+01:00', 'schema.formatMinimum'];
        // And the other way about: text says it is a year too late, the offset
        // says it is the last second of the range.
        yield 'outside the range by the clock, inside it by the offset' => ['2027-01-01T00:59:59+01:00', null];

        yield 'a date where a moment belongs' => ['2026-06-15', 'schema.format'];
        yield 'not a moment at all' => ['tomorrow', 'schema.format'];
        // Opis reads `date-time` more loosely than RFC 3339 does and lets a
        // string with no offset through. There is no moment in it to compare —
        // 12:00 where? — so this says nothing rather than guessing a zone. A
        // schema that needs the offset says so itself, with a `pattern` beside
        // the format; that is the document's business and not this keyword's.
        yield 'a reading on a wall, with no offset to place it' => ['2026-06-15T12:00:00', null];
        yield 'and one that would be out of range if it were a moment' => ['2099-06-15T12:00:00', null];
        yield 'a month that does not exist' => ['2026-13-01T00:00:00Z', 'schema.format'];
        // Shaped right, and no such day: this one is left to `format` as well,
        // and never rolled over into the day after it.
        yield 'the thirtieth of February' => ['2026-02-30T00:00:00Z', 'schema.format'];
    }

    #[DataProvider('moments')]
    public function testARangeOfMomentsIsEnforced(string $moment, ?string $code): void
    {
        // GIVEN a schema bounding a moment on both sides
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{
            "type": "object",
            "properties": {
                "when": {
                    "type": "string",
                    "format": "date-time",
                    "formatMinimum": "2026-01-01T00:00:00Z",
                    "formatMaximum": "2026-12-31T23:59:59Z"
                }
            }
        }');

        // WHEN
        $report = $validator->validate(self::document($moment), $schema);

        // THEN
        if ($code === null) {
            self::assertTrue($report->isEmpty(), \sprintf('Expected "%s" to be accepted.', $moment));

            return;
        }

        self::assertFalse($report->isEmpty(), \sprintf('Expected "%s" to be refused.', $moment));
        self::assertSame($code, $report->errors[0]->code);
        self::assertSame('/when', $report->errors[0]->pointer->toString());
    }

    private static function document(string $date): object
    {
        $document = json_decode(json_encode(['when' => $date], \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        return $document;
    }
}
