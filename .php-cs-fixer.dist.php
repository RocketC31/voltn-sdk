<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => true,
        'single_quote' => true,
        'no_superfluous_phpdoc_tags' => true,
        'phpdoc_align' => false,
        'concat_space' => ['spacing' => 'one'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'blank_line_after_opening_tag' => true,
        'no_empty_statement' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'void_return' => true,
        'ordered_class_elements' => false,
    ])
    ->setFinder($finder)
;
