<?php
require_once dirname(__DIR__) . '/lib/SanitizeContent.php';

$payloadFile = __DIR__ . '/sanitize-policy-payloads.json';
$payloadContent = file_get_contents($payloadFile);
if ($payloadContent === false) {
    fwrite(STDERR, "Unable to read payload file: " . $payloadFile . PHP_EOL);
    exit(1);
}

$payloads = json_decode($payloadContent, true);
if (!is_array($payloads)) {
    fwrite(STDERR, "Unable to parse payload JSON." . PHP_EOL);
    exit(1);
}

$hasFailure = false;

foreach ($payloads as $payload) {
    $name = isset($payload['name']) ? $payload['name'] : 'unnamed';
    $input = isset($payload['input']) ? $payload['input'] : '';
    $output = SanitizeContent::sanitizeHTMLForStorage($input);

    $mustContain = isset($payload['mustContain']) && is_array($payload['mustContain'])
        ? $payload['mustContain']
        : [];
    $mustNotContain = isset($payload['mustNotContain']) && is_array($payload['mustNotContain'])
        ? $payload['mustNotContain']
        : [];

    foreach ($mustContain as $requiredSnippet) {
        if (strpos($output, $requiredSnippet) === false) {
            $hasFailure = true;
            fwrite(STDERR, 'FAIL [' . $name . '] missing snippet: ' . $requiredSnippet . PHP_EOL);
            fwrite(STDERR, 'Output: ' . $output . PHP_EOL);
        }
    }

    foreach ($mustNotContain as $forbiddenSnippet) {
        if (strpos($output, $forbiddenSnippet) !== false) {
            $hasFailure = true;
            fwrite(STDERR, 'FAIL [' . $name . '] found forbidden snippet: ' . $forbiddenSnippet . PHP_EOL);
            fwrite(STDERR, 'Output: ' . $output . PHP_EOL);
        }
    }
}

if ($hasFailure) {
    exit(1);
}

fwrite(STDOUT, 'Sanitizer policy verification passed for ' . count($payloads) . ' payloads.' . PHP_EOL);
