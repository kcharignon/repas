<?php

namespace Repas\Tests\Repas\OpenHostService;

use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\OpenHostService\ActiveListLineDTO;
use Repas\Repas\Application\OpenHostService\ActiveListLineLookupQueryOHS;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Domain\Service\ActiveListLookup;
use Repas\Repas\Domain\Service\SpokenIngredientRanker;
use Repas\Tests\Helper\InMemoryLoader\ActiveShoppingListLinesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenIngredientCandidatesInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\SpokenPhraseMemoryInMemoryLoader;

/**
 * L'Open Host Service de lecture seulement (Loaders en mémoire) : il lit les lignes de la liste active, les ingrédients de l'utilisateur et sa
 * mémoire de phrase, laisse `ActiveListLookup` chercher (ce que `ActiveListLookupCommandOHSTest` prouve) et publie des DTO, jamais les modèles de
 * `Repas`. Il ne lit les ingrédients que s'il y a une liste active.
 */
class ActiveListLineLookupQueryOHSTest extends TestCase
{
    private ActiveShoppingListLinesInMemoryLoader $lines;
    private SpokenIngredientCandidatesInMemoryLoader $candidates;

    /**
     * @param ?list<ActiveListLine> $lines
     * @param list<SpokenCandidate> $candidates
     * @param array<string, string> $remembered
     */
    private function ohs(?array $lines, array $candidates, bool $hasSentList = false, array $remembered = []): ActiveListLineLookupQueryOHS
    {
        $this->lines = new ActiveShoppingListLinesInMemoryLoader($lines, $hasSentList);
        $this->candidates = new SpokenIngredientCandidatesInMemoryLoader($candidates);

        return new ActiveListLineLookupQueryOHS($this->lines, $this->candidates, new SpokenPhraseMemoryInMemoryLoader($remembered), new ActiveListLookup());
    }

    private static function line(string $slug, string $name, string $departmentSlug, string $departmentName, float $quantity = 1.0): ActiveListLine
    {
        return new ActiveListLine($slug, $name, $departmentSlug, $departmentName, $quantity, 'paquet', 'paquet');
    }

    public function testWithoutAnyListNothingElseIsReadAndNoListIsSaid(): void
    {
        // Given
        $ohs = $this->ohs(null, [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes')]);

        // When
        $match = $ohs->findLine('user-id', 'des tomates');

        // Then
        $this->assertSame([false, [], null, false, []], [$match->hasList, $match->lines, $match->absentName, $match->listSent, $match->suggestions]);
        $this->assertSame(['user-id'], $this->lines->calls);
        $this->assertSame(['user-id'], $this->lines->sentCalls);
        $this->assertSame([], $this->candidates->calls);
    }

    public function testAListSentToTheDriveIsSaidApart(): void
    {
        // When
        $match = $this->ohs(null, [], hasSentList: true)->findLine('user-id', 'des tomates');

        // Then
        $this->assertSame([false, true], [$match->hasList, $match->listSent]);
    }

    public function testTheLineOfTheIngredientIsPublishedAsADto(): void
    {
        // Given
        $ohs = $this->ohs(
            [self::line('croquette', 'Croquette', 'animalerie', 'Animalerie', 2.0)],
            [new SpokenCandidate('croquette', 'croquette', 'Croquette', 'Animalerie', 0, 'animalerie')],
        );

        // When
        $match = $ohs->findLine('user-id', 'des croquettes');

        // Then
        $this->assertEquals([new ActiveListLineDTO('croquette', 'Croquette', 'animalerie', 'Animalerie', 2.0, 'paquet', 'paquet')], $match->lines);
        $this->assertSame([true, null, false, []], [$match->hasList, $match->absentName, $match->listSent, $match->suggestions]);
    }

    public function testIngredientsThatTieAreAllInTheListAndPublishedWithTheirDepartment(): void
    {
        // Given : la tomate en fruit et légume et la tomate surgelée sont toutes deux dans la liste
        $ohs = $this->ohs(
            [self::line('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 3.0), self::line('tomate-sg', 'Tomate', 'surgele', 'Surgelé', 1.0)],
            [
                new SpokenCandidate('tomate-fl', 'tomate', 'Tomate', 'Fruit & légume', 0, 'fruit-et-legume'),
                new SpokenCandidate('tomate-sg', 'tomate', 'Tomate', 'Surgelé', 0, 'surgele'),
            ],
        );

        // When
        $match = $ohs->findLine('user-id', 'tomate');

        // Then
        $this->assertSame(['tomate-fl', 'tomate-sg'], array_map(static fn(ActiveListLineDTO $line): string => $line->slug, $match->lines));
        $this->assertSame(['Fruit & légume', 'Surgelé'], array_map(static fn(ActiveListLineDTO $line): string => $line->departmentName, $match->lines));
    }

    public function testAKnownIngredientThatIsNotInTheListIsAbsentWithItsName(): void
    {
        // Given
        $ohs = $this->ohs(
            [self::line('farine', 'farine', 'epicerie', 'épicerie')],
            [new SpokenCandidate('tomate', 'tomate', 'tomate', 'fruits et légumes'), new SpokenCandidate('farine', 'farine', 'farine', 'épicerie')],
        );

        // When
        $match = $ohs->findLine('user-id', 'des tomates');

        // Then
        $this->assertSame([true, [], 'tomate', []], [$match->hasList, $match->lines, $match->absentName, $match->suggestions]);
    }

    public function testAPhraseThatMatchesNoIngredientGetsTheLinesOfTheListThatLookLikeIt(): void
    {
        // Given : la liste porte une pâte brisée ; la phrase dite ne désigne aucun ingrédient de l'utilisateur
        $ohs = $this->ohs(
            [self::line('pate-brisee', 'Pâte brisée', 'frais', 'frais')],
            [new SpokenCandidate('pate-brisee', 'pate-brisee', 'Pâte brisée', 'frais', 0, 'frais')],
        );

        // When
        $match = $ohs->findLine('user-id', 'pâte à pizza');

        // Then
        $this->assertNull($match->absentName);
        $this->assertSame(['pate-brisee'], array_map(static fn(ActiveListLineDTO $line): string => $line->slug, $match->suggestions));
    }

    public function testThePhraseTheUserRememberedWins(): void
    {
        // Given : « patates » a été retenu pour la pomme de terre
        $ohs = $this->ohs(
            [self::line('pomme-de-terre', 'Pomme de terre', 'fruit-et-legume', 'Fruit & légume')],
            [new SpokenCandidate('pomme-de-terre', 'pomme-de-terre', 'Pomme de terre', 'Fruit & légume', 0, 'fruit-et-legume')],
            remembered: [SpokenIngredientRanker::phraseKey('patates') => 'pomme-de-terre'],
        );

        // When
        $match = $ohs->findLine('user-id', 'des patates');

        // Then
        $this->assertSame(['pomme-de-terre'], array_map(static fn(ActiveListLineDTO $line): string => $line->slug, $match->lines));
    }

    public function testNothingIsWritten(): void
    {
        // Given : l'Open Host Service n'a ni bus ni dépôt d'écriture dans son constructeur
        $types = array_map(static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(), new \ReflectionMethod(ActiveListLineLookupQueryOHS::class, '__construct')->getParameters());

        // Then
        $this->assertSame([
            'Repas\Repas\Application\Loader\ActiveShoppingListLines',
            'Repas\Repas\Application\Loader\SpokenIngredientCandidates',
            'Repas\Repas\Application\Loader\SpokenPhraseMemory',
            'Repas\Repas\Domain\Service\ActiveListLookup',
        ], $types);
    }
}
