<?php

namespace Repas\Voice\Infrastructure\Acl;

use Repas\Repas\Application\OpenHostService\IngredientPhraseCommandOHS;
use Repas\Repas\Domain\Model\Confidence;
use Repas\Repas\Domain\Model\RankedIngredient;
use Repas\Voice\Application\Port\IngredientResolutionCommandPort;
use Repas\Voice\Domain\Model\DialogueOption;
use Repas\Voice\Domain\Model\ResolvedIngredient;
use Repas\Voice\Domain\Model\SpokenResolution;

/**
 * Couche anticorruption (ADR 0035) : ce que le classement de `Repas` rend pour une phrase (ingrédient classé, propositions, ex æquo) devient
 * ce que le dialogue vocal attend. Rien n'est décidé ici : la confiance est traduite en son nom, les propositions en choix du dialogue.
 */
final readonly class IngredientResolutionCommandAdapter implements IngredientResolutionCommandPort
{
    public function __construct(
        private IngredientPhraseCommandOHS $ingredients,
    ) {
    }

    public function resolveOrSuggest(string $userId, string $phrase): SpokenResolution
    {
        $resolution = $this->ingredients->resolveOrSuggest($userId, $phrase);

        if ($resolution->ingredient !== null) {
            return SpokenResolution::found(new ResolvedIngredient(
                $resolution->ingredient->slug,
                $resolution->ingredient->name,
                $resolution->ingredient->departmentName,
                self::confidence($resolution->ingredient->confidence),
            ));
        }
        if ($resolution->ties !== []) {
            // Les ex æquo se distinguent par leur rayon, que le dialogue dit à voix haute
            return SpokenResolution::tied(array_map(
                static fn(RankedIngredient $tie): DialogueOption => new DialogueOption($tie->slug, $tie->name, $tie->departmentSlug, $tie->departmentName),
                $resolution->ties,
            ));
        }

        return SpokenResolution::unknown(array_map(
            static fn(RankedIngredient $suggestion): DialogueOption => new DialogueOption($suggestion->slug, $suggestion->name),
            $resolution->suggestions,
        ));
    }

    public function remember(string $userId, string $phrase, string $ingredientSlug): void
    {
        $this->ingredients->remember($userId, $phrase, $ingredientSlug);
    }

    private static function confidence(Confidence $confidence): string
    {
        return match ($confidence) {
            Confidence::SURE => ResolvedIngredient::SURE,
            Confidence::PROBABLE => ResolvedIngredient::PROBABLE,
            Confidence::UNSURE => ResolvedIngredient::UNSURE,
        };
    }
}
