<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Domain\Model\HandoverPrecondition;

/**
 * Un rapport de passation avant la phase 1 (261 anciens communs sans créateur, classement pas posé, rien à amorcer), ajustable par `with…()`.
 */
final class CatalogHandoverReportDTOBuilder
{
    private bool $handedOver = false;
    private int $withoutCreator = 261;
    private int $unknown = 0;
    private int $missing = 0;
    private int $markersToWrite = 151;
    private int $atOwner = 0;
    private int $atOwnerMarkersDiffering = 0;
    /** @var list<HandoverPrecondition> */
    private array $preconditions = [];
    private ?SeedingReportDTO $seeding = null;
    /** @var list<string> */
    private array $anomalies = [];

    public function handedOver(): self
    {
        $this->handedOver = true;
        $this->withoutCreator = 0;
        $this->markersToWrite = 0;
        $this->atOwner = 261;

        return $this;
    }

    public function withPreconditions(HandoverPrecondition ...$preconditions): self
    {
        $this->preconditions = array_values($preconditions);

        return $this;
    }

    public function withSeeding(SeedingReportDTO $seeding): self
    {
        $this->seeding = $seeding;

        return $this;
    }

    public function withAnomaly(string $anomaly): self
    {
        $this->anomalies[] = $anomaly;

        return $this;
    }

    public function withClassification(int $markersToWrite): self
    {
        $this->markersToWrite = $markersToWrite;

        return $this;
    }

    public function withMismatch(int $unknown, int $missing): self
    {
        $this->unknown = $unknown;
        $this->missing = $missing;

        return $this;
    }

    public function withAtOwnerMarkersDiffering(int $count): self
    {
        $this->atOwnerMarkersDiffering = $count;

        return $this;
    }

    public function build(): CatalogHandoverReportDTO
    {
        return new CatalogHandoverReportDTO(
            ownerExists: true,
            ownerReference: 'abcd1234',
            ownerStatus: 'ACTIVE',
            ownerIsAdministrator: true,
            administrators: 1,
            fileEntries: 261,
            fileBase: 151,
            fileChecksum: 'a43e401aaf59b6e65bd1f433f7347c7b',
            fileBaseChecksum: '3776a0ad5d1fbc427f9431656c14b7e2',
            withoutCreator: $this->withoutCreator,
            catalogAtOwner: $this->handedOver,
            catalogCount: 261,
            catalogChecksum: 'a43e401aaf59b6e65bd1f433f7347c7b',
            catalogMarked: $this->markersToWrite === 0 ? 151 : 0,
            catalogMarkedChecksum: $this->markersToWrite === 0 ? '3776a0ad5d1fbc427f9431656c14b7e2' : 'd41d8cd98f00b204e9800998ecf8427e',
            unknown: $this->unknown,
            missing: $this->missing,
            markersToWrite: $this->markersToWrite,
            withOrigin: 0,
            accountFormat: 0,
            ownConversions: 100,
            atOwner: $this->atOwner,
            atOwnerMarkersDiffering: $this->atOwnerMarkersDiffering,
            handedOver: $this->handedOver,
            seedingEnabled: true,
            unknownSample: [],
            missingSample: [],
            preconditions: $this->preconditions,
            seeding: $this->seeding ?? new SeedingReportDTO(true, 261, 151, 100, 40, [], [], [], new SeedingHealthDTO(0, 0, [], $this->handedOver ? [] : null, $this->withoutCreator)),
            blockingAnomalies: $this->anomalies,
        );
    }
}
