<?php

namespace Repas\Drive\Application\Command\ReplaceIngredientPreference;


use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Product;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ReplaceIngredientPreferenceHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ProductRepository $productRepository,
        private ChoiceRepository $choiceRepository,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Remplace d'un coup la préférence d'un ingrédient dans le magasin courant (écran de gestion) : produits acceptés dans
     * l'ordre donné, produits refusés, ou « j'en ai toujours ». Préférence absente : créée ; ni produit ni « j'en ai
     * toujours » : la préférence est supprimée (rien à faire si elle n'existe pas). Nombre de requêtes constant : réglages,
     * produits, préférence, puis l'écriture.
     *
     * @throws DriveException `notConfigured()` et `storeMismatch()` (409) ; `invalidChoice()` (422) pour un produit inconnu ou
     *                        d'un autre magasin, un doublon, un produit à la fois accepté et refusé, plus de 5 produits
     *                        acceptés ou 20 refusés, « j'en ai toujours » avec des produits
     */
    public function __invoke(ReplaceIngredientPreferenceCommand $command): void
    {
        // Un corps JSON `{"a": "…"}` arrive ici en tableau à clés texte : `[...$accepted, ...$rejected]` laisserait alors la clé
        // d'une liste écraser celle de l'autre (un produit à la fois accepté et refusé, ou jamais contrôlé, passerait)
        $accepted = array_values($command->acceptedProductIds);
        $rejected = array_values($command->rejectedProductIds);
        // Avant toute lecture : une demande hors des bornes n'interroge pas la base
        if (count($accepted) > Choice::MAX_ACCEPTED || count($rejected) > Choice::MAX_REJECTED) {
            throw DriveException::invalidChoice();
        }

        $settings = $this->driveSettingsRepository->findOneByUser($command->userId, $command->retailer);
        if ($settings === null) {
            throw DriveException::notConfigured();
        }
        if ($settings->getStore()->id !== $command->storeId) {
            throw DriveException::storeMismatch();
        }

        $this->assertProductsOfTheStore($command, array_values(array_unique([...$accepted, ...$rejected])));

        $choice = $this->choiceRepository->findOne($command->userId, $command->retailer, $command->storeId, $command->ingredientSlug);
        if (!$command->haveAtHome && $accepted === [] && $rejected === []) {
            if ($choice !== null) {
                $this->choiceRepository->delete($choice);
            }

            return;
        }

        $now = $this->clock->now();
        $choice ??= Choice::create($this->uuidGenerator->generate(), $command->userId, $command->retailer, $command->storeId, $command->ingredientSlug, $now);
        $this->choiceRepository->save($choice->replace($command->haveAtHome, $accepted, $rejected)->touch($now));
    }

    /**
     * Chaque identifiant désigne un produit connu de ce magasin (une seule lecture) : un produit inconnu ou d'un autre magasin
     * ne se range pas dans les préférences.
     *
     * @param list<string> $productIds
     */
    private function assertProductsOfTheStore(ReplaceIngredientPreferenceCommand $command, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $products = $this->productRepository->findByIds($productIds);
        $ofTheStore = array_filter(
            $products,
            static fn(Product $product): bool => $product->getRetailer()->value === $command->retailer->value && $product->getStoreId() === $command->storeId,
        );
        if (count($ofTheStore) !== count($productIds)) {
            throw DriveException::invalidChoice();
        }
    }
}
