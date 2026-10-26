<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Dto\SpokenListLookupResult;
use Repas\Shared\Application\Dto\SpokenMealLookupResult;
use Repas\Shared\Application\Interface\SpokenListLookup;

/**
 * Double idiot du port `SpokenListLookup` : renvoie ce qu'on lui a programmé pour une phrase (« pas de liste » pour les autres) et note ses
 * appels. Il ne lit aucune liste : la recherche dans la liste se prouve dans les tests de l'adaptateur de `Repas`.
 */
final class SpokenListLookupInMemory implements SpokenListLookup
{
    /** @var array<string, SpokenListLookupResult> */
    private array $answers = [];

    /** @var list<array{string, string}> les appels à `find` `[userId, phrase]`, dans l'ordre */
    public array $asked = [];

    /** @var array<string, SpokenMealLookupResult> */
    private array $mealAnswers = [];

    /** @var list<array{string, string}> les appels à `findMeal` `[userId, phrase]`, dans l'ordre */
    public array $askedMeals = [];

    public function willFind(string $phrase, SpokenListLookupResult $result): self
    {
        $this->answers[$phrase] = $result;

        return $this;
    }

    public function willFindMeal(string $phrase, SpokenMealLookupResult $result): self
    {
        $this->mealAnswers[$phrase] = $result;

        return $this;
    }

    public function find(string $userId, string $phrase): SpokenListLookupResult
    {
        $this->asked[] = [$userId, $phrase];

        return $this->answers[$phrase] ?? SpokenListLookupResult::noList();
    }

    public function findMeal(string $userId, string $phrase): SpokenMealLookupResult
    {
        $this->askedMeals[] = [$userId, $phrase];

        return $this->mealAnswers[$phrase] ?? SpokenMealLookupResult::noList();
    }
}
