<?php

use Qt\Widgets\QApplication;
use Qt\Widgets\QCheckBox;
use Qt\Widgets\QLineEdit;
use Qt\Widgets\QMainWindow;
use Qt\Widgets\QMessageBox;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

$app = new QApplication();

$window = new QMainWindow();
$window->setWindowTitle('Login Demo');
$window->resize(420, 260);

$central = new QWidget();
$layout = new QVBoxLayout();

$email = new QLineEdit($central);
$email->setPlaceholderText('Email');

$password = new QLineEdit($central);
$password->setPlaceholderText('Password');
$password->setEchoMode(2); // QLineEdit::Password

$remember = new QCheckBox('Remember me', $central);
$login = new QPushButton('Login', $central);

$layout->addWidget($email);
$layout->addWidget($password);
$layout->addWidget($remember);
$layout->addWidget($login);

$central->setLayout($layout);
$window->setCentralWidget($central);

$login->onClicked(function ($checked = false) use ($window): void {
    QMessageBox::about($window, 'Login', 'Login success');
});

$window->show();
QApplication::exec();
