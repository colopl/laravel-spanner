<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in('src')
    ->in('tests')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        // 自動生成で生じる空ブロックをそのまま複数行の形で許したい
        'single_line_empty_body' => false,
    ])
    ->setFinder($finder)
    ;
