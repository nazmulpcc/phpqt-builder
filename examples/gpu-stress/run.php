<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Qt\Core\QCoreApplication;
use Qt\Core\QObject;
use Qt\Core\QTimerEvent;
use Qt\Core\QUrl;
use Qt\Gui\QGuiApplication;
use Qt\Qml\QQmlApplicationEngine;

if (!class_exists(\Qt\Quick3D\QQuick3DObject::class)) {
    example_fail('QtQuick3D classes are unavailable in this build. Rebuild with QtQuick3D support.');
}

final class AutoQuitDriver extends QObject
{
    private int $timerId = 0;

    public function __construct(int $seconds)
    {
        parent::__construct();

        if ($seconds > 0) {
            $this->timerId = $this->startTimer($seconds * 1000);
        }
    }

    protected function timerEvent(QTimerEvent $event): void
    {
        if ($event->timerId() !== $this->timerId) {
            return;
        }

        $this->killTimer($this->timerId);
        QCoreApplication::quit();
    }
}

example_section('GPU Stress (QtQuick3D)');

$argc = 0;
$app = new QGuiApplication($argc, []);
$engine = new QQmlApplicationEngine();
$engine->loadData(<<<'QML'
import QtQuick
import QtQuick.Window
import QtQuick3D

Window {
    id: root
    width: 1280
    height: 760
    visible: true
    color: "#070b14"
    title: "GPU Stress Demo"

    property int instanceCount: 1000
    property real speedFactor: 1.0
    property bool shadowsEnabled: true
    property real timePhase: 0

    property int framesSinceSample: 0
    property int fpsValue: 0
    property real frameMs: 0.0

    function applyPreset(level) {
        if (level === 1) {
            instanceCount = 400
            speedFactor = 0.7
            shadowsEnabled = false
        } else if (level === 2) {
            instanceCount = 900
            speedFactor = 1.0
            shadowsEnabled = true
        } else if (level === 3) {
            instanceCount = 1800
            speedFactor = 1.2
            shadowsEnabled = true
        } else if (level === 4) {
            instanceCount = 3200
            speedFactor = 1.35
            shadowsEnabled = true
        }
    }

    function clampSettings() {
        if (instanceCount < 100) {
            instanceCount = 100
        }
        if (instanceCount > 9000) {
            instanceCount = 9000
        }
        if (speedFactor < 0.15) {
            speedFactor = 0.15
        }
        if (speedFactor > 3.0) {
            speedFactor = 3.0
        }
    }

    function increaseInstances() {
        instanceCount += 150
        clampSettings()
    }

    function decreaseInstances() {
        instanceCount -= 150
        clampSettings()
    }

    function increaseSpeed() {
        speedFactor += 0.1
        clampSettings()
    }

    function decreaseSpeed() {
        speedFactor -= 0.1
        clampSettings()
    }

    function resetCamera() {
        orbitYaw = 0
        orbitPitch = -16
        orbitDistance = 820
    }

    onFrameSwapped: {
        framesSinceSample += 1
    }

    Timer {
        interval: 500
        running: true
        repeat: true
        onTriggered: {
            fpsValue = framesSinceSample * 2
            frameMs = fpsValue > 0 ? (1000.0 / fpsValue) : 0.0
            framesSinceSample = 0
        }
    }

    NumberAnimation on timePhase {
        from: 0
        to: 6.2831853
        duration: 9000 / speedFactor
        loops: Animation.Infinite
    }

    Rectangle {
        anchors.fill: parent
        z: -1
        gradient: Gradient {
            GradientStop { position: 0.0; color: "#122847" }
            GradientStop { position: 0.35; color: "#0d1730" }
            GradientStop { position: 1.0; color: "#070b14" }
        }
    }

    property real orbitYaw: 0
    property real orbitPitch: -16
    property real orbitDistance: 820
    property real lastMouseX: 0
    property real lastMouseY: 0

    MouseArea {
        anchors.fill: parent
        acceptedButtons: Qt.LeftButton
        hoverEnabled: false
        onPressed: function(mouse) {
            lastMouseX = mouse.x
            lastMouseY = mouse.y
        }
        onPositionChanged: function(mouse) {
            if (!(mouse.buttons & Qt.LeftButton)) {
                return
            }
            var dx = mouse.x - lastMouseX
            var dy = mouse.y - lastMouseY
            orbitYaw += dx * 0.32
            orbitPitch += dy * 0.22
            if (orbitPitch < -82) {
                orbitPitch = -82
            }
            if (orbitPitch > 15) {
                orbitPitch = 15
            }
            lastMouseX = mouse.x
            lastMouseY = mouse.y
        }
        onWheel: function(wheel) {
            orbitDistance -= wheel.angleDelta.y * 0.28
            if (orbitDistance < 260) {
                orbitDistance = 260
            }
            if (orbitDistance > 1600) {
                orbitDistance = 1600
            }
        }
    }

    Shortcut { sequence: "1"; onActivated: applyPreset(1) }
    Shortcut { sequence: "2"; onActivated: applyPreset(2) }
    Shortcut { sequence: "3"; onActivated: applyPreset(3) }
    Shortcut { sequence: "4"; onActivated: applyPreset(4) }
    Shortcut { sequence: "["; onActivated: decreaseInstances() }
    Shortcut { sequence: "]"; onActivated: increaseInstances() }
    Shortcut { sequence: "-"; onActivated: decreaseSpeed() }
    Shortcut { sequence: "="; onActivated: increaseSpeed() }
    Shortcut { sequence: "S"; onActivated: shadowsEnabled = !shadowsEnabled }
    Shortcut { sequence: "R"; onActivated: resetCamera() }

    View3D {
        anchors.fill: parent

        environment: SceneEnvironment {
            backgroundMode: SceneEnvironment.Transparent
            antialiasingMode: SceneEnvironment.MSAA
            antialiasingQuality: SceneEnvironment.High
        }

        PerspectiveCamera {
            id: camera
            position: Qt.vector3d(
                Math.sin(orbitYaw * 0.0174533) * orbitDistance,
                140 + (orbitPitch * -3.5),
                Math.cos(orbitYaw * 0.0174533) * orbitDistance
            )
            eulerRotation.x: orbitPitch
            eulerRotation.y: orbitYaw
            clipFar: 6000
        }

        DirectionalLight {
            id: keyLight
            eulerRotation.x: -42
            eulerRotation.y: -24
            brightness: 1.15
            castsShadow: shadowsEnabled
            shadowFactor: 35
        }

        PointLight {
            position: Qt.vector3d(-320, 170, 220)
            brightness: 65
            color: "#4ec7ff"
        }

        PointLight {
            position: Qt.vector3d(320, 120, -200)
            brightness: 56
            color: "#ff5fa8"
        }

        Node {
            id: stage

            NumberAnimation on eulerRotation.y {
                from: 0
                to: 360
                duration: 18000 / speedFactor
                loops: Animation.Infinite
            }

            Model {
                source: "#Rectangle"
                position: Qt.vector3d(0, -150, 0)
                eulerRotation.x: -90
                scale: Qt.vector3d(30, 30, 1)
                receivesShadows: true
                materials: PrincipledMaterial {
                    baseColor: "#161c2e"
                    roughness: 0.92
                    metalness: 0.01
                }
            }

            Model {
                source: "#Sphere"
                materials: PrincipledMaterial {
                    baseColor: "#ff8b3d"
                    roughness: 0.3
                    metalness: 0.2
                }
            }

            Repeater3D {
                model: root.instanceCount
                delegate: Model {
                    source: "#Sphere"
                    receivesShadows: true
                    castsShadows: true
                    readonly property real ring: (index % 80) + 1
                    readonly property real lane: Math.floor(index / 80)
                    readonly property real spin: (index * 0.131) + (timePhase * speedFactor)
                    position: Qt.vector3d(
                        Math.cos(spin) * (110 + (ring * 7.4)),
                        -30 + (Math.sin((lane * 0.71) + (timePhase * 1.7 * speedFactor)) * 170),
                        Math.sin(spin) * (110 + (ring * 7.4))
                    )
                    eulerRotation: Qt.vector3d(
                        (index * 17) + (timePhase * 180 * speedFactor),
                        (index * 13) + (timePhase * 120 * speedFactor),
                        (index * 19) + (timePhase * 210 * speedFactor)
                    )
                    scale: Qt.vector3d(
                        0.22 + ((index % 7) * 0.07),
                        0.22 + ((index % 7) * 0.07),
                        0.22 + ((index % 7) * 0.07)
                    )
                    materials: PrincipledMaterial {
                        baseColor: Qt.rgba(1.0, 0.45 + ((index % 50) / 120.0), 0.25, 1.0)
                        roughness: 0.3
                        metalness: 0.2
                    }
                }
            }

            Repeater3D {
                model: Math.max(120, Math.floor(root.instanceCount * 0.35))
                delegate: Model {
                    source: "#Cone"
                    receivesShadows: true
                    castsShadows: true
                    readonly property real orbit: index * 0.19
                    position: Qt.vector3d(
                        Math.sin(orbit + (timePhase * 0.8 * speedFactor)) * 520,
                        -110 + (index % 10) * 22,
                        Math.cos(orbit + (timePhase * 0.8 * speedFactor)) * 520
                    )
                    eulerRotation: Qt.vector3d(
                        90 + (Math.sin((index * 0.63) + (timePhase * speedFactor)) * 30),
                        (index * 29) + (timePhase * 160 * speedFactor),
                        0
                    )
                    scale: Qt.vector3d(0.25, 0.75 + ((index % 5) * 0.16), 0.25)
                    materials: PrincipledMaterial {
                        baseColor: Qt.rgba(0.4 + ((index % 30) / 80.0), 0.42, 1.0, 1.0)
                        roughness: 0.48
                        metalness: 0.08
                    }
                }
            }
        }
    }

    Rectangle {
        anchors.left: parent.left
        anchors.top: parent.top
        anchors.margins: 16
        width: 430
        height: 154
        radius: 10
        color: "#88101a2e"
        border.width: 1
        border.color: "#66b4c8ff"

        Column {
            anchors.fill: parent
            anchors.margins: 12
            spacing: 6

            Text {
                text: "GPU Stress Demo"
                color: "#e4eeff"
                font.pixelSize: 22
                font.bold: true
            }

            Text {
                text: "FPS " + root.fpsValue + "   |   " + frameMs.toFixed(2) + " ms"
                color: root.fpsValue >= 55 ? "#74f3a6" : (root.fpsValue >= 30 ? "#ffd37f" : "#ff8b8b")
                font.pixelSize: 16
                font.bold: true
            }

            Text {
                text: "Instances " + root.instanceCount + "   Speed " + root.speedFactor.toFixed(2) + "   Shadows " + (root.shadowsEnabled ? "on" : "off")
                color: "#bfd6ff"
                font.pixelSize: 14
            }

            Text {
                text: "1-4 presets | [ ] instances | -/= speed | S shadows | drag orbit | wheel zoom"
                color: "#9db3da"
                font.pixelSize: 12
            }
        }
    }
}
QML, new QUrl());

$roots = $engine->rootObjects();
if (($roots[0] ?? null) === null) {
    example_fail('No root object was created by GPU stress QML scene.');
}

$autoQuitSeconds = example_auto_quit_seconds();
$autoQuitDriver = new AutoQuitDriver($autoQuitSeconds);

if ($autoQuitSeconds > 0) {
    example_line('Auto-quit enabled: ' . $autoQuitSeconds . 's');
}

QGuiApplication::exec();
