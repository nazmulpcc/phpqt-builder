<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\Definition\PhpClass;
use QtBuilder\Definition\PhpMethod;
use QtBuilder\Definition\PhpParameter;
use QtBuilder\Definition\PhpProperty;
use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use QtBuilder\Support\CppClassTypeResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('define', 'Show the merged PHP class definition derived from a C++ header.')]
class DefineCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('header', InputArgument::REQUIRED, 'Path to the C++ header file')
            ->addArgument('class', InputArgument::REQUIRED, 'Name of the class to define')
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

        $builder = new ClassDefinitionBuilder();
        $phpClass = $builder->build(
            $classData,
            CppClassTypeResolver::forSingleClass(
                $className,
                is_string($classData['qualified_name'] ?? null) ? (string) $classData['qualified_name'] : null,
            ),
        );

        if ($format === 'json') {
            $output->writeln($this->renderJson($phpClass));
        } else {
            $this->renderHuman($phpClass, $output);
        }

        return self::SUCCESS;
    }

    private function renderJson(PhpClass $phpClass): string
    {
        $encoded = json_encode($phpClass->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
    }

    private function renderHuman(PhpClass $phpClass, OutputInterface $output): void
    {
        // Class header
        $abstract = $phpClass->isAbstract ? 'abstract ' : '';
        $extends = $phpClass->parent !== null ? ' extends ' . $phpClass->parent : '';
        $output->writeln(sprintf('<options=bold>%sclass %s%s</>', $abstract, $phpClass->name, $extends));
        $output->writeln('{');

        // Properties
        if (\count($phpClass->properties) > 0) {
            foreach ($phpClass->properties as $prop) {
                $this->renderProperty($prop, $output);
            }
            $output->writeln('');
        }

        // Methods
        foreach ($phpClass->methods as $method) {
            $this->renderMethod($method, $output);
        }

        $output->writeln('}');
        $output->writeln('');

        // Summary
        $summary = $phpClass->toArray()['summary'];
        $output->writeln(sprintf(
            '<info>Summary: %d methods (%d public, %d protected), %d overloaded, %d properties</info>',
            $summary['total_methods'],
            $summary['public_methods'],
            $summary['protected_methods'],
            $summary['overloaded_methods'],
            $summary['total_properties'],
        ));
    }

    private function renderProperty(PhpProperty $prop, OutputInterface $output): void
    {
        $static = $prop->isStatic ? 'static ' : '';
        $output->writeln(sprintf(
            '    %s %s%s $%s; <comment>// C++: %s</comment>',
            $this->colorAccess($prop->access),
            $static,
            $prop->phpType,
            $prop->name,
            $prop->cppType,
        ));
    }

    private function renderMethod(PhpMethod $method, OutputInterface $output): void
    {
        $static = $method->isStatic ? 'static ' : '';
        $params = array_map($this->formatParameter(...), $method->parameters);
        $overloadNote = $method->isOverloaded()
            ? sprintf(' <comment>// %d C++ overloads</comment>', $method->overloadCount())
            : '';

        $output->writeln(sprintf(
            '    %s %sfunction %s(%s): %s;%s',
            $this->colorAccess($method->access),
            $static,
            $method->name,
            implode(', ', $params),
            $method->returnType,
            $overloadNote,
        ));
    }

    private function formatParameter(PhpParameter $param): string
    {
        $default = $param->hasDefault ? ' = ...' : '';

        return sprintf('%s $%s%s', $param->phpType, $param->name, $default);
    }

    private function colorAccess(string $access): string
    {
        return match ($access) {
            'public' => '<info>public</info>',
            'protected' => '<comment>protected</comment>',
            default => $access,
        };
    }
}
