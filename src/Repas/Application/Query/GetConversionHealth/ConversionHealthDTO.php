<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

/**
 * Le rapport de santé des conversions : des nombres, des paires d'unités et des références de comptes. Aucune adresse e-mail, aucun identifiant, aucun nom
 * d'ingrédient ou de recette.
 */
final readonly class ConversionHealthDTO
{
    /**
     * @param list<ReferenceConversionDTO> $reference les conversions universelles de référence, dans l'ordre des données de référence
     * @param list<string> $extraUniversal les conversions universelles qui ne sont pas dans la référence (« début > fin x coefficient »), par ordre alphabétique
     * @param list<RepeatedUniversalConversionDTO> $repeated les conversions propres qui répètent une paire universelle, par paire puis coefficient
     * @param list<ConversionHealthAccountDTO> $accounts un élément par compte, par ordre d'identifiant
     * @param int $unattachedIngredientsNotConvertible les ingrédients non convertibles dont le créateur n'est aucun compte existant (attendu : 0)
     * @param int $unattachedRecipeRowsNotConvertible les lignes de recettes non convertibles dont l'auteur n'est aucun compte existant (attendu : 0)
     */
    public function __construct(
        public array $reference,
        public array $extraUniversal,
        public array $repeated,
        public array $accounts,
        public int $unattachedIngredientsNotConvertible = 0,
        public int $unattachedRecipeRowsNotConvertible = 0,
    ) {
    }

    public function missingReference(): int
    {
        return count(array_filter($this->reference, static fn(ReferenceConversionDTO $line): bool => $line->state === ReferenceConversionDTO::MISSING));
    }

    public function otherCoefficientReference(): int
    {
        return count(array_filter($this->reference, static fn(ReferenceConversionDTO $line): bool => $line->state === ReferenceConversionDTO::OTHER_COEFFICIENT));
    }

    /**
     * Les lignes de référence dont la paire est reliée par une universelle, avec le coefficient attendu ou un autre.
     */
    public function presentReference(): int
    {
        return count($this->reference) - $this->missingReference();
    }

    public function repeatedCount(): int
    {
        return array_sum(array_map(static fn(RepeatedUniversalConversionDTO $line): int => $line->count, $this->repeated));
    }

    public function hijackedCount(): int
    {
        return array_sum(array_map(static fn(RepeatedUniversalConversionDTO $line): int => $line->hijacked, $this->repeated));
    }

    /**
     * Tous les ingrédients dont l'unité des recettes ne se convertit pas en l'unité d'achat, comptes et ingrédients sans compte confondus.
     */
    public function ingredientsNotConvertible(): int
    {
        return $this->unattachedIngredientsNotConvertible
            + array_sum(array_map(static fn(ConversionHealthAccountDTO $account): int => $account->ingredientsNotConvertible, $this->accounts));
    }

    public function recipeRowsNotConvertible(): int
    {
        return $this->unattachedRecipeRowsNotConvertible
            + array_sum(array_map(static fn(ConversionHealthAccountDTO $account): int => $account->recipeRowsNotConvertible, $this->accounts));
    }

    /**
     * VERT : aucune universelle de référence ne manque et tout ingrédient convertit son unité des recettes en son unité d'achat. Les lignes de
     * recettes non convertibles, un autre coefficient ou une universelle en plus se disent mais ne changent pas le verdict.
     */
    public function isHealthy(): bool
    {
        return $this->missingReference() === 0 && $this->ingredientsNotConvertible() === 0;
    }
}
