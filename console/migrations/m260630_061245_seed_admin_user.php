<?php

use common\models\User;
use yii\db\Migration;

/**
 * Seed a default admin account for zero-touch login (§5 deliverable / Phase 7):
 *   login: admin   password: admin-password   role: admin
 *
 * Dev/bootstrap convenience — CHANGE THE PASSWORD for any shared/prod deploy.
 * Idempotent: skips if the user already exists. Requires the `user` table
 * (template init) and seeded RBAC roles (m260629_171204_seed_rbac).
 */
final class m260630_061245_seed_admin_user extends Migration
{
    private const USERNAME = 'admin';
    private const PASSWORD = 'admin-password';
    private const ROLE = 'admin';

    public function safeUp()
    {
        $existing = User::findByUsername(self::USERNAME);
        if ($existing === null) {
            $user = new User();
            $user->username = self::USERNAME;
            $user->email = 'admin@site.pro';
            $user->status = User::STATUS_ACTIVE;
            $user->setPassword(self::PASSWORD);
            $user->generateAuthKey();
            if (!$user->save()) {
                throw new RuntimeException('Failed to seed admin user: ' . implode('; ', $user->getErrorSummary(true)));
            }
            $userId = $user->id;
        } else {
            $userId = $existing->id;
        }

        $auth = Yii::$app->authManager;
        $adminRole = $auth->getRole(self::ROLE);
        if ($adminRole !== null && $auth->getAssignment(self::ROLE, $userId) === null) {
            $auth->assign($adminRole, $userId);
        }

        echo "    > seeded user '" . self::USERNAME . "' (role '" . self::ROLE . "')\n";
    }

    public function safeDown()
    {
        $user = User::findByUsername(self::USERNAME);
        if ($user === null) {
            return;
        }
        Yii::$app->authManager->revokeAll($user->id);
        $user->delete();
    }
}
