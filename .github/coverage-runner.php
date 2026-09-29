<?php

declare(strict_types=1);

// Coverage gate for the bespoke bin/test_*.php suites: runs the suite under
// php-code-coverage (pcov/xdebug driver) and fails unless every line in src/
// is covered. The suite scripts call exit() themselves, so reporting happens
// in a shutdown function whose exit() overrides the suite's pending code.
// Suite pass/fail is gated by the regular test job; this runner only gates
// coverage.
//
// Usage: php .github/coverage-runner.php bin/test_psr15.php

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

require __DIR__ . '/../vendor/autoload.php';

$suite = $argv[1] ?? null;
if ($suite === null || ! is_file(__DIR__ . '/../' . $suite)) {
    fwrite(STDERR, "usage: php .github/coverage-runner.php <path/to/bin/test_*.php>\n");
    exit(2);
}

$src = __DIR__ . '/../src';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$filter = new Filter();
$filter->includeFiles($files);
$coverage = new CodeCoverage(
    (new Selector())->forLineCoverage($filter),
    $filter,
);

register_shutdown_function(static function () use ($coverage): void {
    try {
        $coverage->stop();
    } catch (Throwable) {
        // the suite may exit before any covered line executes
    }

    // Text report thresholds are irrelevant here: the gate is a hard 100%.
    $text = new Text(Thresholds::default());
    fwrite(STDOUT, PHP_EOL . $text->process($coverage) . PHP_EOL);

    (new PHP())->process($coverage, __DIR__ . '/../coverage.php');

    $lines = $coverage->getReport()->percentageOfExecutedLines()->asFloat();
    printf("COVERAGE: %.2f%% lines%s\n", $lines, $lines < 100.0 ? ' (GATE: FAIL)' : ' (GATE: PASS)');

    if ($lines < 100.0) {
        exit(1);
    }
});

$coverage->start(basename($suite));

require __DIR__ . '/../' . $suite;
