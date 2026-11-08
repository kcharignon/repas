<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Repas\Domain\Model\ShoppingListRow as Row;

final class ShoppingList implements ModelInterface
{
    use ModelTrait;

    /**
     * Ce qui reste d'une ligne après un retrait à la voix, au-dessous de quoi la ligne est épuisée : Alexa dit une quantité à deux décimales
     * (« 0 » pour 0,004), jamais « il t'en reste 0 ». Le même seuil que `Voice\Domain\Model\RemovedLine::NEGLIGIBLE_QUANTITY` (un test les compare).
     */
    public const float NEGLIGIBLE_QUANTITY = 0.005;

    /**
     * Ce qui reste d'une ligne après un retrait, en dessous de quoi c'est de la poussière d'arithmétique flottante (une recette retirée d'une liste
     * laissait « 3,4e-13 crème fraîche »). Bien plus petit que `NEGLIGIBLE_QUANTITY` : aucune quantité d'achat réelle (4 g en kilos) n'est jamais là.
     */
    public const float FLOAT_DUST = 1e-9;

    /** Repas planifiés dans une même liste : une liste en a quelques-uns, jamais cinq cents. */
    public const int MAX_MEALS = 500;

    /** Listes qu'un propriétaire garde au plus (hors listes terminées, supprimées à la création de la suivante) : vingt ans d'une liste par semaine. */
    public const int MAX_PER_OWNER = 1000;

    /**
     * @param Tab<Meal> $meals
     * @param Tab<ShoppingListIngredient> $ingredients
     * @param Tab<Row> $rows
     * @param array<string, PartShare> $partShares le registre des parties de la liste (ADR 0038 décision 6), par slug de partie
     */
    private function __construct(
        private string            $id,
        private string            $ownerId,
        private DateTimeImmutable $createdAt,
        private Status            $status,
        private ?string           $name,
        private Tab               $meals,
        private Tab               $ingredients,
        private Tab               $rows,
        private int               $version = 1,
        private ?DateTimeImmutable $sentAt = null,
        private ?string           $listTypeId = null,
        private array             $partShares = [],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Version de la liste (ETag, ADR 0016) : augmente à chaque enregistrement de la liste ou de ses repas, ingrédients et
     * quantités ; cocher une ligne n'y touche pas.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Refuse la modification si le client a lu une autre version que celle d'aujourd'hui (`If-Match`). Sans version
     * attendue, la modification passe : le contrôle est facultatif.
     *
     * @throws ShoppingListException
     */
    public function assertVersion(?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $this->version) {
            throw ShoppingListException::outdated($this->id);
        }
    }

    /**
     * Identifiant du propriétaire de la liste (ADR 0013 règle 8 : un agrégat référence un utilisateur par son identifiant).
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Tab<Meal>
     */
    public function getMeals(): Tab
    {
        return $this->meals;
    }

    /**
     * @return Tab<ShoppingListIngredient>
     */
    public function getIngredients(): Tab
    {
        return $this->ingredients;
    }

    public function getRows(): Tab
    {
        return $this->rows->usort(fn(Row $a, Row $b) => $a->getIngredientSlug() <=> $b->getIngredientSlug());
    }

    public static function create(
        string            $id,
        string            $ownerId,
        DateTimeImmutable $createdAt,
        ?string           $listTypeId = null,
        ?string           $name = null,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            ownerId: $ownerId,
            createdAt: $createdAt,
            status: Status::ACTIVE,
            name: $name,
            meals: Tab::newEmptyTyped(Meal::class),
            ingredients: Tab::newEmptyTyped(ShoppingListIngredient::class),
            rows: Tab::newEmptyTyped(Row::class),
            listTypeId: $listTypeId,
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            ownerId: $datas['owner_id'],
            createdAt: $datas['created_at'],
            status: $datas['status'],
            name: $datas['name'],
            meals: $datas['meals'],
            ingredients: $datas['ingredients'],
            rows: $datas['rows'],
            version: $datas['version'] ?? 1,
            sentAt: $datas['sent_at'] ?? null,
            listTypeId: $datas['list_type_id'] ?? null,
            // Le registre est du JSON gardé en base, lu comme une entrée non fiable (ADR 0038 décision 6) : jamais une exception
            partShares: PartShare::registryFrom($datas['part_shares'] ?? null),
        );
    }

    /**
     * Le type de la liste (ADR 0036) : l'identifiant d'un type de liste de son propriétaire, `null` pour la liste principale (celle d'avant les
     * types). Une seule liste en cours par type, et une pour la liste principale (ADR 0036 décision 6).
     */
    public function getListTypeId(): ?string
    {
        return $this->listTypeId;
    }

    /**
     * La liste perd son type quand on le supprime (ADR 0036 décision 15) : elle devient une liste sans type, rien n'est déplacé ni fusionné, et
     * si elle était en cours elle passe en pause (la liste principale en cours n'est pas touchée). Une liste envoyée au drive ou terminée garde
     * son statut. C'est la règle que `ShoppingListRepository::detachFromListType` applique en masse en base.
     */
    public function detachFromListType(): void
    {
        $this->listTypeId = null;
        if ($this->isActive()) {
            $this->status = Status::PAUSED;
        }
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->mealOf($recipe) !== null;
    }

    /**
     * Le repas de cette recette dans la liste, `null` si elle n'y est pas planifiée.
     */
    public function mealOf(Recipe $recipe): ?Meal
    {
        return $this->meals->find(fn(Meal $meal) => $meal->hasRecipe($recipe));
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === Status::PAUSED;
    }

    public function isCompleted(): bool
    {
        return $this->status === Status::COMPLETED;
    }

    public function isSent(): bool
    {
        return $this->status === Status::SENT;
    }

    /**
     * Date d'envoi au drive ; null tant que la liste n'est pas `SENT`.
     */
    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    /**
     * Une liste envoyée au drive n'est plus modifiable (ADR 0023) : seuls la lecture, `completed()`, `reopen()` et la
     * suppression restent permis. Appelée par chaque mutateur de l'agrégat et, pour ce qui ne passe pas par lui, par les
     * handlers (repas par identifiant).
     *
     * @throws ShoppingListException
     */
    public function assertEditable(): void
    {
        if ($this->isSent()) {
            throw ShoppingListException::sent($this->id);
        }
    }

    /**
     * Envoie la liste au drive : elle devient non modifiable. Sans effet si elle est déjà envoyée. Les lignes déjà cochées ne partent pas
     * (DRV-745) : s'il n'en reste aucune à acheter, il n'y a rien à envoyer.
     *
     * @throws ShoppingListException la liste n'est ni active ni déjà envoyée (409), ou elle n'a aucune ligne non cochée (422)
     */
    public function send(DateTimeImmutable $now): void
    {
        if ($this->isSent()) {
            return;
        }

        if ($this->status !== Status::ACTIVE) {
            throw ShoppingListException::notSendable($this->id);
        }

        if ($this->countUncheckedRows() === 0) {
            throw ShoppingListException::empty($this->id);
        }

        $this->status = Status::SENT;
        $this->sentAt = $now;
    }

    /**
     * Rouvre une liste envoyée : elle redevient active et modifiable. Le use case met en pause l'éventuelle autre liste active.
     *
     * @throws ShoppingListException la liste n'est pas envoyée
     */
    public function reopen(): void
    {
        if (!$this->isSent()) {
            throw ShoppingListException::notSent($this->id);
        }

        $this->status = Status::ACTIVE;
        $this->sentAt = null;
    }

    /**
     * La liste peut-elle recevoir un repas de cette recette ? Les refus de `addMeal`, sans rien écrire : une recette éclatée entre plusieurs listes
     * (ADR 0036 décision 9) les juge sur toutes les listes visées avant d'en modifier une seule.
     *
     * @throws RecipeException la recette n'est pas celle du propriétaire de la liste : ses ingrédients personnalisés seraient
     *                         ceux d'un autre, la recette est traitée comme inconnue (comme l'ingrédient d'un autre)
     * @throws ShoppingListException la liste est envoyée au drive, la recette est déjà dans la liste, ou la liste planifie déjà `MAX_MEALS` repas
     */
    public function assertCanAddMeal(Recipe $recipe): void
    {
        $this->assertEditable();

        // Une liste ne planifie que les recettes de son propriétaire : leurs ingrédients sont donc les siens (`Recipe::assertIngredientIsAvailable`)
        if ($recipe->getAuthorId() !== $this->ownerId) {
            throw RecipeException::notFound($recipe->getId());
        }

        // Impossible de mettre la même recette deux fois dans une liste
        if ($this->hasRecipe($recipe)) {
            throw ShoppingListException::recipeAlreadyInList($recipe->getName());
        }

        if ($this->meals->count() >= self::MAX_MEALS) {
            throw ShoppingListException::mealLimitReached();
        }
    }

    /**
     * @param int $servings nombre de personnes du repas : celui du propriétaire (`AuthenticatedUser::getDefaultServing()`),
     *                       que le use case transmet puisque la liste ne connaît pas l'utilisateur
     * @param ?string $plannedGroupId le groupe de la planification quand la recette remplit plusieurs listes (ADR 0036 décision 9)
     * @param ?list<string> $placedIngredients les produits de la recette que ce repas pose dans cette liste, `null` : toute la recette
     * @throws RecipeException la recette n'est pas celle du propriétaire de la liste : ses ingrédients personnalisés seraient
     *                         ceux d'un autre, la recette est traitée comme inconnue (comme l'ingrédient d'un autre)
     * @throws ShoppingListException la recette est déjà dans la liste, ou la liste planifie déjà `MAX_MEALS` repas
     * @throws MealException le nombre de personnes est hors de 1 à 100
     */
    public function addMeal(string $id, Recipe $recipe, int $servings, ?string $plannedGroupId = null, ?array $placedIngredients = null): void
    {
        $this->assertCanAddMeal($recipe);

        // Ajoute à la liste des recettes avec le nombre de personnes demandé
        $this->meals[] = Meal::create(
            id: $id,
            shoppingListId: $this->id,
            recipeId: $recipe->getId(),
            servings: $servings,
            plannedGroupId: $plannedGroupId,
            placedIngredients: $placedIngredients,
        );
    }

    /**
     * La ligne de cet ingrédient, `null` s'il n'est pas dans la liste (une ligne n'a qu'une unité : celle de sa création).
     */
    public function findRow(string $ingredientSlug): ?Row
    {
        return $this->rows->find(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug);
    }

    public function addRow(string $ingredientSlug, string $purchaseUnitSlug, float $quantity): void
    {
        $this->assertEditable();

        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($row = $this->rows->find(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug)) !== null) {
            $row->addQuantity($quantity);
        } else {
            //Sinon on créer la ligne
            $this->rows[] = Row::create($this->id, $ingredientSlug, $purchaseUnitSlug, $quantity);
        }
    }

    public function subtractRow(string $ingredientSlug, float $quantity): void
    {
        $this->assertEditable();

        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($rowKey = $this->rows->findKey(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug)) !== null) {
            $this->rows[$rowKey]->subtractQuantity($quantity);
            // si la quantité passe à zero ou moins, on supprime la row (au poussière flottante près : 0,3 - 0,1 - 0,2 n'est pas tout à fait 0)
            if ($this->rows[$rowKey]->getQuantity() <= self::FLOAT_DUST) {
                unset($this->rows[$rowKey]);
            }
            // Un parent qui perd sa ligne perd ses parties ; une ligne plus petite que ses parties les réduit (ADR 0038 décision 7)
            $this->reconcileParts($ingredientSlug);
        }
    }

    /**
     * Le registre des parties de la liste (ADR 0038 décision 6), par slug de partie : ce que la liste a reçu de chaque partie, dans l'unité d'achat de
     * la partie, et ce que cela vaut en unité d'achat du parent. Vide pour tout compte qui n'a jamais découpé un ingrédient.
     *
     * @return array<string, PartShare>
     */
    public function getPartShares(): array
    {
        return $this->partShares;
    }

    /**
     * Ce que la liste a gardé de cette partie, `null` si elle n'en a rien (un ingrédient ordinaire, ou une partie jamais posée dans cette liste).
     */
    public function partShareOf(string $partSlug): ?PartShare
    {
        return $this->partShares[$partSlug] ?? null;
    }

    /**
     * Les entrées du registre dont la ligne de ce parent porte le total, par slug de partie.
     *
     * @return array<string, PartShare>
     */
    public function partsOf(string $parentSlug): array
    {
        return array_filter($this->partShares, static fn(PartShare $share): bool => $share->parentSlug === $parentSlug);
    }

    /**
     * Pose `$quantity` d'une partie dans la liste (ADR 0038 décision 8). La partie n'est jamais une ligne : sa quantité et son équivalent vont au
     * registre, et la ligne du parent reçoit la DIFFÉRENCE entre l'ancien et le nouveau plus grand équivalent des parties du parent. L'invariant
     * de l'agrégat est donc « ligne du parent = quantité directe + plus grand équivalent de ses parties » : 3 œufs + 2 jaunes = 5, + 2 blancs = 5
     * encore (le blanc complète le jaune), + 1 jaune de plus = 6.
     *
     * Une partie déjà dans le registre garde SON parent d'origine, même si l'ingrédient a changé de parent depuis : le nouvel équivalent est alors
     * proportionnel à celui que la liste a déjà (jamais une entrée dont les équivalents seraient dans deux unités).
     *
     * @param float $quantity   dans l'unité d'achat de la partie
     * @param float $equivalent ce que `$quantity` vaut dans l'unité d'achat du parent (`PartEquivalence`)
     *
     * @throws ShoppingListException la liste est envoyée au drive, ou une quantité n'est pas un nombre fini strictement positif
     */
    public function addPart(string $partSlug, string $parentSlug, string $parentPurchaseUnitSlug, float $quantity, float $equivalent): void
    {
        $this->assertEditable();
        if (!is_finite($quantity) || !is_finite($equivalent) || $quantity <= 0 || $equivalent <= 0) {
            throw ShoppingListException::invalidQuantity();
        }

        $existing = $this->partShares[$partSlug] ?? null;
        if ($existing !== null && $existing->parentSlug !== $parentSlug) {
            $parentSlug = $existing->parentSlug;
            $equivalent = $quantity * $existing->equivalent / $existing->quantity;
        }

        $before = $this->largestEquivalent($parentSlug);
        $total = new PartShare($parentSlug, ($existing->quantity ?? 0.0) + $quantity, ($existing->equivalent ?? 0.0) + $equivalent);
        if (!is_finite($total->quantity) || !is_finite($total->equivalent)) {
            throw ShoppingListException::invalidQuantity();
        }
        $this->partShares[$partSlug] = $total;

        $difference = $this->largestEquivalent($parentSlug) - $before;
        if ($difference > 0) {
            $this->addRow($parentSlug, $parentPurchaseUnitSlug, $difference);
        }
    }

    /**
     * Retire `$quantity` d'une partie (ADR 0038 décision 8), l'inverse d'`addPart`, PROPORTIONNELLEMENT à ce que la liste a gardé : retirer 1 sur 4
     * jaunes retire un quart de leur équivalent, quel que soit le ratio du jour. L'entrée disparaît quand il n'en reste rien (au-dessous de
     * `FLOAT_DUST`, ou quand on retire autant ou plus qu'elle n'a : jamais de quantité nulle ou négative dans le registre) ; la ligne du parent perd
     * la différence entre l'ancien et le nouveau plus grand équivalent, et disparaît quand il ne reste rien.
     *
     * @return ?PartShare ce que ce retrait a ôté du registre (le parent, la quantité et l'équivalent retirés), `null` si la liste n'a rien gardé de cette
     *                    partie (rien n'est modifié : l'appelant retire alors une ligne ordinaire)
     *
     * @throws ShoppingListException la liste est envoyée au drive, ou la quantité n'est pas un nombre fini strictement positif
     */
    public function subtractPart(string $partSlug, float $quantity): ?PartShare
    {
        $this->assertEditable();

        $share = $this->partShares[$partSlug] ?? null;
        if ($share === null) {
            return null;
        }
        if (!is_finite($quantity) || $quantity <= 0) {
            throw ShoppingListException::invalidQuantity();
        }

        $before = $this->largestEquivalent($share->parentSlug);
        $remaining = $share->quantity - $quantity;
        $left = $remaining <= self::FLOAT_DUST ? null : new PartShare($share->parentSlug, $remaining, $share->equivalent * $remaining / $share->quantity);
        if ($left === null || $left->equivalent <= self::FLOAT_DUST) {
            unset($this->partShares[$partSlug]);
            $removed = $share;
        } else {
            $this->partShares[$partSlug] = $left;
            $removed = new PartShare($share->parentSlug, $quantity, $share->equivalent - $left->equivalent);
        }

        $difference = $before - $this->largestEquivalent($share->parentSlug);
        if ($difference > 0) {
            $this->subtractRow($share->parentSlug, $difference);
        }

        return $removed;
    }

    /**
     * Retire la part d'une LIGNE DE RECETTE (le retrait d'un repas, moins de personnes) : sur le registre quand la liste y a gardé cette partie
     * (ADR 0038 décision 9 : un retrait se décide sur ce que la liste contient, jamais sur ce que l'ingrédient est aujourd'hui), sinon sur la
     * ligne ordinaire. Un compte sans partie ne passe jamais par le registre.
     */
    public function subtractLine(string $ingredientSlug, float $quantity): void
    {
        if ($this->subtractPart($ingredientSlug, $quantity) === null) {
            $this->subtractRow($ingredientSlug, $quantity);
        }
    }

    /**
     * Le plus grand équivalent, dans l'unité d'achat du parent, des parties que la liste a gardées de ce parent (0 sans partie).
     */
    private function largestEquivalent(string $parentSlug): float
    {
        $largest = 0.0;
        foreach ($this->partShares as $share) {
            if ($share->parentSlug === $parentSlug && $share->equivalent > $largest) {
                $largest = $share->equivalent;
            }
        }

        return $largest;
    }

    /**
     * Tient l'invariant après une baisse de la ligne du parent (la ligne a perdu de la quantité à la main, à la voix ou en retirant un repas) : plus
     * de ligne, plus de partie ; une ligne devenue plus petite que le plus grand équivalent de ses parties les réduit toutes dans la même
     * proportion (le total qui reste est la vérité, jamais une quantité directe négative). Sans partie, rien ne se passe.
     */
    private function reconcileParts(string $parentSlug): void
    {
        if ($this->partShares === []) {
            return;
        }
        $shares = $this->partsOf($parentSlug);
        if ($shares === []) {
            return;
        }

        $row = $this->findRow($parentSlug);
        if ($row === null) {
            foreach (array_keys($shares) as $partSlug) {
                unset($this->partShares[$partSlug]);
            }

            return;
        }

        $largest = $this->largestEquivalent($parentSlug);
        if ($row->getQuantity() >= $largest - self::FLOAT_DUST) {
            return;
        }
        $factor = $row->getQuantity() / $largest;
        foreach ($shares as $partSlug => $share) {
            $this->partShares[$partSlug] = new PartShare($share->parentSlug, $share->quantity * $factor, $share->equivalent * $factor);
        }
    }

    /**
     * Retire `$quantity` de la ligne de cet ingrédient (le retrait dit à voix haute, ADR 0029 décision 12), symétrique de `addIngredient` +
     * `addRow` : la ligne perd la quantité, et la part ajoutée à la main de la même unité aussi (jamais plus qu'elle n'en a). Si la quantité retirée
     * est au moins celle de la ligne, la ligne disparaît avec tout ce qui a été ajouté à la main pour cet ingrédient : jamais de quantité nulle
     * ou négative, jamais une part orpheline.
     *
     * @return ?float la quantité qui reste dans la ligne, 0.0 si elle a disparu, `null` si l'ingrédient n'a pas de ligne (rien n'est modifié)
     *
     * @throws ShoppingListException la liste est envoyée au drive
     */
    public function removeQuantity(string $ingredientSlug, float $quantity): ?float
    {
        $this->assertEditable();

        $rowKey = $this->rows->findKey(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug);
        if ($rowKey === null) {
            return null;
        }
        $row = $this->rows[$rowKey];

        // Un reste inférieur à `NEGLIGIBLE_QUANTITY` est épuisé : ni poussière de virgule flottante, ni « il t'en reste 0 » dit par Alexa
        if ($row->getQuantity() - $quantity < self::NEGLIGIBLE_QUANTITY) {
            unset($this->rows[$rowKey]);
            foreach ($this->ingredients->keys() as $key) {
                if ($this->ingredients[$key]->hasIngredient($ingredientSlug)) {
                    unset($this->ingredients[$key]);
                }
            }
            // La ligne disparaît avec tout ce qu'elle contenait, parties comprises (ADR 0038 décision 11)
            $this->reconcileParts($ingredientSlug);

            return 0.0;
        }

        $row->subtractQuantity($quantity);
        $this->reconcileParts($ingredientSlug);

        $manualKey = $this->ingredients->findKey(fn(ShoppingListIngredient $manual) => $manual->hasIngredient($ingredientSlug) && $manual->getUnitSlug() === $row->getUnitSlug());
        if ($manualKey !== null) {
            $manual = $this->ingredients[$manualKey];
            $manual->subtractQuantity(min($quantity, $manual->getQuantity()));
            if ($manual->getQuantity() <= 0) {
                unset($this->ingredients[$manualKey]);
            }
        }

        return $row->getQuantity();
    }

    public function setStatus(?Status $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): ShoppingList
    {
        $this->assertEditable();
        $this->name = $name;
        return $this;
    }

    public function addIngredient(string $ingredientSlug, string $purchaseUnitSlug, float $quantity = 1): void
    {
        $this->assertEditable();

        // Recherche de l'ingrédient dans l'unité d'achat dans la liste
        if (($shopListIngredient = $this->ingredients->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($ingredientSlug) && $sli->getUnitSlug() === $purchaseUnitSlug)) !== null) {
            // Si on le trouve on incrémente la quantité (de 1 par défaut)
            $shopListIngredient->addQuantity($quantity);
        } else {
            // Sinon on le créer avec cette quantité (1 par défaut)
            $this->ingredients[] = ShoppingListIngredient::create(
                shoppingListId: $this->id,
                ingredientSlug: $ingredientSlug,
                unitSlug: $purchaseUnitSlug,
                quantity: $quantity
            );
        }
    }

    public function removeIngredient(string $ingredientSlug, string $purchaseUnitSlug): void
    {
        $this->assertEditable();

        $ingredientKey = $this->ingredients->findKey(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($ingredientSlug) && $sli->getUnitSlug() === $purchaseUnitSlug);

        // Si on ne retrouve pas l'ingrédient, il n'est deja plus present dans la liste
        if ($ingredientKey === null) {
            return;
        }

        // On soustrait 1 a la quantité
        $this->ingredients[$ingredientKey]->subtractQuantity(1);

        // Si la quantité est inférieur ou égal à zéro, alors on supprime l'ingrédient
        if ($this->ingredients[$ingredientKey]->getQuantity() <= 0) {
            unset($this->ingredients[$ingredientKey]);
        }
    }

    public function completed(): void
    {
        $this->status = Status::COMPLETED;
    }

    public function activated(): void
    {
        $this->assertEditable();
        $this->status = Status::ACTIVE;
        // Une liste envoyée puis terminée garde sa date d'envoi (relevé) ; redevenue active, elle n'est plus envoyée
        $this->sentAt = null;
    }

    public function removeMeal(Recipe $recipe): void
    {
        $this->assertEditable();

        $mealKey = $this->meals->findKey(fn(Meal $meal) => $meal->hasRecipe($recipe));

        if ($mealKey === null) {
            // Le repas n'est déjà pas/plus présent dans la liste
            return ;
        }

        // On trouve le coefficient grace au serving
        unset($this->meals[$mealKey]);
    }

    public function countRows(): int
    {
        return $this->rows->count();
    }

    /**
     * Les lignes qu'il reste à acheter : celles que personne n'a cochées.
     */
    public function countUncheckedRows(): int
    {
        return $this->rows->filter(fn(Row $row): bool => !$row->isChecked())->count();
    }

    public function countRecipes(): int
    {
        return $this->meals->count();
    }

    public function getIngredientQuantity(Ingredient $ingredient): ?float
    {
        return $this->rows->find(fn(Row $row) => $row->getIngredientSlug() === $ingredient->getSlug())?->getQuantity();
    }

    public function isActivate(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    public function pause(): void
    {
        $this->assertEditable();
        $this->status = Status::PAUSED;
    }
}
