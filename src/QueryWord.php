<?php declare(strict_types=1);

namespace mii\search;


class QueryWord
{
    public string $text;
    public string $normalized;
    public int $pos = 0;
    public int $hits = 0;
    public bool $isLatin;
    public bool $isNoun;
    public bool $isNumber;
    public bool $hasDigit;
}
