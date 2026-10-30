<?php

namespace Repas\Tests\Drive\Application;


use Repas\Shared\Domain\Exception\DriveFeatureException;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\ControlledDriveFeature;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
use Repas\Tests\Helper\InMemoryLoader\IngredientAliasInMemoryResolver;
use Repas\Tests\Helper\InMemoryRepository\ChoiceInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DriveSettingsInMemoryRepository;

class ForgetChoiceHandlerTest extends TestCase
{
    private ChoiceInMemoryRepository $choices;
    private DriveSettingsInMemoryRepository $settings;
    private ForgetChoiceHandler $handler;

    protected function setUp(): void
    {
        $this->settings = new DriveSettingsInMemoryRepository([new DriveSettingsBuilder()->withUserId('user-1')->build()]);
        $this->choices = new ChoiceInMemoryRepository([
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine')->withAccepted(['a'])->withRejected(['x'])->build(),
            new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('sel')->haveAtHome()->build(),
            new ChoiceBuilder()->withUserId('user-2')->withIngredientSlug('farine')->withAccepted(['a'])->build(),
        ]);
        $this->handler = new ForgetChoiceHandler($this->settings, $this->choices, new IngredientAliasInMemoryResolver(), new ControlledDriveFeature());
    }

    public function testForgetsTheWholeChoiceOfOneIngredientOnly(): void
    {
        // When
        ($this->handler)(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '123456', 'farine'));

        // Then : ni les autres ingrédients, ni les préférences d'un autre utilisateur
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));
        $this->assertNotNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'sel'));
        $this->assertNotNull($this->choices->findOne('user-2', Retailer::LECLERC, '123456', 'farine'));
    }

    public function testForgetsAHaveAtHomeChoiceToo(): void
    {
        // When
        ($this->handler)(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '123456', 'sel'));

        // Then
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'sel'));
    }

    public function testAnOldSlugOfACommonTheUserAdoptedForgetsThePreferenceOfHisCopy(): void
    {
        // Given : l'utilisateur a adopté la farine (ADR 0028) ; sa préférence est sous la copie, le client envoie encore le slug du commun
        $this->choices->save(new ChoiceBuilder()->withUserId('user-1')->withIngredientSlug('farine-user-1')->withAccepted(['b'])->build());
        $aliases = new IngredientAliasInMemoryResolver(['farine' => 'farine-user-1']);
        $handler = new ForgetChoiceHandler($this->settings, $this->choices, $aliases, new ControlledDriveFeature());

        // When
        $handler(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '123456', 'farine'));

        // Then : la préférence de la copie est oubliée, l'autre (plus ancienne, sous le commun) n'est pas touchée
        $this->assertNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'farine-user-1'));
        $this->assertNotNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));
        $this->assertSame([['user-1', ['farine']]], $aliases->resolveCalls);
    }

    public function testTheAliasIsNotReadWhenTheStoreIsNotTheCurrentOne(): void
    {
        // Given
        $aliases = new IngredientAliasInMemoryResolver(['farine' => 'farine-user-1']);
        $handler = new ForgetChoiceHandler($this->settings, $this->choices, $aliases, new ControlledDriveFeature());

        // Then
        $this->expectExceptionObject(DriveException::storeMismatch());

        // When
        try {
            $handler(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '654321', 'farine'));
        } finally {
            $this->assertSame([], $aliases->resolveCalls);
        }
    }

    public function testAnIngredientWithoutChoiceIsNotFound(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::choiceNotFound());

        // When
        ($this->handler)(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '123456', 'sucre'));
    }

    public function testAUserCannotForgetTheChoiceOfAnother(): void
    {
        // Given
        $this->settings->save(new DriveSettingsBuilder()->withUserId('user-3')->build());

        // Then
        $this->expectExceptionObject(DriveException::choiceNotFound());

        // When
        ($this->handler)(new ForgetChoiceCommand('user-3', Retailer::LECLERC, '123456', 'farine'));
    }

    public function testAStoreThatIsNotTheCurrentOneIsRefused(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::storeMismatch());

        // When
        ($this->handler)(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '654321', 'farine'));
    }

    public function testWithoutSettingsTheDriveIsNotConfigured(): void
    {
        // Then
        $this->expectExceptionObject(DriveException::notConfigured());

        // When
        ($this->handler)(new ForgetChoiceCommand('user-9', Retailer::LECLERC, '123456', 'farine'));
    }

    public function testADisabledRetailerForgetsNothing(): void
    {
        // Given : l'enseigne n'est pas allumée (DRV-702)
        $handler = new ForgetChoiceHandler($this->settings, $this->choices, new IngredientAliasInMemoryResolver(), new ControlledDriveFeature(true, []));

        // Then
        $this->expectExceptionObject(DriveFeatureException::retailerDisabled());

        // When
        try {
            $handler(new ForgetChoiceCommand('user-1', Retailer::LECLERC, '123456', 'farine'));
        } finally {
            $this->assertNotNull($this->choices->findOne('user-1', Retailer::LECLERC, '123456', 'farine'));
        }
    }
}
