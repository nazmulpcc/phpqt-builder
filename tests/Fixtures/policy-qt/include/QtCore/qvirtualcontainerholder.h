template <typename T> class QList {};
template <typename K, typename V> class QMap {};
template <typename K, typename V> class QHash {};

class QString {};
class QByteArray {};
class QVariant {};
class QModelIndex {};
class QPersistentModelIndex {};
class QAccessibleInterface {};
class QListWidgetItem {};

using QStringList = QList<QString>;
using QModelIndexList = QList<QModelIndex>;

class QVirtualContainerHolder
{
public:
    virtual ~QVirtualContainerHolder() = default;

    virtual QStringList mimeTypes() const = 0;
    virtual QModelIndexList selectedIndexes() const = 0;
    virtual QHash<int, QByteArray> roleNames() const = 0;
    virtual QMap<int, QVariant> itemData() const = 0;
    virtual void setItemData(const QMap<int, QVariant> &roles) = 0;
    virtual void dataChanged(const QModelIndex &topLeft, const QModelIndex &bottomRight, const QList<int> &roles) = 0;
    virtual QList<QAccessibleInterface *> selectedItems() const = 0;
    virtual QList<QByteArray> convertFromMime() const = 0;
    virtual void convertToMime(const QList<QByteArray> &formats) = 0;
    virtual void consumeWidgetItems(const QList<QListWidgetItem *> &items) = 0;
};
