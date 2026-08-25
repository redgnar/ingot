<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

/**
 * What both ends of a range agree a moment is, in the two formats they may be
 * written in.
 *
 * A **date** is `YYYY-MM-DD`, whole, and a day that exists — 2026-02-30 is
 * neither a bound nor a value. Reformatting what was parsed and comparing it to
 * the input is what makes all three of those one check: a rolled-over day, a
 * missing zero and anything tacked onto the end all come back different from
 * what came in.
 *
 * A **date-time** is RFC 3339: the same day, a time, and an offset — `Z` or
 * `±HH:MM`, either of which is what makes it a moment rather than a reading on
 * somebody's wall. The shape is checked by pattern and the day by parsing it,
 * because the two catch different mistakes: `2026-13-01T00:00:00Z` has the right
 * shape and no such month.
 */
final class DateBound
{
    /**
     * RFC 3339, with the seconds it requires and the fraction it allows. The day
     * is captured because the shape alone does not say it exists.
     */
    private const string RFC3339 = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    private static function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function isDateTime(string $value): bool
    {
        if (preg_match(self::RFC3339, $value, $parts) !== 1) {
            return false;
        }

        // The shape says nothing about whether the day is there, and the two
        // ways of not being there are not alike: PHP refuses a thirteenth month
        // and quietly rolls the thirtieth of February over into March, which
        // would be compared as though somebody had written it.
        if (!checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            return false;
        }

        try {
            self::moment($value);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * The instant a date-time names, for comparing two of them. Never a string
     * comparison: `2026-01-01T00:30:00+01:00` is earlier than
     * `2026-01-01T00:00:00Z` and sorts after it.
     *
     * Only ever called for a string {@see matches()} has already accepted, which
     * is why it throws rather than answering null: a caller that has checked has
     * nothing to handle, and one that has not should not be quietly given a
     * comparison that cannot be made.
     *
     * @throws \Exception when the value is not a moment
     */
    public static function moment(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    /** Whether a value is written in the format a bound was read beside. */
    public static function matches(string $value, string $format): bool
    {
        return $format === 'date' ? self::isCalendarDate($value) : self::isDateTime($value);
    }
}
