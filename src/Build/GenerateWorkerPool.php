<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use Symfony\Component\Process\Process;

class GenerateWorkerPool
{
    public function __construct(private readonly string $projectRoot = __DIR__ . '/../..') {}

    /**
     * @param list<GenerateTask> $tasks
     * @param null|callable(int, int, GenerateResult): void $onProgress
     * @return list<GenerateResult>
     */
    public function run(array $tasks, int $jobs, ?callable $onProgress = null, ?int $progressTotal = null): array
    {
        $jobs = max(1, $jobs);
        $queue = array_values($tasks);
        $active = [];
        $results = [];
        $total = $progressTotal ?? count($queue);
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
                /** @var GenerateTask $task */
                $task = $entry['task'];
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());
                $taskResults = $this->resultsForFinishedProcess($task, $process->isSuccessful(), $stdout, $stderr);
                foreach ($taskResults as $result) {
                    $results[] = $result;
                    $completed++;
                    if ($onProgress !== null) {
                        $onProgress($completed, $total, $result);
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
     * @return list<GenerateResult>
     */
    private function resultsForFinishedProcess(GenerateTask $task, bool $successful, string $stdout, string $stderr): array
    {
        if (!$successful) {
            return $this->errorResultsForTask(
                $task,
                $stderr !== '' ? $stderr : $stdout,
                $stderr,
            );
        }

        $payload = json_decode($stdout, true);
        if (!is_array($payload)) {
            return $this->errorResultsForTask(
                $task,
                'Worker did not return valid JSON output.',
                $stderr,
            );
        }

        if (!is_array($payload['results'] ?? null)) {
            return [GenerateResult::fromPayload($payload, $stderr)];
        }

        $results = [];
        foreach ($payload['results'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $results[] = GenerateResult::fromPayload($entry, $stderr);
        }

        if ($results === []) {
            return $this->errorResultsForTask(
                $task,
                'Batch worker returned no result entries.',
                $stderr,
            );
        }

        return $results;
    }

    /**
     * @return list<GenerateResult>
     */
    private function errorResultsForTask(GenerateTask $task, string $reasonMessage, string $stderr): array
    {
        $batchEntries = $this->readBatchEntries($task);
        if ($batchEntries === []) {
            return [GenerateResult::error(
                $task->className,
                $task->headerPath,
                $reasonMessage,
                $stderr,
                $task->candidateKey,
            )];
        }

        $results = [];
        foreach ($batchEntries as $entry) {
            $results[] = GenerateResult::error(
                className: $entry['class'],
                headerPath: $task->headerPath,
                reasonMessage: $reasonMessage,
                stderr: $stderr,
                candidateKey: $entry['task_key'],
            );
        }

        return $results;
    }

    /**
     * @return list<array{class: string, task_key: string|null}>
     */
    private function readBatchEntries(GenerateTask $task): array
    {
        if ($task->classBatchFile === null || $task->classBatchFile === '' || !is_file($task->classBatchFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($task->classBatchFile), true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $className = is_string($entry['class'] ?? null) ? trim((string) $entry['class']) : '';
            if ($className === '') {
                continue;
            }

            $taskKey = is_string($entry['task_key'] ?? null) ? trim((string) $entry['task_key']) : null;
            $entries[] = [
                'class' => $className,
                'task_key' => $taskKey !== '' ? $taskKey : null,
            ];
        }

        return $entries;
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

        if ($task->candidateKey !== null && $task->candidateKey !== '') {
            $command[] = '--task-key=' . $task->candidateKey;
        }

        foreach ($task->includePaths as $includePath) {
            $command[] = '--include=' . $includePath;
        }

        if ($task->allowedClassesFile !== null && $task->allowedClassesFile !== '') {
            $command[] = '--allowed-classes-file=' . $task->allowedClassesFile;
        } elseif ($task->allowedClasses !== []) {
            $command[] = '--allowed-classes=' . implode(',', $task->allowedClasses);
        }

        if ($task->classBatchFile !== null && $task->classBatchFile !== '') {
            $command[] = '--class-batch-file=' . $task->classBatchFile;
        }

        if ($task->classNamespacesFile !== null && $task->classNamespacesFile !== '') {
            $command[] = '--class-namespaces-file=' . $task->classNamespacesFile;
        }

        if ($task->classHeadersFile !== null && $task->classHeadersFile !== '') {
            $command[] = '--class-headers-file=' . $task->classHeadersFile;
        }

        return $command;
    }
}
