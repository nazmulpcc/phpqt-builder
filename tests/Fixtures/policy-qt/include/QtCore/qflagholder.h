template <typename Enum>
class QFlags
{
public:
    using Int = int;

    QFlags();
    static QFlags fromInt(Int value);
};

#define Q_DECLARE_FLAGS(Flags, Enum) using Flags = QFlags<Enum>;

class QFlagHolder
{
public:
    enum Mode {
        A = 0x01,
        B = 0x02,
    };

    Q_DECLARE_FLAGS(Modes, Mode)

    void setModes(Modes modes);
    Modes modes() const;
};
