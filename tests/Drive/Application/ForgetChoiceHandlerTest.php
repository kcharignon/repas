<?php

namespace Repas\Tests\Drive\Application;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceCommand;
use Repas\Drive\Application\Command\ForgetChoice\ForgetChoiceHandler;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\Builder\ChoiceBuilder;
use Repas\Tests\Helper\Builder\DriveSettingsBuilder;
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
        $this->handler = new ForgetChoiceHandler($this->settings, $this->choices);
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
}
