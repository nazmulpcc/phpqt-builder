<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Contracts\SystemInformation;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Qt\QtInstallationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand('inspect', 'Dump class properties, methods, and access specifiers for a Qt class.')]
class InspectCommand extends Command
{
    public function __construct(private readonly SystemInformation $systemInformation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Name of the class to inspect')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: human or json', 'human')
            ->addOption('qt-path', null, InputOption::VALUE_REQUIRED, 'Path to the Qt installation root')
            ->addOption('header', null, InputOption::VALUE_REQUIRED, 'Path to the C++ header file (optional override)')
            ->addOption('include', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional include paths for the compiler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['human', 'json'], true)) {
            $output->writeln(sprintf('<error>Unsupported format "%s". Use human or json.</error>', $format));

            return self::FAILURE;
        }

        $className = (string) $input->getArgument('class');
        $qtPath = $input->getOption('qt-path') !== null ? (string) $input->getOption('qt-path') : null;

        /** @var list<string> $includePaths */
        $includePaths = $input->getOption('include');

        $headerOverride = $input->getOption('header') !== null ? (string) $input->getOption('header') : null;
        $headerPath = $this->resolveHeaderPath($className, $headerOverride, $qtPath, $includePaths);
        if ($headerPath === null) {
            $output->writeln(sprintf('<error>Unable to locate header for class "%s".</error>', $className));
            $output->writeln('<comment>Hint: pass --header /full/path/to/header.h or --qt-path /path/to/qt.</comment>');

            return self::FAILURE;
        }

        $includePaths = [...$includePaths, ...$this->resolveQtIncludeRoots($qtPath)];

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);

        if ($classData === null) {
            $output->writeln(sprintf('<error>Class "%s" not found in %s</error>', $className, $headerPath));

            return self::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln($this->renderJson($classData));
        } else {
            $this->renderHuman($classData, $output);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $classData
     */
    private function renderJson(array $classData): string
    {
        $encoded = json_encode($classData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * @param array<string, mixed> $classData
     */
    private function renderHuman(array $classData, OutputInterface $output): void
    {
        $kind = $classData['is_struct'] ? 'struct' : 'class';
        $abstract = $classData['is_abstract'] ? ' (abstract)' : '';
        $output->writeln(sprintf('<options=bold>%s %s%s</>', $kind, $classData['name'], $abstract));

        if (!empty($classData['bases'])) {
            $output->writeln(sprintf('  Bases: %s', implode(', ', $classData['bases'])));
        }

        $output->writeln('');

        // Properties
        /** @var list<array<string, mixed>> $properties */
        $properties = $classData['properties'];
        if (\count($properties) > 0) {
            $output->writeln('<options=bold>Properties</>');
            foreach ($properties as $prop) {
                $static = $prop['is_static'] ? 'static ' : '';
                $output->writeln(sprintf(
                    '  [%s] %s%s %s',
                    $this->colorAccess((string) $prop['access']),
                    $static,
                    $prop['type'],
                    $prop['name'],
                ));
            }
            $output->writeln('');
        }

        // Methods
        /** @var list<array<string, mixed>> $methods */
        $methods = $classData['methods'];
        if (\count($methods) > 0) {
            $output->writeln('<options=bold>Methods</>');
            foreach ($methods as $method) {
                $qualifiers = [];
                if ($method['is_static']) {
                    $qualifiers[] = 'static';
                }
                if ($method['is_virtual']) {
                    $qualifiers[] = 'virtual';
                }
                if ($method['is_pure_virtual']) {
                    $qualifiers[] = '= 0';
                }
                if ($method['is_const']) {
                    $qualifiers[] = 'const';
                }
                if ($method['is_override']) {
                    $qualifiers[] = 'override';
                }
                if (($method['is_signal'] ?? false) === true) {
                    $qualifiers[] = 'signal';
                }
                if (($method['is_slot'] ?? false) === true) {
                    $qualifiers[] = 'slot';
                }

                $qualifierStr = \count($qualifiers) > 0 ? ' {' . implode(', ', $qualifiers) . '}' : '';
                $params = array_map(
                    static function (array $p): string {
                        $default = $p['has_default'] ? ' = ...' : '';

                        return sprintf('%s %s%s', $p['type'], $p['name'], $default);
                    },
                    $method['parameters'],
                );

                $output->writeln(sprintf(
                    '  [%s] %s %s(%s)%s',
                    $this->colorAccess((string) $method['access']),
                    $method['return_type'],
                    $method['name'],
                    implode(', ', $params),
                    $qualifierStr,
                ));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Summary: %d properties, %d methods</info>',
            \count($properties),
            \count($methods),
        ));
    }

    private function colorAccess(string $access): string
    {
        return match ($access) {
            'public' => '<info>public</info>',
            'protected' => '<comment>protected</comment>',
            'private' => '<error>private</error>',
            default => $access,
        };
    }

    /**
     * @return list<string>
     */
    private function resolveQtIncludeRoots(?string $qtPath): array
    {
        try {
            $installation = (new QtInstallationResolver($this->systemInformation))->resolve($qtPath, ['QtCore']);
        } catch (Throwable) {
            return [];
        }

        return $installation->includeRoots;
    }

    /**
     * @param list<string> $includePaths
     */
    private function resolveHeaderPath(string $className, ?string $headerOverride, ?string $qtPath, array $includePaths): ?string
    {
        if ($headerOverride !== null && $headerOverride !== '') {
            $resolved = realpath($headerOverride);

            return $resolved !== false && is_file($resolved) ? $resolved : null;
        }

        if (!preg_match('/^Q[A-Za-z0-9_]+$/', $className)) {
            return null;
        }

        $roots = $this->discoverHeaderSearchRoots($qtPath, $includePaths);

        $candidates = [];
        foreach ($roots as $root) {
            $candidates[] = $root . '/' . $className;
            $moduleCandidates = glob($root . '/Qt*/' . $className);
            if ($moduleCandidates !== false) {
                foreach ($moduleCandidates as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $parsed = $this->resolveParseHeader($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $implHeader = strtolower($className) . '.h';
        $fallbacks = [];
        foreach ($roots as $root) {
            $fallbacks[] = $root . '/' . $implHeader;
            $moduleCandidates = glob($root . '/Qt*/' . $implHeader);
            if ($moduleCandidates !== false) {
                foreach ($moduleCandidates as $candidate) {
                    $fallbacks[] = $candidate;
                }
            }
        }

        foreach ($fallbacks as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved !== false) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param list<string> $includePaths
     * @return list<string>
     */
    private function discoverHeaderSearchRoots(?string $qtPath, array $includePaths): array
    {
        $roots = [];

        if ($qtPath !== null && $qtPath !== '') {
            $resolvedQtPath = realpath($qtPath) ?: $qtPath;
            $roots[] = $resolvedQtPath . '/include';
            $roots[] = $resolvedQtPath . '/lib';
        }

        $qtDetection = $this->systemInformation->detectQt();
        if ($qtDetection->isDetected()) {
            $meta = $qtDetection->getMeta();
            $headersPath = is_string($meta['headers'] ?? null) ? $meta['headers'] : null;
            $libsPath = is_string($meta['libs'] ?? null) ? $meta['libs'] : null;
            $hostPrefix = is_string($meta['host_prefix'] ?? null) ? $meta['host_prefix'] : null;

            if ($headersPath !== null) {
                $roots[] = $headersPath;
            }
            if ($libsPath !== null) {
                $roots[] = $libsPath;
            }
            if ($hostPrefix !== null && $hostPrefix !== '') {
                $resolvedHostPrefix = realpath($hostPrefix) ?: $hostPrefix;
                $roots[] = $resolvedHostPrefix . '/include';
                $roots[] = $resolvedHostPrefix . '/lib';
            }
        }

        foreach ($includePaths as $includePath) {
            if ($includePath === '' || str_starts_with($includePath, '-')) {
                continue;
            }

            $roots[] = $includePath;
        }

        $frameworkRoots = [];
        foreach ($roots as $root) {
            $frameworkHeaders = glob(rtrim($root, '/') . '/Qt*.framework/Headers');
            if ($frameworkHeaders === false) {
                continue;
            }

            foreach ($frameworkHeaders as $frameworkHeader) {
                $frameworkRoots[] = $frameworkHeader;
            }
        }

        $roots = [...$roots, ...$frameworkRoots];
        $roots = array_values(array_unique(array_filter(
            $roots,
            static fn(string $path): bool => is_dir($path),
        )));

        return $roots;
    }

    private function resolveParseHeader(string $publicHeader): ?string
    {
        $contents = file_get_contents($publicHeader);
        if ($contents === false) {
            $resolved = realpath($publicHeader);

            return $resolved !== false ? $resolved : null;
        }

        if (preg_match('/#include\s+"([^"]+)"/', $contents, $matches) === 1) {
            $candidate = dirname($publicHeader) . '/' . $matches[1];
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        $resolved = realpath($publicHeader);

        return $resolved !== false ? $resolved : null;
    }
}
