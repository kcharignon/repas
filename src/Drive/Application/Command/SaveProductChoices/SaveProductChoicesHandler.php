<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Interface\ProductRepository;
use Repas\Drive\Domain\Interface\RayonVoteRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\RayonVote;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\IngredientAliasResolver;
use Repas\Shared\Application\Interface\IngredientDepartmentResolver;
use Repas\Shared\Application\Interface\UnitSlugResolver;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Shared\Application\Interface\DriveFeature;
use Repas\Shared\Domain\Exception\DriveFeatureException;
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
        private RayonVoteRepository $rayonVoteRepository,
        private IngredientDepartmentResolver $ingredientDepartments,
        private UnitSlugResolver $unitSlugs,
        private IngredientAliasResolver $ingredientAliases,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private DriveFeature $driveFeature,
    ) {
    }

    /**
     * Fusionne un lot d'intentions de l'agent dans les préférences de l'utilisateur pour son magasin courant. Les intentions
     * d'un même ingrédient s'appliquent dans l'ordre reçu ; les produits décrits sont créés (fait public partagé) ou, s'ils sont
     * déjà connus, complétés seulement là où la fiche est vide : premier écrivain gagnant pour le libellé, le contenu et le
     * code-barres, jamais réécrits par un autre utilisateur (constat C1, DRV-613). Le prix d'une fiche connue ne change que par
     * `REFRESH_PRICE` ou par l'`ADD_PRODUCT` d'un produit que l'utilisateur accepte déjà pour cet ingrédient. Les préférences
     * sont créées si besoin. Tout ou rien : tout est validé avant la première écriture, et la commande est atomique. Nombre de
     * requêtes constant, quelle que soit la taille du lot : une lecture des réglages, des alias d'ingrédients (ING-002), des produits et des préférences, puis
     * une écriture par table, celle du compteur du jour dans les réglages, et un décompte des préférences quand le lot en crée.
     * Un choix ne s'enregistre que sur un ingrédient de l'utilisateur (`IngredientAliasResolver::resolveOwned()`, la même lecture que la
     * traduction des anciens slugs) : si un seul slug du lot n'en est pas un, `ingredientNotFound()` et rien n'est écrit.
     *
     * **Valeurs propres au compte** : le libellé et le prix que le compte vient de lire (`ADD_PRODUCT`, `REJECT_PRODUCT` : le libellé ;
     * `ADD_PRODUCT` : aussi le prix ; `REFRESH_PRICE` : le prix) sont écrits sur SA ligne de produit (`Choice::observe()`,
     * `Choice::observePrice()`), quel que soit ce que la fiche commune connaît déjà : chaque compte garde ce qu'il a lui-même observé, la
     * fiche commune (créée ou complétée comme ci-dessus) ne sert que de valeur de repli aux lignes qui n'ont pas les leurs. Un prix
     * rafraîchi n'enregistre que la ligne (la date de la préférence ne bouge pas). Les valeurs voyagent avec la réécriture des lignes de
     * produits, déjà faite pour chaque préférence écrite : aucune requête de plus, sauf pour un lot qui ne fait que rafraîchir des prix,
     * dont les préférences sont maintenant enregistrées (les trois requêtes habituelles, quel que soit le nombre de préférences).
     *
     * **Recherche mémorisée (DRV-650)** : un `ADD_PRODUCT` peut porter le texte qui a fait trouver le produit sur le site de l'enseigne
     * (`SearchQueryChange`) ; il est mémorisé sur la préférence, sous la clé (utilisateur, enseigne, magasin, ingrédient) et nulle
     * part ailleurs. Intention sans recherche : celle qui est mémorisée est gardée ; recherche effacée explicitement : elle est
     * oubliée ; recherche sur une autre action : `invalidChoice()`. Aucune requête de plus : le texte voyage avec la préférence.
     *
     * **Recherche sans produit (DRV-653)** : `NOTE_SEARCH` mémorise la recherche d'un ingrédient sans toucher à ses produits (un produit pris
     * « cette fois seulement » n'est pas mémorisé, mais la recherche qui l'a fait trouver reste la bonne). La préférence est créée si elle
     * n'existe pas, sans produit accepté : le plan la lit `NEW` et cherche le texte mémorisé. Refusée sur « j'en ai toujours »
     * (`invalidChoice()`). Pas de produit à lire ni à écrire : jamais plus de requêtes qu'un `ADD_PRODUCT`.
     *
     * **Contenance mémorisée (DRV-652)** : le produit d'un `ADD_PRODUCT` peut porter sa contenance dans l'unité de la liste (« 20 tranches
     * dans ce produit », `PackSizeChange`) ; elle est mémorisée sur la ligne de ce produit dans la préférence (couple préférence,
     * produit), privée, et nulle part ailleurs. Intention sans contenance : celle qui est mémorisée pour le MÊME produit est gardée
     * (un produit nouveau n'en a pas) ; contenance effacée explicitement : oubliée ; contenance sur une autre action :
     * `invalidChoice()`. L'unité doit exister dans Repas (`UnitSlugResolver`, une requête, seulement quand le lot donne une
     * contenance) sinon `packSizeInvalid()`. Le reste voyage avec la préférence : aucune autre requête.
     *
     * **Apprentissage du rayon (DRV-642)** : chaque `ADD_PRODUCT` dont le produit connaît son rayon dans l'enseigne (donné par
     * l'agent ou déjà sur la fiche, la fiche faisant foi) vote, pour le compte, pour le couple (rayon de Repas de l'ingrédient, rayon
     * de l'enseigne) dans `drive_rayon_vote`, ce qui nourrit les `rayonHints` du plan : un compte pèse une fois par couple, quel que
     * soit le nombre de fois qu'il renvoie le même choix. Une lecture de plus (les rayons de Repas des ingrédients, par le port
     * `Shared`) et une écriture de plus, seulement quand un choix porte un rayon ; un ingrédient sans rayon de Repas connu, un refus
     * et un rafraîchissement de prix n'apprennent rien.
     *
     * @throws DriveException `invalidChoice()` (422) pour un lot, un produit ou une intention hors des bornes, un produit à la
     *                        fois ajouté et refusé pour un ingrédient, plus de 5 produits acceptés ou 20 refusés ;
     *                        `ingredientNotFound()` (404) si un slug du lot n'est pas un ingrédient de l'utilisateur ;
     *                        `limitReached()` (422) au-delà de 5 000 écritures de produits dans la journée ou de 1 000
     *                        préférences dans le magasin ; `notConfigured()` et `storeMismatch()` (409) si `storeId` n'est
     *                        pas le magasin courant ; `packSizeInvalid()` (422) si le lot donne une contenance dans une unité qui
     *                        n'existe pas (les valeurs mal formées sont refusées plus tôt, à la construction de `PackSize`)
     */
    public function __invoke(SaveProductChoicesCommand $command): void
    {
        // DRV-702 : une enseigne éteinte (`DRIVE_RETAILERS`) n'existe pas pour l'utilisateur, avant toute lecture ou écriture
        if (!$this->driveFeature->isRetailerEnabled($command->retailer->value)) {
            throw DriveFeatureException::retailerDisabled();
        }
        $this->assertWellFormed($command->retailer, $command->intents);

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

        // Chaque slug est un ingrédient de l'utilisateur, ou l'ancien slug d'un de ses ingrédients (brouillon d'envoi resté sur le téléphone,
        // onglet ouvert : un ancien commun dont il a reçu une copie, un ingrédient fusionné) qui désigne alors son ingrédient actuel : jamais
        // de préférence sous un ancien slug (ADR 0028 décision 4), jamais sur l'ingrédient d'un autre. Une lecture, quel que soit le lot
        $command = $this->withOwnedIngredients($command);

        $now = $this->clock->now();
        // Plafond du jour avant toute lecture de plus : le compteur se réserve en UNE instruction atomique (deux requêtes simultanées du même
        // compte ne lisent pas la même valeur) ; le modèle des réglages déjà lu n'en porte pas la valeur à jour
        $productWrites = count(array_filter($command->intents, static fn(ChoiceIntent $intent): bool => $intent->product !== null));
        if ($productWrites > 0 && !$this->driveSettingsRepository->reserveProductWrites($command->userId, $command->retailer, $productWrites, $now)) {
            throw DriveException::limitReached();
        }

        $this->assertPackSizeUnitsExist($command->intents);

        [$products, $changedProducts] = $this->productsOf($command, $now);
        $choices = $this->existingChoices($command);
        $this->assertRoomForNewChoices($command, $choices);
        $touchedChoices = [];

        foreach ($command->intents as $intent) {
            $input = $intent->product;
            $slug = $intent->ingredientSlug;

            if ($intent->action === ChoiceAction::REFRESH_PRICE) {
                $product = $products[$input->retailerProductId] ?? null;
                $choice = $choices[$slug] ?? null;
                // Un prix ne se rafraîchit que pour un produit que l'utilisateur accepte pour cet ingrédient
                if ($product !== null && $choice?->isAccepted($product->getId())) {
                    // Le prix que CE compte vient de lire va sur sa ligne (celui que lisent son estimation et son plan) ; la fiche commune
                    // garde sa dernière observation, valeur de repli des lignes qui n'ont pas la leur. La préférence, elle, ne change pas
                    // (sa date non plus) : seule la valeur de la ligne est enregistrée
                    $changedProducts[$input->retailerProductId] = $product->seePrice($input->price, $now);
                    $touchedChoices[$slug] ??= $choice;
                    $choice->observePrice($product->getId(), $input->price, $now);
                }
                continue;
            }

            $choice = $choices[$slug] ??= Choice::create($this->uuidGenerator->generate(), $command->userId, $command->retailer, $command->storeId, $slug, $now);
            $productId = $input === null ? null : $products[$input->retailerProductId]->getId();
            // Le prix d'une fiche connue ne bouge que pour l'utilisateur qui accepte déjà ce produit pour cet ingrédient
            if ($intent->action === ChoiceAction::ADD_PRODUCT && $input->price !== null && $choice->isAccepted($productId)) {
                $changedProducts[$input->retailerProductId] = $products[$input->retailerProductId]->seePrice($input->price, $now);
            }
            match ($intent->action) {
                ChoiceAction::ADD_PRODUCT => $intent->placement === Placement::PRIMARY ? $choice->addPrimary($productId) : $choice->addFallback($productId),
                ChoiceAction::REJECT_PRODUCT => $choice->reject($productId),
                ChoiceAction::HAVE_AT_HOME => $choice->markHaveAtHome(),
                // Seule la recherche change (ci-dessous) : les produits acceptés et refusés restent tels quels
                ChoiceAction::NOTE_SEARCH => $choice,
            };
            if ($input?->label !== null) {
                // Le libellé et le prix que CE compte vient de lire vont sur sa ligne de produit (accepté ou refusé), jamais ailleurs : la
                // fiche commune n'est que le repli des lignes qui n'ont pas les leurs. Sans prix lu, celui que la ligne avait reste
                $choice->observe($productId, $input->label, $input->price, $now);
            }
            // Après l'ajout : la préférence est alors une préférence de produits. Clé absente : la recherche mémorisée est gardée
            $intent->searchQuery?->applyTo($choice);
            if ($intent->action === ChoiceAction::ADD_PRODUCT) {
                // Le produit est accepté : clé absente, la contenance déjà mémorisée pour CE produit est gardée (un produit nouveau n'en a pas)
                $input->packSize?->applyTo($choice, $productId);
            }
            $touchedChoices[$slug] = $choice->touch($now);
        }

        $learned = $this->rayonsLearnedFrom($command->intents, $products);

        $this->productRepository->saveAll(array_values($changedProducts));
        $this->choiceRepository->saveAll(array_values($touchedChoices));
        if ($learned !== []) {
            $this->rayonVoteRepository->record($command->userId, $command->retailer, $learned);
        }
    }

    /**
     * Les intentions dont le slug désigne, pour cet utilisateur, un autre ingrédient à lui (un ancien slug) sont réécrites sur ce slug ; les
     * autres ne changent pas.
     *
     * @throws DriveException `ingredientNotFound()` si un slug n'est ni un ingrédient de l'utilisateur ni l'ancien slug de l'un d'eux
     */
    private function withOwnedIngredients(SaveProductChoicesCommand $command): SaveProductChoicesCommand
    {
        $slugs = array_values(array_unique(array_map(static fn(ChoiceIntent $intent): string => $intent->ingredientSlug, $command->intents)));
        $owned = $this->ingredientAliases->resolveOwned($command->userId, $slugs);
        foreach ($slugs as $slug) {
            if (!isset($owned[$slug])) {
                throw DriveException::ingredientNotFound();
            }
        }

        $resolved = array_filter($owned, static fn(string $target, string $slug): bool => $target !== $slug, ARRAY_FILTER_USE_BOTH);
        if ($resolved === []) {
            return $command;
        }

        return new SaveProductChoicesCommand(
            $command->userId,
            $command->retailer,
            $command->storeId,
            array_map(
                static fn(ChoiceIntent $intent): ChoiceIntent => isset($resolved[$intent->ingredientSlug])
                    ? new ChoiceIntent($resolved[$intent->ingredientSlug], $intent->action, $intent->placement, $intent->product, $intent->searchQuery)
                    : $intent,
                $command->intents,
            ),
        );
    }

    /**
     * Un vote donné par un `ADD_PRODUCT` dont le produit connaît son rayon dans l'enseigne, pour un ingrédient dont Repas connaît
     * le rayon. Le rayon est celui de la fiche (premier écrivain gagnant), pas celui de l'intention : un agent qui se trompe ne
     * fausse pas l'apprentissage d'un produit déjà connu. Aucune lecture quand aucun choix ne porte de rayon.
     *
     * @param list<ChoiceIntent> $intents
     * @param array<string, Product> $products par identifiant chez l'enseigne, déjà complétés
     *
     * @return list<RayonVote>
     */
    private function rayonsLearnedFrom(array $intents, array $products): array
    {
        $candidates = [];
        foreach ($intents as $intent) {
            $rayonId = $intent->action === ChoiceAction::ADD_PRODUCT ? $products[$intent->product->retailerProductId]->getRayonId() : null;
            if ($rayonId !== null) {
                $candidates[] = [$intent->ingredientSlug, $rayonId];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $departments = $this->ingredientDepartments->departmentSlugsByIngredient(array_values(array_unique(array_column($candidates, 0))));

        $learned = [];
        foreach ($candidates as [$ingredientSlug, $rayonId]) {
            if (isset($departments[$ingredientSlug])) {
                $learned[] = new RayonVote($departments[$ingredientSlug], $rayonId);
            }
        }

        return $learned;
    }

    /**
     * Cohérence de chaque intention et du lot, sans rien lire : tout ce qui peut être refusé l'est avant la première requête.
     *
     * @param Retailer $retailer l'enseigne du lot : elle dit la forme d'un identifiant de produit
     * @param list<ChoiceIntent> $intents
     */
    private function assertWellFormed(Retailer $retailer, array $intents): void
    {
        if (count($intents) > self::MAX_INTENTS) {
            throw DriveException::invalidChoice();
        }

        $added = $rejected = [];
        foreach ($intents as $intent) {
            $product = $intent->product;
            $wellFormed = preg_match(Choice::SLUG_PATTERN, $intent->ingredientSlug) === 1
                && ($product === null || $retailer->isValidProductId($product->retailerProductId))
                && ($product === null || self::catalogIdsAreInBounds($product))
                // DRV-721 : un identifiant d'offre a la forme de l'enseigne ; une enseigne sans offre n'en reçoit jamais
                && ($product?->offerId === null || $retailer->isValidOfferId($product->offerId))
                && ($intent->searchQuery === null || $intent->action === ChoiceAction::ADD_PRODUCT || $intent->action === ChoiceAction::NOTE_SEARCH)
                && ($product?->packSize === null || $intent->action === ChoiceAction::ADD_PRODUCT)
                && match ($intent->action) {
                    ChoiceAction::ADD_PRODUCT => $intent->placement !== null && $product?->label !== null,
                    ChoiceAction::REJECT_PRODUCT => $intent->placement === null && $product?->label !== null,
                    ChoiceAction::HAVE_AT_HOME => $intent->placement === null && $product === null,
                    ChoiceAction::REFRESH_PRICE => $intent->placement === null && $product?->price !== null,
                    // DRV-653 : une recherche à mémoriser, jamais une clé absente ni `null` (rien à noter, et l'effacer passe par `ADD_PRODUCT`)
                    ChoiceAction::NOTE_SEARCH => $intent->placement === null && $product === null && $intent->searchQuery?->searchQuery !== null,
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
     * Chaque unité des contenances données par le lot existe dans Repas (une seule lecture, aucune quand le lot n'en donne pas ou n'en
     * efface que). Une contenance dans une unité inconnue ne servirait jamais : aucune ligne de liste ne l'emploie.
     *
     * @param list<ChoiceIntent> $intents
     */
    private function assertPackSizeUnitsExist(array $intents): void
    {
        $unitSlugs = [];
        foreach ($intents as $intent) {
            $packSize = $intent->product?->packSize?->packSize;
            if ($packSize !== null) {
                $unitSlugs[$packSize->unitSlug] = true;
            }
        }
        if ($unitSlugs === []) {
            return;
        }

        $requested = array_map('strval', array_keys($unitSlugs));
        if (array_diff($requested, $this->unitSlugs->existingSlugs($requested)) !== []) {
            throw DriveException::packSizeInvalid();
        }
    }

    /**
     * Le rayon et la famille, quand l'agent les donne, sont des entiers de 1 à 2147483647 (absents : rien à contrôler).
     */
    private static function catalogIdsAreInBounds(ProductInput $product): bool
    {
        foreach ([$product->rayonId, $product->familyId] as $id) {
            if ($id !== null && !Product::isCatalogId($id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Les produits du lot, par identifiant chez l'enseigne : ceux que l'agent décrit (`ADD_PRODUCT`, `REJECT_PRODUCT`) sont
     * créés, ou complétés là où la fiche est vide s'ils sont déjà connus (une même description vue plusieurs fois s'accumule,
     * la première valeur lue reste), ceux dont il ne donne que le prix (`REFRESH_PRICE`) ne sont là que s'ils étaient déjà
     * connus. Une seule lecture.
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
        // Un lot sans produit (`NOTE_SEARCH`) n'interroge pas le dépôt
        foreach ($keys === [] ? [] : $this->productRepository->findByKeys(array_values($keys)) as $product) {
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
                ? $products[$id]->complete($input->ean, $input->label, $input->content, $input->price, $now, $input->rayonId, $input->familyId, $input->offerId)
                : Product::create($command->retailer, $command->storeId, $id, $input->ean, $input->label, $input->content, $input->price, $now, $input->rayonId, $input->familyId, $input->offerId);
        }

        return [$products, $changed];
    }

    /**
     * Plafond de préférences par utilisateur et par magasin : le lot crée une préférence par ingrédient qui n'en a pas encore
     * (`REFRESH_PRICE` n'en crée jamais). Le décompte n'est lu que dans ce cas.
     *
     * @param array<string, Choice> $choices les préférences déjà enregistrées, par slug
     */
    private function assertRoomForNewChoices(SaveProductChoicesCommand $command, array $choices): void
    {
        $newSlugs = [];
        foreach ($command->intents as $intent) {
            if ($intent->action !== ChoiceAction::REFRESH_PRICE && !isset($choices[$intent->ingredientSlug])) {
                $newSlugs[$intent->ingredientSlug] = true;
            }
        }

        if ($newSlugs !== []) {
            Choice::assertRoomForNew($this->choiceRepository->countByUserAndStore($command->userId, $command->retailer, $command->storeId), count($newSlugs));
        }
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
