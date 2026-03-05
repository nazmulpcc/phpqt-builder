<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\DemoStorage;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\FormFieldRow;
use Qt\Widgets\QApplication;
use Qt\Widgets\QCheckBox;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class LoginController extends \Qt\Core\QObject
{
    private DemoStorage $storage;
    /** @var list<array{email:string,password:string,name:string}> */
    private array $users;

    public function __construct(DemoStorage $storage)
    {
        parent::__construct();
        $this->storage = $storage;
        $this->users = $storage->loadList('users.json');
    }

    /**
     * @return array{remember_me:bool,email:string}
     */
    public function loadSession(): array
    {
        $session = $this->storage->loadAssoc('session.json', [
            'remember_me' => false,
            'email' => '',
        ]);

        return [
            'remember_me' => (bool) ($session['remember_me'] ?? false),
            'email' => (string) ($session['email'] ?? ''),
        ];
    }

    /**
     * @return array{ok:bool,message:string,name:string}
     */
    public function submit(string $email, string $password, bool $rememberMe): array
    {
        $email = trim($email);
        $password = trim($password);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid work email address.', 'name' => ''];
        }

        if ($password === '') {
            return ['ok' => false, 'message' => 'Password is required.', 'name' => ''];
        }

        foreach ($this->users as $user) {
            if (($user['email'] ?? '') === $email && ($user['password'] ?? '') === $password) {
                $this->storage->saveData('session.json', [
                    'remember_me' => $rememberMe,
                    'email' => $rememberMe ? $email : '',
                ]);

                return [
                    'ok' => true,
                    'message' => 'Welcome back. Credentials verified and session restored.',
                    'name' => (string) ($user['name'] ?? 'Demo User'),
                ];
            }
        }

        return ['ok' => false, 'message' => 'Incorrect email or password. Try demo@acme.test / secret123.', 'name' => ''];
    }
}

example_section('Login Form');

$paths = AppPaths::fromExampleRoot(__DIR__);
$storage = new DemoStorage($paths);
$controller = new LoginController($storage);
$session = $controller->loadSession();

$app = new QApplication();

$window = new QWidget();
$window->setWindowTitle('Sign in | PHP Qt Builder');
$window->resize(540, 420);
WidgetTheme::apply($window, 'dark');

$outer = new QVBoxLayout();
$outer->setContentsMargins(36, 36, 36, 36);

$card = new AppWindow('Welcome back', 'Use the demo account to enter the operations console.');
$banner = new Banner();

$email = new QLineEdit();
$email->setPlaceholderText('demo@acme.test');
$email->setText($session['email']);

$password = new QLineEdit();
$password->setPlaceholderText('secret123');
if (method_exists($password, 'setEchoMode')) {
    $password->setEchoMode(2);
}

$remember = new QCheckBox('Remember this email on this machine');
if ($session['remember_me']) {
    $remember->setChecked(true);
}

$hint = new QLabel('Demo credentials: demo@acme.test / secret123');
$hint->setProperty('role', 'caption');

$status = new QLabel('');
$status->setProperty('role', 'caption');

$submit = new QPushButton('Sign in');
$submit->setMinimumHeight(42);

$secondary = new QPushButton('Clear');
$secondary->setProperty('variant', 'secondary');

$actions = new QHBoxLayout();
$actions->addWidget($submit);
$actions->addWidget($secondary);

$card->bodyLayout()->addWidget($banner);
$card->bodyLayout()->addWidget(new FormFieldRow('Email', $email, 'Use a company-style email address.'));
$card->bodyLayout()->addWidget(new FormFieldRow('Password', $password, 'The demo password is shown above.'));
$card->bodyLayout()->addWidget($remember);
$card->bodyLayout()->addWidget($hint);
$card->bodyLayout()->addLayout($actions);
$card->bodyLayout()->addWidget($status);

$outer->addStretch(1);
$outer->addWidget($card);
$outer->addStretch(1);
$window->setLayout($outer);

$setBusy = static function (bool $busy) use ($submit, $secondary, $status): void {
    $submit->setEnabled(!$busy);
    $secondary->setEnabled(!$busy);
    $status->setText($busy ? 'Authenticating account…' : '');
};

$secondary->onClicked(static function () use ($email, $password, $remember, $banner, $status): void {
    $email->setText('');
    $password->setText('');
    $remember->setChecked(false);
    $banner->clear();
    $status->setText('Form cleared.');
});

$submit->onClicked(static function () use ($controller, $email, $password, $remember, $banner, $window, $status, $setBusy): void {
    $setBusy(true);
    $result = $controller->submit($email->text(), $password->text(), $remember->isChecked());
    $setBusy(false);

    if ($result['ok']) {
        $banner->showInfo($result['message']);
        $window->setWindowTitle('Signed in as ' . $result['name']);
        $status->setText('Remembered email and session state were written to data/session.json.');
        return;
    }

    $banner->showError($result['message']);
    $status->setText('Authentication failed.');
});

$window->show();

example_line('login form ready; try demo@acme.test / secret123');
QApplication::exec();
