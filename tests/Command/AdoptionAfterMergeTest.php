<?php

namespace Repas\Tests\Command;


use Repas\Repas\Application\Command\MergeIngredients\MergeIngredientsCommand;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La commande de migration d'adoption (ING-003, déjà exécutée en production) doit rester juste APRÈS des fusions (ING-005) : une fusion fait
 * désigner à l'alias d'un commun adopté l'ingrédient gardé, qui n'est pas né de ce commun. `--check` ne doit pas y voir une erreur, un
 * nouveau `--apply` ne doit ni réadopter le commun ni rien écrire, et l'alias reste valide pour masquer le commun.
 */
class AdoptionAfterMergeTest extends DatabaseTestCase
{
    public function testAFusionThatAbsorbsAnAdoptionLeavesTheMigrationCheckAtPeace(): void
    {
        // Given : un commun « poivre », un utilisateur concerné (il a son ingrédient « Tomate » et une recette qui utilise le commun), la migration faite
        $persister = new DataPersister(static::getContainer());
        $dataset = new MergeDataset($this->entityManager, $persister);
        $dataset->units();
        $user = $dataset->user();
        $kept = $dataset->ingredient('Tomate', $user);
        $common = new IngredientBuilder()->withName('Poivre')->withDepartment(new DepartmentBuilder()->isCereal())->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        $persister->saveIngredient($common);
        $dataset->recipe('r1', $user->getId(), [[$common->getSlug(), 5.0, 'gramme']]);
        $tester = new CommandTester(new Application(self::$kernel)->find('repas:ingredients:adopt-commons'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true, '--accept-sent' => true, '--source' => 'migration-essai']), $tester->getDisplay());
        $copy = $dataset->connection->fetchOne('SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = ?', [$user->getId(), 'poivre']);
        $this->assertSame('poivre' . $user->getId(), $copy);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--check' => true]), $tester->getDisplay());

        // When : l'utilisateur fusionne la copie du poivre dans sa « Tomate » (l'un de ses ingrédients, pas né du poivre)
        $this->entityManager->clear();
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new MergeIngredientsCommand($user->getId(), $copy, $kept->getSlug()));
        $afterMerge = $dataset->tableHashes();

        // Then : la migration n'y voit rien d'anormal, ne réadopte rien et n'écrit rien ; le commun reste masqué derrière l'ingrédient gardé
        $this->assertSame('merge', $dataset->connection->fetchOne("SELECT source FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'poivre'", [$user->getId()]));
        $this->assertSame($kept->getSlug(), $dataset->connection->fetchOne("SELECT ingredient_slug FROM ingredient_alias WHERE user_id = ? AND alias_slug = 'poivre'", [$user->getId()]));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--check' => true]), $tester->getDisplay());
        $this->assertStringContainsString('Rien à faire : 0 à adopter, 0 à repointer, aucun alias invalide.', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]), $tester->getDisplay());
        $this->assertSame($afterMerge, $dataset->tableHashes());
        $this->assertSame(0, $dataset->count('ingredient', "slug LIKE 'poivre%' AND creator IS NOT NULL"));
        $this->assertSame([$kept->getSlug()], $dataset->connection->fetchFirstColumn('SELECT ingredient FROM recipe_row WHERE recipe = ?', ['r1']));
    }
}
