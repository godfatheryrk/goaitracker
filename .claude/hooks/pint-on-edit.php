<?php

/**
 * PostToolUse hook: silently auto-format an edited PHP file with Laravel Pint.
 * PHP-only; skips Blade templates and vendor/. No output back to Claude.
 *
 * Wired up in .claude/settings.json for the Write|Edit tools. Reads the hook
 * payload from stdin and formats the just-edited file in place ("format on save").
 */
$payload = json_decode((string) file_get_contents('php://stdin'), true) ?: [];
$file = $payload['tool_input']['file_path'] ?? null;

// Format real PHP source only — not Blade (Pint would mangle it), not dependencies.
if (
    ! is_string($file)
    || ! is_file($file)
    || ! str_ends_with($file, '.php')
    || str_ends_with($file, '.blade.php')
    || str_contains(str_replace('\\', '/', $file), '/vendor/')
) {
    exit(0);
}

$pint = dirname(__DIR__, 2).'/vendor/bin/pint';   // .claude/hooks -> project root
if (is_file($pint)) {
    exec(sprintf('php %s %s 2>&1', escapeshellarg($pint), escapeshellarg($file)));
}

exit(0);                                          // always succeed, stay silent
