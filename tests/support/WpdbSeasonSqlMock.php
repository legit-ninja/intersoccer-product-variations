<?php
/**
 * In-memory $wpdb mock for season term SQL resolution tests.
 */

class WpdbSeasonSqlMock
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $terms = 'wp_terms';

    /** @var string */
    public $term_taxonomy = 'wp_term_taxonomy';

    /** @var int */
    public $season_lookup_query_count = 0;

    /** @var bool */
    public $icl_table_exists = true;

    /** @var array{terms:array,term_taxonomy:array,icl:array} */
    private $fixture = [
        'terms' => [],
        'term_taxonomy' => [],
        'icl' => [],
    ];

    /** @var array{query:string,args:array<int,mixed>}|null */
    private $last_prepare = null;

    /**
     * @param array{
     *   terms?:array<int,array{term_id:int,name:string,slug:string}>,
     *   term_taxonomy?:array<int,array{term_taxonomy_id:int,term_id:int,taxonomy:string}>,
     *   icl?:array<int,array{element_id:int,element_type:string,trid:int,language_code:string}>
     * } $fixture
     */
    public function load_fixture(array $fixture): void
    {
        $this->fixture['terms'] = $fixture['terms'] ?? [];
        $this->fixture['term_taxonomy'] = $fixture['term_taxonomy'] ?? [];
        $this->fixture['icl'] = $fixture['icl'] ?? [];
        $this->season_lookup_query_count = 0;
        $this->last_prepare = null;
    }

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        $this->last_prepare = ['query' => (string) $query, 'args' => $args];
        $i = 0;
        return preg_replace_callback(
            '/%(?:s|d|f)/',
            static function () use ($args, &$i) {
                $value = $args[$i] ?? '';
                $i++;
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
                return "'" . str_replace("'", "''", (string) $value) . "'";
            },
            (string) $query
        );
    }

    public function get_var($query)
    {
        if (stripos((string) $query, 'SHOW TABLES LIKE') !== false) {
            return $this->icl_table_exists ? $this->prefix . 'icl_translations' : null;
        }
        return null;
    }

    /**
     * @param string $query
     * @param string $output
     * @return object|null
     */
    public function get_row($query, $output = OBJECT)
    {
        if ($this->last_prepare === null) {
            return null;
        }
        if (stripos($this->last_prepare['query'], 'term_taxonomy') === false) {
            return null;
        }
        $this->season_lookup_query_count++;

        $args = $this->last_prepare['args'];
        $query = $this->last_prepare['query'];
        $taxonomy = '';
        $default_lang = 'en';
        $name_candidates = [];
        $slug_candidates = [];
        $use_wpml = stripos($query, 'icl_translations') !== false;
        $has_name = stripos($query, 'LOWER(t.name)') !== false;
        $has_slug = stripos($query, 'LOWER(t.slug)') !== false;

        if ($use_wpml) {
            $taxonomy = (string) ($args[2] ?? '');
            $default_lang = (string) ($args[1] ?? 'en');
            $rest = array_slice($args, 3);
        } else {
            $taxonomy = (string) ($args[0] ?? '');
            $rest = array_slice($args, 1);
        }

        if ($has_name && $has_slug) {
            $n = (int) (count($rest) / 2);
            $name_candidates = array_slice($rest, 0, $n);
            $slug_candidates = array_slice($rest, $n);
        } elseif ($has_name) {
            $name_candidates = $rest;
        } else {
            $slug_candidates = $rest;
        }

        $match_term_id = $this->find_matching_term_id($taxonomy, $name_candidates, $slug_candidates);
        if ($match_term_id <= 0) {
            return null;
        }

        if (!$use_wpml || !$this->icl_table_exists) {
            return (object) ['term_id' => $match_term_id];
        }

        $tt_id = $this->term_taxonomy_id_for_term($match_term_id, $taxonomy);
        if ($tt_id <= 0) {
            return null;
        }
        $trid = $this->trid_for_element($tt_id);
        if ($trid <= 0) {
            return (object) ['term_id' => $match_term_id];
        }
        $def_tt = $this->element_id_for_trid_lang($trid, $default_lang);
        if ($def_tt <= 0) {
            return (object) ['term_id' => $match_term_id];
        }
        $def_term_id = $this->term_id_for_taxonomy_row($def_tt);
        if ($def_term_id <= 0) {
            return (object) ['term_id' => $match_term_id];
        }

        return (object) ['term_id' => $def_term_id];
    }

    /**
     * @param array<int,string> $name_candidates
     * @param array<int,string> $slug_candidates
     */
    private function find_matching_term_id(string $taxonomy, array $name_candidates, array $slug_candidates): int
    {
        foreach ($this->fixture['term_taxonomy'] as $row) {
            if (($row['taxonomy'] ?? '') !== $taxonomy) {
                continue;
            }
            $term = $this->term_row((int) ($row['term_id'] ?? 0));
            if ($term === null) {
                continue;
            }
            $name_norm = function_exists('intersoccer_discount_normalize_season_label_for_lookup')
                ? intersoccer_discount_normalize_season_label_for_lookup($term['name'])
                : strtolower($term['name']);
            $slug_norm = function_exists('intersoccer_discount_normalize_season_label_for_lookup')
                ? intersoccer_discount_normalize_season_label_for_lookup($term['slug'])
                : strtolower($term['slug']);
            if (in_array($name_norm, $name_candidates, true) || in_array($slug_norm, $slug_candidates, true)) {
                return (int) $term['term_id'];
            }
        }
        return 0;
    }

    /**
     * @return array{term_id:int,name:string,slug:string}|null
     */
    private function term_row(int $term_id): ?array
    {
        foreach ($this->fixture['terms'] as $row) {
            if ((int) ($row['term_id'] ?? 0) === $term_id) {
                return $row;
            }
        }
        return null;
    }

    private function term_taxonomy_id_for_term(int $term_id, string $taxonomy): int
    {
        foreach ($this->fixture['term_taxonomy'] as $row) {
            if ((int) ($row['term_id'] ?? 0) === $term_id && ($row['taxonomy'] ?? '') === $taxonomy) {
                return (int) ($row['term_taxonomy_id'] ?? 0);
            }
        }
        return 0;
    }

    private function trid_for_element(int $element_id): int
    {
        foreach ($this->fixture['icl'] as $row) {
            if ((int) ($row['element_id'] ?? 0) === $element_id) {
                return (int) ($row['trid'] ?? 0);
            }
        }
        return 0;
    }

    private function element_id_for_trid_lang(int $trid, string $lang): int
    {
        foreach ($this->fixture['icl'] as $row) {
            if ((int) ($row['trid'] ?? 0) === $trid && (string) ($row['language_code'] ?? '') === $lang) {
                return (int) ($row['element_id'] ?? 0);
            }
        }
        return 0;
    }

    private function term_id_for_taxonomy_row(int $term_taxonomy_id): int
    {
        foreach ($this->fixture['term_taxonomy'] as $row) {
            if ((int) ($row['term_taxonomy_id'] ?? 0) === $term_taxonomy_id) {
                return (int) ($row['term_id'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * Autumn / summer season fixture shared by discount tests.
     *
     * @return array{terms:array,term_taxonomy:array,icl:array}
     */
    public static function autumn_2026_wpml_fixture(int $en_id = 88001, int $fr_id = 88002, int $de_id = 88003, int $summer_id = 88004): array
    {
        $tt_en = 880101;
        $tt_fr = 880102;
        $tt_de = 880103;
        $tt_summer = 880104;
        $trid_autumn = 90001;
        $trid_summer = 90002;

        return [
            'terms' => [
                ['term_id' => $en_id, 'name' => 'Autumn Camps 2026', 'slug' => 'autumn-camps-2026'],
                ['term_id' => $fr_id, 'name' => "Camps d'automne 2026", 'slug' => 'camps-dautomne-2026'],
                ['term_id' => $de_id, 'name' => 'Herbst Camps 2026', 'slug' => 'herbst-camps-2026'],
                ['term_id' => $summer_id, 'name' => 'Summer Camps 2026', 'slug' => 'summer-camps-2026'],
            ],
            'term_taxonomy' => [
                ['term_taxonomy_id' => $tt_en, 'term_id' => $en_id, 'taxonomy' => 'pa_program-season'],
                ['term_taxonomy_id' => $tt_fr, 'term_id' => $fr_id, 'taxonomy' => 'pa_program-season'],
                ['term_taxonomy_id' => $tt_de, 'term_id' => $de_id, 'taxonomy' => 'pa_program-season'],
                ['term_taxonomy_id' => $tt_summer, 'term_id' => $summer_id, 'taxonomy' => 'pa_program-season'],
            ],
            'icl' => [
                ['element_id' => $tt_en, 'element_type' => 'tax_pa_program-season', 'trid' => $trid_autumn, 'language_code' => 'en'],
                ['element_id' => $tt_fr, 'element_type' => 'tax_pa_program-season', 'trid' => $trid_autumn, 'language_code' => 'fr'],
                ['element_id' => $tt_de, 'element_type' => 'tax_pa_program-season', 'trid' => $trid_autumn, 'language_code' => 'de'],
                ['element_id' => $tt_summer, 'element_type' => 'tax_pa_program-season', 'trid' => $trid_summer, 'language_code' => 'en'],
            ],
        ];
    }
}
