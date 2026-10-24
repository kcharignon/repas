<?php

namespace Repas\Tests\Repas\Application\Query\GetSeedingReport;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetSeedingReport\GetSeedingReportQuery;
use Repas\Repas\Application\Query\GetSeedingReport\GetSeedingReportQueryHandler;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
use Repas\Tests\Helper\InMemoryLoader\IngredientSeedingReportInMemoryLoader;

class GetSeedingReportQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheSelection(): void
    {
        // Given
        $report = $this->report([]);
        $loader = new IngredientSeedingReportInMemoryLoader($report);

        // When
        $result = new GetSeedingReportQueryHandler($loader)(new GetSeedingReportQuery(['abcd1234'], ['ef567890'], true));

        // Then
        $this->assertSame($report, $result);
        $this->assertSame([[['abcd1234'], ['ef567890'], true]], $loader->reportCalls);
    }

    public function testTheDefaultSelectionIsEveryoneAndNotComplete(): void
    {
        // Given
        $loader = new IngredientSeedingReportInMemoryLoader($this->report([]));

        // When
        new GetSeedingReportQueryHandler($loader)(new GetSeedingReportQuery());

        // Then
        $this->assertSame([[[], [], false]], $loader->reportCalls);
    }

    public function testTheReportKnowsWhatIsBlockingWhatIsSentAndWhenThereIsNothingLeftToDo(): void
    {
        // Given : un compte retenu avec une anomalie bloquante et une liste envoyée, un compte retenu sans rien à faire, un compte ignoré
        $blocked = $this->user(1, selected: true, sent: 2, anomalies: ['recettes : 1 ligne(s) dont l\'ingrédient n\'existe pas']);
        $clean = $this->user(2, selected: true);
        $ignored = $this->user(3, selected: false, toSeed: 50);

        // Then
        $this->assertTrue($this->report([$blocked, $clean, $ignored])->hasBlockingAnomalies());
        $this->assertSame(2, $this->report([$blocked, $clean, $ignored])->sentShoppingListsToRepoint());
        $this->assertSame([$blocked, $clean], $this->report([$blocked, $clean, $ignored])->selected());
        $this->assertFalse($this->report([$clean, $ignored])->hasBlockingAnomalies());
        $this->assertTrue($this->report([$clean, $ignored])->isNothingToDo(), 'un compte ignoré ne compte pas, même avec des ingrédients à copier');
        $this->assertFalse($this->report([$this->user(4, selected: true, toSeed: 1)])->isNothingToDo());
        $this->assertFalse($this->report([$this->user(5, selected: true, driveChoices: 1)])->isNothingToDo());
    }

    public function testTheVerdictOfCheckNeedsNothingToDoNoAnomalyAndAGreenHealth(): void
    {
        // Given
        $clean = $this->user(1, selected: true);
        $green = new SeedingHealthDTO(0, 0, [], null, 12);
        $red = new SeedingHealthDTO(1, 0, [], null, 12);

        // Then
        $this->assertTrue($this->report([$clean], $green)->isConform());
        $this->assertFalse($this->report([$clean], $red)->isConform(), 'un compte actif non amorcé');
        $this->assertFalse($this->report([$clean], new SeedingHealthDTO(0, 1, [], null, 0))->isConform(), 'un compte actif sans ingrédient');
        $this->assertFalse($this->report([$clean], new SeedingHealthDTO(0, 0, ['recettes' => 2], null, 0))->isConform(), 'des lignes sur l\'ingrédient d\'un autre compte');
        $this->assertFalse($this->report([$clean], new SeedingHealthDTO(0, 0, [], ['recettes' => 1], 0))->isConform(), 'des lignes du propriétaire sur un ingrédient qui n\'est pas à lui');
        $this->assertTrue($this->report([$clean], new SeedingHealthDTO(0, 0, [], [], 0))->isConform(), 'le propriétaire évalué et conforme');
        $this->assertFalse($this->report([$this->user(2, selected: true, toSeed: 3)], $green)->isConform());
        $this->assertFalse($this->report([$this->user(3, selected: true, anomalies: ['x'])], $green)->isConform());
    }

    /**
     * @param list<SeedingUserReportDTO> $users
     */
    private function report(array $users, ?SeedingHealthDTO $health = null): SeedingReportDTO
    {
        return new SeedingReportDTO(true, 12, 8, 3, 15, [], [], $users, $health ?? new SeedingHealthDTO(0, 0, [], null, 12));
    }

    /**
     * @param list<string> $anomalies
     */
    private function user(int $rank, bool $selected, int $toSeed = 0, int $sent = 0, array $anomalies = [], int $driveChoices = 0): SeedingUserReportDTO
    {
        return new SeedingUserReportDTO(
            rank: $rank,
            reference: sprintf('%08d', $rank),
            userId: 'user-' . $rank,
            status: 'ACTIVE',
            owner: false,
            seeded: false,
            ingredients: 0,
            recipes: 1,
            selected: $selected,
            exclusion: $selected ? null : SeedingUserReportDTO::EXCEPTED,
            toSeed: $toSeed,
            sentShoppingListsToRepoint: $sent,
            driveChoicesToRepoint: $driveChoices,
            blockingAnomalies: $anomalies,
        );
    }
}
