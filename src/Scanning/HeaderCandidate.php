<?php

declare(strict_types=1);

namespace QtBuilder\Scanning;

use QtBuilder\Support\GeneratedTypeIdentity;

readonly class HeaderCandidate
{
    public function __construct(
        public string $module,
        public string $className,
        public string $publicHeader,
        public string $parseHeader,
        public ?string $qualifiedClassName = null,
        public ?string $generationId = null,
    ) {}

    public function identityKey(): string
    {
        if (is_string($this->qualifiedClassName) && $this->qualifiedClassName !== '') {
            return $this->qualifiedClassName;
        }

        return GeneratedTypeIdentity::provisional($this->module, $this->className, $this->parseHeader)->canonicalKey;
    }

    public function resolvedGenerationId(): string
    {
        if (is_string($this->generationId) && $this->generationId !== '') {
            return $this->generationId;
        }

        return GeneratedTypeIdentity::provisional($this->module, $this->className, $this->parseHeader)->generationId;
    }

    public function withQualifiedClassName(?string $qualifiedClassName): self
    {
        $qualifiedClassName = is_string($qualifiedClassName) ? trim($qualifiedClassName) : '';
        if ($qualifiedClassName === '') {
            return $this;
        }

        $identity = GeneratedTypeIdentity::fromNames($this->className, $qualifiedClassName, $this->module);

        return new self(
            module: $this->module,
            className: $this->className,
            publicHeader: $this->publicHeader,
            parseHeader: $this->parseHeader,
            qualifiedClassName: $identity->canonicalKey,
            generationId: $identity->generationId,
        );
    }
}
