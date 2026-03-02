<?php

declare(strict_types=1);

namespace QtBuilder\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ExtensionSmokeTest extends TestCase
{
    private static ?string $extensionPath = null;

    public static function setUpBeforeClass(): void
    {
        $configured = getenv('PHPQT_EXTENSION');
        if (is_string($configured) && $configured !== '') {
            self::$extensionPath = $configured;
            return;
        }

        $default = dirname(__DIR__, 2) . '/build/ext/.libs/qt.so';
        self::$extensionPath = is_file($default) ? $default : null;
    }

    public function testQObjectSupportsBasicLifecycleMethods(): void
    {
        $payload = $this->runPhp(<<<'PHP'
        $parent = new \Qt\Core\QObject();
        $child = new \Qt\Core\QObject();
        $child->setObjectName('Smoke');
        $child->setParent($parent);

        echo json_encode([
            'name' => $child->objectName(),
            'has_parent' => $child->parent() instanceof \Qt\Core\QObject,
            'inherits_qobject' => $child->inherits('QObject'),
        ], JSON_THROW_ON_ERROR);
        PHP);

        self::assertSame('Smoke', $payload['name']);
        self::assertTrue($payload['has_parent']);
        self::assertTrue($payload['inherits_qobject']);
    }

    public function testQDateSupportsMutationAndFieldAccess(): void
    {
        $payload = $this->runPhp(<<<'PHP'
        $date = new \Qt\Core\QDate();

        echo json_encode([
            'was_null' => $date->isNull(),
            'set_ok' => $date->setDate(2025, 3, 2),
            'year' => $date->year(),
            'month' => $date->month(),
            'day' => $date->day(),
            'valid' => $date->isValid(),
        ], JSON_THROW_ON_ERROR);
        PHP);

        self::assertTrue($payload['was_null']);
        self::assertTrue($payload['set_ok']);
        self::assertSame(2025, $payload['year']);
        self::assertSame(3, $payload['month']);
        self::assertSame(2, $payload['day']);
        self::assertTrue($payload['valid']);
    }

    public function testQPointSupportsCoordinateMutation(): void
    {
        $payload = $this->runPhp(<<<'PHP'
        $point = new \Qt\Core\QPoint();
        $point->setX(3);
        $point->setY(4);

        echo json_encode([
            'x' => $point->x(),
            'y' => $point->y(),
            'is_null' => $point->isNull(),
            'manhattan' => $point->manhattanLength(),
        ], JSON_THROW_ON_ERROR);
        PHP);

        self::assertSame(3, $payload['x']);
        self::assertSame(4, $payload['y']);
        self::assertFalse($payload['is_null']);
        self::assertSame(7, $payload['manhattan']);
    }

    public function testQStringSupportsBasicEmptyStringOperations(): void
    {
        $payload = $this->runPhp(<<<'PHP'
        $string = new \Qt\Core\QString();

        echo json_encode([
            'empty' => $string->isEmpty(),
            'size' => $string->size(),
            'length' => $string->length(),
            'std' => $string->toStdString(),
            'upper' => $string->toUpper(),
            'lower' => $string->toLower(),
            'trimmed' => $string->trimmed(),
        ], JSON_THROW_ON_ERROR);
        PHP);

        self::assertTrue($payload['empty']);
        self::assertSame(0, $payload['size']);
        self::assertSame(0, $payload['length']);
        self::assertSame('', $payload['std']);
        self::assertSame('', $payload['upper']);
        self::assertSame('', $payload['lower']);
        self::assertSame('', $payload['trimmed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPhp(string $body): array
    {
        if (self::$extensionPath === null || !is_file(self::$extensionPath)) {
            self::markTestSkipped('Built qt extension not found. Run `php qtb build` first or set PHPQT_EXTENSION.');
        }

        $script = tempnam(sys_get_temp_dir(), 'phpqt-runtime-');
        if ($script === false) {
            self::fail('Could not create temporary runtime test file.');
        }

        file_put_contents($script, "<?php\n" . $body . "\n");

        $process = new Process([PHP_BINARY, '-dextension=' . self::$extensionPath, $script]);
        $process->setTimeout(30);
        $process->run();

        @unlink($script);

        self::assertSame(
            0,
            $process->getExitCode(),
            sprintf(
                "PHP runtime smoke test failed.\nSTDOUT:\n%s\nSTDERR:\n%s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
