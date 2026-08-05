<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\InventoryAuthorization;
use Mockery;
use Tests\TestCase;

class InventoryAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_store_keeper_can_inspect_but_not_approve_grn(): void
    {
        $user = $this->userWithPermissions(['grn-inspect', 'inventory-view']);

        $this->assertTrue(InventoryAuthorization::canInspectGrn($user));
        $this->assertFalse(InventoryAuthorization::canApproveGrn($user));
    }

    public function test_controller_can_approve_with_grn_approve_only(): void
    {
        $user = $this->userWithPermissions(['grn-approve']);

        $this->assertFalse(InventoryAuthorization::canInspectGrn($user));
        $this->assertTrue(InventoryAuthorization::canApproveGrn($user));
    }

    public function test_manage_inventory_can_inspect_but_not_approve_without_extra_permission(): void
    {
        $user = $this->userWithPermissions(['manage-inventory']);

        $this->assertTrue(InventoryAuthorization::canInspectGrn($user));
        $this->assertFalse(InventoryAuthorization::canApproveGrn($user));
    }

    public function test_manage_grn_legacy_grants_both_inspect_and_approve(): void
    {
        $user = $this->userWithPermissions(['manage-grn']);

        $this->assertTrue(InventoryAuthorization::canInspectGrn($user));
        $this->assertTrue(InventoryAuthorization::canApproveGrn($user));
    }

    public function test_accounts_can_pay_vendor_without_manage_inventory(): void
    {
        $user = $this->userWithPermissions(['accounting-vendor-pay']);

        $this->assertTrue(InventoryAuthorization::canPayVendor($user));
        $this->assertFalse(InventoryAuthorization::canAny($user, [InventoryAuthorization::MANAGE]));
    }

    public function test_waiter_cannot_view_procurement(): void
    {
        $user = $this->userWithPermissions(['pos-order']);

        $this->assertFalse(InventoryAuthorization::canViewProcurement($user));
        $this->assertFalse(InventoryAuthorization::canViewCatalog($user));
    }

    public function test_requisition_user_can_view_catalog_for_item_picker(): void
    {
        $user = $this->userWithPermissions(['create-requisition']);

        $this->assertTrue(InventoryAuthorization::canViewCatalog($user));
    }

    /** @param  array<int, string>  $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')
            ->with(Mockery::any())
            ->andReturn(false);
        $user->shouldReceive('can')
            ->andReturnUsing(fn (string $permission) => in_array($permission, $permissions, true));

        return $user;
    }
}
