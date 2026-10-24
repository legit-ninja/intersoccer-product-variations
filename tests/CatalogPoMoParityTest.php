<?php

use PHPUnit\Framework\TestCase;

/**
 * Ensure compiled .mo files contain every non-empty .po translation (catches merged PO entries).
 */
class CatalogPoMoParityTest extends TestCase {
    /**
     * @return array<string,string> msgid => msgstr
     */
    private function parse_po_translations(string $path): array {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $entries = [];
        $state = null;
        $msgctxt = '';
        $msgid = '';
        $msgid_plural = '';
        $msgstr = '';
        /** @var array<int,string> */
        $msgstr_plural = [];
        $fuzzy = false;

        $flush = static function () use (&$entries, &$msgctxt, &$msgid, &$msgid_plural, &$msgstr, &$msgstr_plural, &$fuzzy): void {
            $lookup_id = $msgid;
            if ($msgctxt !== '' && $lookup_id !== '') {
                $lookup_id = $msgctxt . "\4" . $lookup_id;
            }
            if ($msgid_plural !== '' && $msgid !== '' && $msgstr_plural !== [] && !$fuzzy) {
                ksort($msgstr_plural);
                $forms = array_values($msgstr_plural);
                if ($forms !== [] && !in_array('', $forms, true)) {
                    $entries[$lookup_id . "\0" . $msgid_plural] = implode("\0", $forms);
                }
            } elseif ($msgid !== '' && $msgstr !== '' && !$fuzzy) {
                $entries[$lookup_id] = $msgstr;
            }
            $msgctxt = '';
            $msgid = '';
            $msgid_plural = '';
            $msgstr = '';
            $msgstr_plural = [];
            $fuzzy = false;
        };

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                if (str_contains($line, 'fuzzy')) {
                    $fuzzy = true;
                }
                if ($state === 'msgstr' && $msgid !== '') {
                    $flush();
                    $state = null;
                } elseif ($state === 'msgstr_plural' && $msgid !== '' && $msgid_plural !== '') {
                    $flush();
                    $state = null;
                }
                continue;
            }

            if (str_starts_with($line, 'msgctxt ')) {
                if ($state === 'msgstr' && $msgid !== '') {
                    $flush();
                } elseif ($state === 'msgstr_plural' && $msgid !== '' && $msgid_plural !== '') {
                    $flush();
                }
                $msgctxt = $this->po_parse_quoted($line, 'msgctxt');
                continue;
            }

            if (str_starts_with($line, 'msgid ')) {
                if ($state === 'msgstr' && $msgid !== '') {
                    $flush();
                } elseif ($state === 'msgstr_plural' && $msgid !== '' && $msgid_plural !== '') {
                    $flush();
                }
                $state = 'msgid';
                $msgid = $this->po_parse_quoted($line);
                $msgid_plural = '';
                $msgstr = '';
                $msgstr_plural = [];
                continue;
            }

            if (str_starts_with($line, 'msgid_plural ')) {
                $state = 'msgid_plural';
                $msgid_plural = $this->po_parse_quoted($line, 'msgid_plural');
                continue;
            }

            if (preg_match('/^msgstr\[(\d+)\]\s+/', $line, $index_match)) {
                $state = 'msgstr_plural';
                $msgstr_plural[(int) $index_match[1]] = $this->po_parse_quoted($line, 'msgstr');
                continue;
            }

            if (str_starts_with($line, 'msgstr ')) {
                $state = 'msgstr';
                $msgstr = $this->po_parse_quoted($line);
                continue;
            }

            if ($state === 'msgid' && str_starts_with($line, '"')) {
                $msgid .= $this->po_parse_quoted($line);
                continue;
            }

            if ($state === 'msgid_plural' && str_starts_with($line, '"')) {
                $msgid_plural .= $this->po_parse_quoted($line);
                continue;
            }

            if ($state === 'msgstr' && str_starts_with($line, '"')) {
                $msgstr .= $this->po_parse_quoted($line);
                continue;
            }

            if ($state === 'msgstr_plural' && str_starts_with($line, '"')) {
                $last_index = array_key_last($msgstr_plural);
                if ($last_index !== null) {
                    $msgstr_plural[$last_index] .= $this->po_parse_quoted($line);
                }
            }
        }

        if ($state === 'msgstr' && $msgid !== '') {
            $flush();
        } elseif ($state === 'msgstr_plural' && $msgid !== '' && $msgid_plural !== '') {
            $flush();
        }

        return $entries;
    }

    private function po_parse_quoted(string $line, string $prefix = 'msgid'): string {
        if (!preg_match('/^' . $prefix . '(?:\[\d+\])?\s+(.+)$/', $line, $m)) {
            if (!preg_match('/^msg(id|str)\s+(.+)$/', $line, $m)) {
                $quoted = $line;
            } else {
                $quoted = $m[2];
            }
        } else {
            $quoted = $m[1];
        }
        if (!preg_match('/^"((?:\\\\.|[^"\\\\])*)"/s', trim($quoted), $match)) {
            return '';
        }
        return stripcslashes($match[1]);
    }

    /**
     * @return array<string,string> msgid => msgstr (excludes empty header entry)
     */
    private function read_mo_entries(string $path): array {
        $data = file_get_contents($path);
        $this->assertIsString($data);
        $this->assertGreaterThan(28, strlen($data));

        $magic = unpack('V', substr($data, 0, 4))[1];
        $unpack = 'V';
        if ($magic === 0xde120495) {
            $unpack = 'N';
        } elseif ($magic !== 0x950412de) {
            $this->fail('Unsupported MO magic: ' . dechex($magic));
        }

        $count = unpack($unpack, substr($data, 4 + 4, 4))[1];
        $orig_off = unpack($unpack, substr($data, 4 + 4 + 4, 4))[1];
        $trans_off = unpack($unpack, substr($data, 4 + 4 + 4 + 4, 4))[1];

        $read_string = static function (int $offset) use ($data, $unpack): string {
            $len = unpack($unpack, substr($data, $offset, 4))[1];
            $str_off = unpack($unpack, substr($data, $offset + 4, 4))[1];
            return substr($data, $str_off, $len);
        };

        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $orig = $read_string($orig_off + $i * 8);
            $trans = $read_string($trans_off + $i * 8);
            if ($orig === '') {
                continue;
            }
            $entries[$orig] = $trans;
        }

        return $entries;
    }

    /**
     * @return array<int,string>
     */
    private function locale_pairs(): array {
        return [
            'fr_FR',
            'de_DE',
        ];
    }

    public function test_po_translated_entries_exist_in_matching_mo(): void {
        $lang_dir = dirname(__DIR__) . '/lang';
        foreach ($this->locale_pairs() as $locale) {
            $po = $lang_dir . '/intersoccer-product-variations-' . $locale . '.po';
            $mo = $lang_dir . '/intersoccer-product-variations-' . $locale . '.mo';
            $po_entries = $this->parse_po_translations($po);
            $mo_entries = $this->read_mo_entries($mo);

            $this->assertNotEmpty($po_entries, $locale . ' PO should have translated entries');
            foreach ($po_entries as $msgid => $msgstr) {
                $this->assertArrayHasKey($msgid, $mo_entries, $locale . ' MO missing msgid: ' . $msgid);
                $this->assertSame($msgstr, $mo_entries[$msgid], $locale . ' MO msgstr mismatch for: ' . $msgid);
            }
            $this->assertSame(
                count($po_entries),
                count($mo_entries),
                $locale . ' translated PO count must match MO entry count (header excluded)'
            );
            $this->assertArrayHasKey('Select Days', $mo_entries, $locale . ' MO must include Select Days');
            $this->assertArrayHasKey(
                'Booking data is still saving. Please wait a moment, then try Add to cart again.',
                $mo_entries,
                $locale . ' MO must include stash_wait_timeout'
            );
        }
    }

    public function test_fallback_mo_matches_locale_mo(): void {
        $lang_dir = dirname(__DIR__) . '/lang';
        $pairs = [
            ['fr_FR', 'fr'],
            ['de_DE', 'de'],
        ];
        foreach ($pairs as [$locale, $short]) {
            $primary = $this->read_mo_entries($lang_dir . '/intersoccer-product-variations-' . $locale . '.mo');
            $fallback = $this->read_mo_entries($lang_dir . '/intersoccer-product-variations-' . $short . '.mo');
            $this->assertSame($primary, $fallback, $short . '.mo must match ' . $locale . '.mo');
        }
    }
}
