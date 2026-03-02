<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Support;

use QtBuilder\Contracts\SystemInformation;
use QtBuilder\System\QtDetectionResult;

final class FakeSystemInformation implements SystemInformation
{
    /**
     * @var array<string, bool>
     */
    private array $extensions = [];

    /**
     * @var array<string, string>
     */
    private array $executables = [];

    private QtDetectionResult $qtDetectionResult;

    public function __construct(
        private string $osFamily = 'Linux',
        private string $osName = 'Linux',
        private string $kernelVersion = '6.8.0',
        private string $architecture = 'x86_64',
        private string $phpVersion = '8.4.0',
    ) {
        $this->qtDetectionResult = new QtDetectionResult(
            false,
            'No working Qt discovery path found (qtpaths/qmake/pkg-config Qt6Core).',
            ['attempts' => []],
        );
    }

    public static function passing(): self
    {
        $instance = new self();
        $instance->setExtension('cparser', true);
        $instance->setExecutable('qtpaths6', '/usr/bin/qtpaths6');
        $instance->setExecutable('c++', '/usr/bin/c++');
        $instance->setExecutable('phpize', '/usr/bin/phpize');
        $instance->setExecutable('php-config', '/usr/bin/php-config');
        $instance->setExecutable('make', '/usr/bin/make');
        $instance->setExecutable('cmake', '/usr/bin/cmake');
        $instance->setQtDetectionResult(
            new QtDetectionResult(
                true,
                'Qt discovered via qtpaths6.',
                ['tool' => 'qtpaths6', 'path' => '/usr/bin/qtpaths6'],
            ),
        );

        return $instance;
    }

    public function setOsFamily(string $osFamily): void
    {
        $this->osFamily = $osFamily;
    }

    public function setPhpVersion(string $phpVersion): void
    {
        $this->phpVersion = $phpVersion;
    }

    public function setExtension(string $name, bool $loaded): void
    {
        $this->extensions[$name] = $loaded;
    }

    public function setExecutable(string $name, ?string $path): void
    {
        if ($path === null) {
            unset($this->executables[$name]);

            return;
        }

        $this->executables[$name] = $path;
    }

    public function setQtDetectionResult(QtDetectionResult $qtDetectionResult): void
    {
        $this->qtDetectionResult = $qtDetectionResult;
    }

    public function getOsFamily(): string
    {
        return $this->osFamily;
    }

    public function getOsName(): string
    {
        return $this->osName;
    }

    public function getKernelVersion(): string
    {
        return $this->kernelVersion;
    }

    public function getArchitecture(): string
    {
        return $this->architecture;
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }

    public function hasExtension(string $extension): bool
    {
        return $this->extensions[$extension] ?? false;
    }

    public function findExecutable(string $name): ?string
    {
        return $this->executables[$name] ?? null;
    }

    public function detectQt(): QtDetectionResult
    {
        return $this->qtDetectionResult;
    }
}
