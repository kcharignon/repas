<?php

namespace Repas\Tests\Shared;


use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Builder\UserBuilder;

class TabTest extends TestCase
{
    public function goodArrayDataProvider(): array
    {
        return [
            "integer" => [[1, 2, 3]],
            "string" => [['a', 'b', 'c']],
            "integer with string key" => [['a' => 1, 'b' => 2, 'c' => 3]],
            "Object (User)" => [[
                new UserBuilder()->withEmail('un@fake.com')->build(),
                new UserBuilder()->withEmail('deux@fake.com')->build(),
                new UserBuilder()->withEmail('trois@fake.com')->build(),
            ]],
        ];
    }

    /**
     * @dataProvider goodArrayDataProvider
     */
    public function testCreateWithArray(array $datas): void
    {
        //Act
        $tab = Tab::fromArray($datas);

        //Assert
        $expectedType = gettype(current($datas));
        $this->assertEquals($expectedType, $tab->getType());
        foreach ($tab as $key => $value) {
            $this->assertEquals($value, $datas[$key]);
        }
    }

    public function testCreateWithElements(): void
    {
        //Act
        $tab = Tab::fromArray(1, 2, 3);

        //Assert
        $this->assertEquals([1, 2, 3], $tab->toArray());
    }

    public function testNewEmpty(): void
    {
        //Arrange
        $tab = Tab::newEmpty('integer');

        //Act
        $tab[] = 2;

        //Assert
        $this->assertCount(1, $tab);
        $this->expectException(InvalidArgumentException::class);
        $tab->add('string');
    }

    public function testMap(): void
    {
        //Arrange
        $tab = Tab::fromArray('test', 'with', 'map');

        //Act
        $actual = $tab->map(fn(string $item) => "prefix-{$item}");

        //Assert
        $this->assertEquals(['prefix-test', 'prefix-with', 'prefix-map'], $actual->toArray());
    }

    public function testFilter(): void
    {
        // Arrange
        $array = [1, 2, 3, 4, 5];
        $tab = Tab::fromArray(1, 2, 3, 4, 5);
        $closure = fn(int $item) => $item % 2 === 0;

        // Act
        $filteredTab = $tab->filter($closure);
        $filteredArray = array_filter($array, $closure);

        // Assert
        $this->assertEquals($filteredArray, $filteredTab->toArray());
    }

    public function testFind(): void
    {
        // Arrange
        $closure = fn(int $item) => $item === 2;
        $array = ['a' => 1, 'b' => 2, 'c' => 3];
        $tab = Tab::fromArray($array);

        // Act
        $itemTab = $tab->find($closure);
        $itemArray = array_find($array, $closure);

        // Assert
        $this->assertEquals($itemArray, $itemTab);
    }

    public function testFindKey(): void
    {
        // Arrange
        $closure = fn(int $item) => $item === 2;
        $array = ['a' => 1, 'b' => 2, 'c' => 3];
        $tab = Tab::fromArray($array);

        // Act
        $keyTab = $tab->findKey(fn(int $item) => $item === 2);
        $keyArray = array_find_key($array, $closure);

        // Assert
        $this->assertEquals($keyArray, $keyTab);
    }

    public function arraySliceDataProvider(): array
    {
        $array = [1, 2, 3, 4, 5];
        return [
            "standard" => [$array , 1, 3, false],
            "no length" => [$array , 1, null, true],
            "big length" => [$array , 1, 10, true],
            "big offset" => [$array , 10, 1, true],
            "preserve key" => [$array , 1, 3, false],
        ];
    }

    /**
     * @dataProvider arraySliceDataProvider
     */
    public function testSlice(array $array, int $offset, ?int $length, bool $preserveKey): void
    {
        // Arrange
        $tab = Tab::fromArray($array);

        // Act
        $slicedTab = $tab->slice($offset, $length, $preserveKey);
        $slicedArray  = array_slice($array, $offset, $length, $preserveKey);

        // Assert
        $this->assertEquals($slicedArray, $slicedTab->toArray());
    }

    public function testImplode(): void
    {
        // Arrange
        $array = ['une', 'petite', 'phrase.'];
        $tab = Tab::fromArray($array);

        // Act
        $implodedTab = $tab->implode('-');
        $implodedArray = implode('-', $array);

        // Assert
        $this->assertEquals($implodedArray, $implodedTab);
    }

    public function testExplode(): void
    {
        // Act
        $tab = Tab::explode(', ', 'a, b, c');

        // Assert
        $this->assertEquals(['a', 'b', 'c'], $tab->toArray());
    }

    public function testAddThrowsExceptionForInvalidType(): void
    {
        // Arrange
        $tab = Tab::fromArray(1, 2, 3);

        // Expect Exception
        $this->expectException(InvalidArgumentException::class);

        // Act
        $tab->add('string');
    }

    public function testOffsetAccess(): void
    {
        // Arrange
        $tab = Tab::fromArray(1, 2, 3);

        // Act
        $tab[1] = 4;

        // Assert
        $this->assertEquals(4, $tab[1]);
        unset($tab[1]);
        $this->assertFalse(isset($tab[1]));
    }

    public function testValues(): void
    {
        //Arrange
        $array = ['test' => 1, 2, 5 => 3, 4, '0' => 5, 6];
        $tab = Tab::fromArray($array);

        //Act
        $tabKeys = $tab->values();
        $arrayKeys = array_values($array);

        //Assert
        $this->assertEquals($arrayKeys, $tabKeys);
    }

    public function testKeys(): void
    {
        //Arrange
        $array = ['test' => 1, 2, 5 => 3, 4, '0' => 5, 6];
        $tab = Tab::fromArray($array);

        //Act
        $tabKeys = $tab->keys();
        $arrayKeys = array_keys($array);

        //Assert
        $this->assertEquals($arrayKeys, $tabKeys);
    }
}
