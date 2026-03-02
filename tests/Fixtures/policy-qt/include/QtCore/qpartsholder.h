class QDate;

class QPartsHolder
{
public:
    struct YearMonthDay
    {
        int year;
        int month;
        int day;
    };

    enum NameFormat {
        Short = 0,
        Long = 1,
    };

    YearMonthDay partsFromDate(QDate date) const;
    void setFormat(NameFormat format);
};
