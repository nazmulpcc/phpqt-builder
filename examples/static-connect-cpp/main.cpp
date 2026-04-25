#include <QCoreApplication>
#include <QDebug>
#include <QThread>

class Sender final : public QObject
{
    Q_OBJECT

public slots:
    void kick()
    {
        emit ping(QStringLiteral("hello"));
    }

signals:
    void ping(const QString &message);
};

class Receiver final : public QObject
{
    Q_OBJECT

public:
    bool senderOk = false;
    int signalIndex = -1;
    QString senderClassName;
    QString message;
    bool ranOnMainThread = false;

public slots:
    void onPing(const QString &value)
    {
        QObject *origin = sender();
        senderOk = origin != nullptr;
        signalIndex = senderSignalIndex();
        senderClassName = origin ? QString::fromLatin1(origin->metaObject()->className()) : QString();
        message = value;
        ranOnMainThread = QThread::currentThread() == qApp->thread();
        qApp->quit();
    }
};

int main(int argc, char **argv)
{
    QCoreApplication app(argc, argv);

    Sender sender;
    Receiver receiver;
    QThread worker;

    sender.moveToThread(&worker);

    const auto startedOk = QObject::connect(
        &worker,
        SIGNAL(started()),
        &sender,
        SLOT(kick()),
        Qt::QueuedConnection
    );

    const auto pingOk = QObject::connect(
        &sender,
        SIGNAL(ping(QString)),
        &receiver,
        SLOT(onPing(QString)),
        Qt::QueuedConnection
    );

    qInfo().noquote()
        << "started_connect_valid=" << static_cast<bool>(startedOk)
        << "ping_connect_valid=" << static_cast<bool>(pingOk);

    worker.start();
    const int exitCode = app.exec();
    worker.quit();
    worker.wait();

    qInfo().noquote()
        << "sender_ok=" << receiver.senderOk
        << "sender_class=" << receiver.senderClassName
        << "signal_index=" << receiver.signalIndex
        << "message=" << receiver.message
        << "callback_main_thread=" << receiver.ranOnMainThread;

    const bool pass = static_cast<bool>(startedOk)
        && static_cast<bool>(pingOk)
        && receiver.senderOk
        && receiver.signalIndex >= 0
        && receiver.senderClassName == QStringLiteral("Sender")
        && receiver.message == QStringLiteral("hello")
        && receiver.ranOnMainThread;

    qInfo().noquote() << "PASS=" << pass;
    return pass ? exitCode : 1;
}

#include "main.moc"
