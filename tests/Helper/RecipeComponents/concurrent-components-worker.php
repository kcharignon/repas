<?php

/**
 * Un processus d'essai de l'écriture des recettes utilisées sous charge simultanée (tests/Repas/OutputAdapter/RecipeComponentsAreWrittenOneAtATimeTest) :
 * le VRAI noyau (environnement de test), le VRAI bus de commandes et la VRAIE base, dans un processus à lui, avec sa propre connexion et sa propre
 * transaction. Il modifie une recette (nom, personnes, zéro ligne) en lui donnant UNE recette utilisée, puis écrit le résultat en JSON :
 * {"ok": true} ou {"ok": false, "message": "RECIPE_COMPONENT_CHAIN"…}.
 *
 * Arguments : <identifiant de la recette> <identifiant de la recette utilisée> <identifiant de l'auteur> <type de recette>
 * Les écritures sont VRAIMENT validées (la connexion statique de DAMA, qui annulerait tout, est coupée) : l'appelant nettoie.
 */

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Repas\Kernel;
use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\Tab;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $recipeId, $componentId, $authorId, $typeSlug] = $_SERVER['argv'];

new Dotenv()->bootEnv(dirname(__DIR__, 3) . '/.env');

// Le même mode que les tests (le conteneur compilé du mode débogage se refait quand le code change, l'autre non)
$kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
// Après l'amorçage, avant la première connexion : les écritures de ce processus sont validées pour de bon
StaticDriver::setKeepStaticConnections(false);
$bus = $kernel->getContainer()->get('test.service_container')->get(CommandBusInterface::class);

try {
    $bus->dispatch(new UpdateRecipeCommand(
        $recipeId,
        'Recette concurrente',
        4,
        new Tab([], UpdateRecipeRowSubCommand::class),
        $typeSlug,
        userId: $authorId,
        components: new Tab([new RecipeComponentSubCommand($componentId, 1.0)], RecipeComponentSubCommand::class),
    ));
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => $exception->getMessage(), 'class' => $exception::class], JSON_THROW_ON_ERROR);
}

exit(0);
