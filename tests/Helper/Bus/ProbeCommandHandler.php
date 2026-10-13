<?php

namespace Repas\Tests\Helper\Bus;


use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ProbeCommandHandler
{
    public const string RESULT = 'probe-command-result';

    public function __invoke(ProbeCommand $command): string
    {
        return match ($command->mode) {
            ProbeCommand::DOMAIN_ERROR => throw new ProbeDomainException('probe domain error'),
            ProbeCommand::TECHNICAL_ERROR => throw new RuntimeException('probe technical error'),
            default => self::RESULT,
        };
    }
}
