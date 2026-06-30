<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\models\User;
use Yii;

/**
 * Phase 7: a default admin account is seeded for zero-touch login
 * (admin / admin-password, role admin).
 */
class AdminUserSeedTest extends Unit
{
    public function testAdminUserSeededAndCanAuthenticate(): void
    {
        $user = User::findByUsername('admin');

        $this->assertNotNull($user, 'admin user must be seeded');
        $this->assertTrue($user->validatePassword('admin-password'), 'default password works');
        $this->assertFalse($user->validatePassword('wrong'), 'wrong password rejected');
        $this->assertSame(User::STATUS_ACTIVE, (int) $user->status);
    }

    public function testAdminUserHasAdminRolePermissions(): void
    {
        $user = User::findByUsername('admin');
        $auth = Yii::$app->authManager;

        $this->assertTrue($auth->checkAccess($user->id, 'manageUsers'), 'admin role grants manageUsers');
        $this->assertTrue($auth->checkAccess($user->id, 'importKeywords'), 'admin inherits marketer permissions');
    }
}
