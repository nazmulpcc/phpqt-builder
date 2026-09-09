<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use Symfony\Component\Process\Process;

class AnalysisWorkerPool
{
    public function __construct(private readonly string $projectRoot = __DIR__ . '/../..') {}

    /**
     * @param list<AnalysisTask> $tasks
     * @param null|callable(int, int, AnalysisBatchResult): void $onProgress
     * @return list<AnalysisBatchResult>
     */
    public function run(array $tasks, int $jobs, ?callable $onProgress = null, ?int $totalCandidates = null): array
    {
        $jobs = max(1, $jobs);
        $queue = array_values($tasks);
        $active = [];
        $results = [];
        $total = $totalCandidates ?? count($queue);
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
                /** @var AnalysisTask $task */
                $task = $entry['task'];
                /** @var Process $process */
                $process = $entry['process'];

                if ($process->isRunning()) {
                    continue;
                }

                $stdout = trim($process->getOutput());
                $stderr = trim($process->getErrorOutput());

                if (!$process->isSuccessful()) {
                    $result = AnalysisBatchResult::error($stderr !== '' ? $stderr : $stdout);
                } else {
                    $payload = json_decode($stdout, true);
                    if (!is_array($payload)) {
                        $result = AnalysisBatchResult::error('Analysis worker did not return valid JSON output: ' . ($stderr !== '' ? $stderr : $stdout));
                    } elseif (($payload['status'] ?? '') !== 'ok') {
                        $result = AnalysisBatchResult::error((string) ($payload['reason_message'] ?? 'Analysis worker failed'));
                    } else {
                        $batchResults = (array) ($payload['results'] ?? []);
                        $result = AnalysisBatchResult::ok($batchResults);
                    }
                }

                $results[] = $result;
                $completed += $task->candidateCount;
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
    private function commandFor(AnalysisTask $task): array
    {
        return [
            PHP_BINARY,
            $this->projectRoot . '/qtb',
            'generate',
            __FILE__,
            'DummyAnalysisClass',
            '--namespace=Qt\\Core',
            '--output=' . sys_get_temp_dir(),
            '--module=QtCore',
            '--build-mode',
            '--worker-mode=analysis-batch',
            '--class-batch-file=' . $task->batchFile,
        ];
    }
}
