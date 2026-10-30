<?php

namespace Repas\Repas\Application\Command\UpdateDepartment;


readonly class UpdateDepartmentCommand
{
    /**
     * @param string|null $icon       une valeur de `DepartmentIcon`, `null` : effacer l'icône (seulement si `$changeIcon`)
     * @param bool        $changeIcon la clé `icon` figurait dans le corps : sinon l'icône n'est pas touchée (un ancien client, qui ne connaît pas l'icône, ne l'efface jamais en
     *                                modifiant le nom ou l'image)
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $image,
        public ?string $icon = null,
        public bool $changeIcon = false,
    ) {
    }
}
