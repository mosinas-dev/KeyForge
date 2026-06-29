<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use Yii;

/**
 * Phase 1 RBAC seed test (keyforge_test). ADR 0006:
 *  - roles admin + marketer exist;
 *  - marketer holds import/review/preview/export permissions;
 *  - admin holds manageUsers/manageConfig AND inherits all marketer permissions.
 */
class RbacTest extends Unit
{
    private const MARKETER_PERMISSIONS = [
        'importKeywords', 'reviewKeywords', 'previewCampaigns', 'exportCampaigns',
    ];

    public function testRolesExist(): void
    {
        $auth = Yii::$app->authManager;
        $this->assertNotNull($auth->getRole('admin'), 'role admin must be seeded');
        $this->assertNotNull($auth->getRole('marketer'), 'role marketer must be seeded');
    }

    public function testMarketerHasPipelinePermissions(): void
    {
        $perms = array_keys(Yii::$app->authManager->getPermissionsByRole('marketer'));
        foreach (self::MARKETER_PERMISSIONS as $permission) {
            $this->assertContains($permission, $perms, "marketer must have {$permission}");
        }
    }

    public function testAdminHasAdminPermissionsAndInheritsMarketer(): void
    {
        $perms = array_keys(Yii::$app->authManager->getPermissionsByRole('admin'));
        $this->assertContains('manageUsers', $perms, 'admin must have manageUsers');
        $this->assertContains('manageConfig', $perms, 'admin must have manageConfig');
        foreach (self::MARKETER_PERMISSIONS as $permission) {
            $this->assertContains($permission, $perms, "admin must inherit marketer permission {$permission}");
        }
    }
}
