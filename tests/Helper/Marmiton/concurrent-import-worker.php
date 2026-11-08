<?php

/**
 * Un processus d'import Marmiton qui doit ATTENDRE un autre import du même lien (tests/Repas/OutputAdapter/MarmitonImportConcurrencyTest) :
 * il démarre le noyau de test (sa propre connexion à la base de test, hors de la transaction du test qui le lance), écrit ses données de
 * référence et son compte pour de bon, puis importe le lien avec un lecteur de page qui compte ses lectures. Écrit en JSON le résultat de
 * l'import et le nombre de pages lues.
 *
 * Arguments : <identifiant du compte> <lien>
 */

use Repas\Kernel;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Domain\Interface\MarmitonPageReader;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MarmitonInMemoryPageReader;

require dirname(__DIR__, 2) . '/bootstrap.php';

[, $userId, $url] = $_SERVER['argv'];

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

// Les données de référence et le compte, écrits pour de bon (aucune transaction de test ici)
$persister = new DataPersister($container);
$persister->saveRecipeType(new RecipeTypeBuilder()->isMeal()->build());
$persister->saveDepartment(new DepartmentBuilder()->isMiscellaneous()->build());
foreach ([new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme()] as $unit) {
    $persister->saveUnit($unit->build());
}
$persister->saveUser(new UserBuilder()->withId($userId)->build());

$reader = new MarmitonInMemoryPageReader()->willRead('22082', new MarmitonRecipePage(
    'https://www.marmiton.org/recettes/recette_tarte_22082.aspx',
    'Une autre lecture',
    null,
    null,
    ['1 oeuf'],
));
$container->set(MarmitonPageReader::class, $reader);

$imported = $container->get(CommandBusInterface::class)->dispatch(new ImportMarmitonRecipeCommand($url, $userId, UuidGenerator::new()));

echo json_encode(['recipeId' => $imported->recipeId, 'created' => $imported->created, 'reads' => $reader->callCount()], JSON_THROW_ON_ERROR);
