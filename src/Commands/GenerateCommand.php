<?php

declare(strict_types=1);

namespace QtBuilder\Commands;

use QtBuilder\CodeGen\ExtensionGenerator;
use QtBuilder\Parsing\ClassDefinitionBuilder;
use QtBuilder\Parsing\ClangArgumentBuilder;
use QtBuilder\Parsing\QtClassInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('generate', 'Generate C/C++ PHP extension code from a Qt header.')]
class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('header', InputArgument::REQUIRED, 'Path to the Qt C++ header file')
            ->addArgument('class', InputArgument::REQUIRED, 'Name of the class to wrap')
            ->addOption('namespace', 'N', InputOption::VALUE_REQUIRED, 'PHP namespace (e.g. Qt\\Core)', 'Qt\\Core')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory for generated files', 'ext/')
            ->addOption('include', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional include paths for the compiler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headerPath = (string) $input->getArgument('header');
        $className = (string) $input->getArgument('class');
        $namespace = (string) $input->getOption('namespace');
        $outputDir = (string) $input->getOption('output');

        if (!file_exists($headerPath)) {
            $output->writeln(sprintf('<error>Header file not found: %s</error>', $headerPath));

            return self::FAILURE;
        }

        $headerPath = realpath($headerPath) ?: $headerPath;

        /** @var list<string> $includePaths */
        $includePaths = $input->getOption('include');

        // Layer 2: Parse header and build IR
        $output->writeln(sprintf('<info>Parsing %s for class %s...</info>', basename($headerPath), $className));

        $inspector = new QtClassInspector(new ClangArgumentBuilder($includePaths));
        $classData = $inspector->inspect($headerPath, $className);

        if ($classData === null) {
            $output->writeln(sprintf('<error>Class "%s" not found in %s</error>', $className, $headerPath));

            return self::FAILURE;
        }

        $builder = new ClassDefinitionBuilder();
        $phpClass = $builder->build($classData);

        $output->writeln(sprintf(
            '  Found %d methods (%d overloaded), %d properties',
            count($phpClass->methods),
            count(array_filter($phpClass->methods, fn($m) => $m->isOverloaded())),
            count($phpClass->properties),
        ));

        // Layer 3: Generate code
        $output->writeln(sprintf('<info>Generating extension code to %s...</info>', $outputDir));

        $generator = new ExtensionGenerator();
        $files = $generator->generate($phpClass, $namespace, $outputDir);

        foreach ($files as $file) {
            $output->writeln(sprintf('  <comment>Wrote:</comment> %s', $file));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done. Run gen_stub.php on %s/%s.stub.php to generate _arginfo.h</info>',
            $outputDir,
            $generator->buildContext($phpClass, $namespace)->filePrefix,
        ));

        return self::SUCCESS;
    }
}
