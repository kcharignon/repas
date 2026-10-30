<?php

namespace Repas\Drive\Application\Query\GetDriveSettings;

use OpenApi\Attributes as OA;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SelectionMode;

/**
 * Le verdict de la règle de choix de l'enseigne (R1 à R5 de `docs/drive/multi-enseignes.md` § 5.1), calculé par le back : le web et tout
 * autre client l'affichent, ils ne la réimplémentent pas.
 */
final readonly class RetailerSelectionDTO
{
    /**
     * @param list<string> $candidates
     */
    public function __construct(
        #[OA\Property(description: "Ce que Repas fait de l'enseigne : `NONE` aucune enseigne n'est configurée (mener aux réglages), `AUTO` une enseigne s'impose (l'utiliser sans rien demander : une seule configurée, ou le drive habituel), `ASK` proposer le choix entre les candidates (`retailer` est la présélection)", enum: SelectionMode::VALUES, example: 'AUTO')]
        public string $mode,
        #[OA\Property(description: "L'enseigne à utiliser (`AUTO`) ou à présélectionner (`ASK` : la dernière utilisée si elle est candidate, sinon la première configurée) ; `null` quand `mode` vaut `NONE`", enum: Retailer::IDS, example: 'leclerc', nullable: true)]
        public ?string $retailer,
        #[OA\Property(description: "Les enseignes candidates : allumées (`DRIVE_RETAILERS`) ET où la personne a enregistré un magasin, la plus anciennement enregistrée d'abord ; vide quand `mode` vaut `NONE`", type: 'array', items: new OA\Items(type: 'string', enum: Retailer::IDS, example: 'leclerc'))]
        public array $candidates,
    ) {
    }
}
