<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

qt_runtime_require_class(\Qt\Widgets\QApplication::class, 'QtWidgets classes are unavailable in this build.');

$app = new \Qt\Widgets\QApplication();
$window = new \Qt\Widgets\QWidget();
$window->setWindowTitle('Runtime Layouts');
$window->resize(480, 260);

$rootLayout = new \Qt\Widgets\QVBoxLayout();
$grid = new \Qt\Widgets\QGridLayout();
$grid->addWidget(new \Qt\Widgets\QLabel('Name'), 0, 0);
$grid->addWidget(new \Qt\Widgets\QLineEdit(), 0, 1);
$grid->addWidget(new \Qt\Widgets\QLabel('Email'), 1, 0);
$grid->addWidget(new \Qt\Widgets\QLineEdit(), 1, 1);

$actionsLayout = new \Qt\Widgets\QVBoxLayout();
$button = new \Qt\Widgets\QPushButton('Sync');
$actionsLayout->addWidget($button);
$actionsLayout->addWidget(new \Qt\Widgets\QPushButton('Cancel'));

$rootLayout->addLayout($grid);
$rootLayout->addLayout($actionsLayout);
$window->setLayout($rootLayout);

$window->addAction('Refresh Sync');
$window->addAction('Export CSV');

$sugarCount = 0;
$genericCount = 0;
$sugarConnection = $button->onClicked(function (bool $checked = false) use (&$sugarCount): void {
    $sugarCount++;
});
$connection = $button->connect('clicked(bool)', function ($checked = false) use (&$genericCount): void {
    $genericCount++;
});

$button->click();
$button->disconnect($connection);
$button->click();

$actionTexts = [];
foreach ($window->actions() as $action) {
    if ($action instanceof \Qt\Gui\QAction) {
        $actionTexts[] = $action->text();
    }
}

$button->disconnect($sugarConnection);

qt_runtime_result([
    'window_title' => $window->windowTitle(),
    'root_count' => $rootLayout->count(),
    'grid_rows' => $grid->rowCount(),
    'grid_columns' => $grid->columnCount(),
    'actions_layout_count' => $actionsLayout->count(),
    'action_count' => count($actionTexts),
    'action_texts' => $actionTexts,
    'sugar_count' => $sugarCount,
    'generic_count' => $genericCount,
]);
