<?php

declare(strict_types=1);

namespace QtBuilder\CodeGen;

final readonly class SignalOverloadContext
{
    /**
     * @param list<OverloadParamContext> $params
     */
    public function __construct(
        public string $name,
        public string $phpMethodName,
        public string $signature,
        public string $arginfoName,
        public string $memberPointerExpr,
        public array $params,
    ) {}

    public function signatureLiteral(): string
    {
        return addslashes($this->signature);
    }
}
