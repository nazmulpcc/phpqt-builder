<?php

declare(strict_types=1);

namespace QtBuilder\Prompts;

use Laravel\Prompts\MultiSearchPrompt;
use RuntimeException;

final class ModuleMultiSearchPrompt extends MultiSearchPrompt
{
    /** @var array<string, string> */
    private array $optionLabels = [];
    /** @var array<string, list<string>> */
    private array $moduleDependencies = [];

    /**
     * @param array<string, string> $allOptions
     * @param array<string, list<string>> $dependencyMap
     */
    public function __construct(
        string $label,
        \Closure $options,
        array $allOptions = [],
        array $dependencyMap = [],
        string $placeholder = '',
        int $scroll = 5,
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
        ?\Closure $transform = null,
        private readonly string $allOptionKey = '__all',
    ) {
        $this->optionLabels = $allOptions;
        $this->moduleDependencies = $dependencyMap;
        parent::__construct($label, $options, $placeholder, $scroll, $required, $validate, $hint, $transform);
    }

    protected function toggleHighlighted(): void
    {
        if ($this->isList()) {
            $label = $this->matches[$this->highlighted];
            $key = $label;
        } else {
            $key = array_keys($this->matches)[$this->highlighted];
            $label = $this->matches[$key];
        }

        if (array_key_exists($key, $this->values)) {
            unset($this->values[$key]);
            return;
        }

        if ($key === $this->allOptionKey) {
            $this->values = [$key => $label];
            return;
        }

        unset($this->values[$this->allOptionKey]);
        $this->values[$key] = $label;

        foreach ($this->moduleDependencies[$key] ?? [] as $dependencyModule) {
            if ($dependencyModule === $this->allOptionKey || !isset($this->optionLabels[$dependencyModule])) {
                continue;
            }

            $this->values[$dependencyModule] = $this->optionLabels[$dependencyModule];
        }
    }

    protected function getRenderer(): callable
    {
        $rendererClass = static::$themes[static::$theme][static::class]
            ?? static::$themes['default'][static::class]
            ?? static::$themes[static::$theme][MultiSearchPrompt::class]
            ?? static::$themes['default'][MultiSearchPrompt::class]
            ?? null;

        if (!is_string($rendererClass) || $rendererClass === '') {
            throw new RuntimeException('No prompt renderer registered for module multisearch prompt.');
        }

        return new $rendererClass($this);
    }
}
