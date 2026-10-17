<?php

use PHPUnit\Framework\TestCase;

/**
 * Plugin header Domain Path must match where .mo files ship (lang/).
 */
class TextdomainLoadPathTest extends TestCase
{
    public function test_plugin_header_domain_path_matches_lang_directory(): void
    {
        $plugin_file = dirname(__DIR__) . '/intersoccer-product-variations.php';
        $contents = file_get_contents($plugin_file);
        $this->assertIsString($contents);

        $this->assertMatchesRegularExpression(
            '/Domain Path:\s*\/lang\b/',
            $contents,
            'Plugin header Domain Path should be /lang'
        );

        $lang_dir = dirname(__DIR__) . '/lang';
        $this->assertDirectoryExists($lang_dir);
        $this->assertFileExists($lang_dir . '/intersoccer-product-variations-de_DE.mo');
        $this->assertFileExists($lang_dir . '/intersoccer-product-variations-fr_FR.mo');
    }

    public function test_load_plugin_textdomain_relative_path_matches_lang(): void
    {
        $plugin_file = dirname(__DIR__) . '/intersoccer-product-variations.php';
        $contents = file_get_contents($plugin_file);
        $this->assertIsString($contents);

        $this->assertStringContainsString(
            "dirname(plugin_basename(__FILE__)) . '/lang'",
            $contents,
            'load_plugin_textdomain should load from the lang subdirectory'
        );
    }
}
