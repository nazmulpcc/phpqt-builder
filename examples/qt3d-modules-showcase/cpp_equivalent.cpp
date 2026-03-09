#include <QGuiApplication>
#include <QWindow>
#include <QSurface>
#include <QTimer>
#include <QRectF>
#include <QColor>
#include <QVector3D>
#include <QDebug>
#include <QMouseEvent>
#include <QWheelEvent>

#include <Qt3DCore/QAspectEngine>
#include <Qt3DCore/QEntity>
#include <Qt3DCore/QTransform>
#include <Qt3DInput/QAction>
#include <Qt3DInput/QAxis>
#include <Qt3DInput/QInputAspect>
#include <Qt3DInput/QInputSettings>
#include <Qt3DInput/QLogicalDevice>
#include <Qt3DRender/QCamera>
#include <Qt3DRender/QCameraLens>
#include <Qt3DRender/QCameraSelector>
#include <Qt3DRender/QClearBuffers>
#include <Qt3DRender/QRenderSettings>
#include <Qt3DRender/QRenderSurfaceSelector>
#include <Qt3DRender/QViewport>
#include <Qt3DExtras/QOrbitCameraController>
#include <Qt3DExtras/QCuboidMesh>
#include <Qt3DExtras/QPhongMaterial>

class ShowcaseWindow final : public QWindow
{
public:
    ShowcaseWindow()
    {
        setTitle(QStringLiteral("Qt3D Modules Showcase (C++)"));
        setSurfaceType(QSurface::OpenGLSurface);
        resize(960, 600);
        buildScene();
    }

protected:
    void mousePressEvent(QMouseEvent *event) override
    {
        const QPointF p = event->position();
        qInfo() << "[qt3d-cpp] mousePress"
                << "button=" << event->button()
                << "buttons=" << event->buttons()
                << "x=" << p.x()
                << "y=" << p.y();
        QWindow::mousePressEvent(event);
    }

    void mouseMoveEvent(QMouseEvent *event) override
    {
        const QPointF p = event->position();
        qInfo() << "[qt3d-cpp] mouseMove"
                << "buttons=" << event->buttons()
                << "x=" << p.x()
                << "y=" << p.y();
        QWindow::mouseMoveEvent(event);
    }

    void mouseReleaseEvent(QMouseEvent *event) override
    {
        const QPointF p = event->position();
        qInfo() << "[qt3d-cpp] mouseRelease"
                << "button=" << event->button()
                << "buttons=" << event->buttons()
                << "x=" << p.x()
                << "y=" << p.y();
        QWindow::mouseReleaseEvent(event);
    }

    void wheelEvent(QWheelEvent *event) override
    {
        const QPoint angle = event->angleDelta();
        const QPoint pixel = event->pixelDelta();
        qInfo() << "[qt3d-cpp] wheel"
                << "angleDelta=(" << angle.x() << "," << angle.y() << ")"
                << "pixelDelta=(" << pixel.x() << "," << pixel.y() << ")";
        QWindow::wheelEvent(event);
    }

private:
    void buildScene()
    {
        m_engine = std::make_unique<Qt3DCore::QAspectEngine>();
        m_engine->setRunMode(Qt3DCore::QAspectEngine::Automatic);
        m_engine->registerAspect(QStringLiteral("render"));
        m_engine->registerAspect(QStringLiteral("logic"));

        auto *inputAspect = new Qt3DInput::QInputAspect(m_engine.get());
        m_engine->registerAspect(inputAspect);

        m_root = Qt3DCore::QEntityPtr(new Qt3DCore::QEntity());

        m_camera = new Qt3DRender::QCamera(m_root.data());
        m_camera->setProjectionType(Qt3DRender::QCameraLens::PerspectiveProjection);
        m_camera->setFieldOfView(45.0f);
        m_camera->setAspectRatio(float(width()) / float(height()));
        m_camera->setNearPlane(0.1f);
        m_camera->setFarPlane(1000.0f);
        m_camera->setPosition(QVector3D(0.0f, 0.0f, 14.0f));
        m_camera->setViewCenter(QVector3D(0.0f, 0.0f, 0.0f));

        connect(m_camera, &Qt3DRender::QCamera::positionChanged, this, [](const QVector3D &p) {
            qInfo() << "[qt3d-cpp] camera.positionChanged"
                    << "x=" << p.x() << "y=" << p.y() << "z=" << p.z();
        });
        connect(m_camera, &Qt3DRender::QCamera::viewCenterChanged, this, [](const QVector3D &p) {
            qInfo() << "[qt3d-cpp] camera.viewCenterChanged"
                    << "x=" << p.x() << "y=" << p.y() << "z=" << p.z();
        });

        auto *renderSettings = new Qt3DRender::QRenderSettings(m_root.data());
        auto *surfaceSelector = new Qt3DRender::QRenderSurfaceSelector();
        auto *viewport = new Qt3DRender::QViewport(surfaceSelector);
        auto *clearBuffers = new Qt3DRender::QClearBuffers(viewport);
        auto *cameraSelector = new Qt3DRender::QCameraSelector(clearBuffers);

        surfaceSelector->setSurface(this);
        viewport->setNormalizedRect(QRectF(0.0f, 0.0f, 1.0f, 1.0f));
        clearBuffers->setBuffers(Qt3DRender::QClearBuffers::ColorDepthBuffer);
        clearBuffers->setClearColor(QColor::fromRgbF(0.08f, 0.10f, 0.14f, 1.0f));
        cameraSelector->setCamera(m_camera);
        renderSettings->setActiveFrameGraph(surfaceSelector);
        m_root->addComponent(renderSettings);

        auto *inputSettings = new Qt3DInput::QInputSettings(m_root.data());
        inputSettings->setEventSource(this);
        m_root->addComponent(inputSettings);

        auto *orbit = new Qt3DExtras::QOrbitCameraController(m_root.data());
        orbit->setCamera(m_camera);
        orbit->setLinearSpeed(40.0f);
        orbit->setLookSpeed(180.0f);
        orbit->setZoomInLimit(2.0f);

        auto *logicalDevice = new Qt3DInput::QLogicalDevice(m_root.data());
        logicalDevice->addAction(new Qt3DInput::QAction(m_root.data()));
        logicalDevice->addAxis(new Qt3DInput::QAxis(m_root.data()));

        auto *cubeEntity = new Qt3DCore::QEntity(m_root.data());
        auto *cubeMesh = new Qt3DExtras::QCuboidMesh(cubeEntity);
        cubeMesh->setXExtent(2.2f);
        cubeMesh->setYExtent(2.2f);
        cubeMesh->setZExtent(2.2f);

        auto *cubeMaterial = new Qt3DExtras::QPhongMaterial(cubeEntity);
        cubeMaterial->setDiffuse(QColor::fromRgbF(0.20f, 0.75f, 0.95f, 1.0f));
        cubeMaterial->setAmbient(QColor::fromRgbF(0.08f, 0.28f, 0.36f, 1.0f));
        cubeMaterial->setSpecular(QColor::fromRgbF(0.95f, 0.95f, 0.95f, 1.0f));
        cubeMaterial->setShininess(32.0f);

        m_cubeTransform = new Qt3DCore::QTransform(cubeEntity);
        m_cubeTransform->setTranslation(QVector3D(0.0f, 0.0f, 0.0f));
        cubeEntity->addComponent(cubeMesh);
        cubeEntity->addComponent(cubeMaterial);
        cubeEntity->addComponent(m_cubeTransform);

        const QByteArray spinRaw = qgetenv("QT3D_CPP_SPIN");
        const bool spinEnabled = spinRaw == "1" || spinRaw.compare("true", Qt::CaseInsensitive) == 0;
        if (spinEnabled) {
            m_spinTimer = new QTimer(this);
            connect(m_spinTimer, &QTimer::timeout, this, [this]() {
                m_spinAngle += 1.8f;
                if (m_spinAngle >= 360.0f) {
                    m_spinAngle -= 360.0f;
                }
                if (m_cubeTransform != nullptr) {
                    m_cubeTransform->setRotationY(m_spinAngle);
                }
            });
            m_spinTimer->start(16);
        }

        m_engine->setRootEntity(m_root);

        qInfo() << "[qt3d-cpp] modules wired: Qt3DCore + Qt3DRender + Qt3DExtras + Qt3DInput + Qt3DLogic(aspect)";
        qInfo() << "[qt3d-cpp] visual check: cyan cube should be visible";
        qInfo() << "[qt3d-cpp] spin:" << (spinEnabled ? "enabled" : "disabled");
        qInfo() << "[qt3d-cpp] interaction check: drag/wheel should emit both mouse/wheel and camera.*Changed logs";
    }

    std::unique_ptr<Qt3DCore::QAspectEngine> m_engine;
    Qt3DCore::QEntityPtr m_root;
    Qt3DRender::QCamera *m_camera = nullptr;
    Qt3DCore::QTransform *m_cubeTransform = nullptr;
    QTimer *m_spinTimer = nullptr;
    float m_spinAngle = 0.0f;
};

int main(int argc, char **argv)
{
    QGuiApplication app(argc, argv);

    ShowcaseWindow window;
    window.show();

    const QByteArray autoQuitRaw = qgetenv("QT_EXAMPLE_AUTO_QUIT_SECONDS");
    bool ok = false;
    const int autoQuitSeconds = autoQuitRaw.toInt(&ok);
    if (ok && autoQuitSeconds > 0) {
        qInfo() << "[qt3d-cpp] auto-quit in" << autoQuitSeconds << "second(s)";
        QTimer::singleShot(autoQuitSeconds * 1000, &app, &QCoreApplication::quit);
    }

    return app.exec();
}
