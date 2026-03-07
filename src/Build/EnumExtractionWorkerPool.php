<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use Symfony\Component\Process\Process;

class EnumExtractionWorkerPool
{
    public function __construct(private readonly string $projectRoot = __DIR__ . '/../..') {}

    /**
     * @param list<EnumExtractionTask> $tasks
     * @param null|callable(int, int, EnumExtractionResult): void $onProgress
     * @return list<EnumExtractionResult>
     */
    public function run(array $tasks, int $jobs, ?callable $onProgress = null): array
    {
        $jobs = max(1, $jobs);
        $queue = array_values($tasks);
        $active = [];
        $results = [];
        $total = count($queue);
        $completed = 0;

        while ($queue !== [] || $active !== []) {
            while (count($active) < $jobs && $queue !== []) {
                $task = array_shift($queue);
                $process = new Process($this->commandFor($task));
                $process->setWorkingDirectory($this->projectRoot);
                $process->start();
                $active[] = ['task' => $task, 'process' => $process];
            }

            foreach ($active as $index => $entry) {
                /** @var EnumExtractionTask $task */
                $task = $entry['task'];
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (!$process->isSuccessful()) {
                    $result = EnumExtractionResult::error(
                        $task->headerPath,
                        $stderr !== '' ? $stderr : $stdout,
                        $stderr,
                    );
                } else {
                    $payload = json_decode($stdout, true);
                    if (!is_array($payload)) {
                        $result = EnumExtractionResult::error(
                            $task->headerPath,
                            'Worker did not return valid JSON output.',
                            $stderr,
                        );
                    } else {
                        $result = EnumExtractionResult::fromPayload($payload, $stderr);
                    }
                }

                $results[] = $result;
                $completed++;
                if ($onProgress !== null) {
                    $onProgress($completed, $total, $result);
                }

                unset($active[$index]);
            }

            usleep(10000);
        }

        usort($results, static fn(EnumExtractionResult $a, EnumExtractionResult $b): int => strcmp($a->headerPath, $b->headerPath));

        return $results;
    }

    /**
     * @return list<string>
     */
    private function commandFor(EnumExtractionTask $task): array
    {
        $command = [
            PHP_BINARY,
            $this->projectRoot . '/qtb',
            'generate',
            $task->headerPath,
            basename($task->headerPath),
            '--namespace=Qt\\Core',
            '--output=' . sys_get_temp_dir(),
            '--output-subdir=classes',
            '--module=' . $task->module,
            '--extension-name=qt',
            '--build-mode',
            '--worker-mode=enum-facts',
        ];

        foreach ($task->includePaths as $includePath) {
            $command[] = '--include=' . $includePath;
        }

        if ($task->knownClassesFile !== null && $task->knownClassesFile !== '') {
            $command[] = '--known-classes-file=' . $task->knownClassesFile;
        }

        return $command;
    }
}
