<?php

namespace WPCareGrid\Connector\AdminAccess;

/**
 * The two WordPress accounts WP CareGrid signs in as, created and managed
 * by the connector only:
 *
 *   wp-caregrid-maintenance  role wp_caregrid_maintenance (below)
 *   wp-caregrid-admin        role administrator, only during confirmed full-admin access
 *
 * Both have no role at all while no access is active, a random password
 * nobody knows (never stored elsewhere, never logged), and cannot log in
 * with a password, reset it, or use application passwords. Existing users
 * are never touched: an unmanaged account with the same name is a conflict.
 */
final class Accounts
{
    public const ROLE = 'wp_caregrid_maintenance';

    /**
     * Exactly these capabilities. Deliberately absent: user management
     * (list/edit/create/delete/promote_users), plugin and theme
     * installation and deletion, the file editors, unfiltered_html/upload,
     * manage_options, switch_themes, edit_theme_options, import/export.
     */
    public const CAPABILITIES = [
        'read',
        'update_plugins',
        'update_themes',
        'update_core',
        'update_languages',
        'activate_plugins',
        'view_site_health_checks',
    ];

    public const USERS = ['maintenance' => 'wp-caregrid-maintenance', 'full_admin' => 'wp-caregrid-admin'];

    public const META = 'wp_caregrid_managed_account';

    /** Create the role, or reset it to exactly CAPABILITIES. */
    public static function ensure_role(): void
    {
        $role = get_role(self::ROLE);

        if (! $role) {
            add_role(self::ROLE, 'WP CareGrid maintenance', array_fill_keys(self::CAPABILITIES, true));

            return;
        }

        foreach (array_keys($role->capabilities) as $cap) {
            if (! in_array($cap, self::CAPABILITIES, true)) {
                $role->remove_cap($cap);
            }
        }
        foreach (self::CAPABILITIES as $cap) {
            if (empty($role->capabilities[$cap])) {
                $role->add_cap($cap);
            }
        }
    }

    public static function role_for(string $level): string
    {
        return $level === 'full_admin' ? 'administrator' : self::ROLE;
    }

    /** The managed account for a level, created on first use. */
    public static function user(string $level): \WP_User
    {
        $login = self::USERS[$level] ?? throw new AccessFailure('INVALID_LEVEL', 'Unknown access level.');
        $user = get_user_by('login', $login);

        if ($user) {
            if (get_user_meta($user->ID, self::META, true) !== $level) {
                // Never take over an account WP CareGrid did not create.
                throw new AccessFailure('ACCOUNT_CONFLICT', 'A user with the WP CareGrid account name already exists and was not created by WP CareGrid.');
            }

            return $user;
        }

        $id = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(64, true, true),
            'user_email' => $login.'@wp-caregrid.invalid',
            'display_name' => $level === 'full_admin' ? 'WP CareGrid (temporary full admin)' : 'WP CareGrid (maintenance access)',
            'description' => 'Managed by the WP CareGrid Connector for temporary access. It has no role while no access is active.',
            'role' => '',
        ]);

        if (is_wp_error($id)) {
            throw new AccessFailure('ACCOUNT_FAILED', 'The WP CareGrid account could not be created.');
        }

        update_user_meta($id, self::META, $level);

        return new \WP_User($id);
    }

    /** Existing managed account for a level, without creating one. */
    public static function find(string $level): ?\WP_User
    {
        $user = get_user_by('login', self::USERS[$level] ?? '');

        return $user && get_user_meta($user->ID, self::META, true) === $level ? $user : null;
    }

    public static function is_managed(int $user_id): bool
    {
        return in_array(get_user_meta($user_id, self::META, true), array_keys(self::USERS), true);
    }

    /** No role, no sessions: the account can do nothing until the next authorised access. */
    public static function strip(\WP_User $user): void
    {
        $user->set_role('');
        \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
    }

    /** Password logins, resets and application passwords are refused for managed accounts. */
    public static function register_protections(): void
    {
        add_filter('authenticate', [self::class, 'refuse_password_login'], 100, 1);
        add_filter('allow_password_reset', fn ($allow, $user_id) => self::is_managed((int) $user_id) ? false : $allow, 10, 2);
        add_filter('wp_is_application_passwords_available_for_user', fn ($available, $user) => $user instanceof \WP_User && self::is_managed($user->ID) ? false : $available, 10, 2);
    }

    public static function refuse_password_login($user)
    {
        if ($user instanceof \WP_User && self::is_managed($user->ID)) {
            return new \WP_Error('wp_caregrid_managed_account', __('This account can only be opened from WP CareGrid.', 'wp-caregrid'));
        }

        return $user;
    }
}
