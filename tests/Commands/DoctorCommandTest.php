<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Commands;

use PHPUnit\Framework\TestCase;
use QtBuilder\Commands\DoctorCommand;
use QtBuilder\System\QtDetectionResult;
use QtBuilder\Tests\Support\FakeSystemInformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DoctorCommandTest extends TestCase
{
    public function testDoctorReturnsFailureAndJsonWhenChecksFail(): void
    {
        $system = FakeSystemInformation::passing();
        $system->setExtension('cparser', false);
        $command = new DoctorCommand($system);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--format' => 'json']);

        self::assertSame(Command::FAILURE, $exitCode);

        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fail', $payload['summary']['status']);
    }

    public function testDoctorRejectsUnsupportedFormat(): void
    {
        $system = FakeSystemInformation::passing();
        $command = new DoctorCommand($system);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--format' => 'yaml']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unsupported format', $tester->getDisplay());
    }

    public function testDoctorMarksQtCheckAsFailureWhenQtIsNotDetected(): void
    {
        $system = FakeSystemInformation::passing();
        $system->setQtDetectionResult(
            new QtDetectionResult(
                false,
                'No working Qt discovery path found (qtpaths/qmake/pkg-config Qt6Core).',
                ['attempts' => []],
            ),
        );

        $command = new DoctorCommand($system);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--format' => 'json']);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('fail', $payload['summary']['status']);

        $qtCheck = null;
        foreach ($payload['checks'] as $check) {
            if ($check['id'] === 'qt_discovery') {
                $qtCheck = $check;
                break;
            }
        }

        self::assertNotNull($qtCheck);
        self::assertSame('fail', $qtCheck['status']);
    }
}
