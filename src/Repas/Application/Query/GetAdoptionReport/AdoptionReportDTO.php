<?php

namespace Repas\Repas\Application\Query\GetAdoptionReport;

/**
 * Le rapport de la migration d'adoption des communs (ING-003). Les comptes y sont numérotés et désignés par une référence courte
 * (`reference`), jamais par leur adresse ni leur identifiant en clair : la sortie de la commande est conservée dans des journaux.
 */
final readonly class AdoptionReportDTO
{
    /**
     * @param int $commons nombre de communs du catalogue (jamais modifiés par la migration)
     * @param int $commonOwnConversions leurs conversions propres (celles qu'on copie ; les conversions communes à tous ne se copient pas)
     * @param int $longestCommonSlug la longueur du plus long slug de commun
     * @param list<string> $catalogAnomalies anomalies de données qui ne dépendent d'aucun compte (conversions d'un ingrédient inexistant)
     * @param list<string> $unknownSelectors ce qui a été demandé par `--user` ou `--except` et ne correspond à aucun compte
     * @param list<AdoptionUserReportDTO> $users tous les comptes, dans un ordre stable
     */
    public function __construct(
        public int $commons,
        public int $commonOwnConversions,
        public int $longestCommonSlug,
        public array $catalogAnomalies,
        public array $unknownSelectors,
        public array $users,
    ) {
    }

    /**
     * Les comptes qui seraient traités : concernés et retenus par `--user` / `--except`.
     *
     * @return list<AdoptionUserReportDTO>
     */
    public function selected(): array
    {
        return array_values(array_filter($this->users, static fn(AdoptionUserReportDTO $user): bool => $user->selected));
    }

    /**
     * Anomalies qui interdisent d'écrire (référence orpheline ou croisée d'un compte retenu, slug trop long).
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
        return array_sum(array_map(static fn(AdoptionUserReportDTO $user): int => $user->sentShoppingListsToRepoint, $this->selected()));
    }

    /**
     * Rien à faire : aucun compte retenu n'a de commun à adopter, de ligne ou de choix à repointer, ni d'alias invalide.
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
}
