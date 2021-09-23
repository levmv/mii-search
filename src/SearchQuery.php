<?php declare(strict_types=1);

namespace mii\search;

use mii\search\sphinx\Expression;
use mii\search\sphinx\Sphinx;
use mii\util\UTF8;

class SearchQuery
{
    protected string $raw_q;
    protected array $words;

    protected int $word_count = 0;

    protected Sphinx $sphinx;

    public function __construct(string $q, Sphinx $sphinx = null)
    {
        $this->sphinx = $sphinx ?? \Mii::$app->get('sphinx');

        $this->raw_q = $this->clean($q);

        $this->words = \array_filter(\explode(' ', $this->raw_q));
        $this->word_count = \count($this->words);
    }


    protected function clean(string $q): string
    {
        $q = \mb_strtolower($q);
        $q = UTF8::strip4b($q);
        try {
            $q = \preg_replace('/[^\w\s@\.]+/mu', '', $q);
            $q = \preg_replace('/\s+/u', ' ', $q);
        } catch (\Throwable $t) {
            \Mii::error($t);
        }
        return \trim($q);
    }

    public function wordCount() : int
    {
        return $this->word_count;
    }

    public function text() : string
    {
        return $this->raw_q;
    }

    public function keywords(string $index)
    {
        $words = $this->sphinx->callKeywords($this->raw_q, $index);

        $this->words = array_map(static function($r) {
            $word = new QueryWord;
            $word->pos = (int) $r['qpos'];
            $word->text = $r['tokenized'];
            $word->normalized = $r['normalized'];
            $word->hits = (int) $r['hits'];
            $word->isLatin = (bool) $r['is_latin'];
            $word->isNoun = (bool) $r["is_noun"];
            $word->isNumber = (bool) $r["is_number"];
            $word->hasDigit = (bool) $r["has_digit"];
            return $word;
        }, $words);

        foreach($this->words as $word) {
            if($word->hits === 0) {
                dd($this->sphinx->callQsuggest($word->text, $index));
            }
        }
    }


    public function parseMeta(array $meta, bool $expanded = false): array
    {
        $result = [];

        for ($i = 0; $i < $this->wordCount(); $i++) {
            if (isset($meta['keyword[' . $i . ']']) and $meta['docs[' . $i . ']'] == 0) {
                if ($expanded) {
                    $first = \mb_substr($meta['keyword[' . $i . ']'], 0, 1);
                    if ($first === '=' || $first === '*') {
                        continue;
                    }
                } else {
                    if (isset($this->normalized[$meta['keyword[' . $i . ']']])) {
                        $result[] = $this->normalized[$meta['keyword[' . $i . ']']];
                    } else {
                        $result[] = $meta['keyword[' . $i . ']'];
                    }
                }
            }
        }
        return $result;
    }


    public function quorum($threshold)
    {
        if ($this->wordCount() < 2) {
            return $this->raw_q;
        }

        $query = Sphinx::escapeMatch($this->raw_q);

        if (is_int($threshold)) {
            if ($threshold < 0) {
                $threshold = $this->wordCount() - $threshold;
            }
        } else {
            $threshold = number_format($threshold, 1);
        }
        return new Expression('"' . $query . '"/' . $threshold);
    }

    /**
     * Returns layout changed string
     *
     * @param $string
     * @return string
     */
    private function changeLayout(string $string): string
    {
        $from = ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p', '[', ']', 'a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', ';', '"', 'z', 'x', 'c', 'v', 'b', 'n', 'm', ',', '.'];
        $to = ['й', 'ц', 'у', 'к', 'е', 'н', 'г', 'ш', 'щ', 'з', 'х', 'ъ', 'ф', 'ы', 'в', 'а', 'п', 'р', 'о', 'л', 'д', 'ж', 'э', 'я', 'ч', 'с', 'м', 'и', 'т', 'ь', 'б', 'ю'];
        return \str_replace($from, $to, $string);
    }
}
