<?php

declare(strict_types=1);

// Aserciones de los casos de documentos: lanzan excepción (PHPUnit la cuenta como fallo).
if (false === function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (false === $condition) {
            throw new \RuntimeException('FAIL: ' . $message);
        }
    }

    function same($expected, $actual, string $message): void
    {
        check($expected === $actual, $message . ' Expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }

    function paths(array $errors): array
    {
        return array_column($errors, 'path');
    }
}
