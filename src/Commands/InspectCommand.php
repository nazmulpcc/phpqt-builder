<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('inspect', 'Dump class properties, methods, and access specifiers from a C++ header.')]
class InspectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('header', InputArgument::REQUIRED, 'Path to the C++ header file')
            ->addArgument('class', InputArgument::REQUIRED, 'Name of the class to inspect')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: human or json', 'human')
            ->addOption('include', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional include paths for the compiler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['human', 'json'], true)) {
            $output->writeln(sprintf('<error>Unsupported format "%s". Use human or json.</error>', $format));

            return self::FAILURE;
        }

        $headerPath = (string) $input->getArgument('header');
        $className = (string) $input->getArgument('class');

        if (!file_exists($headerPath)) {
            $output->writeln(sprintf('<error>Header file not found: %s</error>', $headerPath));

            return self::FAILURE;
        }

        $headerPath = realpath($headerPath) ?: $headerPath;

        /** @var list<string> $includePaths */
        $includePaths = $input->getOption('include');

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
}
