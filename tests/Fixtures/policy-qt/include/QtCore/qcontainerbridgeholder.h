template <typename T>
class QList {};

template <typename T>
class QVector {};

template <typename K, typename V>
class QMap {};

template <typename K, typename V>
class QHash {};

class QString {};
class QByteArray {};
class QVariant {};
class QModelIndex {};
class QPersistentModelIndex {};
class QAction {};
class QWidget {};

using QStringList = QList<QString>;
using QVariantList = QList<QVariant>;
using QVariantMap = QMap<QString, QVariant>;
using QModelIndexList = QList<QModelIndex>;

class QContainerBridgeHolder
{
public:
    QStringList mimeTypes() const;
    void setMimeTypes(const QStringList &types);
    QList<int> roles() const;
    void setRoles(const QList<int> &roles);
    QModelIndexList selectedIndexes() const;
    void setSelectedIndexes(const QModelIndexList &indexes);
    QHash<int, QByteArray> roleNames() const;
    void setRoleNames(const QHash<int, QByteArray> &names);
    QMap<int, QVariant> itemData() const;
    void setItemData(const QMap<int, QVariant> &roles);
    QVariantList toVariantList() const;
    void fromVariantList(const QVariantList &values);
    QList<QAction *> actions() const;
    void setActions(const QList<QAction *> &actions);
    QList<QPersistentModelIndex> persistentIndexes() const;
};
