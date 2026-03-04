class QProtectedEnumThing
{
protected:
    enum Extension {
        Base = 0,
        Extra = 1,
    };

    bool supportsExtension(Extension extension) const;
    void setExtension(Extension extension);
};
