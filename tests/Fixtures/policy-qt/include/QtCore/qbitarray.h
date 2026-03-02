class QBitArray
{
public:
    QBitArray();
    const char *bits() const;
    static QBitArray fromBits(const char *data, int len);
    int toUInt32(int endianness, bool *ok = nullptr) const;
};
