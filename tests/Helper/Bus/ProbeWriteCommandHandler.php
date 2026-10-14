<?php

namespace Repas\Tests\Helper\Bus;


use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ProbeWriteCommandHandler
{
    public function __construct(
        private UnitRepository $unitRepository,
    ) {
    }

    public function __invoke(ProbeWriteCommand $command): void
    {
        $this->unitRepository->save(Unit::create($command->unitName, 'pw'));

        if ($command->failAfterWriting) {
            throw new ProbeDomainException('probe failure after writing');
        }
    }
}
