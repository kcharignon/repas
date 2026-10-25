<?php

namespace Repas\Voice\Application\Command\AddSpokenItem;


use Repas\Shared\Application\Dto\ResolvedIngredient;
use Repas\Shared\Application\Interface\SpokenIngredientResolver;
use Repas\Shared\Application\Interface\VoiceShoppingList;
use Repas\Voice\Domain\Model\VoiceReplies;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute à la liste de courses l'article que l'utilisateur vient de dire (ADR 0029 décisions 3 à 5, VOX-204), et dit en une phrase ce
 * qui a été fait :
 *
 * 1. `SpokenIngredientResolver` choisit, parmi les ingrédients de l'utilisateur seulement, le plus probable pour la phrase (un seul passage
 *    en base, quelques requêtes de lecture). Rien de plausible : on ne crée rien, on le dit (« Je ne trouve pas xyz… »), rien n'est écrit ;
 * 2. `VoiceShoppingList` ajoute la ligne à la liste active, ou à une liste neuve s'il n'y en a pas (une liste envoyée au drive n'est jamais
 *    modifiée). Une seule écriture.
 *
 * Un choix sûr se dit court (« C'est noté : pain. »), un choix probable ou incertain dit aussi le rayon, pour que l'erreur se voie. En séance
 * ouverte la réponse invite à continuer.
 *
 * Aucune exception n'est attrapée ici : si le second port échoue (l'ingrédient a disparu entre-temps, panne), la commande est annulée en
 * entier — le bus est transactionnel et une exception attrapée dans une transaction imbriquée la ferait échouer au `commit` — et le webhook
 * répond par la phrase d'échec. Les deux ports sont ceux de `Shared` : ce contexte n'importe rien de `Repas`.
 */
#[AsMessageHandler]
readonly class AddSpokenItemHandler
{
    public function __construct(
        private SpokenIngredientResolver $ingredientResolver,
        private VoiceShoppingList $shoppingList,
    ) {
    }

    public function __invoke(AddSpokenItemCommand $command): AddSpokenItemResult
    {
        $ingredient = $this->ingredientResolver->resolve($command->userId, $command->phrase);
        if ($ingredient === null) {
            return $this->result(VoiceReplies::notFound($command->phrase), $command, added: false);
        }

        $added = $this->shoppingList->addItem($command->userId, $ingredient->slug);

        return $this->result(
            VoiceReplies::added($ingredient->name, $ingredient->departmentName, $ingredient->confidence === ResolvedIngredient::SURE, $added->listCreated, $added->previousListSent),
            $command,
            added: true,
        );
    }

    private function result(string $speech, AddSpokenItemCommand $command, bool $added): AddSpokenItemResult
    {
        return $command->sessionIsOpen
            ? new AddSpokenItemResult(VoiceReplies::withInvitation($speech), keepSessionOpen: true, added: $added)
            : new AddSpokenItemResult($speech, keepSessionOpen: false, added: $added);
    }
}
