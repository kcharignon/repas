<?php

namespace Repas\Drive\Application\Command\ForgetChoice;


use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Shared\Application\Interface\IngredientAliasResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ForgetChoiceHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ChoiceRepository $choiceRepository,
        private IngredientAliasResolver $ingredientAliases,
    ) {
    }

    /**
     * Oublie toute la préférence d'un ingrédient dans le magasin courant : produits acceptés et refusés, ou « j'en ai
     * toujours ». Les produits restent connus.
     *
     * @throws DriveException `notConfigured()` (409) sans réglage, `storeMismatch()` (409) si `storeId` n'est pas le magasin
     *                        courant, `choiceNotFound()` (404) sans préférence pour cet ingrédient
     */
    public function __invoke(ForgetChoiceCommand $command): void
    {
        $settings = $this->driveSettingsRepository->findOneByUser($command->userId, $command->retailer);
        if ($settings === null) {
            throw DriveException::notConfigured();
        }
        if ($settings->getStore()->id !== $command->storeId) {
            throw DriveException::storeMismatch();
        }

        // Un ancien slug d'un commun que l'utilisateur a adopté désigne sa copie (ADR 0028 décision 4)
        $ingredientSlug = $this->ingredientAliases->resolve($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        $choice = $this->choiceRepository->findOne($command->userId, $command->retailer, $command->storeId, $ingredientSlug);
        if ($choice === null) {
            throw DriveException::choiceNotFound();
        }

        $this->choiceRepository->delete($choice);
    }
}
