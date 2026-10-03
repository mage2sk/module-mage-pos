<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Ui\Component\Listing\Column;

require_once __DIR__ . '/../../../../autoload.php';

use Magento\Framework\Escaper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Panth\MagePos\Ui\Component\Listing\Column\MethodActions;
use Panth\MagePos\Ui\Component\Listing\Column\QuickkeyActions;
use Panth\MagePos\Ui\Component\Listing\Column\RegisterActions;
use Panth\MagePos\Ui\Component\Listing\Column\RoleActions;
use Panth\MagePos\Ui\Component\Listing\Column\RolePermissions;
use Panth\MagePos\Ui\Component\Listing\Column\SessionActions;
use Panth\MagePos\Ui\Component\Listing\Column\UserActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColumnsTest extends TestCase
{
    private function urlBuilder(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn ($route, $params = []) => $route . '?' . http_build_query($params)
        );

        return $url;
    }

    private function makeColumn(string $class, ...$extra): object
    {
        return new $class(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            ...[...$extra, [], ['name' => 'actions']]
        );
    }

    public static function actionColumnProvider(): array
    {
        return [
            [MethodActions::class, 'method_id', 'panth_pos/method', 'id', true, 'Delete POS payment method'],
            [QuickkeyActions::class, 'quick_key_id', 'panth_pos/quickkey', 'id', true, 'Delete POS quick key'],
            [RegisterActions::class, 'register_id', 'panth_pos/register', 'register_id', true, 'Delete register'],
            [RoleActions::class, 'role_id', 'panth_pos/role', 'id', true, 'Delete POS Role'],
            [UserActions::class, 'user_id', 'panth_pos/user', 'id', true, 'Delete POS User'],
        ];
    }

    #[DataProvider('actionColumnProvider')]
    public function testEditAndDeleteActionsAreAddedPerRow(
        string $class,
        string $idField,
        string $route,
        string $param,
        bool $post,
        string $confirmTitle
    ): void {
        $column = $this->makeColumn($class, $this->urlBuilder());

        $result = $column->prepareDataSource(['data' => ['items' => [[$idField => 5], ['other' => 1]]]]);

        $actions = $result['data']['items'][0]['actions'];
        $this->assertSame($route . '/edit?' . $param . '=5', $actions['edit']['href']);
        $this->assertSame('Edit', $actions['edit']['label']);
        $this->assertSame($route . '/delete?' . $param . '=5', $actions['delete']['href']);
        $this->assertSame($confirmTitle, $actions['delete']['confirm']['title']);
        $this->assertSame($post, $actions['delete']['post'] ?? false);
        $this->assertArrayNotHasKey('actions', $result['data']['items'][1]);
    }

    public static function actionClassProvider(): array
    {
        return array_map(static fn (array $row) => [$row[0]], self::actionColumnProvider());
    }

    #[DataProvider('actionClassProvider')]
    public function testDataSourceWithoutItemsIsUntouched(string $class): void
    {
        $source = ['data' => ['totalRecords' => 0]];

        $this->assertSame($source, $this->makeColumn($class, $this->urlBuilder())->prepareDataSource($source));
    }

    public function testSessionActionsOfferForceCloseOnlyForOpenSessions(): void
    {
        $column = $this->makeColumn(SessionActions::class, $this->urlBuilder());

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['session_id' => '3', 'status' => 'open'],
            ['session_id' => '4', 'status' => 'closed'],
            ['status' => 'open'],
        ]]]);
        $items = $result['data']['items'];

        $this->assertSame('panth_pos/session/view?session_id=3', $items[0]['actions']['view']['href']);
        $this->assertSame('panth_pos/session/forceclose?session_id=3', $items[0]['actions']['forceclose']['href']);
        $this->assertTrue($items[0]['actions']['forceclose']['post']);
        $this->assertStringContainsString('#3', (string) $items[0]['actions']['forceclose']['confirm']['message']);
        $this->assertArrayNotHasKey('forceclose', $items[1]['actions']);
        $this->assertArrayNotHasKey('actions', $items[2]);
        $this->assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
    }

    public function testRolePermissionsAreSummarised(): void
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($v) => htmlspecialchars((string) $v));
        $column = new RolePermissions(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            new Json(),
            $escaper,
            [],
            ['name' => 'permissions']
        );

        $result = $column->prepareDataSource(['data' => ['items' => [
            ['permissions' => '{"max_discount_percent":12.5,"can_refund":true,"can_view_reports":1,"can_open_close":false}'],
            ['permissions' => 'not json'],
            [],
        ]]]);
        $items = $result['data']['items'];

        $this->assertSame(
            '<strong>Max discount: 12.5%</strong><br>2 of 7 permissions: Refunds, View reports',
            $items[0]['permissions']
        );
        $this->assertSame('<strong>Max discount: 0%</strong><br>0 of 7 permissions', $items[1]['permissions']);
        $this->assertSame('<strong>Max discount: 0%</strong><br>0 of 7 permissions', $items[2]['permissions']);
        $this->assertSame(['data' => []], $column->prepareDataSource(['data' => []]));
    }
}
