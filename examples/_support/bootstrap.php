<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

if (!extension_loaded('qt')) {
    fwrite(STDERR, "The qt extension is not loaded.\n");
    fwrite(STDERR, "Run with: php -dextension=\$PWD/build/ext/.libs/qt.so examples/<example>/run.php\n");
    exit(1);
}

spl_autoload_register(static function (string $class) use ($repoRoot): void {
    $prefix = 'Examples\\Support\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $repoRoot . '/examples/_support/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function example_section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function example_line(string $message): void
{
    echo $message . PHP_EOL;
}

function example_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function example_repo_root(): string
{
    return dirname(__DIR__, 2);
}

function example_auto_quit_seconds(): int
{
    $value = getenv('QT_EXAMPLE_AUTO_QUIT_SECONDS');
    if ($value === false || $value === '') {
        return 0;
    }

    return max(0, (int) $value);
}
