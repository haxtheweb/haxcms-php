<?php
/**
 * Markdown summary of an Infection JSON report (scripts/infection.sh), for
 * the mutation-testing workflow's job summary: the score and every surviving
 * mutant as file:line and mutator. The PHP side of haxcms-nodejs's
 * scripts/mutation-summary.js.
 *
 *   php scripts/mutation-summary.php system/backend/php/reports/mutation/infection.json
 */
$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    echo "## Mutation testing (saveOutline write path)\n\nNo report at `" . $file . "`: the run did not finish. See the job log.\n";
    exit(0);
}
$report = json_decode((string) file_get_contents($file), true);
if (!is_array($report) || !isset($report['stats'])) {
    echo "## Mutation testing (saveOutline write path)\n\nThe report could not be read.\n";
    exit(0);
}
$s = $report['stats'];
$root = realpath(__DIR__ . '/..') . '/';
$out = array();
$out[] = '## Mutation testing (saveOutline write path)';
$out[] = '';
$out[] = '| Covered code MSI | MSI | Mutants | Killed | Survived | Not covered | Timed out | Errors |';
$out[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
$out[] = sprintf(
    '| %s%% | %s%% | %d | %d | %d | %d | %d | %d |',
    $s['coveredCodeMsi'],
    $s['msi'],
    $s['totalMutantsCount'],
    $s['killedCount'] + ($s['killedByStaticAnalysisCount'] ?? 0),
    $s['escapedCount'],
    $s['notCoveredCount'],
    $s['timeOutCount'],
    $s['errorCount']
);
$out[] = '';
$escaped = $report['escaped'] ?? array();
if (count($escaped) === 0) {
    $out[] = 'No surviving mutants.';
} else {
    $out[] = 'Surviving mutants (a change no saveOutline test noticed):';
    $out[] = '';
    foreach ($escaped as $mutant) {
        $m = $mutant['mutator'];
        $path = str_replace($root, '', (string) $m['originalFilePath']);
        $out[] = sprintf('- `%s:%d` %s', $path, $m['originalStartLine'], $m['mutatorName']);
    }
}
echo implode("\n", $out) . "\n";
