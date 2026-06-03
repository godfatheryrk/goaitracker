<?php

/**
 * PostToolUse hook: type-check an edited PHP file with Larastan (PHPStan).
 *
 * Clean result -> exit 0 (silent). Type errors -> write them to STDERR and exit 2,
 * the one PostToolUse exit code Claude Code feeds back into the agent's context, so
 * the agent sees the errors and can fix them. Any OTHER non-zero code would be logged
 * but NOT shown to the agent — that is exactly why we use exit 2 deliberately.
 *
 * Scoped to the same source dirs as `composer analyse` (app/, routes/, database/);
 * skips Blade, files outside those dirs, and is a no-op if Larastan is absent.
 */
$payload = json_decode((string) file_get_contents('php://stdin'), true) ?: [];
$file = $payload['tool_input']['file_path'] ?? null;

if (! is_string($file) || ! is_file($file) || ! str_ends_with($file, '.php') || str_ends_with($file, '.blade.php')) {
    exit(0);
}

$root = dirname(__DIR__, 2);                       // .claude/hooks -> project root
$fileNorm = str_replace('\\', '/', $file);
$rootNorm = str_replace('\\', '/', $root);

// Only analyse files inside the configured analysis paths (matches composer analyse scope).
$inScope = false;
foreach (['/app/', '/routes/', '/database/'] as $dir) {
    if (str_starts_with($fileNorm, $rootNorm.$dir)) {
        $inScope = true;
        break;
    }
}
if (! $inScope) {
    exit(0);
}

$phpstan = $root.'/vendor/bin/phpstan';
$config = $root.'/phpstan.neon.dist';
if (! is_file($phpstan)) {
    exit(0);                                       // Larastan not installed -> no-op
}

$cmd = sprintf(
    'php %s analyse %s -c %s --no-progress --error-format=raw --memory-limit=512M 2>&1',
    escapeshellarg($phpstan),
    escapeshellarg($file),
    escapeshellarg($config),
);
exec($cmd, $output, $exitCode);

if ($exitCode === 0) {
    exit(0);                                       // clean -> silent
}

// Type errors found: surface them to the agent via STDERR + exit 2.
fwrite(STDERR, "Larastan found type issues in {$file}:\n".implode("\n", $output)."\n");
exit(2);
