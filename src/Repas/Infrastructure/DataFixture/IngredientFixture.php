<?php

namespace Repas\Repas\Infrastructure\DataFixture;


use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Exception;
use LogicException;
use Repas\Repas\Domain\Interface\BaseIngredientClassification;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\DataFixture\UserFixture;
use Repas\User\Infrastructure\Entity\User as UserEntity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le catalogue d'ingrédients de développement (ADR 0028, amendement du 2026-10-20) : comme en production après la passation, il appartient
 * au propriétaire du catalogue (le compte protégé de `UserFixture`, ou le compte de `INGREDIENT_CATALOG_OWNER_EMAIL` s'il existe) et les
 * mêmes 151 ingrédients portent le marqueur « de base » (`base-ingredients.json`). Les comptes reçoivent leurs copies à l'activation, ou par
 * l'amorçage des comptes de démonstration (`make fixtures-demo`) : aucun ingrédient n'est « commun », aucun n'est sans créateur.
 */
class IngredientFixture extends RepasFixture implements DependentFixtureInterface, FixtureGroupInterface
{
    const string FILE_NAME = "ingredient.json";

    public function __construct(
        private readonly BaseIngredientClassification $classification,
        #[Autowire('%env(default::INGREDIENT_CATALOG_OWNER_EMAIL)%')]
        private readonly ?string $overrideEmail = null,
    ) {
    }

    public function getDependencies(): array
    {
        return [
            UnitFixture::class,
            DepartmentFixture::class,
            UserFixture::class,
        ];
    }

    public static function getGroups(): array
    {
        return ['test', 'dev'];
    }

    /**
     * @throws Exception
     */
    public function load(ObjectManager $manager): void
    {
        $ownerId = $this->ownerId($manager);
        $isBase = $this->classification->all();
        $filePath = $this->getFilePath(self::FILE_NAME);
        try {
            foreach ($this->readFileObjectByObject($filePath) as $ingredientData) {
                $ingredientEntity = new Ingredient(
                    slug: $ingredientData['slug'],
                    name: $ingredientData['name'],
                    image: $ingredientData['image'] ?? '',
                    sluggedName: StringTool::slugify($ingredientData['name']),
                    department: $ingredientData['department'],
                    defaultCookingUnit: $ingredientData['default_cooking_unit'],
                    defaultPurchaseUnit: $ingredientData['default_purchase_unit'],
                    creatorId: $ownerId,
                    compatibleUnitSlugs: $ingredientData['compatible_units'] ?? array_unique([$ingredientData['default_cooking_unit'], $ingredientData['default_purchase_unit']]),
                    isBase: $isBase[$ingredientData['slug']] ?? false,
                );
                $manager->persist($ingredientEntity);

                $this->addReference($ingredientEntity->getSlug(), $ingredientEntity);
            }

            $manager->flush();
        } catch (Exception $e) {
            dump(sprintf("Failed to create Ingredient: %s", $ingredientData["name"] ?? 'Unknown'));
            throw $e;
        }
    }

    /**
     * Le compte de `INGREDIENT_CATALOG_OWNER_EMAIL` quand il existe (essai de l'interrupteur « de base » avec un compte de démonstration
     * créé avant), sinon le compte protégé, créé par `UserFixture` juste avant.
     */
    private function ownerId(ObjectManager $manager): string
    {
        $override = $this->overrideEmail === null ? '' : User::normalizeEmail($this->overrideEmail);
        $owner = $override === '' ? null : $manager->getRepository(UserEntity::class)->findOneBy(['email' => $override]);
        $owner ??= $manager->getRepository(UserEntity::class)->findOneBy(['email' => User::normalizeEmail(User::PROTECTED_EMAIL)]);

        return $owner?->getId() ?? throw new LogicException("Le propriétaire du catalogue n'existe pas : `UserFixture` crée le compte protégé, il doit passer avant les ingrédients.");
    }
}
