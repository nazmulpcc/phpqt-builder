<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\IO\FileWriteStats;
use Symfony\Component\Process\Process;

class EmissionWorkerPool
{
    public function __construct(private readonly string $projectRoot = __DIR__ . '/../..') {}

    /**
     * @param list<EmissionTask> $tasks
     * @param null|callable(int, int, EmissionBatchResult): void $onProgress
     * @return list<EmissionBatchResult>
     */
    public function run(array $tasks, int $jobs, ?callable $onProgress = null, ?int $totalClasses = null): array
    {
        $jobs = max(1, $jobs);
        $queue = array_values($tasks);
        $active = [];
        $results = [];
        $total = $totalClasses ?? count($queue);
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
                /** @var EmissionTask $task */
                $task = $entry['task'];
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (!$process->isSuccessful()) {
                    $result = EmissionBatchResult::error($stderr !== '' ? $stderr : $stdout);
                } else {
                    $payload = json_decode($stdout, true);
                    if (!is_array($payload)) {
                        $result = EmissionBatchResult::error('Emit worker did not return valid JSON output: ' . ($stderr !== '' ? $stderr : $stdout));
                    } elseif (($payload['status'] ?? '') !== 'ok') {
                        $result = EmissionBatchResult::error((string) ($payload['reason_message'] ?? 'Emit worker failed'));
                    } else {
                        $stats = FileWriteStats::fromArray((array) ($payload['file_write_stats'] ?? []));
                        $classmap = (array) ($payload['classmap'] ?? []);
                        $result = EmissionBatchResult::ok($stats, $classmap);
                    }
                }

                $results[] = $result;
                $completed += $task->classCount;
                if ($onProgress !== null) {
                    $onProgress($completed, $total, $result);
                }

                unset($active[$index]);
            }

            usleep(10000);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function commandFor(EmissionTask $task): array
    {
        return [
            PHP_BINARY,
            $this->projectRoot . '/qtb',
            'generate',
            __FILE__,
            'DummyEmissionClass',
            '--namespace=Qt\\Core',
            '--output=' . $task->outputDir,
            '--module=QtCore',
            '--build-mode',
            '--worker-mode=emit-batch',
            '--class-batch-file=' . $task->batchFile,
        ];
    }
}
