<?php declare(strict_types=1);

namespace mii\search;

use mii\search\sphinx\Sphinx;

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
        $this->words = \explode(' ', $q);
        $this->word_count = \count($this->words);
    }


    protected function clean(string $q): string
    {
        $q = \mb_strtolower($q);
        try {
            $q = \preg_replace('/[^\w\s]+/mu', '', $q);
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


    public function parseMeta(array $meta, bool $expanded = false): array
    {
        $result = [];

        for ($i = 0; $i < $this->wordCount(); $i++) {
            if (isset($meta['keyword[' . $i . ']']) and $meta['docs[' . $i . ']'] == 0) {
                if ($expanded) {
                    $first = \mb_substr($meta['keyword[' . $i . ']'], 0, 1);
                    if ($first == '=' or $first == '*') {
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
}
