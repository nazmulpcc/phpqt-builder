<?php

declare(strict_types=1);

namespace QtBuilder\Build;

use QtBuilder\Contracts\SystemInformation;

final class IosToolchainResolver
{
    public function __construct(private readonly SystemInformation $systemInformation) {}

    public function resolve(IosBuildOptions $options): IosToolchain
    {
        if ($this->systemInformation->getOsFamily() !== 'Darwin') {
            throw new \RuntimeException('The iOS build target is only supported on macOS hosts.');
        }

        $developerDir = $options->developerDir;
        if ($developerDir === null || $developerDir === '') {
            $xcodeSelect = $this->requireCommand(['xcode-select', '-p'], 'Could not resolve the Xcode developer directory.');
            $developerDir = trim($xcodeSelect->getStdout());
        }

        $sdks = [];
        foreach ($options->sdks as $sdk) {
            $sdkPath = trim($this->requireCommand(
                ['xcrun', '--sdk', $sdk, '--show-sdk-path'],
                sprintf('Could not resolve the Apple SDK path for %s.', $sdk),
                $developerDir,
            )->getStdout());
            $clangxxPath = trim($this->requireCommand(
                ['xcrun', '--sdk', $sdk, '--find', 'clang++'],
                sprintf('Could not locate clang++ for %s.', $sdk),
                $developerDir,
            )->getStdout());
            $libtoolPath = trim($this->requireCommand(
                ['xcrun', '--sdk', $sdk, '--find', 'libtool'],
                sprintf('Could not locate libtool for %s.', $sdk),
                $developerDir,
            )->getStdout());

            $sdks[$sdk] = new IosSdkToolchain(
                sdk: $sdk,
                sdkPath: $sdkPath,
                clangxxPath: $clangxxPath,
                libtoolPath: $libtoolPath,
                architectures: $options->architectures,
                minimumVersion: $options->minimumVersion,
            );
        }

        return new IosToolchain($developerDir, $sdks);
    }

    private function requireCommand(array $command, string $errorMessage, ?string $developerDir = null): \QtBuilder\System\CommandResult
    {
        $result = $this->systemInformation->runCommand($command, 10.0);
        if ($result->isSuccessful()) {
            return $result;
        }

        if ($developerDir !== null && $developerDir !== '') {
            $result = $this->systemInformation->runCommand(
                ['env', 'DEVELOPER_DIR=' . $developerDir, ...$command],
                10.0,
            );
            if ($result->isSuccessful()) {
                return $result;
            }
        }

        throw new \RuntimeException($errorMessage);
    }
}
