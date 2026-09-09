{!! '<' . '?php' !!}

/** @generate-class-entries */

namespace Qt\Test;

abstract class QTest
{
    public static function qExec(\Qt\Core\QObject $testObject, array $arguments = []): int {}

    public static function qWait(int $msecs): void {}

    public static function setMainSourcePath(string $file, ?string $builddir = null): void {}

    public static function setThrowOnFail(bool $enable): void {}

    public static function setThrowOnSkip(bool $enable): void {}

    public static function qVerify(bool $statement, string $statementStr, string $description, string $file, int $line): bool {}

    public static function qFail(string $message, string $file, int $line): void {}

    public static function qSkip(string $message, string $file, int $line): void {}

    public static function qExpectFail(string $dataIndex, string $comment, int $mode, string $file, int $line): bool {}

    public static function qCaught(?string $expected = null, ?string $file = null, int $line = 0): void {}

    public static function ignoreMessage(int $type, \Qt\Core\QRegularExpression|string $messagePattern): void {}

    public static function failOnWarning(\Qt\Core\QRegularExpression|string|null $messagePattern = null): void {}

    public static function qExtractTestData(string $dirName): ?\Qt\Core\QTemporaryDir {}

    public static function qFindTestData(string $basepath, ?string $file = null, int $line = 0, ?string $builddir = null, ?string $sourcedir = null): ?string {}

    public static function qData(string $tagName, int $typeId): ?int {}

    public static function qGlobalData(string $tagName, int $typeId): ?int {}

    public static function qElementData(string $elementName, int $metaTypeId): ?int {}

    public static function testObject(): ?\Qt\Core\QObject {}

    public static function currentAppName(): ?string {}

    public static function currentTestFunction(): ?string {}

    public static function currentDataTag(): ?string {}

    public static function currentGlobalDataTag(): ?string {}

    public static function currentTestFailed(): bool {}

    public static function currentTestResolved(): bool {}

    public static function runningTest(): bool {}

    public static function asciiToKey(string $ascii): int {}

    public static function keyToAscii(int $key): string {}

@if($ctx->includesModule('QtGui'))
@if($ctx->includesModule('QtWidgets'))
    public static function mousePress(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseRelease(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseClick(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseDClick(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseMove(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function keyPress(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyRelease(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyClick(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyClicks(\Qt\Gui\QWindow|\Qt\Widgets\QWidget $target, string $sequence, int $modifier = 0, int $delay = -1): void {}
@else
    public static function mousePress(\Qt\Gui\QWindow $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseRelease(\Qt\Gui\QWindow $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseClick(\Qt\Gui\QWindow $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseDClick(\Qt\Gui\QWindow $target, int $button, int $stateKey = 0, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function mouseMove(\Qt\Gui\QWindow $target, ?\Qt\Core\QPoint $pos = null, int $delay = -1): void {}

    public static function keyPress(\Qt\Gui\QWindow $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyRelease(\Qt\Gui\QWindow $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyClick(\Qt\Gui\QWindow $target, int|string $key, int $modifier = 0, int $delay = -1): void {}

    public static function keyClicks(\Qt\Gui\QWindow $target, string $sequence, int $modifier = 0, int $delay = -1): void {}
@endif

    public static function wheelEvent(\Qt\Gui\QWindow $window, \Qt\Core\QPointF $pos, \Qt\Core\QPoint $angleDelta, ?\Qt\Core\QPoint $pixelDelta = null, int $stateKey = 0, int $phase = 0): void {}
@endif
}
