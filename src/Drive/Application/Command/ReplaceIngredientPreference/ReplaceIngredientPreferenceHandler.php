<?php

namespace Repas\Drive\Application\Command\ReplaceIngredientPreference;


use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Application\Port\IngredientAliasCommandPort;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Domain\Exception\DriveFeatureException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class ReplaceIngredientPreferenceHandler
{
    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ProductRepository $productRepository,
        private ChoiceRepository $choiceRepository,
        private IngredientAliasCommandPort $ingredientAliases,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * Remplace d'un coup la préférence d'un ingrédient dans le magasin courant (écran de gestion) : produits acceptés dans
     * l'ordre donné, produits refusés, ou « j'en ai toujours ». Préférence absente : créée ; ni produit ni « j'en ai
     * toujours » : la préférence est supprimée (rien à faire si elle n'existe pas). Nombre de requêtes constant : réglages,
     * produits, alias d'ingrédients (ING-002), préférence, puis l'écriture (et un décompte des préférences quand on en crée une).
     * Créer ou remplacer une préférence exige que le slug soit un ingrédient de l'utilisateur (ou l'ancien slug d'un de ses ingrédients) ;
     * la supprimer, non : elle est la sienne.
     *
     * @throws DriveException `notConfigured()` et `storeMismatch()` (409) ; `ingredientNotFound()` (404) si le slug n'est pas un
     *                        ingrédient de l'utilisateur (une préférence à écrire) ; `invalidChoice()` (422) pour un produit inconnu ou
     *                        d'un autre magasin, un doublon, un produit à la fois accepté et refusé, plus de 5 produits
     *                        acceptés ou 20 refusés, « j'en ai toujours » avec des produits ; `limitReached()` (422) si la
     *                        création dépasserait 1 000 préférences dans le magasin
     */
    public function __invoke(ReplaceIngredientPreferenceCommand $command): void
    {
        // DRV-702 : une enseigne éteinte (`DRIVE_RETAILERS`) n'existe pas pour l'utilisateur, avant toute lecture ou écriture
        if (!$this->driveFeature->isRetailerEnabled($command->retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }
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

        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) désigne son ingrédient actuel : jamais de préférence sous un ancien slug (ADR 0028 décision 4)
        $ownedSlug = $this->ingredientAliases->resolveOwned($command->userId, [$command->ingredientSlug])[$command->ingredientSlug] ?? null;

        if (!$command->haveAtHome && $accepted === [] && $rejected === []) {
            // Effacer sa propre préférence n'exige pas que l'ingrédient existe encore ni qu'il soit à l'utilisateur : la ligne est la sienne
            $choice = $this->choiceRepository->findOne($command->userId, $command->retailer, $command->storeId, $ownedSlug ?? $command->ingredientSlug);
            if ($choice !== null) {
                $this->choiceRepository->delete($choice);
            }

            return;
        }

        // Une préférence ne se pose que sur un ingrédient de l'utilisateur
        $ingredientSlug = $ownedSlug ?? throw DriveException::ingredientNotFound();
        $choice = $this->choiceRepository->findOne($command->userId, $command->retailer, $command->storeId, $ingredientSlug);
        if ($choice === null) {
            // Une préférence de plus : plafond par utilisateur et par magasin (une lecture, seulement quand on en crée une)
            Choice::assertRoomForNew($this->choiceRepository->countByUserAndStore($command->userId, $command->retailer, $command->storeId), 1);
        }

        $now = $this->clock->now();
        $choice ??= Choice::create($this->uuidGenerator->generate(), $command->userId, $command->retailer, $command->storeId, $ingredientSlug, $now);
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
