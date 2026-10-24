<?php

namespace Repas\Repas\Domain\Model;


/**
 * Une précondition de la passation du catalogue (P1 à P7, `docs/ingredients/sans-commun.md` § 6) et son verdict. Le détail ne contient que des
 * nombres : jamais d'identifiant, d'adresse ni de nom d'ingrédient personnel.
 */
final readonly class HandoverPrecondition
{
    /**
     * @param string $code `P1` à `P7`
     * @param bool $met vraie dans l'état actuel
     * @param string $detail ce qui manque (vide quand elle est remplie)
     * @param bool $metAfterSeeding vraie une fois l'amorçage (phase 2) fait : `P2` et `P3` sont levées par lui, les autres ne dépendent pas de lui
     */
    public function __construct(
        public string $code,
        public string $label,
        public bool $met,
        public string $detail,
        public bool $metAfterSeeding,
    ) {
    }

    public function describe(): string
    {
        return $this->met ? sprintf('%s : %s', $this->code, $this->label) : sprintf('%s : %s — %s', $this->code, $this->label, $this->detail);
    }
}
