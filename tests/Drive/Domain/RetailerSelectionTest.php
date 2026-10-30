<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Selection;
use Repas\Drive\Domain\Model\SelectionMode;
use Repas\Drive\Domain\Service\RetailerSelection;

/**
 * La règle de choix de l'enseigne en table (docs/drive/multi-enseignes.md § 5.1, R1 à R5). Le registre ne compte que deux enseignes : le
 * cas « plusieurs » est à deux, le cas « une seule » est à une.
 */
class RetailerSelectionTest extends TestCase
{
    private const Retailer A = Retailer::LECLERC;
    private const Retailer B = Retailer::AUCHAN;

    /**
     * @return iterable<string, array{list<Retailer>, list<Retailer>, ?Retailer, ?Retailer, SelectionMode, ?Retailer, list<Retailer>}>
     *         allumées, configurées (la plus ancienne d'abord), drive habituel, dernière utilisée => mode, enseigne, candidates
     */
    public static function cases(): iterable
    {
        $both = [self::A, self::B];

        // R1 : aucune enseigne configurée
        yield 'R1 aucune configurée' => [$both, [], null, null, SelectionMode::NONE, null, []];
        yield 'R1 aucune configurée, préférences périmées' => [$both, [], self::B, self::A, SelectionMode::NONE, null, []];
        // R2 : une seule configurée, on l'utilise sans rien demander
        yield 'R2 une seule configurée' => [$both, [self::A], null, null, SelectionMode::AUTO, self::A, [self::A]];
        yield 'R2 une seule configurée, l\'autre en dernière utilisée' => [$both, [self::A], null, self::B, SelectionMode::AUTO, self::A, [self::A]];
        yield 'R2 une seule configurée, l\'autre en drive habituel' => [$both, [self::B], self::A, self::A, SelectionMode::AUTO, self::B, [self::B]];
        yield 'R2 une seule configurée, elle est le drive habituel' => [$both, [self::B], self::B, self::B, SelectionMode::AUTO, self::B, [self::B]];
        // R3 : plusieurs configurées, jamais `AUTO` : on demande, le drive habituel est présélectionné (même si la dernière utilisée est une autre)
        yield 'R3 drive habituel A, dernière utilisée B : ASK, présélection A' => [$both, [self::A, self::B], self::A, self::B, SelectionMode::ASK, self::A, [self::A, self::B]];
        yield 'R3 drive habituel B, dernière utilisée A : ASK, présélection B' => [$both, [self::A, self::B], self::B, self::A, SelectionMode::ASK, self::B, [self::A, self::B]];
        yield 'R3 drive habituel B, aucune dernière utilisée : ASK, présélection B' => [$both, [self::A, self::B], self::B, null, SelectionMode::ASK, self::B, [self::A, self::B]];
        yield 'R3 drive habituel B, configurées B puis A, dernière utilisée A : ASK, présélection B' => [$both, [self::B, self::A], self::B, self::A, SelectionMode::ASK, self::B, [self::B, self::A]];
        // R4 : plusieurs configurées, pas de drive habituel candidat : on demande, la dernière utilisée est présélectionnée, sinon la première configurée
        yield 'R4 dernière utilisée B' => [$both, [self::A, self::B], null, self::B, SelectionMode::ASK, self::B, [self::A, self::B]];
        yield 'R4 dernière utilisée A' => [$both, [self::A, self::B], null, self::A, SelectionMode::ASK, self::A, [self::A, self::B]];
        yield 'R4 sans dernière utilisée : la première configurée (A)' => [$both, [self::A, self::B], null, null, SelectionMode::ASK, self::A, [self::A, self::B]];
        yield 'R4 sans dernière utilisée : la première configurée est la plus ancienne (B)' => [$both, [self::B, self::A], null, null, SelectionMode::ASK, self::B, [self::B, self::A]];
        yield 'R4 la dernière utilisée passe avant la plus ancienne' => [$both, [self::B, self::A], null, self::A, SelectionMode::ASK, self::A, [self::B, self::A]];
        // R5 : une enseigne éteinte n'est jamais candidate, un drive habituel éteint est ignoré, jamais une erreur
        yield 'R5 aucune allumée' => [[], [self::A, self::B], self::A, self::A, SelectionMode::NONE, null, []];
        yield 'R5 la seule configurée est éteinte' => [[self::B], [self::A], null, self::A, SelectionMode::NONE, null, []];
        yield 'R5 drive habituel éteint, l\'autre reste seule' => [[self::A], [self::A, self::B], self::B, self::B, SelectionMode::AUTO, self::A, [self::A]];
        yield 'R5 drive habituel allumé, l\'autre éteinte' => [[self::B], [self::A, self::B], self::B, self::A, SelectionMode::AUTO, self::B, [self::B]];
        yield 'R5 dernière utilisée éteinte, l\'autre reste seule' => [[self::A], [self::A, self::B], null, self::B, SelectionMode::AUTO, self::A, [self::A]];
        // Entrées bizarres : une enseigne répétée n'est candidate qu'une fois
        yield 'enseigne répétée' => [$both, [self::A, self::A, self::B], null, null, SelectionMode::ASK, self::A, [self::A, self::B]];
        yield 'enseigne répétée, une seule' => [$both, [self::B, self::B], null, null, SelectionMode::AUTO, self::B, [self::B]];
    }

    /**
     * @param list<Retailer> $enabled
     * @param list<Retailer> $configured
     * @param list<Retailer> $candidates
     */
    #[DataProvider('cases')]
    public function testTheRulesDecideTheModeTheRetailerAndTheCandidates(array $enabled, array $configured, ?Retailer $default, ?Retailer $lastUsed, SelectionMode $mode, ?Retailer $retailer, array $candidates): void
    {
        // When
        $selection = new RetailerSelection()->resolve($enabled, $configured, $default, $lastUsed);

        // Then
        $this->assertSame($mode, $selection->mode);
        $this->assertSame($retailer, $selection->retailer);
        $this->assertSame($candidates, $selection->candidates);
    }

    /**
     * @return iterable<string, array{Selection, list<Retailer>, Retailer}>
     */
    public static function retailersToUse(): iterable
    {
        yield 'la sélection l\'emporte' => [new Selection(SelectionMode::AUTO, self::B, [self::B]), [self::A, self::B], self::B];
        yield 'la présélection d\'une question' => [new Selection(SelectionMode::ASK, self::B, [self::A, self::B]), [self::A, self::B], self::B];
        yield 'aucune configurée : l\'enseigne par défaut du registre' => [new Selection(SelectionMode::NONE, null, []), [self::A, self::B], Retailer::default()];
        yield 'aucune configurée, l\'enseigne par défaut allumée en second' => [new Selection(SelectionMode::NONE, null, []), [self::B, self::A], Retailer::default()];
        yield 'aucune configurée, l\'enseigne par défaut éteinte : la première allumée' => [new Selection(SelectionMode::NONE, null, []), [self::B], self::B];
        yield 'aucune configurée, rien d\'allumé : l\'enseigne par défaut (la demande répondra 404)' => [new Selection(SelectionMode::NONE, null, []), [], Retailer::default()];
    }

    /**
     * @param list<Retailer> $enabled
     */
    #[DataProvider('retailersToUse')]
    public function testTheRetailerOfARequestThatNamesNoneIsThatOfTheSelectionOrTheUsualFallback(Selection $selection, array $enabled, Retailer $expected): void
    {
        // When / Then
        $this->assertSame($expected, new RetailerSelection()->retailerToUse($selection, $enabled));
    }

    public function testWithASingleEnabledRetailerTheSelectionIsExactlyTheBehaviourOfBefore(): void
    {
        // Given : la production d'aujourd'hui, une seule enseigne allumée
        $service = new RetailerSelection();
        $default = Retailer::default();

        // When / Then : un magasin enregistré donne `AUTO`, sinon `NONE`, et l'enseigne en vigueur est toujours la même
        $configured = $service->resolve([$default], [$default], null, null);
        $this->assertSame([SelectionMode::AUTO, $default, [$default]], [$configured->mode, $configured->retailer, $configured->candidates]);
        $this->assertSame($default, $service->retailerToUse($configured, [$default]));
        $none = $service->resolve([$default], [], null, null);
        $this->assertSame([SelectionMode::NONE, null, []], [$none->mode, $none->retailer, $none->candidates]);
        $this->assertSame($default, $service->retailerToUse($none, [$default]));
    }
}
