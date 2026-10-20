<?php

namespace Repas\Tests\Repas\Application\Query\GetCatalogHandoverReport;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\GetCatalogHandoverReportQuery;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\GetCatalogHandoverReportQueryHandler;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
use Repas\Repas\Domain\Model\HandoverPrecondition;
use Repas\Tests\Helper\Builder\DTO\CatalogHandoverReportDTOBuilder;
use Repas\Tests\Helper\InMemoryLoader\CatalogHandoverReportInMemoryLoader;

class GetCatalogHandoverReportQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAsIs(): void
    {
        // Given
        $report = new CatalogHandoverReportDTOBuilder()->build();
        $loader = new CatalogHandoverReportInMemoryLoader($report);

        // When
        $result = new GetCatalogHandoverReportQueryHandler($loader)(new GetCatalogHandoverReportQuery());

        // Then
        $this->assertSame($report, $result);
        $this->assertSame(1, $loader->reportCalls);
    }

    public function testTheReportKnowsWhatIsReadyBeforeAndAfterTheSeeding(): void
    {
        // Given : P3 manque aujourd'hui et sera levée par l'amorçage ; P4 manque et ne l'est pas
        $p3 = new HandoverPrecondition('P3', 'comptes amorcés', false, '1 compte(s)', true);
        $p4 = new HandoverPrecondition('P4', 'interrupteur', false, 'il est coupé', false);
        $ok = new HandoverPrecondition('P1', 'propriétaire', true, '', true);

        // Then
        $liftable = new CatalogHandoverReportDTOBuilder()->withPreconditions($ok, $p3)->build();
        $this->assertFalse($liftable->isReadyToHandOver());
        $this->assertTrue($liftable->isReadyToHandOverAfterSeeding());
        $this->assertSame(['P3'], array_map(static fn(HandoverPrecondition $p): string => $p->code, $liftable->unmet()));
        $this->assertSame([], $liftable->unmetAfterSeeding());

        $blocked = new CatalogHandoverReportDTOBuilder()->withPreconditions($ok, $p3, $p4)->build();
        $this->assertFalse($blocked->isReadyToHandOverAfterSeeding());
        $this->assertSame(['P4'], array_map(static fn(HandoverPrecondition $p): string => $p->code, $blocked->unmetAfterSeeding()));

        $this->assertTrue(new CatalogHandoverReportDTOBuilder()->withPreconditions($ok)->build()->isReadyToHandOver());
    }

    public function testTheClassificationIsPosedOnlyWhenTheOldCommonsMatchTheFileAndNoMarkerIsLeftToWrite(): void
    {
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->build()->isClassified(), '151 marqueurs à écrire');
        $this->assertTrue(new CatalogHandoverReportDTOBuilder()->withClassification(0)->build()->isClassified());
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->withClassification(0)->withMismatch(1, 0)->build()->isClassified(), 'un commun hors fichier');
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->withClassification(0)->withMismatch(0, 2)->build()->matchesTheFile());
        $this->assertTrue(new CatalogHandoverReportDTOBuilder()->handedOver()->build()->isClassified(), 'la passation faite : rien à classer');
    }

    public function testTheVerdictOfCheckNeedsTheHandOverDoneNothingToSeedNoAnomalyAndAGreenHealth(): void
    {
        $done = new CatalogHandoverReportDTOBuilder()->handedOver();

        $this->assertTrue($done->build()->isConform());
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->build()->isConform(), 'la passation n\'est pas faite');
        $this->assertFalse($done->withAnomaly('x')->build()->isConform());
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->handedOver()->withSeeding($this->seeding(new SeedingHealthDTO(1, 0, [], [], 0)))->build()->isConform(), 'un compte actif sans amorçage');
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->handedOver()->withSeeding($this->seeding(new SeedingHealthDTO(0, 0, [], ['recettes' => 1], 0)))->build()->isConform(), 'des lignes du propriétaire hors de chez lui');
        $this->assertFalse(new CatalogHandoverReportDTOBuilder()->handedOver()->withSeeding($this->seeding(new SeedingHealthDTO(0, 0, [], [], 0), toSeed: 3))->build()->isConform(), 'il reste à amorcer');
    }

    private function seeding(SeedingHealthDTO $health, int $toSeed = 0): SeedingReportDTO
    {
        $user = new SeedingUserReportDTO(rank: 1, reference: '00000001', userId: 'user-1', status: 'ACTIVE', owner: false, seeded: false, ingredients: 0, recipes: 0, selected: true, exclusion: null, toSeed: $toSeed);

        return new SeedingReportDTO(true, 261, 151, 100, 40, [], [], [$user], $health);
    }
}
