<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Application\Command\PurgeUserData\PurgeUserDataCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;

/**
 * Les conversions que la fusion copie sur l'ingrédient gardé (amendement du 2026-10-21) sont rattachées à un ingrédient du compte : la
 * suppression du compte (ADR 0021) les efface avec lui, sans qu'aucune colonne ne désigne l'utilisateur (`UserOwnedDataCoverageTest` reste
 * inchangé). Le compte d'un autre utilisateur, lui, n'est pas touché.
 */
class MergeRecoveredConversionsArePurgedTest extends DatabaseTestCase
{
    public function testDeletingTheAccountErasesTheConversionsTheMergeCopiedAndOnlyThose(): void
    {
        // Given : un utilisateur dont « Tomates » sait compter la tranche, fusionnée dans « Tomate » ; un autre utilisateur avec ses conversions
        $dataset = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $dataset->units();
        $user = $dataset->user();
        $other = $dataset->user();
        $kept = $dataset->ingredient('Tomate', $user);
        $absorbed = $dataset->ingredient('Tomates', $user);
        $theirs = $dataset->ingredient('Tomate', $other);
        $dataset->conversion($absorbed, 25.0, new UnitBuilder()->isSlice(), new UnitBuilder()->isGramme());
        $dataset->conversion($theirs);
        $dataset->recipe('r1', $user->getId(), [[$absorbed->getSlug(), 4.0, 'tranche']]);
        $this->entityManager->clear();
        $bus = static::getContainer()->get(CommandBusInterface::class);

        $bus->dispatch(new MergeIngredientsCommand($user->getId(), $absorbed->getSlug(), $kept->getSlug()));
        $this->entityManager->clear();
        $this->assertSame([['tranche', 'gramme', 25.0]], $dataset->ownConversions($kept->getSlug()), 'la fusion a copié la conversion sur « Tomate »');

        // When : le compte est supprimé
        $bus->dispatch(new PurgeUserDataCommand($user->getId()));
        $this->entityManager->clear();

        // Then : plus aucune conversion de ses ingrédients, la conversion commune et celle de l'autre utilisateur sont intactes
        $this->assertSame(0, $dataset->count('conversion', 'ingredient = ?', [$kept->getSlug()]));
        $this->assertSame(0, $dataset->count('ingredient', 'creator = ?', [$user->getId()]));
        $this->assertSame(1, $dataset->count('conversion', 'ingredient = ?', [$theirs->getSlug()]));
        $this->assertSame(1, $dataset->count('conversion', 'ingredient IS NULL'));
    }
}
