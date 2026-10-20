<?php

namespace Repas\Repas\Infrastructure\Loader;


use Repas\Repas\Application\Loader\CatalogHandoverReportLoader;
use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Repas\Domain\Service\CatalogHandoverPreconditions;
use Repas\Repas\Infrastructure\Adoption\CatalogHandoverReader;
use Repas\Shared\Application\Interface\IngredientCatalogOwner;
use Repas\Shared\Application\Interface\IngredientSeedingFeature;

/**
 * Le rapport « à blanc » de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` § 6), en LECTURE SEULE : jamais d'`INSERT`,
 * d'`UPDATE` ni de `DELETE`, aucun verrou. Il lit l'état par `CatalogHandoverReader` et juge les préconditions par `CatalogHandoverPreconditions`,
 * le même code que la commande qui les relit dans sa transaction : le rapport dit ce que la commande fera. Les comptes que l'amorçage servirait
 * viennent du rapport de l'amorçage (ING-008), lu COMME SI le classement était posé (le fichier fait foi) : on sait avant la phase 1 ce que le
 * compte vide recevra et si des listes envoyées seront repointées.
 *
 * Aucune adresse, aucun identifiant, aucun nom d'ingrédient personnel : des nombres, des sommes de contrôle et la référence courte (SHA-256).
 */
final readonly class CatalogHandoverReportPostgreSQLLoader implements CatalogHandoverReportLoader
{
    /** Combien de slugs inconnus ou manquants le rapport nomme (les anciens communs sont publics, mais le rapport reste court). */
    private const int SAMPLE = 10;

    public function __construct(
        private CatalogHandoverReader $reader,
        private IngredientCatalogOwner $catalogOwner,
        private BaseIngredientClassification $classification,
        private CatalogHandoverPreconditions $preconditions,
        private IngredientSeedingFeature $seedingFeature,
        private IngredientSeedingReportLoader $seedingReport,
    ) {
    }

    public function report(): CatalogHandoverReportDTO
    {
        $classification = $this->classification->all();
        $ownerId = $this->catalogOwner->ownerId();
        $state = $this->reader->read($ownerId, $classification);

        $file = array_map('strval', array_keys($classification));
        sort($file, SORT_STRING);
        $fileBase = array_map('strval', array_keys(array_filter($classification)));
        sort($fileBase, SORT_STRING);

        $without = $state->slugsWithoutCreator;
        $unknown = array_values(array_diff($without, $file));
        $missing = array_values(array_diff($file, $without));
        $handedOver = $state->isHandedOver($classification);
        $markersToWrite = count(array_filter($state->markersWithoutCreator, static fn(bool $marked, string $slug): bool => $marked !== ($classification[$slug] ?? $marked), ARRAY_FILTER_USE_BOTH));

        // Là où sont les anciens communs : sans créateur tant qu'il en reste, sinon chez le propriétaire (ceux du fichier)
        $atOwner = $without === [] && $state->fileSlugsAtOwner !== [];
        $current = $atOwner ? $state->fileSlugsAtOwner : $state->markersWithoutCreator;
        $currentSlugs = array_map('strval', array_keys($current));
        sort($currentSlugs, SORT_STRING);
        $currentMarked = array_map('strval', array_keys(array_filter($current)));
        sort($currentMarked, SORT_STRING);
        $atOwnerDiffering = count(array_filter($state->fileSlugsAtOwner, static fn(bool $marked, string $slug): bool => $marked !== ($classification[$slug] ?? $marked), ARRAY_FILTER_USE_BOTH));

        // L'amorçage des comptes actifs sans ingrédients, lu comme si le classement était posé
        $seeding = $this->seedingReport->report([], [], false, $fileBase === [] ? null : $fileBase);
        $toSeed = array_map(static fn($user): string => $user->userId, array_filter($seeding->selected(), static fn($user): bool => $user->status === 'ACTIVE'));
        $preconditions = $handedOver ? [] : $this->preconditions->evaluate($state, $classification, $this->seedingFeature->isEnabled(), array_values($toSeed));

        $anomalies = [];
        if ($ownerId === null) {
            $anomalies[] = "le propriétaire du catalogue n'existe pas";
        }
        if (!$handedOver && $without === []) {
            $anomalies[] = sprintf("aucun ingrédient sans créateur, et %d ingrédient(s) du fichier sur %d seulement sont chez le propriétaire : le catalogue n'est pas celui attendu", count($state->fileSlugsAtOwner), count($file));
        }
        if ($without !== [] && ($unknown !== [] || $missing !== [])) {
            $anomalies[] = sprintf("le classement ne correspond pas aux ingrédients sans créateur : %d ne sont pas dans le fichier, %d du fichier n'y sont pas", count($unknown), count($missing));
        }
        foreach ($seeding->catalogAnomalies as $anomaly) {
            if (!in_array($anomaly, $anomalies, true) && $anomaly !== "le propriétaire du catalogue n'existe pas : rien ne peut être copié") {
                $anomalies[] = $anomaly;
            }
        }
        if ($seeding->hasBlockingAnomalies()) {
            $anomalies[] = "l'amorçage d'un compte a une anomalie bloquante (voir son détail)";
        }

        return new CatalogHandoverReportDTO(
            ownerExists: $ownerId !== null,
            ownerReference: $ownerId === null ? null : IngredientSeedingReportPostgreSQLLoader::referenceOf($ownerId),
            ownerStatus: $state->ownerStatus,
            ownerIsAdministrator: $state->ownerIsAdministrator,
            administrators: $state->administrators,
            fileEntries: count($file),
            fileBase: count($fileBase),
            fileChecksum: md5(implode(',', $file)),
            fileBaseChecksum: md5(implode(',', $fileBase)),
            withoutCreator: count($without),
            catalogAtOwner: $atOwner,
            catalogCount: count($currentSlugs),
            catalogChecksum: md5(implode(',', $currentSlugs)),
            catalogMarked: count($currentMarked),
            catalogMarkedChecksum: md5(implode(',', $currentMarked)),
            unknown: count($unknown),
            missing: count($missing),
            markersToWrite: $markersToWrite,
            withOrigin: $state->withoutCreatorWithOrigin,
            accountFormat: $state->withoutCreatorAccountFormat,
            ownConversions: $state->ownConversionsWithoutCreator,
            atOwner: count($state->fileSlugsAtOwner),
            atOwnerMarkersDiffering: $atOwnerDiffering,
            handedOver: $handedOver,
            seedingEnabled: $this->seedingFeature->isEnabled(),
            unknownSample: $this->sample($unknown),
            missingSample: $this->sample($missing),
            preconditions: $preconditions,
            seeding: $seeding,
            blockingAnomalies: $anomalies,
        );
    }

    /**
     * Quelques slugs, jamais un slug au format d'un compte (il finirait par un identifiant).
     *
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    private function sample(array $slugs): array
    {
        $pattern = '/' . CatalogHandoverReader::ACCOUNT_SLUG_PATTERN . '/';

        return array_slice(array_values(array_filter($slugs, static fn(string $slug): bool => preg_match($pattern, $slug) !== 1)), 0, self::SAMPLE);
    }
}
