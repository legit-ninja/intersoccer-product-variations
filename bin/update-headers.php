#!/usr/bin/env php
<?php
// Simple script to add/update headers in all PHP files.
$header = <<<'HEADER'
<?php
/**
 * Plugin/Theme Name: [Repo Name]
 * Description: [Brief desc]
 * Version: 1.0.0
 * Author: Legit Ninja
 * License: GPL-2.0+
 */

HEADER;

$files = glob('**/*.php', GLOB_BRACE);  // Adjust excludes as needed.
foreach ($files as $file) {
    if (strpos($file, 'vendor/') !== false) continue;
    $content = file_get_contents($file);
    if (strpos($content, 'Plugin/Theme Name:') === false) {
        file_put_contents($file, str_replace('<?php', $header, $content, 1));
        echo "Updated header in $file\n";
    }
}