<?php

namespace Repas\Tests\Shared\Infrastructure\Security;


use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Infrastructure\Security\OwnerVoter;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;
use Repas\User\Infrastructure\Security\HimselfVoter;
use Repas\Voice\Domain\Interface\VoiceLinkRepository;
use Repas\Voice\Domain\Model\VoiceLink;
use Repas\Voice\Infrastructure\Security\VoiceLinkOwnerVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Les droits d'accès tels que le VRAI gestionnaire de décision de Symfony les rend, avec les trois voters des contextes `User`, `Repas` et
 * `Voice` (ADR 0035 : chaque contexte juge ses objets) et la vraie base. Ce test garde la matrice de verdicts qu'avait le voter unique :
 *  - chaque attribut est tranché par UN seul voter, les autres s'abstiennent ;
 *  - l'administrateur passe pour `HIMSELF`, les listes, les repas, les lignes et les recettes ordinaires, JAMAIS pour `INGREDIENT_OWNER`,
 *    `VOICE_LINK_OWNER` ni pour la recette secrète d'un autre (`404`, jamais `403`) ;
 *  - une ressource introuvable est un `404`, un compte désactivé n'a rien.
 */
class ContextVotersDecisionTest extends DatabaseTestCase
{
    private const string GRANTED = 'granted';
    private const string DENIED = 'denied';
    private const string NOT_FOUND = '404';

    /** Les attributs de sécurité de l'application et le voter qui les juge. */
    private const array ATTRIBUTES = [
        'HIMSELF' => HimselfVoter::class,
        'INGREDIENT_OWNER' => OwnerVoter::class,
        'SHOPPING_LIST_OWNER' => OwnerVoter::class,
        'RECIPE_OWNER' => OwnerVoter::class,
        'MEAL_OWNER' => OwnerVoter::class,
        'SHOPPING_LIST_ROW_OWNER' => OwnerVoter::class,
        'VOICE_LINK_OWNER' => VoiceLinkOwnerVoter::class,
    ];

    private AccessDecisionManagerInterface $decisions;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decisions = static::getContainer()->get(AccessDecisionManagerInterface::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}> le compte du jeton, le compte dont les objets sont jugés, les verdicts attendus
     */
    public static function verdicts(): array
    {
        $everything = array_fill_keys([...array_keys(self::ATTRIBUTES), 'RECIPE_OWNER (secrète)'], self::GRANTED);
        $nothing = array_fill_keys([...array_keys(self::ATTRIBUTES), 'RECIPE_OWNER (secrète)'], self::DENIED);

        return [
            'le propriétaire a tout' => ['owner', 'owner', $everything],
            'un administrateur propriétaire a tout' => ['owner-admin', 'owner-admin', $everything],
            'un autre compte n\'a rien, et la recette secrète lui est inconnue' => ['other', 'owner', [...$nothing, 'RECIPE_OWNER (secrète)' => self::NOT_FOUND]],
            'un administrateur passe partout sauf pour les ingrédients, les liaisons Alexa et la recette secrète' => ['admin', 'owner', [
                'HIMSELF' => self::GRANTED,
                'INGREDIENT_OWNER' => self::DENIED,
                'SHOPPING_LIST_OWNER' => self::GRANTED,
                'RECIPE_OWNER' => self::GRANTED,
                'MEAL_OWNER' => self::GRANTED,
                'SHOPPING_LIST_ROW_OWNER' => self::GRANTED,
                'VOICE_LINK_OWNER' => self::DENIED,
                'RECIPE_OWNER (secrète)' => self::NOT_FOUND,
            ]],
            'un compte désactivé n\'a rien chez lui' => ['disabled', 'disabled', $nothing],
            'un administrateur désactivé garde le seul passe-droit du rôle' => ['disabled-admin', 'owner', [
                'HIMSELF' => self::GRANTED,
                'INGREDIENT_OWNER' => self::DENIED,
                'SHOPPING_LIST_OWNER' => self::GRANTED,
                'RECIPE_OWNER' => self::DENIED,
                'MEAL_OWNER' => self::GRANTED,
                'SHOPPING_LIST_ROW_OWNER' => self::GRANTED,
                'VOICE_LINK_OWNER' => self::DENIED,
                'RECIPE_OWNER (secrète)' => self::DENIED,
            ]],
        ];
    }

    #[DataProvider('verdicts')]
    public function testEveryAttributeKeepsItsVerdictThroughTheRealDecisionManager(string $caller, string $objectsOf, array $expected): void
    {
        // Given : les objets d'un propriétaire, et l'utilisateur du jeton (le propriétaire ou un autre compte)
        $world = $this->worldOf($objectsOf);
        $user = $this->account($caller);

        // When
        $actual = [];
        foreach ($expected as $label => $_) {
            $attribute = str_replace(' (secrète)', '', $label);
            $subject = $label === 'RECIPE_OWNER (secrète)' ? $world['secretRecipe'] : $world[$attribute];
            $actual[$label] = $this->verdict($user, $attribute, $subject);
        }

        // Then
        $this->assertSame($expected, $actual);
    }

    public function testAnUnknownResourceIsNotFoundForAnyoneButTheAdministratorHasNoPassThroughOnIt(): void
    {
        // Given
        $this->worldOf('owner');
        $owner = $this->account('owner');
        $admin = $this->account('admin');

        // Then : une ressource introuvable est un 404 ; seule une liste, un repas ou une ligne inconnus passent pour l'administrateur, qui n'a rien à
        // chercher (comme avant)
        foreach (['INGREDIENT_OWNER', 'SHOPPING_LIST_OWNER', 'RECIPE_OWNER', 'MEAL_OWNER', 'SHOPPING_LIST_ROW_OWNER', 'VOICE_LINK_OWNER'] as $attribute) {
            $this->assertSame(self::NOT_FOUND, $this->verdict($owner, $attribute, 'unknown'), $attribute);
        }
        $this->assertSame(self::DENIED, $this->verdict($owner, 'HIMSELF', 'unknown'));
        $this->assertSame(self::GRANTED, $this->verdict($admin, 'HIMSELF', 'unknown'));
        foreach (['SHOPPING_LIST_OWNER', 'MEAL_OWNER', 'SHOPPING_LIST_ROW_OWNER'] as $attribute) {
            $this->assertSame(self::GRANTED, $this->verdict($admin, $attribute, 'unknown'), $attribute);
        }
        foreach (['INGREDIENT_OWNER', 'RECIPE_OWNER', 'VOICE_LINK_OWNER'] as $attribute) {
            $this->assertSame(self::NOT_FOUND, $this->verdict($admin, $attribute, 'unknown'), $attribute);
        }
    }

    public function testNoVoterJudgesASubjectWhichIsNotAStringOrAnUnknownAttribute(): void
    {
        // Given
        $owner = $this->account('owner');
        $token = new UsernamePasswordToken($owner, 'main', $owner->getRoles());

        // Then : tous les voters s'abstiennent, le gestionnaire refuse (aucun voter n'accorde)
        foreach (array_keys(self::ATTRIBUTES) as $attribute) {
            foreach ([null, 12, ['x'], new \stdClass()] as $subject) {
                $this->assertFalse($this->decisions->decide($token, [$attribute], $subject), $attribute);
            }
        }
        $this->assertFalse($this->decisions->decide($token, ['OTHER'], 'owner-id'));
    }

    public function testEveryAttributeIsJudgedByOneVoterOnly(): void
    {
        // Given : un voter par contexte, tous enregistrés dans le vrai gestionnaire de décision
        $world = $this->worldOf('owner');
        $token = new UsernamePasswordToken($this->account('owner'), 'main', ['ROLE_USER']);
        $voters = $this->registeredVoters();

        // When : chaque attribut est posé à tous les voters, sur un objet qui existe
        $judges = [];
        foreach (self::ATTRIBUTES as $attribute => $_) {
            $judges[$attribute] = [];
            foreach ($voters as $voter) {
                if ($voter->vote($token, $world[$attribute], [$attribute]) !== VoterInterface::ACCESS_ABSTAIN) {
                    $judges[$attribute][] = $this->undecorated($voter)::class;
                }
            }
        }

        // Then : un seul voter tranche chaque attribut, celui de son contexte
        $this->assertSame(array_map(static fn(string $voter): array => [$voter], self::ATTRIBUTES), $judges);
    }

    public function testTheThreeContextVotersAreRegistered(): void
    {
        $classes = array_map(fn(VoterInterface $voter): string => $this->undecorated($voter)::class, $this->registeredVoters());

        foreach (array_unique(array_values(self::ATTRIBUTES)) as $voterClass) {
            $this->assertContains($voterClass, $classes);
        }
    }

    private function verdict(User $user, string $attribute, mixed $subject): string
    {
        try {
            return $this->decisions->decide(new UsernamePasswordToken($user, 'main', $user->getRoles()), [$attribute], $subject) ? self::GRANTED : self::DENIED;
        } catch (NotFoundHttpException) {
            return self::NOT_FOUND;
        }
    }

    /**
     * @return list<VoterInterface> les voters que le gestionnaire interroge
     */
    private function registeredVoters(): array
    {
        // En mode débogage, le gestionnaire est enveloppé par celui qui trace les décisions : les voters sont ceux du gestionnaire enveloppé
        $manager = $this->decisions;
        while (property_exists($manager, 'manager')) {
            $manager = new \ReflectionProperty($manager::class, 'manager')->getValue($manager);
        }

        return array_values(iterator_to_array(new \ReflectionProperty($manager::class, 'voters')->getValue($manager), false));
    }

    /**
     * En mode débogage, Symfony enveloppe chaque voter pour tracer ses décisions.
     */
    private function undecorated(VoterInterface $voter): VoterInterface
    {
        return method_exists($voter, 'getDecoratedVoter') ? $voter->getDecoratedVoter() : $voter;
    }

    /** @var array<string, User> */
    private array $accounts = [];

    /** @var array<string, array<string, string>> */
    private array $worlds = [];

    /**
     * Les comptes du test, créés à la demande avec des identifiants fixes.
     */
    private function account(string $name): User
    {
        if (isset($this->accounts[$name])) {
            return $this->accounts[$name];
        }

        $builder = new UserBuilder()->withId(sprintf('%08d-0000-4000-8000-000000000000', crc32($name) % 100000000));
        $builder = match ($name) {
            'owner-admin', 'admin' => $builder->isAdmin(),
            'disabled' => $builder->isDisabled(),
            'disabled-admin' => $builder->isAdmin()->isDisabled(),
            default => $builder,
        };
        $this->accounts[$name] = $builder->build();
        $this->persister->saveUser($this->accounts[$name]);

        return $this->accounts[$name];
    }

    /**
     * Les objets d'un compte, un par attribut : le compte lui-même, un ingrédient, une liste avec un repas, une liste avec une ligne, une
     * recette ordinaire, une recette secrète et une liaison avec Alexa.
     *
     * @return array<string, string> le sujet de chaque attribut, et `secretRecipe`
     */
    private function worldOf(string $name): array
    {
        if (isset($this->worlds[$name])) {
            return $this->worlds[$name];
        }

        $user = $this->account($name);
        $ingredient = new IngredientBuilder()->isEgg()->withCreator($user, true)->build();
        $this->persister->saveIngredient($ingredient);
        $recipe = new RecipeBuilder()->withAuthor($user)->withName('Ordinaire')->build();
        $secretRecipe = new RecipeBuilder()->withAuthor($user)->withName('Secrète')->asSecret()->build();
        $this->persister->saveRecipe($recipe);
        $this->persister->saveRecipe($secretRecipe);
        $lists = static::getContainer()->get(ShoppingListRepository::class);
        $withMeal = new ShoppingListBuilder()->withOwner($user)->addRecipe($recipe)->build();
        $withRow = new ShoppingListBuilder()->withOwner($user)->addIngredient($ingredient)->build();
        $lists->save($withMeal);
        $lists->save($withRow);
        $linkId = sprintf('%08d-1111-4111-8111-111111111111', crc32($name) % 100000000);
        static::getContainer()->get(VoiceLinkRepository::class)->save(VoiceLink::create($linkId, $user->getId(), new DateTimeImmutable('2026-10-25 10:00:00')));

        return $this->worlds[$name] = [
            'HIMSELF' => $user->getId(),
            'INGREDIENT_OWNER' => $ingredient->getSlug(),
            'SHOPPING_LIST_OWNER' => $withMeal->getId(),
            'RECIPE_OWNER' => $recipe->getId(),
            'secretRecipe' => $secretRecipe->getId(),
            'MEAL_OWNER' => $withMeal->getMeals()->reset()->getId(),
            'SHOPPING_LIST_ROW_OWNER' => $withRow->getRows()->reset()->getId(),
            'VOICE_LINK_OWNER' => $linkId,
        ];
    }
}
