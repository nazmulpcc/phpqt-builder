class QNestedEnumHolder
{
public:
    struct Attribute
    {
        enum Semantic
        {
            Position = 0,
            Normal = 1,
        };

        enum ComponentType
        {
            U16 = 0,
            F32 = 1,
        };
    };

    void setPair(Attribute::Semantic semantic, Attribute::ComponentType componentType);
    Attribute::Semantic semantic() const;
};

inline void QNestedEnumHolder::setPair(Attribute::Semantic semantic, Attribute::ComponentType componentType)
{
    (void)semantic;
    (void)componentType;
}

inline QNestedEnumHolder::Attribute::Semantic QNestedEnumHolder::semantic() const
{
    return Attribute::Position;
}
