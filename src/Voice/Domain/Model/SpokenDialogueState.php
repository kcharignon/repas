<?php

namespace Repas\Voice\Domain\Model;

/**
 * Où en est le dialogue d'un produit inconnu (ADR 0029 décision 11, VOX-303) ou d'un repas (décision 13, VOX-305) : ce que la réponse précédente a
 * mis dans `sessionAttributes` et qu'Alexa nous renvoie à la requête suivante. Immuable : chaque étape produit un nouvel état.
 *
 * Étapes (`DialogueStep`) : `choose` (les propositions, « Je le crée ? » sans proposition, ou — ingrédients à départager, `isTieBreak()` — deux ou trois ingrédients avec leur rayon), `department`, `unit`, `confirm`,
 * et `servings` (un repas déjà planifié : combien de personnes ?). L'état garde
 * la phrase entendue, les propositions, le nom du produit à créer, le rayon et l'unité déjà choisis, et `misses` : le nombre de fois de suite
 * où la réponse n'a pas été comprise DANS CETTE ÉTAPE (il repart à zéro quand l'étape change ; à `MAX_MISSES` le dialogue est abandonné). Il garde
 * aussi la quantité et l'unité DITES au départ (« trois paquets de croquettes ») pour qu'elles s'appliquent à l'article quand le dialogue
 * aboutit ; sans rien dit, une unité d'achat.
 *
 * `action` (ADR 0029 décision 12) : `add` par défaut ; `remove` quand le dialogue départage des lignes de la liste à RETIRER (« enlève des
 * tomates » alors que la liste porte des tomates en fruit et légume ET en surgelé) : seule l'étape `choose` d'un départage existe alors, et la
 * réponse retire l'article choisi, sans rien retenir.
 *
 * `target` (décision 13) : `item` par défaut (le dialogue d'un produit) ; `meal` quand le dialogue cherche une RECETTE de l'utilisateur. Les
 * propositions de l'étape `choose` sont alors des recettes (le `slug` d'une `DialogueOption` porte l'identifiant de la recette), `servings` le
 * nombre de personnes dit au départ (`null` : celui du compte). Seules les étapes `choose` et `servings` existent pour un repas, et seulement pour
 * AJOUTER (retirer un repas : VOX-306). L'étape `servings` porte la recette déjà planifiée (l'unique proposition) et `plannedServings`, le nombre de
 * personnes pour lequel elle l'est. `otherSideTried` : l'autre recherche (produit ou recette) a déjà été essayée pour cette phrase, donc aucune issue
 * d'aiguillage n'est proposée (pas de ping-pong).
 *
 * L'état revient de l'extérieur : il est lu comme une ENTRÉE NON FIABLE (`fromArray`), tolérant (une forme inattendue donne « pas d'état »,
 * jamais une exception), borné (chaînes, trois propositions au plus, étape connue, champs requis par l'étape). Les slugs et identifiants qu'il porte ne
 * sont jamais crus : l'ajout, la planification, la mémoire de phrase et la création les vérifient auprès de `Repas`. Il ne contient que ce qu'Alexa vient
 * d'entendre et des noms du catalogue : jamais journalisé, jamais écrit en base.
 */
final readonly class SpokenDialogueState
{
    /** Le nombre de propositions faites à l'utilisateur (trois se disent, quatre se retiennent mal). */
    public const int MAX_OPTIONS = 3;

    /** Deux « je n'ai pas compris » de suite dans la même étape : on abandonne poliment. */
    public const int MAX_MISSES = 2;

    /** Le nom d'un produit à créer et celui d'un rayon ou d'une unité tiennent en quelques mots. */
    public const int MAX_NAME_LENGTH = 100;

    /** Un slug (`ingredient.slug` : 255) et le nom d'une proposition (`ingredient.name` : 255). */
    public const int MAX_SLUG_LENGTH = 255;

    /** Un identifiant d'unité du catalogue (slug) tient en peu de caractères. */
    public const int MAX_UNIT_SLUG_LENGTH = 100;

    /** Un nombre de personnes dit : de 1 à 99, comme une quantité. */
    public const int MIN_SERVINGS = 1;
    public const int MAX_SERVINGS = 99;

    /** La clé des attributs de séance sous laquelle l'état est écrit. */
    public const string ATTRIBUTE = 'dialogue';

    /**
     * @param list<DialogueOption> $options les propositions (étape `choose` seulement, trois au plus ; étape `servings` : la recette déjà planifiée)
     */
    private function __construct(
        public DialogueStep $step,
        public string $phrase,
        public array $options,
        public string $name,
        public ?DialogueOption $department,
        public ?DialogueOption $unit,
        public int $misses,
        /** La quantité dite au départ (1 à 99), 1 si rien n'a été dit. */
        public int $quantity = 1,
        /** L'unité dite au départ (slug), `null` si rien n'a été dit. Distincte de `unit` : celle du produit à créer. */
        public ?string $spokenUnitSlug = null,
        /** Ce que la réponse déclenche : ajouter l'article choisi (par défaut) ou le retirer (départager deux lignes de la liste). */
        public SpokenAction $action = SpokenAction::ADD,
        /** Ce que le dialogue cherche : un produit (par défaut) ou une recette. */
        public SpokenTarget $target = SpokenTarget::ITEM,
        /** L'autre recherche (produit ou recette) a déjà été essayée pour cette phrase : plus d'issue d'aiguillage. */
        public bool $otherSideTried = false,
        /** Repas : le nombre de personnes dit au départ (1 à 99), `null` si rien n'a été dit (celui du compte). */
        public ?int $servings = null,
        /** Étape `servings` : le nombre de personnes pour lequel la recette est déjà planifiée (1 à 99). */
        public ?int $plannedServings = null,
    ) {
    }

    /**
     * @param array<string, mixed> $changes les propriétés à remplacer
     */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * Le produit n'est pas reconnu : on propose `$options` (aucune : « Je le crée ? ») ou de le créer sous `$name`.
     *
     * @param list<DialogueOption> $options
     * @param bool $otherSideTried les recettes ont déjà été cherchées pour cette phrase : « ou une recette ? » n'est plus proposé
     */
    public static function choose(string $phrase, array $options, string $name, int $quantity = 1, ?string $spokenUnitSlug = null, SpokenAction $action = SpokenAction::ADD, bool $otherSideTried = false): self
    {
        return new self(DialogueStep::CHOOSE, $phrase, array_slice(array_values($options), 0, self::MAX_OPTIONS), $name, null, null, 0, $quantity, $spokenUnitSlug, $action, SpokenTarget::ITEM, $otherSideTried);
    }

    /**
     * La recette n'est pas reconnue (ADR 0029 décision 13) : on propose `$options` (les recettes de l'utilisateur les plus proches, aucune : « C'est un
     * produit ? »). Jamais de création de recette à la voix.
     *
     * @param list<DialogueOption> $options des recettes : le `slug` est l'identifiant de la recette
     * @param ?int $servings le nombre de personnes dit au départ
     * @param bool $otherSideTried les produits ont déjà été cherchés pour cette phrase : « ou un produit ? » n'est plus proposé
     */
    public static function chooseRecipe(string $phrase, array $options, string $name, ?int $servings = null, bool $otherSideTried = false): self
    {
        return new self(DialogueStep::CHOOSE, $phrase, array_slice(array_values($options), 0, self::MAX_OPTIONS), $name, null, null, 0, 1, null, SpokenAction::ADD, SpokenTarget::MEAL, $otherSideTried, $servings);
    }

    /**
     * La recette est déjà dans la liste (ADR 0029 décision 13) : on demande le nouveau total de personnes, ou de ne rien changer.
     */
    public static function askingServings(DialogueOption $recipe, int $plannedServings): self
    {
        // Le nom d'une recette peut dépasser ce que `fromArray` accepte pour une phrase : la phrase et le nom de l'état sont coupés
        $name = mb_substr($recipe->name, 0, self::MAX_NAME_LENGTH);

        return new self(DialogueStep::SERVINGS, $name, [$recipe], $name, null, null, 0, 1, null, SpokenAction::ADD, SpokenTarget::MEAL, true, null, $plannedServings);
    }

    /**
     * Deux lignes de la liste se valent (« tomate » en fruit et légume et en surgelé) : on demande le rayon avant de RETIRER l'une d'elles. Sans
     * « un nouveau produit » (le produit existe), comme tout départage (`isTieBreak()`).
     *
     * @param list<DialogueOption> $options au moins deux, chacune avec son rayon
     */
    public static function chooseToRemove(string $phrase, array $options, string $name, int $quantity = 1, ?string $spokenUnitSlug = null): self
    {
        return self::choose($phrase, $options, $name, $quantity, $spokenUnitSlug, SpokenAction::REMOVE);
    }

    /** L'utilisateur veut créer le produit : première des deux questions. */
    public function askingDepartment(): self
    {
        return $this->with(['step' => DialogueStep::DEPARTMENT, 'options' => [], 'department' => null, 'unit' => null, 'misses' => 0]);
    }

    public function withDepartment(DialogueOption $department): self
    {
        return $this->with(['step' => DialogueStep::UNIT, 'options' => [], 'department' => $department, 'unit' => null, 'misses' => 0]);
    }

    public function withUnit(DialogueOption $unit): self
    {
        return $this->with(['step' => DialogueStep::CONFIRM, 'options' => [], 'unit' => $unit, 'misses' => 0]);
    }

    /** Le dialogue départage des lignes à RETIRER : l'article choisi sera retiré, jamais ajouté, ni retenu pour la phrase. */
    public function isRemoval(): bool
    {
        return $this->action === SpokenAction::REMOVE;
    }

    /** Le dialogue cherche une recette (ADR 0029 décision 13). */
    public function isMeal(): bool
    {
        return $this->target === SpokenTarget::MEAL;
    }

    /**
     * La phrase d'origine qu'un autre article (ou une autre recette), dit à la place des propositions, peut faire retenir pour lui (« patates » puis
     * « pomme de terre »). Seulement quand des propositions ont été FAITES : à « Je le crée ? » (aucune proposition) l'utilisateur ne corrige rien,
     * il change d'avis, et ce qu'il dit alors est un ajout ordinaire. `null` aux autres étapes (rayon, unité, confirmation, nombre de personnes).
     */
    public function rememberablePhrase(): ?string
    {
        return $this->step === DialogueStep::CHOOSE && $this->options !== [] && !$this->isTieBreak() ? $this->phrase : null;
    }

    /**
     * Plusieurs ingrédients de l'utilisateur se valent pour la phrase (« tomate » en fruit et légume et en surgelé) : la question est
     * « lequel ? » par le rayon, SANS l'issue « un nouveau produit » (le produit existe), et un autre article dit à la place n'est pas une
     * correction. Chaque proposition porte alors son rayon. Un état forgé qui prétend l'être n'y gagne rien : les slugs sont vérifiés.
     */
    public function isTieBreak(): bool
    {
        if ($this->step !== DialogueStep::CHOOSE || $this->target !== SpokenTarget::ITEM || count($this->options) < 2) {
            return false;
        }
        foreach ($this->options as $option) {
            if (!$option->hasDepartment()) {
                return false;
            }
        }

        return true;
    }

    /** Une réponse de plus qui n'a pas été comprise dans la même étape. */
    public function missed(): self
    {
        return $this->with(['misses' => $this->misses + 1]);
    }

    /** Assez de réponses incomprises de suite : on arrête de demander. */
    public function hasMissedTooMuch(): bool
    {
        return $this->misses >= self::MAX_MISSES;
    }

    /**
     * @return array<string, mixed> la forme écrite dans `sessionAttributes.dialogue`
     */
    public function toArray(): array
    {
        $data = [
            'step' => $this->step->value,
            'phrase' => $this->phrase,
            'options' => array_map(static fn(DialogueOption $option): array => $option->toArray(), $this->options),
            'name' => $this->name,
            'misses' => $this->misses,
        ];
        // Seulement quand quelque chose a été dit : l'état d'une demande ordinaire ne change pas (la séance a une taille limite)
        if ($this->quantity !== 1) {
            $data['quantity'] = $this->quantity;
        }
        if ($this->spokenUnitSlug !== null) {
            $data['spokenUnit'] = $this->spokenUnitSlug;
        }
        // Seulement pour un retrait : l'état d'un ajout garde sa forme
        if ($this->action !== SpokenAction::ADD) {
            $data['action'] = $this->action->value;
        }
        // Seulement pour un repas, et seulement ce qui a été dit ou décidé : l'état d'un produit garde sa forme
        if ($this->target !== SpokenTarget::ITEM) {
            $data['target'] = $this->target->value;
        }
        if ($this->otherSideTried) {
            $data['otherSideTried'] = true;
        }
        if ($this->servings !== null) {
            $data['servings'] = $this->servings;
        }
        if ($this->plannedServings !== null) {
            $data['plannedServings'] = $this->plannedServings;
        }
        if ($this->department !== null) {
            $data['departmentSlug'] = $this->department->slug;
            $data['departmentName'] = $this->department->name;
        }
        if ($this->unit !== null) {
            $data['unitSlug'] = $this->unit->slug;
            $data['unitName'] = $this->unit->name;
        }

        return $data;
    }

    /**
     * L'état lu dans les attributs de séance de la requête, ou `null` : forme inattendue, étape inconnue, chaîne trop longue ou avec des
     * caractères de contrôle, plus de trois propositions, champ que l'étape exige absent. Jamais d'exception.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $step = is_string($data['step'] ?? null) ? DialogueStep::tryFrom($data['step']) : null;
        $phrase = self::text($data['phrase'] ?? null, AlexaRequest::MAX_PHRASE_LENGTH);
        $name = self::text($data['name'] ?? null, self::MAX_NAME_LENGTH);
        $options = self::options($data['options'] ?? []);
        if ($step === null || $phrase === null || $name === null || $options === null) {
            return null;
        }

        $department = self::option($data['departmentSlug'] ?? null, $data['departmentName'] ?? null, self::MAX_NAME_LENGTH);
        $unit = self::option($data['unitSlug'] ?? null, $data['unitName'] ?? null, self::MAX_NAME_LENGTH);
        $misses = is_int($data['misses'] ?? null) ? min(max($data['misses'], 0), self::MAX_MISSES) : 0;
        // Une quantité hors de 1 à 99 ou une unité qui n'est pas un slug n'est pas crue : une unité d'achat, rien de dit
        $quantity = is_int($data['quantity'] ?? null) && $data['quantity'] >= AlexaRequest::MIN_QUANTITY && $data['quantity'] <= AlexaRequest::MAX_QUANTITY ? $data['quantity'] : 1;
        $spokenUnit = $data['spokenUnit'] ?? null;
        $spokenUnit = is_string($spokenUnit) && mb_strlen($spokenUnit) <= self::MAX_UNIT_SLUG_LENGTH && preg_match('/^[a-z0-9-]+$/', $spokenUnit) === 1 ? $spokenUnit : null;
        // Une cible inconnue n'est pas crue : un produit, comme avant ; un nombre de personnes hors de 1 à 99 non plus
        $target = ($data['target'] ?? null) === SpokenTarget::MEAL->value ? SpokenTarget::MEAL : SpokenTarget::ITEM;
        $otherSideTried = ($data['otherSideTried'] ?? null) === true;
        $servings = self::servings($data['servings'] ?? null);
        $plannedServings = self::servings($data['plannedServings'] ?? null);

        // Ce que chaque étape suppose déjà connu : le rayon avant l'unité, les deux avant la confirmation ; pour un repas, seulement le choix d'une
        // recette ou le nombre de personnes d'une recette déjà planifiée (aucun rayon, aucune unité, aucune création)
        $consistent = match ($step) {
            DialogueStep::CHOOSE => true,
            DialogueStep::DEPARTMENT => $options === [] && $target === SpokenTarget::ITEM,
            DialogueStep::UNIT => $options === [] && $department !== null && $target === SpokenTarget::ITEM,
            DialogueStep::CONFIRM => $options === [] && $department !== null && $unit !== null && $target === SpokenTarget::ITEM,
            DialogueStep::SERVINGS => $target === SpokenTarget::MEAL && count($options) === 1 && $plannedServings !== null,
        };
        if (!$consistent) {
            return null;
        }

        // Une action inconnue n'est pas crue : un ajout, comme avant
        $action = ($data['action'] ?? null) === SpokenAction::REMOVE->value ? SpokenAction::REMOVE : SpokenAction::ADD;

        $state = new self(
            $step,
            $phrase,
            $options,
            $name,
            $step === DialogueStep::DEPARTMENT || $step === DialogueStep::CHOOSE || $step === DialogueStep::SERVINGS ? null : $department,
            $step === DialogueStep::CONFIRM ? $unit : null,
            $misses,
            $quantity,
            $spokenUnit,
            $action,
            $target,
            $otherSideTried,
            $target === SpokenTarget::MEAL ? $servings : null,
            $step === DialogueStep::SERVINGS ? $plannedServings : null,
        );

        // Un retrait ne départage que des lignes qui se valent : un état qui prétend retirer autre chose (un repas, par exemple, VOX-306) n'est pas cru
        return $action === SpokenAction::REMOVE && !$state->isTieBreak() ? null : $state;
    }

    private static function servings(mixed $value): ?int
    {
        return is_int($value) && $value >= self::MIN_SERVINGS && $value <= self::MAX_SERVINGS ? $value : null;
    }

    /**
     * @return ?list<DialogueOption> `null` si la forme est inattendue ou s'il y a plus de `MAX_OPTIONS` propositions
     */
    private static function options(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_OPTIONS) {
            return null;
        }

        $options = [];
        foreach ($raw as $item) {
            $option = is_array($item) ? self::option($item['slug'] ?? null, $item['name'] ?? null, self::MAX_SLUG_LENGTH) : null;
            if ($option === null) {
                return null;
            }
            // Le rayon de la proposition est facultatif ; la paire doit être valide en entier, sinon la proposition n'en a pas
            $departmentSlug = self::text($item['departmentSlug'] ?? null, self::MAX_SLUG_LENGTH);
            $departmentName = self::text($item['departmentName'] ?? null, self::MAX_NAME_LENGTH);
            $options[] = $departmentSlug !== null && $departmentName !== null
                ? new DialogueOption($option->slug, $option->name, $departmentSlug, $departmentName)
                : $option;
        }

        return $options;
    }

    private static function option(mixed $slug, mixed $name, int $maxNameLength): ?DialogueOption
    {
        $slug = self::text($slug, self::MAX_SLUG_LENGTH);
        $name = self::text($name, $maxNameLength);

        return $slug === null || $name === null ? null : new DialogueOption($slug, $name);
    }

    /** Une chaîne non vide, bornée, sans caractère de contrôle. */
    private static function text(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || $value === '' || mb_strlen($value) > $maxLength || preg_match('/\p{C}/u', $value) !== 0) {
            return null;
        }

        return $value;
    }
}
