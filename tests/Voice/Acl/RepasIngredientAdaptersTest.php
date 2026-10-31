<?php

namespace Repas\Tests\Voice\Acl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\RememberSpokenPhrase\RememberSpokenPhraseCommand;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Tests\Helper\RepasForVoice;
use Repas\Voice\Domain\Model\CreatedIngredient;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Voice\Domain\Model\SpokenResolution;

/**
 * La transposition des couches anticorruption `IngredientResolutionCommandAdapter` et `IngredientCreationCommandAdapter` (ADR 0035) : ce que
 * `Repas` rend (ingrédient classé, ex æquo, propositions, rayons, unités, ingrédient créé) devient les types de `Voice`. Le classement lui-même
 * est prouvé dans `Repas`, les dialogues par les tests des handlers.
 */
class RepasIngredientAdaptersTest extends TestCase
{
    private RepasForVoice $repas;

    protected function setUp(): void
    {
        $this->repas = new RepasForVoice();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function confidences(): iterable
    {
        yield 'sûr' => [ResolvedIngredient::SURE, Confidence::SURE->value];
        yield 'probable' => [ResolvedIngredient::PROBABLE, Confidence::PROBABLE->value];
        yield 'incertain' => [ResolvedIngredient::UNSURE, Confidence::UNSURE->value];
    }

    #[DataProvider('confidences')]
    public function testEveryConfidenceOfTheRankerIsNamedByTheMatchingConstantOfVoice(string $voiceConfidence, string $rankerConfidence): void
    {
        // Given : le classeur de `Repas` rend cette confiance
        $this->repas->willResolve(new ResolvedIngredient('tomate-u', 'Tomate', 'Fruit & légume', $voiceConfidence));

        // When
        $resolution = $this->repas->resolutionPort()->resolveOrSuggest('user-1', 'des tomates');

        // Then : le nom de la confiance est celui du classeur, rien de plus
        $this->assertSame($rankerConfidence, $resolution->ingredient?->confidence);
        $this->assertSame($voiceConfidence, $resolution->ingredient->confidence);
        $this->assertSame(['tomate-u', 'Tomate', 'Fruit & légume'], [$resolution->ingredient->slug, $resolution->ingredient->name, $resolution->ingredient->departmentName]);
        $this->assertCount(3, Confidence::cases());
    }

    public function testIngredientsThatTieAreGivenAsOptionsThatSayTheirDepartment(): void
    {
        // Given
        $this->repas->willTie([
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ]);

        // When
        $resolution = $this->repas->resolutionPort()->resolveOrSuggest('user-1', 'des tomates');

        // Then
        $this->assertEquals(SpokenResolution::tied([
            new DialogueOption('tomate-fl', 'Tomate', 'fruit-et-legume', 'Fruit & légume'),
            new DialogueOption('tomate-sg', 'Tomate', 'surgele', 'Surgelé'),
        ]), $resolution);
    }

    public function testProposalsAreGivenAsOptionsWithoutDepartment(): void
    {
        // Given
        $this->repas->owns('sirop-rouge-u', 'Sirop fruit rouge', 'Boisson');

        // When
        $resolution = $this->repas->resolutionPort()->resolveOrSuggest('user-1', 'sirop de menthe');

        // Then : le dialogue des propositions ne dit pas les rayons
        $this->assertEquals(SpokenResolution::unknown([new DialogueOption('sirop-rouge-u', 'Sirop fruit rouge')]), $resolution);
    }

    public function testRememberingSendsTheCommandOfRepasWithThePhraseAsHeard(): void
    {
        // When
        $this->repas->resolutionPort()->remember('user-1', 'de la ciboulette', 'persil-u');

        // Then
        $this->assertEquals([new RememberSpokenPhraseCommand('user-1', 'de la ciboulette', 'persil-u')], $this->repas->bus->dispatched());
    }

    public function testTheCataloguesAreGivenAsOptionsInTheOrderOfRepas(): void
    {
        // When
        $port = $this->repas->creationPort();

        // Then
        $this->assertEquals([new DialogueOption('boucherie', 'boucherie'), new DialogueOption('fruit-et-legume', 'fruit & légume'), new DialogueOption('produit-menager', 'produit ménager')], $port->departments());
        $this->assertEquals([new DialogueOption('kilo', 'kilo'), new DialogueOption('unite', 'unité'), new DialogueOption('paquet', 'paquet')], $port->units());
    }

    public function testACreatedIngredientIsGivenWithItsDepartmentName(): void
    {
        // When
        $created = $this->repas->creationPort()->create('user-1', 'ciboulette', 'fruit-et-legume', 'unite');

        // Then
        $this->assertEquals(new CreatedIngredient('ciboulette-user-1', 'ciboulette', 'fruit & légume'), $created);
        $this->assertSame([['user-1', 'ciboulette', 'fruit-et-legume', 'unite']], $this->repas->created());
    }

    public function testTheErrorsOfRepasAreNotCaught(): void
    {
        // Then : un rayon inconnu annule la commande entière, rien n'est envoyé
        $this->expectExceptionObject(DepartmentException::notFound());
        try {
            $this->repas->creationPort()->create('user-1', 'ciboulette', 'inconnu', 'unite');
        } finally {
            $this->assertSame([], $this->repas->created());
        }
    }
}
