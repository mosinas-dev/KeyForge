<?php

use yii\db\Migration;

/**
 * Seed RBAC roles + permissions (ADR 0006, §0 human-reviewed zone).
 * Requires Yii's rbac_init (auth_* tables) — applied earlier via the
 * @yii/rbac/migrations path added to the console migrate controller.
 *
 *   admin    = manageUsers + manageConfig + (inherits) marketer
 *   marketer = importKeywords + reviewKeywords + previewCampaigns + exportCampaigns
 */
final class m260629_171204_seed_rbac extends Migration
{
    public function safeUp()
    {
        $auth = Yii::$app->authManager;

        // Admin-only permissions.
        $manageUsers = $auth->createPermission('manageUsers');
        $manageUsers->description = 'Manage users and access';
        $auth->add($manageUsers);

        $manageConfig = $auth->createPermission('manageConfig');
        $manageConfig->description = 'Edit config: brands, forbidden, thresholds, language->url';
        $auth->add($manageConfig);

        // Pipeline permissions (marketer).
        $pipelinePermissions = [
            'importKeywords' => 'Import keyword sources (CSV/JSON)',
            'reviewKeywords' => 'Review and clean keywords',
            'previewCampaigns' => 'Preview generated campaigns',
            'exportCampaigns' => 'Export to Google Ads Editor format',
        ];
        $created = [];
        foreach ($pipelinePermissions as $name => $description) {
            $permission = $auth->createPermission($name);
            $permission->description = $description;
            $auth->add($permission);
            $created[$name] = $permission;
        }

        // marketer role.
        $marketer = $auth->createRole('marketer');
        $marketer->description = 'Marketer: runs the keyword pipeline';
        $auth->add($marketer);
        foreach ($created as $permission) {
            $auth->addChild($marketer, $permission);
        }

        // admin role: own permissions + inherits marketer.
        $admin = $auth->createRole('admin');
        $admin->description = 'Administrator: full access';
        $auth->add($admin);
        $auth->addChild($admin, $manageUsers);
        $auth->addChild($admin, $manageConfig);
        $auth->addChild($admin, $marketer);
    }

    public function safeDown()
    {
        $auth = Yii::$app->authManager;
        foreach (['admin', 'marketer'] as $roleName) {
            if ($role = $auth->getRole($roleName)) {
                $auth->remove($role);
            }
        }
        foreach ([
            'manageUsers', 'manageConfig',
            'importKeywords', 'reviewKeywords', 'previewCampaigns', 'exportCampaigns',
        ] as $permissionName) {
            if ($permission = $auth->getPermission($permissionName)) {
                $auth->remove($permission);
            }
        }
    }
}
