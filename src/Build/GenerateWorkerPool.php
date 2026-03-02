<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use Symfony\Component\Process\Process;

class GenerateWorkerPool
{
    public function __construct(private readonly string $projectRoot = __DIR__ . '/../..') {}

    /**
     * @param list<GenerateTask> $tasks
     * @return list<GenerateResult>
     */
    public function run(array $tasks, int $jobs): array
    {
        $jobs = max(1, $jobs);
        $queue = array_values($tasks);
        $active = [];
        $results = [];

        while ($queue !== [] || $active !== []) {
            while (count($active) < $jobs && $queue !== []) {
                $task = array_shift($queue);
                $process = new Process($this->commandFor($task));
                $process->setWorkingDirectory($this->projectRoot);
                $process->start();
                $active[] = ['task' => $task, 'process' => $process];
            }

            foreach ($active as $index => $entry) {
                /** @var GenerateTask $task */
                $task = $entry['task'];
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (!$process->isSuccessful()) {
                    $results[] = GenerateResult::error(
                        $task->className,
                        $task->headerPath,
                        $stderr !== '' ? $stderr : $stdout,
                        $stderr,
                    );
                } else {
                    $payload = json_decode($stdout, true);
                    if (!is_array($payload)) {
                        $results[] = GenerateResult::error(
                            $task->className,
                            $task->headerPath,
                            'Worker did not return valid JSON output.',
                            $stderr,
                        );
                    } else {
                        $results[] = GenerateResult::fromPayload($payload, $stderr);
                    }
                }

                unset($active[$index]);
            }

            usleep(10000);
        }

        usort($results, static fn(GenerateResult $a, GenerateResult $b): int => strcmp($a->className, $b->className));

        return $results;
    }

    /**
     * @return list<string>
     */
    private function commandFor(GenerateTask $task): array
    {
        $command = [
            PHP_BINARY,
            $this->projectRoot . '/qtb',
            'generate',
            $task->headerPath,
            $task->className,
            '--namespace=' . $task->namespace,
            '--output=' . $task->outputDir,
            '--output-subdir=classes',
            '--module=' . $task->module,
            '--extension-name=' . $task->extensionName,
            '--build-mode',
            '--worker-mode=' . $task->workerMode,
        ];

        if ($task->qtPath !== null && $task->qtPath !== '') {
            $command[] = '--qt-path=' . $task->qtPath;
        }

        foreach ($task->includePaths as $includePath) {
            $command[] = '--include=' . $includePath;
        }

        if ($task->allowedClassesFile !== null && $task->allowedClassesFile !== '') {
            $command[] = '--allowed-classes-file=' . $task->allowedClassesFile;
        } elseif ($task->allowedClasses !== []) {
            $command[] = '--allowed-classes=' . implode(',', $task->allowedClasses);
        }

        return $command;
    }
}
