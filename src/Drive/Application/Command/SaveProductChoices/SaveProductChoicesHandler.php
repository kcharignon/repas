<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use DateTimeImmutable;
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
readonly class SaveProductChoicesHandler
{
    /** Taille maximale d'un lot d'intentions. */
    public const int MAX_INTENTS = 200;

    public function __construct(
        private DriveSettingsRepository $driveSettingsRepository,
        private ProductRepository $productRepository,
        private ChoiceRepository $choiceRepository,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Fusionne un lot d'intentions de l'agent dans les préférences de l'utilisateur pour son magasin courant. Les intentions
     * d'un même ingrédient s'appliquent dans l'ordre reçu ; les produits décrits sont créés ou mis à jour (fait public
     * partagé), les préférences créées si besoin. Tout ou rien : tout est validé avant la première écriture, et la commande
     * est atomique. Nombre de requêtes constant, quelle que soit la taille du lot : une lecture des réglages, des produits et
     * des préférences, puis une écriture par table. L'existence de l'ingrédient n'est pas vérifiée (`Drive` ne connaît pas
     * `Repas`).
     *
     * @throws DriveException `invalidChoice()` (422) pour un lot, un produit ou une intention hors des bornes, un produit à la
     *                        fois ajouté et refusé pour un ingrédient, plus de 5 produits acceptés ou 20 refusés ;
     *                        `notConfigured()` et `storeMismatch()` (409) si `storeId` n'est pas le magasin courant
     */
    public function __invoke(SaveProductChoicesCommand $command): void
    {
        $this->assertWellFormed($command->intents);

        $settings = $this->driveSettingsRepository->findOneByUser($command->userId, $command->retailer);
        if ($settings === null) {
            throw DriveException::notConfigured();
        }
        if ($settings->getStore()->id !== $command->storeId) {
            throw DriveException::storeMismatch();
        }
        if ($command->intents === []) {
            return;
        }

        $now = $this->clock->now();
        [$products, $changedProducts] = $this->productsOf($command, $now);
        $choices = $this->existingChoices($command);
        $touchedChoices = [];

        foreach ($command->intents as $intent) {
            $input = $intent->product;
            $slug = $intent->ingredientSlug;

            if ($intent->action === ChoiceAction::REFRESH_PRICE) {
                $product = $products[$input->retailerProductId] ?? null;
                // Un prix ne se rafraîchit que pour un produit que l'utilisateur accepte pour cet ingrédient
                if ($product !== null && ($choices[$slug] ?? null)?->isAccepted($product->getId())) {
                    $changedProducts[$input->retailerProductId] = $product->seePrice($input->price, $now);
                }
                continue;
            }

            $choice = $choices[$slug] ??= Choice::create($this->uuidGenerator->generate(), $command->userId, $command->retailer, $command->storeId, $slug, $now);
            $productId = $input === null ? null : $products[$input->retailerProductId]->getId();
            match ($intent->action) {
                ChoiceAction::ADD_PRODUCT => $intent->placement === Placement::PRIMARY ? $choice->addPrimary($productId) : $choice->addFallback($productId),
                ChoiceAction::REJECT_PRODUCT => $choice->reject($productId),
                ChoiceAction::HAVE_AT_HOME => $choice->markHaveAtHome(),
            };
            $touchedChoices[$slug] = $choice->touch($now);
        }

        $this->productRepository->saveAll(array_values($changedProducts));
        $this->choiceRepository->saveAll(array_values($touchedChoices));
    }

    /**
     * Cohérence de chaque intention et du lot, sans rien lire : tout ce qui peut être refusé l'est avant la première requête.
     *
     * @param list<ChoiceIntent> $intents
     */
    private function assertWellFormed(array $intents): void
    {
        if (count($intents) > self::MAX_INTENTS) {
            throw DriveException::invalidChoice();
        }

        $added = $rejected = [];
        foreach ($intents as $intent) {
            $product = $intent->product;
            $wellFormed = preg_match(Choice::SLUG_PATTERN, $intent->ingredientSlug) === 1
                && ($product === null || preg_match(Product::RETAILER_PRODUCT_ID_PATTERN, $product->retailerProductId) === 1)
                && match ($intent->action) {
                    ChoiceAction::ADD_PRODUCT => $intent->placement !== null && $product?->label !== null,
                    ChoiceAction::REJECT_PRODUCT => $intent->placement === null && $product?->label !== null,
                    ChoiceAction::HAVE_AT_HOME => $intent->placement === null && $product === null,
                    ChoiceAction::REFRESH_PRICE => $intent->placement === null && $product?->price !== null,
                };
            if (!$wellFormed) {
                throw DriveException::invalidChoice();
            }

            if ($intent->action === ChoiceAction::ADD_PRODUCT) {
                $added[$intent->ingredientSlug][$product->retailerProductId] = true;
            } elseif ($intent->action === ChoiceAction::REJECT_PRODUCT) {
                $rejected[$intent->ingredientSlug][$product->retailerProductId] = true;
            }
        }

        // Un produit à la fois choisi et refusé pour le même ingrédient : l'agent se contredit
        foreach ($added as $slug => $productIds) {
            if (array_intersect_key($productIds, $rejected[$slug] ?? []) !== []) {
                throw DriveException::invalidChoice();
            }
        }
    }

    /**
     * Les produits du lot, par identifiant chez l'enseigne : ceux que l'agent décrit (`ADD_PRODUCT`, `REJECT_PRODUCT`) sont
     * créés ou mis à jour (une même description vue plusieurs fois s'accumule), ceux dont il ne donne que le prix
     * (`REFRESH_PRICE`) ne sont là que s'ils étaient déjà connus. Une seule lecture.
     *
     * @return array{array<string, Product>, array<string, Product>} tous les produits, et ceux à enregistrer
     */
    private function productsOf(SaveProductChoicesCommand $command, DateTimeImmutable $now): array
    {
        $keys = [];
        foreach ($command->intents as $intent) {
            if ($intent->product !== null) {
                $keys[$intent->product->retailerProductId] = [
                    'retailer' => $command->retailer,
                    'storeId' => $command->storeId,
                    'retailerProductId' => $intent->product->retailerProductId,
                ];
            }
        }

        $products = [];
        foreach ($this->productRepository->findByKeys(array_values($keys)) as $product) {
            $products[$product->getRetailerProductId()] = $product;
        }

        $changed = [];
        foreach ($command->intents as $intent) {
            $input = $intent->product;
            if ($input === null || $intent->action === ChoiceAction::REFRESH_PRICE) {
                continue;
            }

            $id = $input->retailerProductId;
            $changed[$id] = $products[$id] = isset($products[$id])
                ? $products[$id]->see($input->ean, $input->label, $input->content, $input->price, $now)
                : Product::create($command->retailer, $command->storeId, $id, $input->ean, $input->label, $input->content, $input->price, $now);
        }

        return [$products, $changed];
    }

    /**
     * @return array<string, Choice> par slug d'ingrédient
     */
    private function existingChoices(SaveProductChoicesCommand $command): array
    {
        $slugs = array_values(array_unique(array_map(static fn(ChoiceIntent $intent): string => $intent->ingredientSlug, $command->intents)));

        $choices = [];
        foreach ($this->choiceRepository->findByUserStoreAndSlugs($command->userId, $command->retailer, $command->storeId, $slugs) as $choice) {
            $choices[$choice->getIngredientSlug()] = $choice;
        }

        return $choices;
    }
}
