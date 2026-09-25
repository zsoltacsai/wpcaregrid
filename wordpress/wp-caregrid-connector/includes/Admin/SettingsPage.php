<?php

namespace WPCareGrid\Connector\Admin;

use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Scheduler;
use WPCareGrid\Connector\Core\Settings;
use WPCareGrid\Connector\Jobs\JobRegistry;
use WPCareGrid\Connector\Security\Logger;

/**
 * Settings → WP CareGrid. Visible and usable only with manage_options;
 * every action checks the capability and a nonce. The credential is never
 * shown, not even partially.
 */
final class SettingsPage
{
    public const SLUG = 'wp-caregrid';

    public const CAPABILITY = 'manage_options';

    public const ACTIONS = [
        'pair' => 'wp_caregrid_pair',
        'disconnect' => 'wp_caregrid_disconnect',
        'sync' => 'wp_caregrid_sync',
    ];

    public function __construct(private readonly Plugin $plugin) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);

        foreach (self::ACTIONS as $key => $action) {
            add_action("admin_post_{$action}", [$this, "handle_{$key}"]);
        }
    }

    public function menu(): void
    {
        add_options_page(__('WP CareGrid', 'wp-caregrid'), __('WP CareGrid', 'wp-caregrid'), self::CAPABILITY, self::SLUG, [$this, 'render']);
    }

    public function handle_pair(): void
    {
        $this->authorize('pair');

        $code = isset($_POST['pairing_code']) ? sanitize_text_field(wp_unslash((string) $_POST['pairing_code'])) : '';
        $result = $this->plugin->pairing()->pair($code);

        if ($result['ok']) {
            // Initial check right away rather than waiting for cron.
            $this->plugin->heartbeat()->send();
            $this->plugin->runner()->poll();
        }

        $this->finish($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function handle_disconnect(): void
    {
        $this->authorize('disconnect');
        $this->plugin->connection()->disconnect('admin');
        $this->finish('success', __('Disconnected. WP CareGrid can no longer access this site. Pair again to reconnect.', 'wp-caregrid'));
    }

    public function handle_sync(): void
    {
        $this->authorize('sync');
        $ok = $this->plugin->heartbeat()->send();
        $this->plugin->runner()->poll();
        $this->finish($ok ? 'success' : 'error', $ok ? __('Contacted WP CareGrid.', 'wp-caregrid') : __('WP CareGrid could not be reached. See the log below.', 'wp-caregrid'));
    }

    /** Capability and nonce, on every action. */
    public function authorize(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage the WP CareGrid connection.', 'wp-caregrid'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTIONS[$action]);
    }

    private function finish(string $type, string $message): void
    {
        set_transient('wp_caregrid_notice_'.get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('options-general.php?page='.self::SLUG));
        $this->terminate();
    }

    /** Separate so tests can observe the redirect without exiting. */
    protected function terminate(): void
    {
        if (! defined('WP_CAREGRID_TESTING')) {
            exit;
        }
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $state = Settings::all();
        $connected = Settings::is_connected();
        $notice = get_transient('wp_caregrid_notice_'.get_current_user_id());
        delete_transient('wp_caregrid_notice_'.get_current_user_id());
        $next_poll = Scheduler::next_poll();
        $format = get_option('date_format').' '.get_option('time_format');
        $when = static fn ($time) => $time ? wp_date($format, is_numeric($time) ? (int) $time : strtotime($time)) : __('Never', 'wp-caregrid');
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('WP CareGrid', 'wp-caregrid'); ?></h1>

			<?php if (is_array($notice)) { ?>
				<div class="notice notice-<?php echo $notice['type'] === 'success' ? 'success' : 'error'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
			<?php } ?>

			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e('Connection status', 'wp-caregrid'); ?></th>
					<td><strong><?php echo esc_html($connected ? __('Connected', 'wp-caregrid') : ($state['status'] === Settings::REVOKED ? __('Disconnected by WP CareGrid – pair again to reconnect', 'wp-caregrid') : __('Not connected', 'wp-caregrid'))); ?></strong>
					<?php if ($state['last_error']) { ?><br><span class="description"><?php echo esc_html(sprintf( /* translators: %s: error code */ __('Last error: %s', 'wp-caregrid'), $state['last_error'])); ?></span><?php } ?></td></tr>
				<?php if ($connected) { ?>
					<tr><th scope="row"><?php esc_html_e('Connected website', 'wp-caregrid'); ?></th><td><code><?php echo esc_html($state['website_uuid']); ?></code></td></tr>
				<?php } ?>
				<tr><th scope="row"><?php esc_html_e('Connector version', 'wp-caregrid'); ?></th>
					<td><code><?php echo esc_html(WP_CAREGRID_VERSION); ?></code>
					<?php if ($state['latest_version'] && version_compare(WP_CAREGRID_VERSION, $state['latest_version'], '<')) { ?> — <?php echo esc_html(sprintf( /* translators: %s: version */ __('version %s is available', 'wp-caregrid'), $state['latest_version'])); ?><?php } ?></td></tr>
				<tr><th scope="row"><?php esc_html_e('Last successful communication', 'wp-caregrid'); ?></th><td><?php echo esc_html($when($state['last_success_at'])); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e('Next poll', 'wp-caregrid'); ?></th><td><?php echo esc_html($connected && $next_poll ? $when($next_poll) : '—'); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e('Capabilities', 'wp-caregrid'); ?></th><td><code><?php echo esc_html(implode(', ', JobRegistry::capabilities())); ?></code></td></tr>
			</table>

			<?php if ($connected) { ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
					<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIONS['sync']); ?>">
					<?php wp_nonce_field(self::ACTIONS['sync']); ?>
					<?php submit_button(__('Check connection now', 'wp-caregrid'), 'secondary', 'submit', false); ?>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site from WP CareGrid? Reconnecting needs a new pairing code.', 'wp-caregrid')); ?>');">
					<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIONS['disconnect']); ?>">
					<?php wp_nonce_field(self::ACTIONS['disconnect']); ?>
					<?php submit_button(__('Disconnect', 'wp-caregrid'), 'delete', 'submit', false); ?>
				</form>
			<?php } else { ?>
				<h2><?php esc_html_e('Connect this site', 'wp-caregrid'); ?></h2>
				<p><?php esc_html_e('Generate a pairing code for this website in your WP CareGrid account and enter it here. No WordPress, hosting or database password is shared.', 'wp-caregrid'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTIONS['pair']); ?>">
					<?php wp_nonce_field(self::ACTIONS['pair']); ?>
					<label for="wp-caregrid-code"><?php esc_html_e('Pairing code', 'wp-caregrid'); ?></label>
					<input type="text" id="wp-caregrid-code" name="pairing_code" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX" required>
					<?php submit_button(__('Connect', 'wp-caregrid'), 'primary', 'submit', false); ?>
				</form>
			<?php } ?>

			<h2><?php esc_html_e('Recent activity', 'wp-caregrid'); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Time (UTC)', 'wp-caregrid'); ?></th><th><?php esc_html_e('Level', 'wp-caregrid'); ?></th><th><?php esc_html_e('Event', 'wp-caregrid'); ?></th></tr></thead>
				<tbody>
				<?php foreach (array_slice(array_reverse(Logger::entries()), 0, 20) as $entry) { ?>
					<tr><td><code><?php echo esc_html($entry['time']); ?></code></td><td><?php echo esc_html($entry['level']); ?></td><td><?php echo esc_html($entry['event']); ?></td></tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
		<?php
    }
}
