<?php

declare(strict_types=1);

require dirname(__DIR__) . '/_support/bootstrap.php';

use Examples\Support\AppPaths;
use Examples\Support\DemoStorage;
use Examples\Support\Theme\WidgetTheme;
use Examples\Support\Widgets\AppWindow;
use Examples\Support\Widgets\Banner;
use Examples\Support\Widgets\FormFieldRow;
use Examples\Support\Widgets\StatusBarMessage;
use Qt\Widgets\QApplication;
use Qt\Widgets\QCheckBox;
use Qt\Widgets\QComboBox;
use Qt\Widgets\QHBoxLayout;
use Qt\Widgets\QLabel;
use Qt\Widgets\QPushButton;
use Qt\Widgets\QSpinBox;
use Qt\Widgets\QTabWidget;
use Qt\Widgets\QVBoxLayout;
use Qt\Widgets\QWidget;

final class SettingsController
{
    private const DEFAULTS = [
        'general' => [
            'app_name' => 'PHP Qt Builder Demo',
            'launch_on_startup' => false,
            'language' => 'en',
            'autosave_seconds' => 15,
        ],
        'notifications' => [
            'desktop_alerts' => true,
            'email_digest' => false,
            'alert_volume' => 60,
            'reminder_minutes' => 15,
        ],
        'appearance' => [
            'theme' => 'dark',
            'density' => 'comfortable',
            'striped_tables' => true,
            'show_profile_badge' => true,
        ],
    ];

    private DemoStorage $storage;
    /** @var array<string, mixed> */
    private array $persisted;

    public function __construct(DemoStorage $storage)
    {
        $this->storage = $storage;
        $this->persisted = $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        return $this->storage->loadAssoc('settings.json', self::DEFAULTS);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        $this->storage->saveData('settings.json', $data);
        $this->persisted = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function resetToDisk(): array
    {
        return $this->persisted = $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return self::DEFAULTS;
    }
}

/**
 * @param array<string, mixed> $settings
 */
function settings_apply(array $settings, array $widgets): void
{
    $widgets['appName']->setText((string) $settings['general']['app_name']);
    $widgets['startup']->setChecked((bool) $settings['general']['launch_on_startup']);
    $widgets['language']->setCurrentText((string) $settings['general']['language']);
    $widgets['autosave']->setValue((int) $settings['general']['autosave_seconds']);

    $widgets['desktopAlerts']->setChecked((bool) $settings['notifications']['desktop_alerts']);
    $widgets['emailDigest']->setChecked((bool) $settings['notifications']['email_digest']);
    $widgets['alertVolume']->setValue((int) $settings['notifications']['alert_volume']);
    $widgets['reminder']->setValue((int) $settings['notifications']['reminder_minutes']);

    $widgets['theme']->setCurrentText((string) $settings['appearance']['theme']);
    $widgets['density']->setCurrentText((string) $settings['appearance']['density']);
    $widgets['striped']->setChecked((bool) $settings['appearance']['striped_tables']);
    $widgets['profileBadge']->setChecked((bool) $settings['appearance']['show_profile_badge']);
}

/**
 * @return array<string, mixed>
 */
function settings_collect(array $widgets): array
{
    return [
        'general' => [
            'app_name' => $widgets['appName']->text(),
            'launch_on_startup' => $widgets['startup']->isChecked(),
            'language' => $widgets['language']->currentText(),
            'autosave_seconds' => $widgets['autosave']->value(),
        ],
        'notifications' => [
            'desktop_alerts' => $widgets['desktopAlerts']->isChecked(),
            'email_digest' => $widgets['emailDigest']->isChecked(),
            'alert_volume' => $widgets['alertVolume']->value(),
            'reminder_minutes' => $widgets['reminder']->value(),
        ],
        'appearance' => [
            'theme' => $widgets['theme']->currentText(),
            'density' => $widgets['density']->currentText(),
            'striped_tables' => $widgets['striped']->isChecked(),
            'show_profile_badge' => $widgets['profileBadge']->isChecked(),
        ],
    ];
}

example_section('Settings Editor');

$paths = AppPaths::fromExampleRoot(__DIR__);
$storage = new DemoStorage($paths);
$controller = new SettingsController($storage);
$settings = $controller->load();

$app = new QApplication();
$window = new QWidget();
$window->resize(780, 560);
$window->setWindowTitle('Settings Editor');
WidgetTheme::apply($window, (string) ($settings['appearance']['theme'] ?? 'dark'));

$shell = new AppWindow('Preferences', 'A realistic settings editor with JSON persistence and live dirty state.');
$banner = new Banner();
$tabs = new QTabWidget();
$status = new StatusBarMessage();

$widgets = [];

$general = new QWidget();
$generalLayout = new QVBoxLayout();
$widgets['appName'] = new \Qt\Widgets\QLineEdit();
$widgets['startup'] = new QCheckBox('Launch dashboard at startup');
$widgets['language'] = new QComboBox();
foreach (['en', 'fr', 'es'] as $lang) {
    $widgets['language']->addItem($lang);
}
$widgets['autosave'] = new QSpinBox();
$widgets['autosave']->setRange(5, 300);
$generalLayout->addWidget(new FormFieldRow('Application name', $widgets['appName']));
$generalLayout->addWidget($widgets['startup']);
$generalLayout->addWidget(new FormFieldRow('Language', $widgets['language']));
$generalLayout->addWidget(new FormFieldRow('Autosave interval (seconds)', $widgets['autosave']));
$generalLayout->addStretch(1);
$general->setLayout($generalLayout);

$notifications = new QWidget();
$notificationsLayout = new QVBoxLayout();
$widgets['desktopAlerts'] = new QCheckBox('Desktop alerts enabled');
$widgets['emailDigest'] = new QCheckBox('Daily email digest');
$widgets['alertVolume'] = new QSpinBox();
$widgets['alertVolume']->setRange(0, 100);
$widgets['reminder'] = new QSpinBox();
$widgets['reminder']->setRange(5, 120);
$notificationsLayout->addWidget($widgets['desktopAlerts']);
$notificationsLayout->addWidget($widgets['emailDigest']);
$notificationsLayout->addWidget(new FormFieldRow('Alert volume', $widgets['alertVolume']));
$notificationsLayout->addWidget(new FormFieldRow('Reminder lead time (minutes)', $widgets['reminder']));
$notificationsLayout->addStretch(1);
$notifications->setLayout($notificationsLayout);

$appearance = new QWidget();
$appearanceLayout = new QVBoxLayout();
$widgets['theme'] = new QComboBox();
foreach (['dark', 'light'] as $mode) {
    $widgets['theme']->addItem($mode);
}
$widgets['density'] = new QComboBox();
foreach (['comfortable', 'compact'] as $density) {
    $widgets['density']->addItem($density);
}
$widgets['striped'] = new QCheckBox('Use striped tables');
$widgets['profileBadge'] = new QCheckBox('Show profile badge in the header');
$appearanceLayout->addWidget(new FormFieldRow('Theme', $widgets['theme']));
$appearanceLayout->addWidget(new FormFieldRow('Density', $widgets['density']));
$appearanceLayout->addWidget($widgets['striped']);
$appearanceLayout->addWidget($widgets['profileBadge']);
$appearanceLayout->addStretch(1);
$appearance->setLayout($appearanceLayout);

$tabs->addTab($general, 'General');
$tabs->addTab($notifications, 'Notifications');
$tabs->addTab($appearance, 'Appearance');

$save = new QPushButton('Save');
$reset = new QPushButton('Reset');
$reset->setProperty('variant', 'secondary');
$defaults = new QPushButton('Defaults');
$defaults->setProperty('variant', 'secondary');
$actions = new QHBoxLayout();
$actions->addWidget($save);
$actions->addWidget($reset);
$actions->addWidget($defaults);
$actions->addStretch(1);
$actions->addWidget(new QLabel('Data file: examples/settings-editor/data/settings.json'));

$shell->bodyLayout()->addWidget($banner);
$shell->bodyLayout()->addWidget($tabs);
$shell->bodyLayout()->addLayout($actions);
$shell->bodyLayout()->addWidget($status);

$root = new QVBoxLayout();
$root->setContentsMargins(24, 24, 24, 24);
$root->addWidget($shell);
$window->setLayout($root);

settings_apply($settings, $widgets);

$persisted = settings_collect($widgets);

$refresh = static function () use (&$persisted, $widgets, $window, $status): void {
    $current = settings_collect($widgets);
    $dirty = $current != $persisted;
    $window->setWindowTitle('Settings Editor' . ($dirty ? ' • unsaved changes' : ''));
    $status->info($dirty ? 'Unsaved changes in the current tab set.' : 'Settings are in sync with disk.');
};

foreach ($widgets as $widget) {
    if (method_exists($widget, 'connectSignal')) {
        if (method_exists($widget, 'text')) {
            $widget->connectSignal('textChanged(QString)', $refresh);
        } elseif (method_exists($widget, 'clicked')) {
            $widget->connectSignal('clicked(bool)', $refresh);
        } elseif (method_exists($widget, 'currentIndexChanged')) {
            $widget->connectSignal('currentIndexChanged(int)', $refresh);
        } elseif (method_exists($widget, 'valueChanged')) {
            $widget->connectSignal('valueChanged(int)', $refresh);
        }
    }
}

$save->onClicked(static function () use ($controller, $widgets, &$persisted, $banner, $status, $refresh, $window): void {
    $persisted = settings_collect($widgets);
    $controller->save($persisted);
    $banner->showInfo('Preferences saved to disk.');
    $status->info('Saved at ' . date('H:i:s'));
    WidgetTheme::apply($window, (string) $persisted['appearance']['theme']);
    $refresh();
});

$reset->onClicked(static function () use ($controller, $widgets, &$persisted, $banner, $status, $refresh): void {
    $persisted = $controller->resetToDisk();
    settings_apply($persisted, $widgets);
    $banner->showInfo('Reverted changes to the last saved version.');
    $status->info('Reset from disk.');
    $refresh();
});

$defaults->onClicked(static function () use ($controller, $widgets, $banner, $status, $refresh): void {
    settings_apply($controller->defaults(), $widgets);
    $banner->showInfo('Loaded default values. Save to persist them.');
    $status->info('Defaults applied.');
    $refresh();
});

$refresh();
$window->show();

example_line('settings editor ready; save/reset updates examples/settings-editor/data/settings.json');
QApplication::exec();
