<?php

namespace Repas\Repas\Application\Query\GetSeedingReport;

/**
 * Le rapport de l'amorçage des comptes (ING-008). Les comptes y sont numérotés et désignés par une référence courte (`reference`), jamais par
 * leur adresse ni leur identifiant en clair : la sortie de la commande est conservée dans des journaux.
 */
final readonly class SeedingReportDTO
{
    /**
     * @param bool $catalogOwnerExists le propriétaire du catalogue existe (sinon rien ne peut être copié)
     * @param int $catalogIngredients ingrédients du catalogue (créateur propriétaire ou vide), jamais modifiés par l'amorçage
     * @param int $baseIngredients dont « de base » : ce que reçoit tout nouveau compte
     * @param int $baseOwnConversions leurs conversions propres (celles qu'on copie ; les conversions communes à tous ne se copient pas)
     * @param int $longestBaseSlug la longueur du plus long slug d'un ingrédient « de base »
     * @param list<string> $catalogAnomalies anomalies qui ne dépendent d'aucun compte
     * @param list<string> $unknownSelectors ce qui a été demandé par `--user` ou `--except` et ne correspond à aucun compte
     * @param list<SeedingUserReportDTO> $users tous les comptes, dans un ordre stable
     */
    public function __construct(
        public bool $catalogOwnerExists,
        public int $catalogIngredients,
        public int $baseIngredients,
        public int $baseOwnConversions,
        public int $longestBaseSlug,
        public array $catalogAnomalies,
        public array $unknownSelectors,
        public array $users,
        public SeedingHealthDTO $health,
    ) {
    }

    /**
     * Les comptes qui seraient traités.
     *
     * @return list<SeedingUserReportDTO>
     */
    public function selected(): array
    {
        return array_values(array_filter($this->users, static fn(SeedingUserReportDTO $user): bool => $user->selected));
    }

    /**
     * Anomalies qui interdisent d'écrire (référence orpheline ou croisée d'un compte retenu, slug trop long, classement absent), ou un
     * catalogue sans propriétaire quand il y a des comptes à servir.
     */
    public function hasBlockingAnomalies(): bool
    {
        foreach ($this->selected() as $user) {
            if ($user->blockingAnomalies !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les comptes retenus qui ont des listes déjà envoyées au drive que le repointage modifierait.
     */
    public function sentShoppingListsToRepoint(): int
    {
        return array_sum(array_map(static fn(SeedingUserReportDTO $user): int => $user->sentShoppingListsToRepoint, $this->selected()));
    }

    /**
     * Rien à copier ni à repointer pour aucun compte retenu.
     */
    public function isNothingToDo(): bool
    {
        foreach ($this->selected() as $user) {
            if (!$user->isNothingToDo()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le verdict de `--check` : plus rien à faire, et la requête de santé est verte.
     */
    public function isConform(): bool
    {
        return $this->isNothingToDo() && !$this->hasBlockingAnomalies() && $this->health->isHealthy();
    }
}
