<?php

namespace Repas\Tests\Repas\Application\Query\GetAdoptionReport;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionReportDTO;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionUserReportDTO;
use Repas\Repas\Application\Query\GetAdoptionReport\GetAdoptionReportQuery;
use Repas\Repas\Application\Query\GetAdoptionReport\GetAdoptionReportQueryHandler;
use Repas\Tests\Helper\InMemoryLoader\IngredientAdoptionReportInMemoryLoader;

class GetAdoptionReportQueryHandlerTest extends TestCase
{
    public function testReturnsWhatTheLoaderReturnsAndForwardsTheSelection(): void
    {
        // Given
        $report = new AdoptionReportDTO(262, 105, 24, [], [], []);
        $loader = new IngredientAdoptionReportInMemoryLoader($report);

        // When
        $result = new GetAdoptionReportQueryHandler($loader)(new GetAdoptionReportQuery(['abcd1234'], ['ef567890']));

        // Then
        $this->assertSame($report, $result);
        $this->assertSame([[['abcd1234'], ['ef567890']]], $loader->reportCalls);
    }

    public function testTheReportKnowsWhatIsBlockingWhatIsSentAndWhenThereIsNothingLeftToDo(): void
    {
        // Given : un compte retenu avec une anomalie bloquante et une liste envoyée, un compte retenu sans rien à faire, un compte ignoré
        $blocked = $this->user(1, selected: true, sent: 2, anomalies: ['recettes : 1 ligne(s) dont l\'ingrédient n\'existe pas']);
        $clean = $this->user(2, selected: true);
        $ignored = $this->user(3, selected: false, toAdopt: 50);

        // Then
        $this->assertTrue(new AdoptionReportDTO(1, 0, 1, [], [], [$blocked, $clean, $ignored])->hasBlockingAnomalies());
        $this->assertSame(2, new AdoptionReportDTO(1, 0, 1, [], [], [$blocked, $clean, $ignored])->sentShoppingListsToRepoint());
        $this->assertSame([$blocked, $clean], new AdoptionReportDTO(1, 0, 1, [], [], [$blocked, $clean, $ignored])->selected());
        $this->assertFalse(new AdoptionReportDTO(1, 0, 1, [], [], [$clean, $ignored])->hasBlockingAnomalies());
        $this->assertTrue(new AdoptionReportDTO(1, 0, 1, [], [], [$clean, $ignored])->isNothingToDo(), 'un compte ignoré ne compte pas, même avec des communs à adopter');
        $this->assertFalse(new AdoptionReportDTO(1, 0, 1, [], [], [$this->user(4, selected: true, toAdopt: 1)])->isNothingToDo());
        $this->assertFalse(new AdoptionReportDTO(1, 0, 1, [], [], [$this->user(5, selected: true, invalidAliases: 1)])->isNothingToDo());
    }

    /**
     * @param list<string> $anomalies
     */
    private function user(int $rank, bool $selected, int $toAdopt = 0, int $sent = 0, array $anomalies = [], int $invalidAliases = 0): AdoptionUserReportDTO
    {
        return new AdoptionUserReportDTO(
            rank: $rank,
            reference: sprintf('%08d', $rank),
            userId: 'user-' . $rank,
            status: 'ACTIVE',
            administrator: false,
            personalIngredients: 1,
            adoptedCopies: 0,
            recipes: 1,
            copiedRecipes: 0,
            concerned: true,
            selected: $selected,
            exclusion: $selected ? null : AdoptionUserReportDTO::EXCEPTED,
            toAdopt: $toAdopt,
            sentShoppingListsToRepoint: $sent,
            blockingAnomalies: $anomalies,
            invalidAliases: $invalidAliases,
        );
    }
}
