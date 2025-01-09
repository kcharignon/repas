<?php

namespace Repas\Shared\Domain\Exception;


abstract class DomainException
{
    private string $message;

    private function __construct(
        private int $code,
        string $message
    ) {
        $prefix = $this->getPrefix();
        $this->message = "{$prefix}_{$message}";
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    protected function getPrefix(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }
}
