<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\DepartmentIcon;

/**
 * L'icône d'un rayon (ING-016 B) : les valeurs permises vivent à un seul endroit, l'énumération.
 */
class DepartmentIconTest extends TestCase
{
    public function testTheAllowedValuesAreTheOnesOfTheEnumerationInOrder(): void
    {
        $this->assertSame(['snowflake', 'can'], DepartmentIcon::values());
        $this->assertSame(array_map(static fn(DepartmentIcon $icon): string => $icon->value, DepartmentIcon::cases()), DepartmentIcon::values());
    }

    public function testParseAcceptsNullAndTheAllowedValuesOnly(): void
    {
        $this->assertNull(DepartmentIcon::parse(null));
        $this->assertSame(DepartmentIcon::SNOWFLAKE, DepartmentIcon::parse('snowflake'));
        $this->assertSame(DepartmentIcon::CAN, DepartmentIcon::parse('can'));
        foreach (['', 'Snowflake', ' can', 'sparkles'] as $unknown) {
            try {
                DepartmentIcon::parse($unknown);
                $this->fail("« $unknown » aurait dû être refusée.");
            } catch (DepartmentException $exception) {
                $this->assertEquals(DepartmentException::unknownIcon(), $exception);
                $this->assertSame(['DEPARTMENT_UNKNOWN_ICON', 422], [$exception->getMessage(), $exception->getCode()]);
            }
        }
    }

    public function testKnownKeepsAKnownValueAndReadsAnythingElseAsNoIcon(): void
    {
        $this->assertSame('snowflake', DepartmentIcon::known('snowflake'));
        $this->assertNull(DepartmentIcon::known(null));
        $this->assertNull(DepartmentIcon::known('bag'));
        $this->assertNull(DepartmentIcon::known(''));
    }

    public function testADepartmentHasNoIconByDefaultAndKeepsItThroughAnUpdate(): void
    {
        // Given
        $department = Department::create('Surgelés', 'images/frozen.svg');
        $withIcon = Department::create('Conserves', 'images/can.svg', DepartmentIcon::CAN);

        // When : le nom et l'image changent
        $withIcon->update('Boîtes', 'images/box.svg');

        // Then : `update()` ne touche jamais l'icône, `changeIcon()` la règle ou l'efface
        $this->assertNull($department->getIcon());
        $this->assertSame(DepartmentIcon::CAN, $withIcon->getIcon());
        $withIcon->changeIcon(DepartmentIcon::SNOWFLAKE);
        $this->assertSame(DepartmentIcon::SNOWFLAKE, $withIcon->getIcon());
        $withIcon->changeIcon(null);
        $this->assertNull($withIcon->getIcon());
    }

    public function testLoadingReadsTheIconAndToleratesItsAbsenceOrAnUnknownValue(): void
    {
        $base = ['slug' => 'surgele', 'name' => 'Surgelé', 'image' => 'images/surgele.png'];

        $this->assertSame(DepartmentIcon::SNOWFLAKE, Department::load($base + ['icon' => 'snowflake'])->getIcon());
        $this->assertNull(Department::load($base)->getIcon());
        $this->assertNull(Department::load($base + ['icon' => null])->getIcon());
        $this->assertNull(Department::load($base + ['icon' => 'bag'])->getIcon());
    }
}
