<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\IngredientMergeLoader;
use Repas\Repas\Application\Query\GetMergePreview\MergeIngredientDTO;
use Repas\Repas\Application\Query\GetMergePreview\MergePreviewDTO;
use Repas\Repas\Application\Query\GetMergePreview\MergeUnitDTO;
use Repas\Repas\Application\Query\ListMergeCandidates\MergeCandidateDTO;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\IngredientMergeUsage;
use Repas\Repas\Domain\Model\MatchLevel;
use Repas\Repas\Domain\Model\MergeBlock;
use Repas\Repas\Domain\Model\MergeParty;
use Repas\Repas\Domain\Service\ConversionGraph;
use Repas\Repas\Domain\Service\IngredientMergePlanner;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Infrastructure\Merge\IngredientMergeUsageReader;
use Repas\Shared\Application\Dto\DriveMergeImpact;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * L'aperçu et les candidats de la fusion de deux ingrédients (ADR 0028, ING-005), en SQL : aucun modèle de domaine n'est hydraté.
 *
 * L'aperçu pose au service de domaine `IngredientMergePlanner` les mêmes faits que l'écriture (`MergeIngredientsHandler`) : les mêmes
 * nombres (`IngredientMergeUsageReader`), le même graphe de conversion de l'ingrédient gardé (`ConversionGraph`, le parcours de
 * `ConversionService`), les mêmes choix du drive (port `Shared`). Il dit donc exactement ce que la fusion ferait. Un test compare les
 * deux sur des jeux de données de tous les cas.
 */
final readonly class IngredientMergePostgreSQLLoader implements IngredientMergeLoader
{
    /** Nombre maximal de candidats : au-delà, la saisie est à préciser */
    private const int CANDIDATES_LIMIT = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngredientMergeUsageReader $usageReader,
        private IngredientMergePlanner $planner,
        private IngredientNameMatcher $nameMatcher,
        private DriveIngredientReferences $driveReferences,
    ) {
    }

    public function preview(string $userId, string $absorbedSlug, string $keptSlug): MergePreviewDTO
    {
        $ingredients = $this->ingredientsOf($userId, [$absorbedSlug, $keptSlug]);
        $absorbed = $ingredients[$absorbedSlug];
        $kept = $ingredients[$keptSlug];

        $absorbedParty = new MergeParty($absorbed['slug'], $absorbed['creator'] === null, $absorbed['unit_slug']);
        $keptParty = new MergeParty($kept['slug'], $kept['creator'] === null, $kept['unit_slug']);

        // Le même ingrédient ou un commun : rien n'est lu de plus, le verdict est donné d'avance (comme l'écriture, qui s'arrête là)
        $mergeable = $absorbedSlug !== $keptSlug && !$absorbedParty->isCommon && !$keptParty->isCommon;
        $usage = $mergeable ? $this->usageReader->usageOf($userId, $absorbedSlug, $keptSlug, $kept['unit_slug']) : new IngredientMergeUsage();
        $drive = $mergeable ? $this->driveReferences->mergeImpact($userId, $absorbedSlug, $keptSlug) : new DriveMergeImpact();

        $plan = $this->planner->plan($absorbedParty, $keptParty, $this->graphOf($keptSlug), $usage, $drive->chosenByAnotherUser);

        return new MergePreviewDTO(
            keep: $this->ingredientDto($kept),
            absorbed: $this->ingredientDto($absorbed),
            recipeCount: $usage->recipeCount,
            listCount: $usage->listCount,
            driveChoiceCount: $drive->choicesMoved,
            departmentChanges: $absorbed['department_slug'] !== $kept['department_slug'],
            listQuantitiesConverted: $plan->listQuantitiesConverted(),
            missingUnits: $this->unitDtos($plan->missingUnitSlugs),
            blockedBy: $plan->block?->value,
            canMerge: $plan->canMerge(),
        );
    }

    public function candidates(string $userId, string $slug, ?string $search = null): array
    {
        $source = $this->ingredientsOf($userId, [$slug])[$slug];
        if ($source['creator'] === null) {
            throw MergeBlock::COMMON_INGREDIENT->toException();
        }

        $needle = $search === null ? '' : StringTool::slugify($search);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT i.slug, i.name, i.slugged_name, i.department AS department_slug, d.name AS department_name, u.name AS unit_name'
            . ' FROM ingredient i JOIN department d ON d.slug = i.department JOIN unit u ON u.slug = i.default_purchase_unit'
            . ' WHERE i.creator = :user AND i.slug <> :slug' . ($needle === '' ? '' : ' AND i.slugged_name LIKE :search'),
            ['user' => $userId, 'slug' => $slug] + ($needle === '' ? [] : ['search' => '%' . $needle . '%']),
        );
        if ($rows === []) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $level = $this->nameMatcher->similar($source['name'], $row['name']);
            $candidates[] = [
                'row' => $row,
                'same' => $row['department_slug'] === $source['department_slug'],
                'level' => $level,
                'rank' => match ($level) {
                    MatchLevel::EXACT => 0,
                    MatchLevel::TYPO => 1,
                    MatchLevel::SUGGESTION => 2,
                    MatchLevel::NONE => 3,
                },
            ];
        }
        // Le même rayon d'abord, les plus ressemblants en tête ; à égalité l'ordre alphabétique, pour que deux appels donnent le même résultat
        usort($candidates, static fn(array $a, array $b): int => [$b['same'], $a['rank'], $a['row']['slugged_name'], $a['row']['slug']]
            <=> [$a['same'], $b['rank'], $b['row']['slugged_name'], $b['row']['slug']]);
        $candidates = array_slice($candidates, 0, self::CANDIDATES_LIMIT);

        $counts = $this->usageReader->counts($userId, array_map(static fn(array $candidate): string => $candidate['row']['slug'], $candidates));

        return array_map(
            static fn(array $candidate): MergeCandidateDTO => new MergeCandidateDTO(
                slug: $candidate['row']['slug'],
                name: $candidate['row']['name'],
                departmentSlug: $candidate['row']['department_slug'],
                departmentName: $candidate['row']['department_name'],
                purchaseUnitName: $candidate['row']['unit_name'],
                recipeCount: $counts[$candidate['row']['slug']]['recipes'] ?? 0,
                listCount: $counts[$candidate['row']['slug']]['lists'] ?? 0,
                sameDepartment: $candidate['same'],
                veryClose: $candidate['level'] === MatchLevel::EXACT || $candidate['level'] === MatchLevel::TYPO,
            ),
            $candidates,
        );
    }

    /**
     * Les ingrédients de ces slugs, avec leur rayon et leur unité d'achat. L'ingrédient d'un autre utilisateur est inconnu, comme un
     * slug qui n'existe pas : sans exception pour un administrateur (ADR 0028 décision 9). Un commun est rendu : le verdict l'écarte.
     *
     * @param list<string> $slugs
     * @return array<string, array{slug: string, name: string, creator: ?string, department_slug: string, department_name: string, unit_slug: string, unit_name: string}>
     * @throws IngredientException
     */
    private function ingredientsOf(string $userId, array $slugs): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT i.slug, i.name, i.creator, i.department AS department_slug, d.name AS department_name,'
            . ' i.default_purchase_unit AS unit_slug, u.name AS unit_name'
            . ' FROM ingredient i JOIN department d ON d.slug = i.department JOIN unit u ON u.slug = i.default_purchase_unit WHERE i.slug IN (:slugs)',
            ['slugs' => array_values(array_unique($slugs))],
            ['slugs' => ArrayParameterType::STRING],
        );

        $ingredients = [];
        foreach ($rows as $row) {
            $ingredients[$row['slug']] = $row;
        }
        foreach ($slugs as $slug) {
            $row = $ingredients[$slug] ?? null;
            if ($row === null || ($row['creator'] !== null && $row['creator'] !== $userId)) {
                throw IngredientException::notFound($slug);
            }
        }

        return $ingredients;
    }

    /**
     * Le graphe de conversion de l'ingrédient : ses conversions propres et les conversions communes, comme `ConversionService`.
     */
    private function graphOf(string $slug): ConversionGraph
    {
        $rows = $this->connection()->fetchAllNumeric(
            'SELECT start_unit, end_unit, coefficient FROM conversion WHERE ingredient = :slug OR ingredient IS NULL',
            ['slug' => $slug],
        );

        return ConversionGraph::fromConversions(array_map(static fn(array $row): array => [$row[0], $row[1], (float) $row[2]], $rows));
    }

    /**
     * @param array{slug: string, name: string, department_slug: string, department_name: string, unit_name: string} $ingredient
     */
    private function ingredientDto(array $ingredient): MergeIngredientDTO
    {
        return new MergeIngredientDTO($ingredient['slug'], $ingredient['name'], $ingredient['department_slug'], $ingredient['department_name'], $ingredient['unit_name']);
    }

    /**
     * @param list<string> $unitSlugs
     * @return list<MergeUnitDTO>
     */
    private function unitDtos(array $unitSlugs): array
    {
        if ($unitSlugs === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = $this->connection()->fetchAllKeyValue('SELECT slug, name FROM unit WHERE slug IN (:slugs)', ['slugs' => $unitSlugs], ['slugs' => ArrayParameterType::STRING]);

        return array_map(static fn(string $slug): MergeUnitDTO => new MergeUnitDTO($slug, $names[$slug] ?? $slug), $unitSlugs);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
