<?php

namespace QtBuilder\Preflight;

final readonly class PreflightReport
{
    /**
     * @param list<CheckResult> $checks
     */
    public function __construct(private array $checks)
    {
    }

    /**
     * @return list<CheckResult>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->getStatus() === CheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }

    public function getFailedCount(): int
    {
        return $this->countByStatus(CheckStatus::Fail);
    }

    public function getWarningCount(): int
    {
        return $this->countByStatus(CheckStatus::Warn);
    }

    public function getPassedCount(): int
    {
        return $this->countByStatus(CheckStatus::Pass);
    }

    public function getOverallStatus(): CheckStatus
    {
        if ($this->hasFailures()) {
            return CheckStatus::Fail;
        }

        if ($this->getWarningCount() > 0) {
            return CheckStatus::Warn;
        }

        return CheckStatus::Pass;
    }

    private function countByStatus(CheckStatus $status): int
    {
        $count = 0;

        foreach ($this->checks as $check) {
            if ($check->getStatus() === $status) {
                $count++;
            }
        }

        return $count;
    }
}
