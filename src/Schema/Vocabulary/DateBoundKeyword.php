<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\ErrorTrait;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/**
 * One end of a range in time: `formatMinimum` or `formatMaximum` beside
 * `"format": "date"` or `"format": "date-time"`, with the meaning ajv-formats
 * gives them.
 *
 * JSON Schema has no way to bound a string in time — there is `minLength`,
 * `maxLength` and `pattern`, and none of them can say "not before 2026-01-01".
 * These two keywords are the vocabulary the ecosystem settled on, so a
 * document carrying them is enforced the same way here and in a browser
 * running ajv.
 *
 * Both formats are compared as the instants they name, and a date-time is why.
 * Calendar dates in `YYYY-MM-DD` sort as text exactly as they sort in time, so
 * either way would do for them; a date-time carries an offset and does not —
 * `2026-01-01T00:30:00+01:00` is half an hour *earlier* than
 * `2026-01-01T00:00:00Z` and sorts after it. One comparison for both is worth
 * more than the parsing it saves on one of them.
 */
final class DateBoundKeyword implements Keyword
{
    use ErrorTrait;

    public function __construct(
        private readonly string $keyword,
        private readonly bool $isMinimum,
        private readonly string $bound,
        /** The format this bound was read beside: `date` or `date-time`. */
        private readonly string $format = 'date',
    ) {}

    public function validate(ValidationContext $context, Schema $schema): ?ValidationError
    {
        /**
         * Registered as a string keyword, so opis only ever runs this over a
         * string.
         *
         * @var string $value
         */
        $value = $context->currentData();

        // A string that is not written in the format is `format`'s business:
        // saying it is also out of range would be a second complaint about one
        // mistake.
        if (!DateBound::matches($value, $this->format)) {
            return null;
        }

        if ($this->withinRange($value)) {
            return null;
        }

        return $this->error(
            $schema,
            $context,
            $this->keyword,
            $this->isMinimum ? 'Date must not be earlier than {bound}' : 'Date must not be later than {bound}',
            ['bound' => $this->bound],
        );
    }

    private function withinRange(string $value): bool
    {
        // Both were accepted on the way in — the value by `matches()` just
        // above, the bound by the parser that built this — so neither throws.
        // Dates go through the same comparison as moments rather than being
        // compared as text: midnight against midnight is the same answer, and
        // one path is worth more than the arithmetic it saves.
        $moment = DateBound::moment($value);
        $bound = DateBound::moment($this->bound);

        return $this->isMinimum ? $moment >= $bound : $moment <= $bound;
    }
}
