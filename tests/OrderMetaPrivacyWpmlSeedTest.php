<?php
/**
 * WPML privacy redaction string seeding (idempotent, correct icl_add_string_translation args).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if (!defined('ICL_TM_COMPLETE')) {
    define('ICL_TM_COMPLETE', 10);
}

if (!defined('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR')) {
    define('INTERSOCCER_PRODUCT_VARIATIONS_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!function_exists('intersoccer_test_wpml_privacy_seed_reset_recorder')) {
    function intersoccer_test_wpml_privacy_seed_reset_recorder(): void {
        $GLOBALS['intersoccer_test_wpml_privacy_seed'] = [
            'register_string' => [],
            'add_string_translation' => [],
            'get_string_translations_by_id' => [],
            'update_option' => [],
        ];
    }
}

if (!function_exists('icl_register_string')) {
    function icl_register_string($context, $name, $value) {
        $GLOBALS['intersoccer_test_wpml_privacy_seed']['register_string'][] = [
            'context' => $context,
            'name' => $name,
            'value' => $value,
        ];
        return true;
    }
}

if (!function_exists('icl_get_string_id')) {
    function icl_get_string_id($name, $context) {
        static $ids = [];
        $key = $context . "\0" . $name;
        if (isset($GLOBALS['intersoccer_test_wpml_privacy_get_string_id_overrides'][$key])) {
            return $GLOBALS['intersoccer_test_wpml_privacy_get_string_id_overrides'][$key];
        }
        if (!isset($ids[$key])) {
            $ids[$key] = count($ids) + 1;
        }
        return $ids[$key];
    }
}

if (!function_exists('icl_add_string_translation')) {
    function icl_add_string_translation($string_id, $language, $value = null, $status = false) {
        $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'][] = [
            'string_id' => $string_id,
            'language' => $language,
            'value' => $value,
            'status' => $status,
        ];
        if (!isset($GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'])) {
            $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'] = [];
        }
        if (!isset($GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'][$string_id])) {
            $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'][$string_id] = [];
        }
        $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'][$string_id][$language] = [
            'value' => $value,
            'status' => $status,
        ];
        return true;
    }
}

if (!function_exists('icl_get_string_translations_by_id')) {
    function icl_get_string_translations_by_id($string_id) {
        $GLOBALS['intersoccer_test_wpml_privacy_seed']['get_string_translations_by_id'][] = $string_id;
        $store = $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'] ?? [];
        return $store[$string_id] ?? [];
    }
}

require_once dirname(__DIR__) . '/includes/woocommerce/order-meta-contract.php';

class OrderMetaPrivacyWpmlSeedTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        intersoccer_test_wpml_privacy_seed_reset_recorder();
        $GLOBALS['intersoccer_test_options'] = [];
        unset($GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id']);
        unset($GLOBALS['intersoccer_test_wpml_privacy_get_string_id_overrides']);
    }

    private function expected_map(): array {
        return intersoccer_privacy_redaction_string_i18n_map();
    }

    private function seed_fingerprint(): string {
        return intersoccer_pv_privacy_redaction_strings_seed_fingerprint();
    }

    public function test_first_run_seeds_six_translations_with_correct_argument_order(): void {
        intersoccer_register_privacy_redaction_wpml_strings();

        $calls = $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'];
        $this->assertCount(6, $calls);

        $map = $this->expected_map();
        $expected = [];
        foreach (intersoccer_privacy_redaction_string_msgids() as $index => $msgid) {
            $string_id = $index + 1;
            foreach ($map[$msgid] as $lang => $translation) {
                $expected[] = [
                    'string_id' => $string_id,
                    'language' => $lang,
                    'value' => $translation,
                    'status' => ICL_TM_COMPLETE,
                ];
            }
        }
        $this->assertSame($expected, $calls);
        $this->assertSame($this->seed_fingerprint(), get_option('intersoccer_pv_privacy_strings_seed'));
    }

    public function test_second_run_with_matching_seed_option_writes_nothing(): void {
        intersoccer_register_privacy_redaction_wpml_strings();
        intersoccer_test_wpml_privacy_seed_reset_recorder();
        $options_snapshot = $GLOBALS['intersoccer_test_options'];

        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertSame([], $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation']);
        $this->assertSame($options_snapshot, $GLOBALS['intersoccer_test_options']);
    }

    public function test_complete_human_edited_french_translation_is_not_overwritten(): void {
        $map = $this->expected_map();
        $msgid = 'Medical information: provided (view or update in My Account).';
        $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'] = [
            1 => [
                'fr' => [
                    'value' => 'Texte personnalisé par un humain.',
                    'status' => ICL_TM_COMPLETE,
                ],
            ],
        ];

        intersoccer_register_privacy_redaction_wpml_strings();

        $fr_calls = array_values(array_filter(
            $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'],
            static fn(array $row): bool => $row['language'] === 'fr' && (int) $row['string_id'] === 1
        ));
        $this->assertSame([], $fr_calls);

        $de_expected = $map[$msgid]['de'];
        $de_calls = array_values(array_filter(
            $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'],
            static fn(array $row): bool => $row['language'] === 'de' && (int) $row['string_id'] === 1
        ));
        $this->assertCount(1, $de_calls);
        $this->assertSame($de_expected, $de_calls[0]['value']);
        $this->assertSame(ICL_TM_COMPLETE, $de_calls[0]['status']);
    }

    public function test_existing_ten_value_is_repaired(): void {
        $map = $this->expected_map();
        $msgid = 'Birth year: %s';
        $GLOBALS['intersoccer_test_wpml_privacy_string_translations_by_id'] = [
            3 => [
                'fr' => [
                    'value' => '10',
                    'status' => ICL_TM_COMPLETE,
                ],
            ],
        ];

        intersoccer_register_privacy_redaction_wpml_strings();

        $fr_calls = array_values(array_filter(
            $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'],
            static fn(array $row): bool => $row['language'] === 'fr' && (int) $row['string_id'] === 3
        ));
        $this->assertCount(1, $fr_calls);
        $this->assertSame($map[$msgid]['fr'], $fr_calls[0]['value']);
        $this->assertSame(ICL_TM_COMPLETE, $fr_calls[0]['status']);
    }

    public function test_changed_fingerprint_seeds_again(): void {
        $GLOBALS['intersoccer_test_options']['intersoccer_pv_privacy_strings_seed'] = 'stale-hash';

        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertCount(6, $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation']);
        $this->assertSame($this->seed_fingerprint(), get_option('intersoccer_pv_privacy_strings_seed'));
    }

    public function test_missing_string_id_skips_seed_option_until_all_ids_available(): void {
        $context = 'intersoccer-product-variations';
        $missing_msgid = 'Birth year: %s';
        $missing_key = $context . "\0" . $missing_msgid;
        $GLOBALS['intersoccer_test_wpml_privacy_get_string_id_overrides'] = [
            $missing_key => 0,
        ];

        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertFalse(array_key_exists('intersoccer_pv_privacy_strings_seed', $GLOBALS['intersoccer_test_options'] ?? []));
        $calls = $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'];
        $this->assertCount(4, $calls);
        foreach ($calls as $row) {
            $this->assertNotSame(0, (int) $row['string_id']);
        }
        $birth_year_writes = array_values(array_filter(
            $calls,
            static fn(array $row): bool => (int) $row['string_id'] === 3
        ));
        $this->assertSame([], $birth_year_writes);

        unset($GLOBALS['intersoccer_test_wpml_privacy_get_string_id_overrides']);
        intersoccer_test_wpml_privacy_seed_reset_recorder();

        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertSame($this->seed_fingerprint(), get_option('intersoccer_pv_privacy_strings_seed'));
        $second_calls = $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation'];
        $this->assertCount(2, $second_calls);
        foreach ($second_calls as $row) {
            $this->assertSame(3, (int) $row['string_id']);
        }
    }

    public function test_complete_seed_option_saved_once_then_no_writes_or_option_updates(): void {
        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertSame($this->seed_fingerprint(), get_option('intersoccer_pv_privacy_strings_seed'));
        $options_after_first = $GLOBALS['intersoccer_test_options'];

        intersoccer_test_wpml_privacy_seed_reset_recorder();
        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertSame([], $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation']);
        $this->assertSame($options_after_first, $GLOBALS['intersoccer_test_options']);

        intersoccer_test_wpml_privacy_seed_reset_recorder();
        intersoccer_register_privacy_redaction_wpml_strings();

        $this->assertSame([], $GLOBALS['intersoccer_test_wpml_privacy_seed']['add_string_translation']);
        $this->assertSame($options_after_first, $GLOBALS['intersoccer_test_options']);
    }
}
