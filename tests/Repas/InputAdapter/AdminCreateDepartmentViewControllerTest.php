<?php

namespace Repas\Tests\Repas\InputAdapter;


use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\QueryBusWebTestCase;

class AdminCreateDepartmentViewControllerTest extends QueryBusWebTestCase
{
    public function testValidSubmissionCreatesTheDepartmentThenRedirectsToTheList(): void
    {
        // Given
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $this->client->request('POST', '/admin/department/create', [
            'create_department' => ['name' => 'Zzz surgelés', 'image' => 'https://exemple.org/zzz.png', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertTrue($this->client->getResponse()->isRedirect('/admin/department'));
        $this->assertSame('Zzz surgelés', static::getContainer()->get(DepartmentRepository::class)->findOneBySlug('zzz-surgeles')->getName());
    }

    public function testADuplicateNameShowsAnErrorAndKeepsTheFirstDepartment(): void
    {
        // Given
        $this->persister->saveDepartment(new DepartmentBuilder()->withName('Zzz surgelés')->withImage('https://exemple.org/premier.png')->build());
        $this->client->loginUser($this->savedUser(admin: true));

        // When
        $crawler = $this->client->request('POST', '/admin/department/create', [
            'create_department' => ['name' => 'zzz surgeles', 'image' => 'https://exemple.org/second.png', '_token' => 'csrf-token'],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        // Then
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Un rayon de ce nom existe déjà.', $crawler->text());
        $this->assertSame('https://exemple.org/premier.png', static::getContainer()->get(DepartmentRepository::class)->findOneBySlug('zzz-surgeles')->getImage());
    }
}
