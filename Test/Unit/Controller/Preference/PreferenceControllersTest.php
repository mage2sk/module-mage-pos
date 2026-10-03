<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Preference;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Panth\MagePos\Controller\Preference\Load;
use Panth\MagePos\Controller\Preference\Save;
use Panth\MagePos\Service\PreferenceService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class PreferenceControllersTest extends AbstractControllerTestCase
{
    private PreferenceService&MockObject $preferenceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preferenceService = $this->createMock(PreferenceService::class);
    }

    private function dispatch(string $class): void
    {
        (new $class(...[...$this->baseArgs(), $this->preferenceService]))->execute();
    }

    public function testLoadReturnsPreferencesOfCurrentUser(): void
    {
        $this->signIn(9);
        $this->preferenceService->expects($this->once())->method('get')->with(9)->willReturn(['layout' => null, 'theme' => null]);

        $this->dispatch(Load::class);

        $this->assertSuccess(['layout' => null, 'theme' => null]);
    }

    public function testLoadRequiresSignIn(): void
    {
        $this->signOut();

        $this->dispatch(Load::class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testSaveRequiresSomething(): void
    {
        $this->signIn();
        $this->body = ['layout' => 'not-json', 'theme' => ''];
        $this->preferenceService->expects($this->never())->method('save');

        $this->dispatch(Save::class);

        $this->assertError('Nothing to save: provide a layout and/or theme object.');
    }

    public function testSaveThemeOnlyDoesNotNeedLayoutPermission(): void
    {
        $this->signIn(9);
        $this->authService->expects($this->never())->method('requirePermission');
        $this->body = ['theme' => '{"dark":true}'];
        $this->preferenceService->expects($this->once())->method('save')->with(9, null, ['dark' => true]);
        $this->preferenceService->method('get')->willReturn(['layout' => null, 'theme' => ['dark' => true]]);

        $this->dispatch(Save::class);

        $this->assertSuccess(['layout' => null, 'theme' => ['dark' => true]]);
        $this->assertSame('Preferences saved.', $this->result['message']);
    }

    public function testSaveLayoutRequiresPermission(): void
    {
        $this->signIn(9);
        $this->denyPermission('can_edit_layout');
        $this->body = ['layout' => ['grid' => 4]];
        $this->preferenceService->expects($this->never())->method('save');

        $this->dispatch(Save::class);

        $this->assertError('You do not have permission to perform this action.', 'forbidden');
    }

    public function testSaveLayoutWithPermission(): void
    {
        $this->signIn(9);
        $this->body = ['layout' => ['grid' => 4]];
        $this->authService->expects($this->once())->method('requirePermission')->with('can_edit_layout');
        $this->preferenceService->expects($this->once())->method('save')->with(9, ['grid' => 4], null);

        $this->dispatch(Save::class);

        $this->assertTrue($this->result['success']);
    }

    public function testSaveThemeErrorHasNoForbiddenCode(): void
    {
        $this->signIn(9);
        $this->body = ['theme' => ['a' => 1]];
        $this->preferenceService->method('save')->willThrowException($this->localized('Could not encode.'));

        $this->dispatch(Save::class);

        $this->assertError('Could not encode.');
        $this->assertArrayNotHasKey('code', $this->result);
    }
}
