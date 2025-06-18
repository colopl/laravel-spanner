<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in('src')
    ->in('tests')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        // Preserve the multi-line format for auto-generated empty blocks.
        'single_line_empty_body' => false,
    ])
    ->setFinder($finder)
    ;
