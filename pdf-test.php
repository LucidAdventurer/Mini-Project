<?php

header('Content-Type: text/plain');

$pdf = __DIR__ . '/prepaura_ce_text_only_parser_test.pdf';

echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n\n";

echo "shell_exec exists: ";
var_dump(function_exists('shell_exec'));

echo "disable_functions: ";
var_dump(ini_get('disable_functions'));

echo "\nwhich pdftotext:\n";
var_dump(shell_exec('which pdftotext 2>&1'));

echo "\npdftotext output:\n";
$text = shell_exec(
    '/usr/bin/pdftotext -layout ' .
    escapeshellarg($pdf) .
    ' - 2>&1'
);

var_dump($text);

echo "\ntext length: ";
var_dump(strlen(trim($text ?? '')));
