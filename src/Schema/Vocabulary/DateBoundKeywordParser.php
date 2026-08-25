<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Parsers\KeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/**
 * Reads one end of a range out of a schema, and refuses it here rather than at
 * validation time: a bound that is not written in the format beside it, or one
 * sitting beside a format that says nothing about time, is a mistake in the
 * schema and should be reported to whoever wrote it.
 *
 * The format is read first, because it is what says how to read the bound.
 */
final class DateBoundKeywordParser extends KeywordParser
{
    public function __construct(
        private readonly string $name,
        private readonly bool $isMinimum,
    ) {
        parent::__construct($name);
    }

    public function type(): string
    {
        return self::TYPE_STRING;
    }

    public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword
    {
        $schema = $info->data();

        if (!\is_object($schema) || !$this->keywordExists($schema)) {
            return null;
        }

        $value = $this->keywordValue($schema);
        $format = $schema->format ?? null;

        if ($format !== 'date' && $format !== 'date-time') {
            throw $this->keywordException('{keyword} only means anything beside "format": "date" or "date-time"', $info);
        }

        if (!\is_string($value) || !DateBound::matches($value, $format)) {
            throw $this->keywordException(
                $format === 'date'
                    ? '{keyword} must be a calendar date in YYYY-MM-DD form'
                    : '{keyword} must be an RFC 3339 date-time, with an offset',
                $info,
            );
        }

        return new DateBoundKeyword($this->name, $this->isMinimum, $value, $format);
    }
}
