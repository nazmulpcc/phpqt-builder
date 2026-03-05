# Login Form

A polished desktop login screen with validation, remember-me persistence, loading-style feedback, and an inline banner.

## Modules

- `QtCore`
- `QtGui`
- `QtWidgets`

## Run

```sh
php -dextension=$PWD/build/ext/.libs/qt.so examples/login-form/run.php
```

## Demo credentials

- `demo@acme.test / secret123`
- `ops@acme.test / desk-admin`

## Data

- users: `examples/login-form/data/users.json`
- remembered session: `examples/login-form/data/session.json`
