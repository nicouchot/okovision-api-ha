<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/_include'])
    ->name('*.class.php')
    ->exclude(['AutoUpdate.class.php', 'UploadHandler.class.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'strict_param' => false,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
