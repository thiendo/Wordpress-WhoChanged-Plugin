<?php
/**
 * Admin UI.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders admin listing for logs.
 */
class WhoChanged_Admin {

	/**
	 * Logger instance.
	 *
	 * @var WhoChanged_Logger
	 */
	private $logger;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $menu_slug = 'whochanged';

	/**
	 * Settings submenu slug.
	 *
	 * @var string
	 */
	private $settings_slug = 'whochanged-settings';

	/**
	 * Statistics submenu slug.
	 *
	 * @var string
	 */
	private $statistics_slug = 'whochanged-statistics';

	/**
	 * Constructor.
	 *
	 * @param WhoChanged_Logger $logger Logger.
	 */
	public function __construct( WhoChanged_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Logger accessor. Exposed (instead of keeping $logger strictly private)
	 * so the PRO export modules (includes/pro/) can reuse the same query
	 * logic without duplicating it.
	 *
	 * @return WhoChanged_Logger
	 */
	public function get_logger() {
		return $this->logger;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_export_request' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_freemius_page_assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar' ), 100 );
		add_action( 'wp_ajax_whochanged_admin_bar_events', array( $this, 'ajax_admin_bar_events' ) );
		add_action( 'admin_footer', array( $this, 'render_admin_bar_panel' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_admin_bar_panel' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_bar_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_admin_bar_assets' ) );
	}

	/**
	 * Handle export requests early (before admin HTML output starts).
	 *
	 * @return void
	 */
	public function handle_export_request() {
		if ( ! is_admin() || ! $this->current_user_can_view_logs() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( $this->menu_slug !== $page ) {
			return;
		}

		$export_format = isset( $_GET['whochanged_export'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_export'] ) ) : '';
		if ( '' === $export_format ) {
			return;
		}

		$export_nonce = isset( $_GET['whochanged_export_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['whochanged_export_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $export_nonce, 'whochanged_export' ) ) {
			wp_die( esc_html__( 'Invalid export request.', 'whochanged' ) );
		}

		$filter_user = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$action_type = isset( $_GET['action_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['action_type'] ) ) : '';
		$log_tab     = isset( $_GET['whochanged_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_tab'] ) ) : '';
		if ( ! in_array( $log_tab, array( 'user', 'system' ), true ) ) {
			$log_tab = 'user';
		}
		if ( 'system' === $log_tab ) {
			$filter_user = 0;
		}

		$date_state = $this->parse_log_date_filter_state();
		$search_q   = isset( $_GET['whochanged_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['whochanged_q'] ) ) : '';

		if ( 'csv' === $export_format ) {
			$this->export_logs_csv( $filter_user, $action_type, $log_tab, $date_state, $search_q );
			exit;
		}

		if ( in_array( $export_format, array( 'xls', 'pdf' ), true ) ) {
			if ( ! WhoChanged_Pro::ships_premium_modules() ) {
				wp_die( esc_html__( 'Available in WhoChanged PRO.', 'whochanged' ) );
			}

			if ( ! WhoChanged_Pro::is_active() ) {
				wp_die( esc_html__( 'Excel (XLS) and PDF export are PRO features. Upgrade to WhoChanged PRO to unlock them.', 'whochanged' ) );
			}
		}

		if ( 'xls' === $export_format ) {
			if ( class_exists( 'WhoChanged_Pro_Exports' ) ) {
				WhoChanged_Pro_Exports::export_logs_xls( $this, $filter_user, $action_type, $log_tab, $date_state, $search_q );
			}
			exit;
		}

		if ( 'pdf' === $export_format ) {
			if ( class_exists( 'WhoChanged_Pro_Exports' ) ) {
				WhoChanged_Pro_Exports::export_logs_pdf( $this, $filter_user, $action_type, $log_tab, $date_state, $search_q );
			}
			exit;
		}
	}

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( $this->current_user_can_view_logs() ) {
			add_menu_page(
				esc_html__( 'WhoChanged', 'whochanged' ),
				esc_html__( 'WhoChanged', 'whochanged' ),
				'read',
				$this->menu_slug,
				array( $this, 'render_page' ),
				'dashicons-backup',
				58
			);

			add_submenu_page(
				$this->menu_slug,
				esc_html__( 'Statistics', 'whochanged' ),
				esc_html__( 'Statistics', 'whochanged' ),
				'read',
				$this->statistics_slug,
				array( $this, 'render_statistics_page' )
			);
		}

		add_submenu_page(
			$this->menu_slug,
			esc_html__( 'Settings', 'whochanged' ),
			esc_html__( 'Settings', 'whochanged' ),
			'manage_options',
			$this->settings_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue plugin admin assets.
	 *
	 * @param string $hook_suffix Hook suffix (unused; required by the admin_enqueue_scripts signature).
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by hook signature.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( $this->menu_slug, $this->settings_slug, $this->statistics_slug ), true ) ) {
			return;
		}

		if ( $this->statistics_slug === $page ) {
			wp_enqueue_script(
				'whochanged-chartjs',
				WHOCHANGED_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
				array(),
				'4.5.0',
				true
			);
			wp_enqueue_script(
				'whochanged-stats-charts',
				WHOCHANGED_PLUGIN_URL . 'assets/js/stats-charts.js',
				array( 'whochanged-chartjs' ),
				WHOCHANGED_VERSION,
				true
			);
		}

		wp_register_style(
			'flatpickr',
			WHOCHANGED_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
			array(),
			'4.6.13'
		);
		wp_register_script(
			'flatpickr',
			WHOCHANGED_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
			array(),
			'4.6.13',
			true
		);

		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_script( 'flatpickr' );

		$flatpickr_locale  = 'default';
		$admin_script_deps = array( 'jquery', 'flatpickr' );
		if ( 0 === strpos( strtolower( get_user_locale() ), 'vi' ) ) {
			wp_register_script(
				'flatpickr-l10n-vn',
				WHOCHANGED_PLUGIN_URL . 'assets/js/vendor/flatpickr-l10n/vn.js',
				array( 'flatpickr' ),
				'4.6.13',
				true
			);
			wp_enqueue_script( 'flatpickr-l10n-vn' );
			$admin_script_deps[] = 'flatpickr-l10n-vn';
			$flatpickr_locale    = 'vn';
		}

		wp_enqueue_style(
			'whochanged-admin',
			WHOCHANGED_PLUGIN_URL . 'assets/css/admin.css',
			array( 'flatpickr' ),
			WHOCHANGED_VERSION
		);

		wp_enqueue_script(
			'whochanged-admin',
			WHOCHANGED_PLUGIN_URL . 'assets/js/admin.js',
			$admin_script_deps,
			WHOCHANGED_VERSION,
			true
		);

		$today_ymd  = wp_date( 'Y-m-d' );
		$tz         = wp_timezone();
		$last7_from = ( new DateTimeImmutable( 'now', $tz ) )->modify( '-6 days' )->format( 'Y-m-d' );

		wp_localize_script(
			'whochanged-admin',
			'WhoChangedAdmin',
			array(
				'showText'                 => esc_html__( 'View more', 'whochanged' ),
				'hideText'                 => esc_html__( 'Hide details', 'whochanged' ),
				'menuSlug'                 => $this->menu_slug,
				'adminUrl'                 => admin_url( 'admin.php' ),
				'filterNonce'              => wp_create_nonce( 'whochanged_filter' ),
				'todayYmd'                 => $today_ymd,
				'defaultCustomFromYmd'     => $last7_from,
				'flatpickrLocale'          => $flatpickr_locale,
				'rangeCalendarPlaceholder' => esc_html__( 'Click to choose dates on the calendar…', 'whochanged' ),
				'tabLoadingDelayMs'        => 120,
				'exportingText'            => esc_html__( 'Exporting...', 'whochanged' ),
				'purgeConfirmInvalidText'  => esc_html__( 'Purge cancelled: confirmation is invalid.', 'whochanged' ),
				'statsExportTitle'         => esc_html__( 'WhoChanged Statistics', 'whochanged' ),
				'statsExportSubtitle'      => esc_html__( 'Generated from current filters', 'whochanged' ),
			)
		);
	}

	/**
	 * Small style patch for Freemius's own Account/Pricing screens (not our
	 * own admin pages, so not handled by enqueue_assets()). Keeps the
	 * Upgrade button's icon and label on one line and ties its color to the
	 * site's WP Admin color scheme instead of a fixed blue.
	 *
	 * @param string $hook_suffix Hook suffix (unused; required by the admin_enqueue_scripts signature).
	 * @return void
	 */
	public function enqueue_freemius_page_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by hook signature.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, $this->menu_slug . '-' ) ) {
			return;
		}

		wp_enqueue_style(
			'whochanged-freemius-account',
			WHOCHANGED_PLUGIN_URL . 'assets/css/freemius-account.css',
			array(),
			WHOCHANGED_VERSION
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! $this->current_user_can_view_logs() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whochanged' ) );
		}

		$page        = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$filter_user = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$action_type = isset( $_GET['action_type'] ) ? sanitize_key( wp_unslash( $_GET['action_type'] ) ) : '';
		$log_tab     = isset( $_GET['whochanged_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_tab'] ) ) : '';
		if ( ! in_array( $log_tab, array( 'user', 'system' ), true ) ) {
			$log_tab = 'user';
		}
		// Legacy checkbox URL: treat as System tab when no explicit tab.
		if ( 'user' === $log_tab && isset( $_GET['whochanged_show_system'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['whochanged_show_system'] ) ) && ! isset( $_GET['whochanged_tab'] ) ) {
			$log_tab = 'system';
		}

		$nonce = isset( $_GET['whochanged_filter_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['whochanged_filter_nonce'] ) ) : '';
		if ( isset( $_GET['whochanged_filter_nonce'] ) && ! wp_verify_nonce( $nonce, 'whochanged_filter' ) ) {
			$filter_user = 0;
			$action_type = '';
			$log_tab     = 'user';
		}

		$date_state = $this->parse_log_date_filter_state();
		$search_q   = isset( $_GET['whochanged_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['whochanged_q'] ) ) : '';

		if ( 'system' === $log_tab ) {
			$filter_user = 0;
		}

		$export_format = isset( $_GET['whochanged_export'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_export'] ) ) : '';
		if ( '' !== $export_format ) {
			$export_nonce = isset( $_GET['whochanged_export_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['whochanged_export_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $export_nonce, 'whochanged_export' ) ) {
				wp_die( esc_html__( 'Invalid export request.', 'whochanged' ) );
			}

			if ( 'csv' === $export_format ) {
				$this->export_logs_csv( $filter_user, $action_type, $log_tab, $date_state, $search_q );
				return;
			}

			if ( 'pdf' === $export_format && WhoChanged_Pro::ships_premium_modules() && WhoChanged_Pro::is_active() ) {
				WhoChanged_Pro_Exports::export_logs_pdf( $this, $filter_user, $action_type, $log_tab, $date_state, $search_q );
				return;
			}
		}

		$result                  = $this->logger->get_logs( max( 1, $page ), 20, $filter_user, $action_type, $log_tab, $date_state['from'], $date_state['to'], $search_q );
		$action_types            = $this->logger->get_action_types();
		$groups                  = $this->group_logs( $result['items'] );
		$analytics               = $this->logger->get_analytics_counts( $filter_user, $action_type, $log_tab, $date_state['from'], $date_state['to'], $search_q );
		$whochanged_ships_pro    = WhoChanged_Pro::ships_premium_modules();
		$whochanged_pro_active   = $whochanged_ships_pro && WhoChanged_Pro::is_active();
		$whochanged_pro_lock_url = $whochanged_ships_pro
			? admin_url( 'admin.php?page=' . $this->settings_slug )
			: WhoChanged_Pro::get_upgrade_url();

		$users = get_users(
			array(
				'fields'  => array( 'ID', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		?>
		<div class="wrap whochanged-wrap">
			<h1><?php echo esc_html__( 'Site Activity', 'whochanged' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Track what changed on your site.', 'whochanged' ); ?></p>

			<div class="whochanged-filters-bar">
				<div class="whochanged-filters-row">
					<form method="get" class="whochanged-filters">
						<input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>">
						<input type="hidden" name="whochanged_tab" value="<?php echo esc_attr( $log_tab ); ?>">
						<?php wp_nonce_field( 'whochanged_filter', 'whochanged_filter_nonce' ); ?>

						<?php if ( 'system' === $log_tab ) : ?>
							<input type="hidden" name="user_id" value="0">
						<?php endif; ?>
						<label for="whochanged-search" class="screen-reader-text"><?php echo esc_html__( 'Search', 'whochanged' ); ?></label>
						<input
							type="text"
							id="whochanged-search"
							name="whochanged_q"
							class="whochanged-search-input"
							value="<?php echo esc_attr( $search_q ); ?>"
							placeholder="<?php echo esc_attr__( 'Search logs…', 'whochanged' ); ?>"
							autocomplete="off"
						>
						<label for="whochanged-user-id" class="screen-reader-text"><?php echo esc_html__( 'User', 'whochanged' ); ?></label>
						<select id="whochanged-user-id"<?php echo 'system' === $log_tab ? ' disabled="disabled"' : ' name="user_id"'; ?>>
							<option value="0"><?php echo esc_html__( 'All users', 'whochanged' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $filter_user, (int) $user->ID ); ?>>
									<?php echo esc_html( $user->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<label for="whochanged-action-type" class="screen-reader-text"><?php echo esc_html__( 'Action', 'whochanged' ); ?></label>
						<select id="whochanged-action-type" name="action_type">
							<option value=""><?php echo esc_html__( 'All actions', 'whochanged' ); ?></option>
							<?php foreach ( $action_types as $action ) : ?>
								<option value="<?php echo esc_attr( $action ); ?>" <?php selected( $action_type, $action ); ?>>
									<?php echo esc_html( $this->humanize_action_type_label( $action ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( esc_html__( 'Go', 'whochanged' ), 'secondary', 'submit', false ); ?>
					</form>

					<div class="whochanged-date-wrap whochanged-date-range">
						<label for="whochanged-range-preset" class="whochanged-range-label"><?php echo esc_html__( 'Time', 'whochanged' ); ?></label>
						<select id="whochanged-range-preset" class="whochanged-range-preset" autocomplete="off">
							<option value="all" <?php selected( $date_state['dr'], 'all' ); ?>><?php echo esc_html__( 'All time', 'whochanged' ); ?></option>
							<option value="today" <?php selected( $date_state['dr'], 'today' ); ?>><?php echo esc_html__( 'Today', 'whochanged' ); ?></option>
							<option value="yesterday" <?php selected( $date_state['dr'], 'yesterday' ); ?>><?php echo esc_html__( 'Yesterday', 'whochanged' ); ?></option>
							<option value="7d" <?php selected( $date_state['dr'], '7d' ); ?>><?php echo esc_html__( 'Last 7 days', 'whochanged' ); ?></option>
							<option value="30d" <?php selected( $date_state['dr'], '30d' ); ?>><?php echo esc_html__( 'Last 30 days', 'whochanged' ); ?></option>
							<option value="custom" <?php selected( $date_state['dr'], 'custom' ); ?>><?php echo esc_html__( 'Custom range…', 'whochanged' ); ?></option>
						</select>
						<span class="whochanged-custom-range<?php echo 'custom' === $date_state['dr'] ? '' : ' is-collapsed'; ?>">
							<label for="whochanged-range-calendar" class="whochanged-custom-range-label screen-reader-text"><?php echo esc_html__( 'Custom date range', 'whochanged' ); ?></label>
							<input
								type="text"
								id="whochanged-range-calendar"
								class="whochanged-range-calendar-input"
								readonly
								autocomplete="off"
								placeholder="<?php echo esc_attr__( 'Click to choose dates on the calendar…', 'whochanged' ); ?>"
								data-df="<?php echo esc_attr( $date_state['df'] ); ?>"
								data-dt="<?php echo esc_attr( $date_state['dt'] ); ?>"
							>
							<button type="button" class="button button-small whochanged-range-apply"><?php echo esc_html__( 'Apply', 'whochanged' ); ?></button>
						</span>
					</div>
				</div>
			</div>

			<div class="whochanged-list-panel">
				<div class="whochanged-list-panel-content">
				<div class="whochanged-log-tabs-row">
				<nav class="nav-tab-wrapper whochanged-log-tabs wp-clearfix" aria-label="<?php echo esc_attr__( 'Activity source', 'whochanged' ); ?>">
					<a
						href="<?php echo esc_url( $this->build_log_tab_url( 'user', $filter_user, $action_type, 1, $date_state, $search_q ) ); ?>"
						class="nav-tab whochanged-log-tab <?php echo 'user' === $log_tab ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html__( 'User', 'whochanged' ); ?></a>
					<a
						href="<?php echo esc_url( $this->build_log_tab_url( 'system', $filter_user, $action_type, 1, $date_state, $search_q ) ); ?>"
						class="nav-tab whochanged-log-tab <?php echo 'system' === $log_tab ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html__( 'System', 'whochanged' ); ?></a>
				</nav>
				<?php $this->render_pagination( $page, (int) $result['total_pages'], (int) $result['total_items'], $filter_user, $action_type, $log_tab, $date_state, $search_q, 'tab_bar' ); ?>
				</div>
				<?php
				$export_nonce                         = wp_create_nonce( 'whochanged_export' );
				$export_base                          = array(
					'page'                    => $this->menu_slug,
					'whochanged_tab'          => $log_tab,
					'whochanged_filter_nonce' => wp_create_nonce( 'whochanged_filter' ),
					'whochanged_export_nonce' => $export_nonce,
					'user_id'                 => ( 'system' === $log_tab ) ? 0 : (int) $filter_user,
					'action_type'             => $action_type,
					'whochanged_q'            => $search_q,
				);
				$export_args                          = array_merge(
					$export_base,
					$this->build_date_range_query_args( $date_state )
				);
				$export_args_csv                      = $export_args;
				$export_args_csv['whochanged_export'] = 'csv';
				$export_url_csv                       = add_query_arg( $export_args_csv, admin_url( 'admin.php' ) );

				$export_args_xls                      = $export_args;
				$export_args_xls['whochanged_export'] = 'xls';
				$export_url_xls                       = add_query_arg( $export_args_xls, admin_url( 'admin.php' ) );

				$export_args_pdf                      = $export_args;
				$export_args_pdf['whochanged_export'] = 'pdf';
				$export_url_pdf                       = add_query_arg( $export_args_pdf, admin_url( 'admin.php' ) );
				?>
				<div class="whochanged-pro-analytics-row">
					<div class="whochanged-pro-analytics-card">
						<div class="whochanged-pro-analytics-label"><?php echo esc_html__( 'Top actions', 'whochanged' ); ?></div>
						<div class="whochanged-pro-analytics-value">
							<?php
							$top_types = isset( $analytics['top_action_types'] ) && is_array( $analytics['top_action_types'] ) ? $analytics['top_action_types'] : array();
							if ( empty( $top_types ) ) {
								echo esc_html__( '—', 'whochanged' );
							} else {
								$parts = array();
								foreach ( $top_types as $row ) {
									$key = isset( $row['action_type'] ) ? sanitize_key( (string) $row['action_type'] ) : '';
									$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
									if ( '' === $key ) {
										continue;
									}
									$parts[] = esc_html( $this->humanize_action_type_label( $key ) ) . ': ' . esc_html( (string) $c );
								}
								echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $parts entry is already escaped above.
							}
							?>
						</div>
					</div>
					<div class="whochanged-pro-analytics-card">
						<div class="whochanged-pro-analytics-label"><?php echo esc_html__( 'Top users', 'whochanged' ); ?></div>
						<div class="whochanged-pro-analytics-value">
							<?php
							$top_users = isset( $analytics['top_users'] ) && is_array( $analytics['top_users'] ) ? $analytics['top_users'] : array();
							if ( empty( $top_users ) ) {
								echo esc_html__( '—', 'whochanged' );
							} else {
								$parts = array();
								foreach ( $top_users as $row ) {
									$uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
									$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
									if ( 0 === $uid ) {
										$uname = __( 'System', 'whochanged' );
									} else {
										$u_obj = get_user_by( 'id', $uid );
										$uname = $u_obj ? (string) $u_obj->display_name : __( 'Unknown', 'whochanged' );
									}
									$parts[] = esc_html( $uname ) . ': ' . esc_html( (string) $c );
								}
								echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each $parts entry is already escaped above.
							}
							?>
						</div>
					</div>
					<div class="whochanged-pro-analytics-card whochanged-pro-export-card">
						<div class="whochanged-pro-analytics-label"><?php echo esc_html__( 'Export', 'whochanged' ); ?></div>
						<div class="whochanged-pro-export-buttons">
							<a class="button button-secondary whochanged-pro-export-btn" href="<?php echo esc_url( $export_url_csv ); ?>" data-loading-label="<?php echo esc_attr__( 'Exporting CSV...', 'whochanged' ); ?>"><?php echo esc_html__( 'Export CSV', 'whochanged' ); ?></a>
							<?php if ( $whochanged_pro_active ) : ?>
								<a class="button button-secondary whochanged-pro-export-btn" href="<?php echo esc_url( $export_url_xls ); ?>" data-loading-label="<?php echo esc_attr__( 'Exporting XLS...', 'whochanged' ); ?>"><?php echo esc_html__( 'Export XLS', 'whochanged' ); ?></a>
								<a class="button button-secondary whochanged-pro-export-btn" href="<?php echo esc_url( $export_url_pdf ); ?>" data-loading-label="<?php echo esc_attr__( 'Exporting PDF...', 'whochanged' ); ?>"><?php echo esc_html__( 'Export PDF', 'whochanged' ); ?></a>
							<?php else : ?>
								<a
									class="button button-secondary whochanged-pro-export-btn whochanged-pro-locked"
									href="<?php echo esc_url( $whochanged_pro_lock_url ); ?>"
									<?php echo $whochanged_ships_pro ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>
									title="<?php echo esc_attr__( 'Available in WhoChanged PRO.', 'whochanged' ); ?>"
								><?php echo esc_html__( 'Export XLS', 'whochanged' ); ?> <span class="whochanged-pro-badge whochanged-pro-badge--inline"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span></a>
								<a
									class="button button-secondary whochanged-pro-export-btn whochanged-pro-locked"
									href="<?php echo esc_url( $whochanged_pro_lock_url ); ?>"
									<?php echo $whochanged_ships_pro ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>
									title="<?php echo esc_attr__( 'Available in WhoChanged PRO.', 'whochanged' ); ?>"
								><?php echo esc_html__( 'Export PDF', 'whochanged' ); ?> <span class="whochanged-pro-badge whochanged-pro-badge--inline"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="whochanged-table-stack">
					<table class="widefat fixed striped whochanged-table whochanged-table-head">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Activity', 'whochanged' ); ?></th>
								<th><?php echo esc_html__( 'Changes', 'whochanged' ); ?></th>
								<th><?php echo esc_html__( 'User', 'whochanged' ); ?></th>
								<th><?php echo esc_html__( 'Time', 'whochanged' ); ?></th>
								<th></th>
							</tr>
						</thead>
					</table>
					<div class="whochanged-tbody-shell">
						<div class="whochanged-tbody-inner">
							<div class="whochanged-tbody-dim-target">
							<table class="widefat fixed striped whochanged-table whochanged-table-body">
								<tbody>
					<?php if ( empty( $groups ) ) : ?>
						<tr>
							<td colspan="5"><?php echo esc_html__( 'No logs found.', 'whochanged' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $groups as $index => $group ) : ?>
							<?php
							$log_user      = get_user_by( 'id', (int) $group['user_id'] );
							$username      = $log_user ? $log_user->display_name : __( 'System', 'whochanged' );
							$user_avatar   = $log_user ? get_avatar( $log_user->ID, 28, '', $username, array( 'class' => 'whochanged-user-avatar' ) ) : '';
							$row_id        = 'whochanged-details-' . absint( $group['id'] );
							$ago           = human_time_diff( (int) $group['timestamp'], time() );
							$exact_time    = wp_date( 'Y-m-d H:i:s', (int) $group['timestamp'] );
							$row_class     = ( 0 === ( $index % 2 ) ) ? 'is-odd' : 'is-even';
							$has_details   = ! empty( $group['hidden'] ) || $this->has_group_details( $group['main_event'] );
							$important_key = $this->get_important_event_key( $group['main_event'] );
							$important_cls = $important_key ? ' is-important is-important--' . esc_attr( $important_key ) : '';
							?>
							<tr class="whochanged-row <?php echo esc_attr( $row_class . $important_cls ); ?>">
								<td>
									<div class="whochanged-title">
										<?php if ( $has_details ) : ?>
											<button type="button" class="button-link whochanged-activity-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>">
												<?php echo esc_html( $group['title'] ); ?>
											</button>
										<?php else : ?>
											<?php echo esc_html( $group['title'] ); ?>
										<?php endif; ?>
									</div>
									<?php if ( ! empty( $group['hidden'] ) && empty( $group['is_bulk'] ) ) : ?>
										<div class="whochanged-meta">
											<?php
											printf(
												/* translators: %d: number of hidden system changes */
												esc_html__( '→ %d system changes hidden', 'whochanged' ),
												absint( $group['hidden'] )
											);
											?>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $group['subtitle'] ) ) : ?>
										<div class="whochanged-desc whochanged-desc-italic"><?php echo esc_html( $group['subtitle'] ); ?></div>
									<?php endif; ?>
									<?php if ( ! empty( $group['lines'] ) && is_array( $group['lines'] ) ) : ?>
										<?php echo wp_kses( $this->render_mapped_lines_html( $group['lines'], 2, $row_id ), $this->allowed_html() ); ?>
									<?php elseif ( empty( $group['subtitle'] ) ) : ?>
										<span class="whochanged-meta"><?php echo esc_html__( '—', 'whochanged' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<div class="whochanged-user-cell">
										<?php if ( ! empty( $user_avatar ) ) : ?>
											<?php echo wp_kses_post( $user_avatar ); ?>
										<?php endif; ?>
										<span class="whochanged-user-name"><?php echo esc_html( $username ); ?></span>
									</div>
								</td>
								<td>
									<div class="whochanged-time-cell">
										<div class="whochanged-time-ago"><?php echo esc_html( $ago . ' ' . __( 'ago', 'whochanged' ) ); ?></div>
										<div class="whochanged-time-exact"><?php echo esc_html( $exact_time ); ?></div>
									</div>
								</td>
								<td>
									<?php if ( $has_details ) : ?>
										<button type="button" class="button whochanged-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>">
											<?php echo esc_html__( 'View more', 'whochanged' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
							<?php if ( $has_details ) : ?>
								<tr id="<?php echo esc_attr( $row_id ); ?>" class="whochanged-details-row" hidden>
									<td colspan="5">
										<?php echo wp_kses( $this->render_group_details_html( $group ), $this->allowed_html() ); ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
								</tbody>
							</table>
							</div>
							<div class="whochanged-list-loading whochanged-tbody-loading" aria-hidden="true">
								<span class="whochanged-list-loading-spinner"></span>
								<span class="whochanged-list-loading-text" aria-hidden="true"><?php echo esc_html__( 'Loading…', 'whochanged' ); ?></span>
								<span class="screen-reader-text"><?php echo esc_html__( 'Loading activity list…', 'whochanged' ); ?></span>
							</div>
						</div>
					</div>
				</div>

			<?php $this->render_pagination( $page, (int) $result['total_pages'], (int) $result['total_items'], $filter_user, $action_type, $log_tab, $date_state, $search_q ); ?>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render PRO settings page (submenu).
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whochanged' ) );
		}

		$pro_notice      = '';
		$pro_notice_type = 'success';
		$nonce           = isset( $_POST['whochanged_pro_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_nonce'] ) ) : '';

		if ( isset( $_POST['whochanged_pro_send_test'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_send_test'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce is verified below before any state change.
			if ( wp_verify_nonce( $nonce, 'whochanged_pro_settings' ) ) {
				$pro_email_recipient = isset( $_POST['whochanged_pro_email_recipient'] )
					? sanitize_email( wp_unslash( (string) $_POST['whochanged_pro_email_recipient'] ) )
					: '';
				if ( '' === $pro_email_recipient ) {
					$pro_email_recipient = (string) get_option( 'whochanged_pro_email_recipient', (string) get_option( 'admin_email' ) );
				}
				if ( '' === $pro_email_recipient || ! is_email( $pro_email_recipient ) ) {
					$pro_notice      = esc_html__( 'Test alert failed: invalid recipient email.', 'whochanged' );
					$pro_notice_type = 'error';
				} else {
					$sent = $this->send_test_alert_email( $pro_email_recipient );
					if ( $sent ) {
						$pro_notice = sprintf(
							/* translators: %s: recipient email */
							esc_html__( 'Test alert sent to %s.', 'whochanged' ),
							$pro_email_recipient
						);
					} else {
						$pro_notice      = esc_html__( 'Test alert could not be sent. Please check your mail server/SMTP settings.', 'whochanged' );
						$pro_notice_type = 'error';
					}
				}
			} else {
				$pro_notice      = esc_html__( 'Invalid PRO settings request.', 'whochanged' );
				$pro_notice_type = 'error';
			}
		} elseif ( isset( $_POST['whochanged_pro_license_activate'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_license_activate'] ) ) ) {
			if ( ! WhoChanged_Pro::is_using_legacy_license() ) {
				// Freemius is configured; license activation goes through the Freemius
				// account/checkout UI instead, so this legacy POST action is disabled.
				$pro_notice      = esc_html__( 'Please manage your license from the account panel above.', 'whochanged' );
				$pro_notice_type = 'error';
			} elseif ( wp_verify_nonce( $nonce, 'whochanged_pro_settings' ) ) {
				$license_candidate = isset( $_POST['whochanged_pro_license_key'] )
					? sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_license_key'] ) )
					: '';
				if ( '' === $license_candidate ) {
					$pro_notice      = esc_html__( 'License activation failed: license key is empty.', 'whochanged' );
					$pro_notice_type = 'error';
				} else {
					update_option( 'whochanged_pro_license_key', $license_candidate, false );
					update_option( 'whochanged_pro_license_active', 1, false );
					update_option( 'whochanged_pro_license_activated_at', gmdate( 'Y-m-d H:i:s' ), false );
					$pro_notice      = esc_html__( 'License activated. PRO is now active.', 'whochanged' );
					$pro_notice_type = 'success';
				}
			} else {
				$pro_notice      = esc_html__( 'Invalid PRO settings request.', 'whochanged' );
				$pro_notice_type = 'error';
			}
		} elseif ( isset( $_POST['whochanged_pro_purge_all'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_purge_all'] ) ) ) {
			if ( ! WhoChanged_Pro::ships_premium_modules() ) {
				$pro_notice      = esc_html__( 'Available in WhoChanged PRO.', 'whochanged' );
				$pro_notice_type = 'error';
			} elseif ( ! WhoChanged_Pro::is_active() ) {
				$pro_notice      = esc_html__( 'Purging all activity logs is a PRO feature.', 'whochanged' );
				$pro_notice_type = 'error';
			} elseif ( wp_verify_nonce( $nonce, 'whochanged_pro_settings' ) ) {
				$confirm_checkbox = isset( $_POST['whochanged_pro_purge_checkbox'] ) ? '1' : '0';
				$confirm_text     = isset( $_POST['whochanged_pro_purge_confirm_text'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_purge_confirm_text'] ) ) : '';
				$required_text    = 'PURGE ALL ACTIVITY LOGS';
				if ( '1' !== $confirm_checkbox || $required_text !== $confirm_text ) {
					$pro_notice      = esc_html__( 'Purge cancelled: confirmation is invalid.', 'whochanged' );
					$pro_notice_type = 'error';
				} else {
					$result = WhoChanged_Pro_Purge::purge_all_activity_logs( get_current_user_id() );
					if ( isset( $result['ok'] ) && $result['ok'] ) {
						$pro_notice = sprintf(
							/* translators: %d: number of deleted logs */
							esc_html__( 'Purge complete. Deleted %d logs.', 'whochanged' ),
							isset( $result['deleted'] ) ? (int) $result['deleted'] : 0
						);
					} else {
						$pro_notice      = esc_html__( 'Purge failed. Please try again.', 'whochanged' );
						$pro_notice_type = 'error';
					}
				}
			} else {
				$pro_notice      = esc_html__( 'Invalid PRO settings request.', 'whochanged' );
				$pro_notice_type = 'error';
			}
		} elseif ( isset( $_POST['whochanged_pro_save'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_save'] ) ) ) {
			if ( wp_verify_nonce( $nonce, 'whochanged_pro_settings' ) ) {
				$pro_retention  = isset( $_POST['whochanged_pro_retention_days'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_retention_days'] ) ) : 'unlimited';
				$allowed_values = array( 'unlimited', '7', '30', '60', '90' );
				if ( ! in_array( $pro_retention, $allowed_values, true ) ) {
					$pro_retention = 'unlimited';
				}

				$pro_email_enabled        = isset( $_POST['whochanged_pro_email_enabled'] ) ? 1 : 0;
				$pro_include_system_logs  = isset( $_POST['whochanged_pro_include_system_logs'] ) ? 1 : 0;
				$delete_data_on_uninstall = isset( $_POST['whochanged_delete_data_on_uninstall'] ) ? 1 : 0;

				$pro_email_events = array();
				if ( isset( $_POST['whochanged_pro_email_events'] ) && is_array( $_POST['whochanged_pro_email_events'] ) ) {
					$pro_email_events = array_map(
						'sanitize_key',
						wp_unslash( (array) $_POST['whochanged_pro_email_events'] )
					);
				}

				$valid_email_events = array( 'theme_changed', 'installed_plugin', 'admin_role_changed' );
				$pro_email_events   = array_values( array_intersect( $pro_email_events, $valid_email_events ) );

				$pro_email_recipient = isset( $_POST['whochanged_pro_email_recipient'] )
					? sanitize_email( wp_unslash( (string) $_POST['whochanged_pro_email_recipient'] ) )
					: '';
				if ( '' === $pro_email_recipient ) {
					$pro_email_recipient = (string) get_option( 'admin_email' );
				}

				$pro_license_key = get_option( 'whochanged_pro_license_key', '' );
				if ( isset( $_POST['whochanged_pro_license_key'] ) ) {
					$pro_license_key = sanitize_text_field( wp_unslash( (string) $_POST['whochanged_pro_license_key'] ) );
				}

				$pro_allowed_roles = array();
				if ( isset( $_POST['whochanged_pro_allowed_roles'] ) && is_array( $_POST['whochanged_pro_allowed_roles'] ) ) {
					$pro_allowed_roles = array_map(
						'sanitize_key',
						wp_unslash( (array) $_POST['whochanged_pro_allowed_roles'] )
					);
				}

				$pro_viewer_roles = array();
				if ( isset( $_POST['whochanged_pro_viewer_roles'] ) && is_array( $_POST['whochanged_pro_viewer_roles'] ) ) {
					$pro_viewer_roles = array_map(
						'sanitize_key',
						wp_unslash( (array) $_POST['whochanged_pro_viewer_roles'] )
					);
				}

				update_option( 'whochanged_pro_retention_days', $pro_retention, false );
				update_option( 'whochanged_pro_email_enabled', $pro_email_enabled, false );
				update_option( 'whochanged_pro_email_events', $pro_email_events, false );
				update_option( 'whochanged_pro_email_recipient', $pro_email_recipient, false );
				update_option( 'whochanged_pro_license_key', $pro_license_key, false );
				update_option( 'whochanged_pro_allowed_roles', $pro_allowed_roles, false );
				update_option( 'whochanged_pro_viewer_roles', $pro_viewer_roles, false );
				update_option( 'whochanged_pro_include_system_logs', $pro_include_system_logs, false );
				update_option( 'whochanged_delete_data_on_uninstall', $delete_data_on_uninstall, false );

				$pro_notice      = esc_html__( 'PRO settings saved.', 'whochanged' );
				$pro_notice_type = 'success';
			} else {
				$pro_notice      = esc_html__( 'Invalid PRO settings request.', 'whochanged' );
				$pro_notice_type = 'error';
			}
		}

		$pro_retention_days       = get_option( 'whochanged_pro_retention_days', 'unlimited' );
		$pro_email_enabled        = (int) get_option( 'whochanged_pro_email_enabled', 0 );
		$pro_email_events         = get_option( 'whochanged_pro_email_events', array() );
		$pro_email_recipient      = get_option( 'whochanged_pro_email_recipient', (string) get_option( 'admin_email' ) );
		$pro_license_key          = get_option( 'whochanged_pro_license_key', '' );
		$pro_license_active       = (int) get_option( 'whochanged_pro_license_active', 0 );
		$pro_allowed_roles        = get_option( 'whochanged_pro_allowed_roles', array() );
		$pro_viewer_roles         = get_option( 'whochanged_pro_viewer_roles', array( 'administrator' ) );
		$pro_include_system_logs  = (int) get_option( 'whochanged_pro_include_system_logs', 1 );
		$delete_data_on_uninstall = (int) get_option( 'whochanged_delete_data_on_uninstall', 0 );
		$whochanged_ships_pro     = WhoChanged_Pro::ships_premium_modules();
		$whochanged_pro_active    = $whochanged_ships_pro && WhoChanged_Pro::is_active();
		$whochanged_using_legacy  = WhoChanged_Pro::is_using_legacy_license();
		$whochanged_fs_instance   = $whochanged_using_legacy ? null : whochanged_fs();
		$whochanged_plugin_file   = plugin_basename( WHOCHANGED_PLUGIN_FILE );
		$update_transient         = get_site_transient( 'update_plugins' );
		$has_whochanged_update    = is_object( $update_transient ) && isset( $update_transient->response ) && is_array( $update_transient->response ) && isset( $update_transient->response[ $whochanged_plugin_file ] );
		$whochanged_update_url    = '';
		if ( $has_whochanged_update ) {
			$whochanged_update_url = wp_nonce_url(
				self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $whochanged_plugin_file ) ),
				'upgrade-plugin_' . $whochanged_plugin_file
			);
		}
		?>
		<div class="wrap whochanged-wrap">
			<h1><?php echo esc_html__( 'Settings', 'whochanged' ); ?></h1>
			<p class="description">
				<?php
				echo $whochanged_ships_pro
					? esc_html__( 'Configure WhoChanged PRO features.', 'whochanged' )
					: esc_html__( 'Manage Free plan settings. Upgrade to WhoChanged PRO for retention controls, alerts, exports and more.', 'whochanged' );
				?>
			</p>

			<?php if ( $whochanged_ships_pro && ! $whochanged_pro_active ) : ?>
				<div class="notice notice-warning whochanged-pro-upsell">
					<p>
						<strong><?php echo esc_html__( "You're on the Free plan.", 'whochanged' ); ?></strong>
						<?php echo esc_html__( 'Retention control, email alerts, extended viewer/logging roles, and bulk log purge are PRO features. Settings below are saved but only take effect once PRO is active.', 'whochanged' ); ?>
					</p>
				</div>
			<?php elseif ( ! $whochanged_ships_pro ) : ?>
				<div class="notice notice-warning whochanged-pro-upsell">
					<p>
						<strong><?php echo esc_html__( "You're on the Free plan.", 'whochanged' ); ?></strong>
						<?php echo esc_html__( 'Retention control, email alerts, extended viewer/logging roles, bulk log purge, and XLS/PDF export are available in WhoChanged PRO.', 'whochanged' ); ?>
						<a href="<?php echo esc_url( WhoChanged_Pro::get_upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Learn more', 'whochanged' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="whochanged-pro-settings-panel">
				<h2><?php echo esc_html__( 'PRO Settings', 'whochanged' ); ?></h2>
				<?php if ( '' !== (string) $pro_notice ) : ?>
					<div class="notice <?php echo 'error' === $pro_notice_type ? 'notice-error' : 'notice-success'; ?> is-dismissible">
						<p><?php echo esc_html( $pro_notice ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="" class="whochanged-pro-settings-form">
					<input type="hidden" name="whochanged_pro_save" value="1">
					<input type="hidden" name="whochanged_pro_nonce" value="<?php echo esc_attr( wp_create_nonce( 'whochanged_pro_settings' ) ); ?>">

					<div class="whochanged-settings-grid">
						<section class="whochanged-settings-card whochanged-settings-card--wide whochanged-settings-card--updates">
							<h3><?php echo esc_html__( 'Updates & License', 'whochanged' ); ?></h3>
							<?php if ( $whochanged_using_legacy ) : ?>
								<p class="description"><?php echo esc_html__( 'Use a license key to request secured, time-limited update packages.', 'whochanged' ); ?></p>
								<div class="whochanged-settings-field">
									<label for="whochanged-pro-license-key"><?php echo esc_html__( 'License key', 'whochanged' ); ?></label>
									<?php if ( $pro_license_active ) : ?>
										<div class="whochanged-license-active-pill"><?php echo esc_html__( 'PRO Active', 'whochanged' ); ?></div>
									<?php else : ?>
										<div class="whochanged-license-inline">
											<input
												type="text"
												id="whochanged-pro-license-key"
												name="whochanged_pro_license_key"
												value="<?php echo esc_attr( (string) $pro_license_key ); ?>"
												class="regular-text"
												autocomplete="off"
												placeholder="<?php echo esc_attr__( 'Enter your license key', 'whochanged' ); ?>"
											>
											<button type="submit" class="button button-primary" name="whochanged_pro_license_activate" value="1"><?php echo esc_html__( 'Active', 'whochanged' ); ?></button>
										</div>
									<?php endif; ?>
								</div>
								<p class="description">
									<?php if ( $has_whochanged_update ) : ?>
										<a class="button button-primary" href="<?php echo esc_url( $whochanged_update_url ); ?>"><?php echo esc_html__( 'Update now', 'whochanged' ); ?></a>
									<?php endif; ?>
								</p>
							<?php else : ?>
								<p class="description"><?php echo esc_html__( 'Your license, billing and updates are managed through your account.', 'whochanged' ); ?></p>
								<div class="whochanged-settings-field">
									<?php if ( $whochanged_pro_active ) : ?>
										<div class="whochanged-license-active-pill"><?php echo esc_html__( 'PRO Active', 'whochanged' ); ?></div>
									<?php else : ?>
										<div class="whochanged-license-active-pill whochanged-license-active-pill--free"><?php echo esc_html__( 'Free plan', 'whochanged' ); ?></div>
									<?php endif; ?>
								</div>
								<p class="description whochanged-fs-account-actions">
									<?php if ( is_object( $whochanged_fs_instance ) && method_exists( $whochanged_fs_instance, 'get_account_url' ) ) : ?>
										<a class="button" href="<?php echo esc_url( $whochanged_fs_instance->get_account_url() ); ?>"><?php echo esc_html__( 'Manage Account', 'whochanged' ); ?></a>
									<?php endif; ?>
									<?php if ( ! $whochanged_pro_active && is_object( $whochanged_fs_instance ) && method_exists( $whochanged_fs_instance, 'get_upgrade_url' ) ) : ?>
										<a class="button button-primary" href="<?php echo esc_url( $whochanged_fs_instance->get_upgrade_url() ); ?>"><?php echo esc_html__( 'Upgrade to PRO', 'whochanged' ); ?></a>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</section>

						<?php if ( $whochanged_ships_pro ) : ?>
						<section class="whochanged-settings-card">
							<h3>
								<?php echo esc_html__( 'Retention', 'whochanged' ); ?>
								<?php
								if ( ! $whochanged_pro_active ) :
									?>
									<span class="whochanged-pro-badge"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span><?php endif; ?>
							</h3>
							<?php if ( $whochanged_pro_active ) : ?>
								<p class="description"><?php echo esc_html__( 'Choose how long activity logs are kept before cleanup.', 'whochanged' ); ?></p>
								<div class="whochanged-settings-field">
									<label for="whochanged-pro-retention"><?php echo esc_html__( 'Keep logs for', 'whochanged' ); ?></label>
									<select id="whochanged-pro-retention" name="whochanged_pro_retention_days">
										<option value="unlimited" <?php selected( $pro_retention_days, 'unlimited' ); ?>><?php echo esc_html__( 'Unlimited', 'whochanged' ); ?></option>
										<option value="7" <?php selected( (string) $pro_retention_days, '7' ); ?>><?php echo esc_html__( '7 days', 'whochanged' ); ?></option>
										<option value="30" <?php selected( (string) $pro_retention_days, '30' ); ?>><?php echo esc_html__( '30 days', 'whochanged' ); ?></option>
										<option value="60" <?php selected( (string) $pro_retention_days, '60' ); ?>><?php echo esc_html__( '60 days', 'whochanged' ); ?></option>
										<option value="90" <?php selected( (string) $pro_retention_days, '90' ); ?>><?php echo esc_html__( '90 days', 'whochanged' ); ?></option>
									</select>
								</div>
							<?php else : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %d: number of days logs are kept on the Free plan. */
										esc_html__( 'Free plan logs are automatically cleaned up after %d days. Upgrade to PRO to keep logs longer or forever.', 'whochanged' ),
										absint( defined( 'WHOCHANGED_FREE_RETENTION_DAYS' ) ? WHOCHANGED_FREE_RETENTION_DAYS : 30 )
									);
									?>
								</p>
								<div class="whochanged-settings-field">
									<label for="whochanged-pro-retention"><?php echo esc_html__( 'Keep logs for', 'whochanged' ); ?></label>
									<select id="whochanged-pro-retention" disabled>
										<option><?php echo esc_html__( '30 days (Free plan)', 'whochanged' ); ?></option>
									</select>
								</div>
							<?php endif; ?>
						</section>

						<section class="whochanged-settings-card">
							<h3>
								<?php echo esc_html__( 'Email Alerts', 'whochanged' ); ?>
								<?php
								if ( ! $whochanged_pro_active ) :
									?>
									<span class="whochanged-pro-badge"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span><?php endif; ?>
							</h3>
							<p class="description"><?php echo esc_html__( 'Send notifications when important events are detected.', 'whochanged' ); ?></p>

							<label class="whochanged-check-row">
								<input type="checkbox" name="whochanged_pro_email_enabled" value="1" <?php checked( 1, $pro_email_enabled ); ?> <?php disabled( ! $whochanged_pro_active ); ?>>
								<span><?php echo esc_html__( 'Enable email notifications', 'whochanged' ); ?></span>
							</label>

							<div class="whochanged-settings-field">
								<label><?php echo esc_html__( 'Notify for', 'whochanged' ); ?></label>
								<div class="whochanged-check-grid">
									<label class="whochanged-check-row"><input type="checkbox" name="whochanged_pro_email_events[]" value="theme_changed" <?php checked( in_array( 'theme_changed', (array) $pro_email_events, true ), true ); ?> <?php disabled( ! $whochanged_pro_active ); ?>> <span><?php echo esc_html__( 'Theme switched', 'whochanged' ); ?></span></label>
									<label class="whochanged-check-row"><input type="checkbox" name="whochanged_pro_email_events[]" value="installed_plugin" <?php checked( in_array( 'installed_plugin', (array) $pro_email_events, true ), true ); ?> <?php disabled( ! $whochanged_pro_active ); ?>> <span><?php echo esc_html__( 'Plugin installed', 'whochanged' ); ?></span></label>
									<label class="whochanged-check-row"><input type="checkbox" name="whochanged_pro_email_events[]" value="admin_role_changed" <?php checked( in_array( 'admin_role_changed', (array) $pro_email_events, true ), true ); ?> <?php disabled( ! $whochanged_pro_active ); ?>> <span><?php echo esc_html__( 'Admin role changed', 'whochanged' ); ?></span></label>
								</div>
							</div>

							<div class="whochanged-settings-field">
								<label for="whochanged-pro-email-recipient"><?php echo esc_html__( 'Recipient email', 'whochanged' ); ?></label>
								<input
									type="email"
									id="whochanged-pro-email-recipient"
									name="whochanged_pro_email_recipient"
									value="<?php echo esc_attr( (string) $pro_email_recipient ); ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr__( 'admin@yourdomain.com', 'whochanged' ); ?>"
									<?php disabled( ! $whochanged_pro_active ); ?>
								>
								<p class="description">
									<button type="submit" class="button button-secondary" name="whochanged_pro_send_test" value="1" <?php disabled( ! $whochanged_pro_active ); ?>>
										<?php echo esc_html__( 'Send test alert now', 'whochanged' ); ?>
									</button>
									<?php if ( ! $whochanged_pro_active ) : ?>
										<span class="whochanged-pro-badge whochanged-pro-badge--inline"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span>
									<?php endif; ?>
								</p>
							</div>
						</section>

						<section class="whochanged-settings-card">
							<h3>
								<?php echo esc_html__( 'Logging Scope', 'whochanged' ); ?>
								<?php
								if ( ! $whochanged_pro_active ) :
									?>
									<span class="whochanged-pro-badge"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span><?php endif; ?>
							</h3>
							<p class="description">
								<?php
								echo $whochanged_pro_active
									? esc_html__( 'Control which roles are included in activity logging.', 'whochanged' )
									: esc_html__( 'All users are logged on the Free plan. Upgrade to PRO to log only specific roles.', 'whochanged' );
								?>
							</p>

							<div class="whochanged-settings-field">
								<label><?php echo esc_html__( 'Log only these user roles', 'whochanged' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Leave empty to log all users.', 'whochanged' ); ?></p>
								<div class="whochanged-check-grid">
									<?php
									$editable_roles = get_editable_roles();
									if ( empty( $editable_roles ) ) :
										echo '<span>' . esc_html__( 'No roles found.', 'whochanged' ) . '</span>';
									else :
										foreach ( $editable_roles as $role_slug => $role_info ) :
											$checked = in_array( (string) $role_slug, (array) $pro_allowed_roles, true );
											?>
											<label class="whochanged-check-row">
												<input type="checkbox" name="whochanged_pro_allowed_roles[]" value="<?php echo esc_attr( (string) $role_slug ); ?>" <?php checked( $checked ); ?> <?php disabled( ! $whochanged_pro_active ); ?>>
												<span><?php echo esc_html( (string) $role_info['name'] ); ?></span>
											</label>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</div>

						</section>

						<section class="whochanged-settings-card">
							<h3>
								<?php echo esc_html__( 'Viewer Access', 'whochanged' ); ?>
								<?php
								if ( ! $whochanged_pro_active ) :
									?>
									<span class="whochanged-pro-badge"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span><?php endif; ?>
							</h3>
							<p class="description">
								<?php
								echo $whochanged_pro_active
									? esc_html__( 'Choose which roles can view WhoChanged activity and statistics.', 'whochanged' )
									: esc_html__( 'Only Administrators can view logs on the Free plan. Upgrade to PRO to grant access to other roles.', 'whochanged' );
								?>
							</p>
							<div class="whochanged-settings-field">
								<label><?php echo esc_html__( 'Roles allowed to view logs', 'whochanged' ); ?></label>
								<p class="description"><?php echo esc_html__( 'Administrators always have access for safety.', 'whochanged' ); ?></p>
								<div class="whochanged-check-grid">
									<?php
									$editable_roles = get_editable_roles();
									if ( empty( $editable_roles ) ) :
										echo '<span>' . esc_html__( 'No roles found.', 'whochanged' ) . '</span>';
									else :
										foreach ( $editable_roles as $role_slug => $role_info ) :
											$checked = in_array( (string) $role_slug, (array) $pro_viewer_roles, true );
											?>
											<label class="whochanged-check-row">
												<input type="checkbox" name="whochanged_pro_viewer_roles[]" value="<?php echo esc_attr( (string) $role_slug ); ?>" <?php checked( $checked ); ?> <?php disabled( ! $whochanged_pro_active ); ?>>
												<span><?php echo esc_html( (string) $role_info['name'] ); ?></span>
											</label>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</div>
						</section>
						<?php else : ?>
						<section class="whochanged-settings-card whochanged-settings-card--wide">
							<h3>
								<?php echo esc_html__( 'PRO features', 'whochanged' ); ?>
								<span class="whochanged-pro-badge"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span>
							</h3>
							<p class="description"><?php echo esc_html__( 'These features are available in WhoChanged PRO (separate premium package):', 'whochanged' ); ?></p>
							<ul class="whochanged-pro-feature-list">
								<li><?php echo esc_html__( 'Configurable log retention (or unlimited) instead of the fixed Free retention window.', 'whochanged' ); ?></li>
								<li><?php echo esc_html__( 'Email alerts for important events (theme switches, plugin installs, admin role changes).', 'whochanged' ); ?></li>
								<li><?php echo esc_html__( 'Restrict activity logging to specific user roles.', 'whochanged' ); ?></li>
								<li><?php echo esc_html__( 'Grant activity log viewer access to non-Administrator roles.', 'whochanged' ); ?></li>
								<li><?php echo esc_html__( 'Excel (XLS) and PDF export of activity logs and statistics.', 'whochanged' ); ?></li>
								<li><?php echo esc_html__( 'Bulk purge of all activity logs.', 'whochanged' ); ?></li>
							</ul>
							<p class="description">
								<a class="button button-primary" href="<?php echo esc_url( WhoChanged_Pro::get_upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Get WhoChanged PRO', 'whochanged' ); ?></a>
							</p>
						</section>
						<?php endif; ?>

						<section class="whochanged-settings-card">
							<h3><?php echo esc_html__( 'System Logs', 'whochanged' ); ?></h3>
							<p class="description"><?php echo esc_html__( 'Choose whether background/system events are included.', 'whochanged' ); ?></p>
							<label class="whochanged-check-row">
								<input type="checkbox" name="whochanged_pro_include_system_logs" value="1" <?php checked( 1, $pro_include_system_logs ); ?>>
								<span><?php echo esc_html__( 'Include system logs (background events)', 'whochanged' ); ?></span>
							</label>
						</section>

						<section class="whochanged-settings-card whochanged-settings-card--wide whochanged-settings-card--danger">
							<h3><?php echo esc_html__( 'Danger Zone', 'whochanged' ); ?></h3>
							<p class="description">
								<?php echo esc_html__( 'Deleting all data on uninstall is available on every plan.', 'whochanged' ); ?>
							</p>
							<div class="whochanged-settings-field">
								<label class="whochanged-check-row">
									<input type="checkbox" name="whochanged_delete_data_on_uninstall" value="1" <?php checked( 1, $delete_data_on_uninstall ); ?>>
									<span><?php echo esc_html__( 'Delete all WhoChanged data (logs and settings) when the plugin is deleted.', 'whochanged' ); ?></span>
								</label>
								<p class="description"><?php echo esc_html__( 'Applies when the plugin is removed via Plugins → Delete, not on simple deactivation. Saved together with the PRO settings below.', 'whochanged' ); ?></p>
							</div>
							<?php if ( $whochanged_ships_pro ) : ?>
								<p class="description"><?php echo esc_html__( 'Permanently delete all activity logs. This action cannot be undone.', 'whochanged' ); ?></p>
								<div class="whochanged-settings-field">
									<label class="whochanged-check-row">
										<input type="checkbox" name="whochanged_pro_purge_checkbox" value="1">
										<span><?php echo esc_html__( 'I understand this will permanently remove all logs.', 'whochanged' ); ?></span>
									</label>
								</div>
								<div class="whochanged-settings-field">
									<label for="whochanged-pro-purge-confirm"><?php echo esc_html__( 'Type to confirm', 'whochanged' ); ?>: <code>PURGE ALL ACTIVITY LOGS</code></label>
									<input type="text" id="whochanged-pro-purge-confirm" name="whochanged_pro_purge_confirm_text" class="regular-text" autocomplete="off">
								</div>
								<p class="description">
									<button type="submit" class="button button-secondary whochanged-button-danger" name="whochanged_pro_purge_all" value="1" <?php disabled( ! $whochanged_pro_active ); ?>>
										<?php echo esc_html__( 'Purge All Activity Log', 'whochanged' ); ?>
									</button>
									<?php if ( ! $whochanged_pro_active ) : ?>
										<span class="whochanged-pro-badge"><?php echo esc_html__( 'PRO', 'whochanged' ); ?></span>
									<?php endif; ?>
								</p>
								<div class="whochanged-purge-feedback" data-whochanged-purge-feedback aria-live="polite"></div>
							<?php else : ?>
								<p class="description">
									<?php echo esc_html__( 'Bulk-purging all activity logs is available in WhoChanged PRO.', 'whochanged' ); ?>
									<a class="button button-secondary" href="<?php echo esc_url( WhoChanged_Pro::get_upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Get WhoChanged PRO', 'whochanged' ); ?></a>
								</p>
							<?php endif; ?>
						</section>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save PRO settings', 'whochanged' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render statistics page (submenu).
	 *
	 * @return void
	 */
	public function render_statistics_page() {
		if ( ! $this->current_user_can_view_logs() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whochanged' ) );
		}

		$page        = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$filter_user = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$action_type = isset( $_GET['action_type'] ) ? sanitize_key( wp_unslash( $_GET['action_type'] ) ) : '';
		$log_tab     = isset( $_GET['whochanged_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_tab'] ) ) : '';

		if ( ! in_array( $log_tab, array( 'user', 'system' ), true ) ) {
			$log_tab = 'user';
		}

		// Legacy checkbox URL: treat as System tab when no explicit tab.
		if ( 'user' === $log_tab && isset( $_GET['whochanged_show_system'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['whochanged_show_system'] ) ) && ! isset( $_GET['whochanged_tab'] ) ) {
			$log_tab = 'system';
		}

		$nonce = isset( $_GET['whochanged_filter_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['whochanged_filter_nonce'] ) ) : '';
		if ( isset( $_GET['whochanged_filter_nonce'] ) && ! wp_verify_nonce( $nonce, 'whochanged_filter' ) ) {
			$filter_user = 0;
			$action_type = '';
			$log_tab     = 'user';
		}

		$date_state = $this->parse_log_date_filter_state();
		$search_q   = isset( $_GET['whochanged_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['whochanged_q'] ) ) : '';

		if ( 'system' === $log_tab ) {
			$filter_user = 0;
		}

		$action_types = $this->logger->get_action_types();
		$users        = get_users(
			array(
				'fields'  => array( 'ID', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$analytics = $this->logger->get_analytics_counts( $filter_user, $action_type, $log_tab, $date_state['from'], $date_state['to'], $search_q );

		$top_action_rows = isset( $analytics['top_action_types'] ) && is_array( $analytics['top_action_types'] ) ? $analytics['top_action_types'] : array();
		$action_labels   = array();
		$action_values   = array();
		foreach ( $top_action_rows as $row ) {
			$key = isset( $row['action_type'] ) ? sanitize_key( (string) $row['action_type'] ) : '';
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( '' === $key ) {
				continue;
			}
			$action_labels[] = $this->humanize_action_type_label( $key );
			$action_values[] = $c;
		}
		$action_other_count = max( 0, ( isset( $analytics['unique_action_types'] ) ? (int) $analytics['unique_action_types'] : 0 ) - count( $action_labels ) );

		$top_user_rows = isset( $analytics['top_users'] ) && is_array( $analytics['top_users'] ) ? $analytics['top_users'] : array();
		$user_labels   = array();
		$user_values   = array();
		foreach ( $top_user_rows as $row ) {
			$uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( 0 === $uid ) {
				$user_labels[] = __( 'System', 'whochanged' );
			} else {
				$u             = get_user_by( 'id', $uid );
				$user_labels[] = $u ? (string) $u->display_name : __( 'Unknown', 'whochanged' );
			}
			$user_values[] = $c;
		}
		$user_other_count = max( 0, ( isset( $analytics['unique_users'] ) ? (int) $analytics['unique_users'] : 0 ) - count( $user_labels ) );

		$top_object_rows = isset( $analytics['top_object_types'] ) && is_array( $analytics['top_object_types'] ) ? $analytics['top_object_types'] : array();
		$object_labels   = array();
		$object_values   = array();
		foreach ( $top_object_rows as $row ) {
			$ot = isset( $row['object_type'] ) ? sanitize_key( (string) $row['object_type'] ) : '';
			$c  = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( '' === $ot ) {
				continue;
			}
			$object_labels[] = ucwords( str_replace( '_', ' ', $ot ) );
			$object_values[] = $c;
		}

		$top_days = isset( $analytics['top_days'] ) && is_array( $analytics['top_days'] ) ? $analytics['top_days'] : array();
		usort(
			$top_days,
			function ( $a, $b ) {
				$da = isset( $a['day'] ) ? (string) $a['day'] : '';
				$db = isset( $b['day'] ) ? (string) $b['day'] : '';
				return strcmp( $da, $db );
			}
		);
		$days_labels = array();
		$days_values = array();
		foreach ( $top_days as $row ) {
			$day = isset( $row['day'] ) ? (string) $row['day'] : '';
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( '' === $day ) {
				continue;
			}
			$days_labels[] = date_i18n( 'M j', strtotime( $day . ' 00:00:00' ) );
			$days_values[] = $c;
		}

		// Hour-of-day distribution (0–23, site-local time), zero-filled so the
		// chart always shows a full 24-hour axis even when some hours are quiet.
		$top_hour_rows = isset( $analytics['top_hours'] ) && is_array( $analytics['top_hours'] ) ? $analytics['top_hours'] : array();
		$hours_values  = array_fill( 0, 24, 0 );
		foreach ( $top_hour_rows as $row ) {
			$h = isset( $row['h'] ) ? absint( $row['h'] ) : -1;
			$c = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( $h >= 0 && $h <= 23 ) {
				$hours_values[ $h ] = $c;
			}
		}
		$hours_labels = array();
		for ( $h = 0; $h <= 23; $h++ ) {
			$hours_labels[] = date_i18n( 'ga', mktime( $h, 0, 0, 1, 1, 2000 ) ); // e.g. "2am", "2pm" — locale-aware, no timezone conversion (already localized above).
		}

		// Weekday distribution (Mon..Sun, site-local), derived from the same
		// top_days rows as the trend chart — no extra query needed.
		$weekday_values = array_fill( 0, 7, 0 );
		$weekday_labels = array();
		for ( $wd = 0; $wd <= 6; $wd++ ) {
			// Jan 1, 2024 was a Monday, so day-of-month (1 + $wd) walks Mon..Sun
			// in order — a locale-safe way to get translated weekday abbreviations
			// without touching global locale state.
			$weekday_labels[] = date_i18n( 'D', mktime( 0, 0, 0, 1, 1 + $wd, 2024 ) );
		}
		foreach ( $top_days as $row ) {
			$day = isset( $row['day'] ) ? (string) $row['day'] : '';
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( '' === $day ) {
				continue;
			}
			$iso_weekday                         = (int) date_i18n( 'N', strtotime( $day . ' 00:00:00' ) ); // 1 (Mon) .. 7 (Sun).
			$weekday_values[ $iso_weekday - 1 ] += $c;
		}

		// Weekday × hour heatmap (site-local), Monday-first rows. MySQL's
		// DAYOFWEEK() returns 1 (Sun) .. 7 (Sat); +5 mod 7 remaps it to a
		// Monday-first index (0..6) matching $weekday_labels above.
		$heatmap_rows = isset( $analytics['heatmap'] ) && is_array( $analytics['heatmap'] ) ? $analytics['heatmap'] : array();
		$heatmap_grid = array();
		for ( $wd = 0; $wd <= 6; $wd++ ) {
			$heatmap_grid[ $wd ] = array_fill( 0, 24, 0 );
		}
		$heatmap_max = 0;
		foreach ( $heatmap_rows as $row ) {
			$dow = isset( $row['dow'] ) ? absint( $row['dow'] ) : 0;
			$h   = isset( $row['h'] ) ? absint( $row['h'] ) : -1;
			$c   = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( $dow < 1 || $dow > 7 || $h < 0 || $h > 23 ) {
				continue;
			}
			$wd_index                        = ( $dow + 5 ) % 7;
			$heatmap_grid[ $wd_index ][ $h ] = $c;
			$heatmap_max                     = max( $heatmap_max, $c );
		}

		// Most-changed specific items — one level more concrete than "top object
		// types" (e.g. "Post: Homepage" rather than just "Post").
		$top_changed_rows = isset( $analytics['top_changed_items'] ) && is_array( $analytics['top_changed_items'] ) ? $analytics['top_changed_items'] : array();
		$changed_items    = array();
		foreach ( $top_changed_rows as $row ) {
			$obj_type = isset( $row['object_type'] ) ? sanitize_key( (string) $row['object_type'] ) : '';
			$obj_name = isset( $row['object_name'] ) ? sanitize_text_field( (string) $row['object_name'] ) : '';
			$c        = isset( $row['c'] ) ? (int) $row['c'] : 0;
			if ( '' === $obj_name ) {
				continue;
			}
			$changed_items[] = array(
				'label' => '' !== $obj_type ? ucwords( str_replace( '_', ' ', $obj_type ) ) . ': ' . $obj_name : $obj_name,
				'value' => $c,
			);
		}

		$whochanged_ships_pro    = WhoChanged_Pro::ships_premium_modules();
		$whochanged_pro_active   = $whochanged_ships_pro && WhoChanged_Pro::is_active();
		$whochanged_pro_lock_url = $whochanged_ships_pro
			? admin_url( 'admin.php?page=' . $this->settings_slug )
			: WhoChanged_Pro::get_upgrade_url();

		// Period-over-period trend for the headline metric.
		$total_items          = isset( $analytics['total_items'] ) ? (int) $analytics['total_items'] : 0;
		$previous_total_items = isset( $analytics['previous_total_items'] ) ? (int) $analytics['previous_total_items'] : 0;
		$previous_available   = ! empty( $analytics['previous_available'] );
		$trend_direction      = 'flat';
		$trend_percent        = 0;
		if ( $previous_available ) {
			if ( $previous_total_items > 0 ) {
				$trend_percent = (int) round( ( ( $total_items - $previous_total_items ) / $previous_total_items ) * 100 );
			} elseif ( $total_items > 0 ) {
				$trend_percent = 100;
			}
			if ( $trend_percent > 0 ) {
				$trend_direction = 'up';
			} elseif ( $trend_percent < 0 ) {
				$trend_direction = 'down';
			}
		}

		// Plain-language insight strip: busiest weekday, dominant action type,
		// and peak hour — the three questions admins actually ask a log for.
		$insight_parts = array();
		if ( $total_items > 0 ) {
			if ( ! empty( $action_labels ) && ! empty( $action_values ) ) {
				$top_action_share = (int) round( ( $action_values[0] / max( 1, $total_items ) ) * 100 );
				if ( $top_action_share >= 20 ) {
					$insight_parts[] = sprintf(
						/* translators: 1: percentage, 2: action type label. */
						__( '%1$d%% of activity is %2$s', 'whochanged' ),
						$top_action_share,
						$action_labels[0]
					);
				}
			}

			if ( max( $weekday_values ) > 0 ) {
				$busiest_weekday_index = array_keys( $weekday_values, max( $weekday_values ), true )[0];
				$insight_parts[]       = sprintf(
					/* translators: %s: weekday name, e.g. "Wednesday". */
					__( 'busiest on %s', 'whochanged' ),
					date_i18n( 'l', mktime( 0, 0, 0, 1, 1 + $busiest_weekday_index, 2024 ) )
				);
			}

			$peak_hour_index = array_keys( $hours_values, max( $hours_values ), true )[0];
			if ( max( $hours_values ) > 0 ) {
				$insight_parts[] = sprintf(
					/* translators: %s: hour of day, e.g. "10am". */
					__( 'peaks around %s', 'whochanged' ),
					$hours_labels[ $peak_hour_index ]
				);
			}
		}
		$insight_text = ! empty( $insight_parts ) ? ucfirst( implode( ', ', $insight_parts ) ) . '.' : '';

		// Anomaly detection: flag a day whose count is a statistical outlier
		// (more than 2 standard deviations above the mean) so an admin scanning
		// the dashboard doesn't have to eyeball the trend chart to spot a spike.
		// Needs a reasonable sample size and non-trivial volume to avoid noise
		// on quiet sites.
		$anomaly_text = '';
		if ( count( $days_values ) >= 5 ) {
			$day_count = count( $days_values );
			$mean      = array_sum( $days_values ) / $day_count;
			$variance  = 0.0;
			foreach ( $days_values as $v ) {
				$variance += ( $v - $mean ) * ( $v - $mean );
			}
			$std_dev = sqrt( $variance / $day_count );

			if ( $std_dev > 0 ) {
				$best_index = -1;
				$best_value = 0;
				foreach ( $days_values as $i => $v ) {
					if ( $v >= 3 && $v > ( $mean + 2 * $std_dev ) && $v > $best_value ) {
						$best_index = $i;
						$best_value = $v;
					}
				}
				if ( $best_index >= 0 && $mean > 0 ) {
					$anomaly_text = sprintf(
						/* translators: 1: date label, e.g. "Jul 20", 2: multiplier, e.g. "3.2". */
						__( 'Unusual spike on %1$s — %2$sx the average daily activity.', 'whochanged' ),
						$days_labels[ $best_index ],
						number_format_i18n( round( $best_value / $mean, 1 ), 1 )
					);
				}
			}
		}

		// Average activity per day, and how far back the visible history goes —
		// uses the selected range length when one is active, otherwise the span
		// from the earliest matching record to today.
		$first_activity_gmt  = isset( $analytics['first_activity_gmt'] ) ? (string) $analytics['first_activity_gmt'] : '';
		$tracking_since_text = '';
		$days_span           = 1;
		try {
			if ( '' !== $date_state['from'] && '' !== $date_state['to'] ) {
				$range_from = new DateTime( $date_state['from'] );
				$range_to   = new DateTime( $date_state['to'] );
				$days_span  = max( 1, (int) $range_from->diff( $range_to )->days + 1 );
			} elseif ( '' !== $first_activity_gmt ) {
				$first_local_ymd = get_date_from_gmt( $first_activity_gmt, 'Y-m-d' );
				$range_from      = new DateTime( $first_local_ymd );
				$range_to        = new DateTime( current_time( 'Y-m-d' ) );
				$days_span       = max( 1, (int) $range_from->diff( $range_to )->days + 1 );
			}
		} catch ( Exception $e ) {
			$days_span = 1;
		}
		if ( '' !== $first_activity_gmt ) {
			$first_local_ts = strtotime( get_date_from_gmt( $first_activity_gmt, 'Y-m-d H:i:s' ) );
			if ( false !== $first_local_ts ) {
				$tracking_since_text = sprintf(
					/* translators: %s: date the earliest matching log entry was recorded. */
					__( 'Tracking since %s', 'whochanged' ),
					date_i18n( get_option( 'date_format' ), $first_local_ts )
				);
			}
		}
		$avg_per_day = $total_items > 0 ? round( $total_items / $days_span, 1 ) : 0;

		// Shared palette so the doughnut charts and their server-rendered legend
		// lists always agree on which color maps to which slice.
		$chart_palette = array( '#2271b1', '#00a32a', '#d63638', '#dba617', '#8b5cf6', '#0891b2', '#db2777', '#65a30d' );

		?>
		<div class="wrap whochanged-wrap whochanged-statistics-wrap">
			<h1><?php echo esc_html__( 'Statistics', 'whochanged' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Charts and insights based on the selected filters.', 'whochanged' ); ?>
				<?php if ( '' !== $tracking_since_text ) : ?>
					<span class="whochanged-stat-tracking-since"> · <?php echo esc_html( $tracking_since_text ); ?></span>
				<?php endif; ?>
			</p>

			<div class="whochanged-filters-bar">
				<div class="whochanged-filters-row">
					<form method="get" class="whochanged-filters">
						<input type="hidden" name="page" value="<?php echo esc_attr( $this->statistics_slug ); ?>">
						<input type="hidden" name="whochanged_tab" value="<?php echo esc_attr( $log_tab ); ?>">
						<?php wp_nonce_field( 'whochanged_filter', 'whochanged_filter_nonce' ); ?>

						<?php if ( 'system' === $log_tab ) : ?>
							<input type="hidden" name="user_id" value="0">
						<?php endif; ?>

						<label for="whochanged-search" class="screen-reader-text"><?php echo esc_html__( 'Search', 'whochanged' ); ?></label>
						<input
							type="text"
							id="whochanged-search"
							name="whochanged_q"
							class="whochanged-search-input"
							value="<?php echo esc_attr( $search_q ); ?>"
							placeholder="<?php echo esc_attr__( 'Search logs…', 'whochanged' ); ?>"
							autocomplete="off"
						>

						<label for="whochanged-user-id" class="screen-reader-text"><?php echo esc_html__( 'User', 'whochanged' ); ?></label>
						<select id="whochanged-user-id"<?php echo 'system' === $log_tab ? ' disabled="disabled"' : ' name="user_id"'; ?>>
							<option value="0"><?php echo esc_html__( 'All users', 'whochanged' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $filter_user, (int) $user->ID ); ?>>
									<?php echo esc_html( $user->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<label for="whochanged-action-type" class="screen-reader-text"><?php echo esc_html__( 'Action', 'whochanged' ); ?></label>
						<select id="whochanged-action-type" name="action_type">
							<option value=""><?php echo esc_html__( 'All actions', 'whochanged' ); ?></option>
							<?php foreach ( $action_types as $action ) : ?>
								<option value="<?php echo esc_attr( $action ); ?>" <?php selected( $action_type, $action ); ?>>
									<?php echo esc_html( $this->humanize_action_type_label( $action ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<?php submit_button( esc_html__( 'Go', 'whochanged' ), 'secondary', 'submit', false ); ?>
					</form>

					<div class="whochanged-date-wrap whochanged-date-range">
						<label for="whochanged-range-preset" class="whochanged-range-label"><?php echo esc_html__( 'Time', 'whochanged' ); ?></label>
						<select id="whochanged-range-preset" class="whochanged-range-preset" autocomplete="off">
							<option value="all" <?php selected( $date_state['dr'], 'all' ); ?>><?php echo esc_html__( 'All time', 'whochanged' ); ?></option>
							<option value="today" <?php selected( $date_state['dr'], 'today' ); ?>><?php echo esc_html__( 'Today', 'whochanged' ); ?></option>
							<option value="yesterday" <?php selected( $date_state['dr'], 'yesterday' ); ?>><?php echo esc_html__( 'Yesterday', 'whochanged' ); ?></option>
							<option value="7d" <?php selected( $date_state['dr'], '7d' ); ?>><?php echo esc_html__( 'Last 7 days', 'whochanged' ); ?></option>
							<option value="30d" <?php selected( $date_state['dr'], '30d' ); ?>><?php echo esc_html__( 'Last 30 days', 'whochanged' ); ?></option>
							<option value="custom" <?php selected( $date_state['dr'], 'custom' ); ?>><?php echo esc_html__( 'Custom range…', 'whochanged' ); ?></option>
						</select>

						<span class="whochanged-custom-range<?php echo 'custom' === $date_state['dr'] ? '' : ' is-collapsed'; ?>">
							<label for="whochanged-range-calendar" class="whochanged-custom-range-label screen-reader-text"><?php echo esc_html__( 'Custom date range', 'whochanged' ); ?></label>
							<input
								type="text"
								id="whochanged-range-calendar"
								class="whochanged-range-calendar-input"
								readonly
								autocomplete="off"
								placeholder="<?php echo esc_attr__( 'Click to choose dates on the calendar…', 'whochanged' ); ?>"
								data-df="<?php echo esc_attr( $date_state['df'] ); ?>"
								data-dt="<?php echo esc_attr( $date_state['dt'] ); ?>"
							>
							<button type="button" class="button button-small whochanged-range-apply"><?php echo esc_html__( 'Apply', 'whochanged' ); ?></button>
						</span>
					</div>
				</div>
			</div>

			<div class="whochanged-log-tabs-row">
				<nav class="nav-tab-wrapper whochanged-log-tabs wp-clearfix" aria-label="<?php echo esc_attr__( 'Activity source', 'whochanged' ); ?>">
					<a
						href="<?php echo esc_url( $this->build_log_tab_url( 'user', $filter_user, $action_type, 1, $date_state, $search_q, $this->statistics_slug ) ); ?>"
						class="nav-tab whochanged-log-tab <?php echo 'user' === $log_tab ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html__( 'User', 'whochanged' ); ?></a>
					<a
						href="<?php echo esc_url( $this->build_log_tab_url( 'system', $filter_user, $action_type, 1, $date_state, $search_q, $this->statistics_slug ) ); ?>"
						class="nav-tab whochanged-log-tab <?php echo 'system' === $log_tab ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html__( 'System', 'whochanged' ); ?></a>
				</nav>
				<div class="whochanged-log-tabs-actions">
					<?php if ( $whochanged_pro_active ) : ?>
						<button type="button" class="button button-secondary whochanged-stat-export-pdf-btn">
							<?php echo esc_html__( 'Export Statistics PDF', 'whochanged' ); ?>
						</button>
					<?php else : ?>
						<a
							class="button button-secondary whochanged-pro-locked"
							href="<?php echo esc_url( $whochanged_pro_lock_url ); ?>"
							<?php echo $whochanged_ships_pro ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>
							title="<?php echo esc_attr__( 'Available in WhoChanged PRO.', 'whochanged' ); ?>"
						><?php echo esc_html__( 'Export Statistics PDF', 'whochanged' ); ?> <span class="whochanged-pro-badge whochanged-pro-badge--inline"><?php esc_html_e( 'PRO', 'whochanged' ); ?></span></a>
					<?php endif; ?>
				</div>
			</div>

			<div class="whochanged-stat-metrics">
				<div class="whochanged-stat-metric whochanged-stat-metric--hero">
					<div class="whochanged-stat-metric-label"><?php echo esc_html__( 'Total items', 'whochanged' ); ?></div>
					<div class="whochanged-stat-metric-value"><?php echo esc_html( number_format_i18n( $total_items ) ); ?></div>
					<?php if ( $previous_available && 'flat' !== $trend_direction ) : ?>
						<div class="whochanged-stat-trend whochanged-stat-trend--<?php echo esc_attr( $trend_direction ); ?>">
							<span class="whochanged-stat-trend-arrow" aria-hidden="true"><?php echo 'up' === $trend_direction ? '▲' : '▼'; ?></span>
							<?php
							printf(
								/* translators: %d: absolute percentage change vs. the previous period. */
								esc_html__( '%d%% vs. previous period', 'whochanged' ),
								absint( abs( $trend_percent ) )
							);
							?>
						</div>
					<?php elseif ( $previous_available ) : ?>
						<div class="whochanged-stat-trend whochanged-stat-trend--flat"><?php echo esc_html__( 'Same as previous period', 'whochanged' ); ?></div>
					<?php endif; ?>
				</div>
				<div class="whochanged-stat-metric">
					<div class="whochanged-stat-metric-label"><?php echo esc_html__( 'Unique users', 'whochanged' ); ?></div>
					<div class="whochanged-stat-metric-value"><?php echo esc_html( number_format_i18n( isset( $analytics['unique_users'] ) ? (int) $analytics['unique_users'] : 0 ) ); ?></div>
				</div>
				<div class="whochanged-stat-metric">
					<div class="whochanged-stat-metric-label"><?php echo esc_html__( 'Unique action types', 'whochanged' ); ?></div>
					<div class="whochanged-stat-metric-value"><?php echo esc_html( number_format_i18n( isset( $analytics['unique_action_types'] ) ? (int) $analytics['unique_action_types'] : 0 ) ); ?></div>
				</div>
				<div class="whochanged-stat-metric">
					<div class="whochanged-stat-metric-label"><?php echo esc_html__( 'Unique object types', 'whochanged' ); ?></div>
					<div class="whochanged-stat-metric-value"><?php echo esc_html( number_format_i18n( isset( $analytics['unique_object_types'] ) ? (int) $analytics['unique_object_types'] : 0 ) ); ?></div>
				</div>
				<div class="whochanged-stat-metric">
					<div class="whochanged-stat-metric-label"><?php echo esc_html__( 'Avg. per day', 'whochanged' ); ?></div>
					<div class="whochanged-stat-metric-value"><?php echo esc_html( number_format_i18n( $avg_per_day, 1 ) ); ?></div>
				</div>
			</div>

			<?php if ( '' !== $insight_text ) : ?>
				<div class="whochanged-stat-insight">
					<span class="whochanged-stat-insight-icon" aria-hidden="true">💡</span>
					<span><?php echo esc_html( $insight_text ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $anomaly_text ) : ?>
				<div class="whochanged-stat-insight whochanged-stat-insight--warning">
					<span class="whochanged-stat-insight-icon" aria-hidden="true">⚠️</span>
					<span><?php echo esc_html( $anomaly_text ); ?></span>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Render a "count · % of total" legend list under a doughnut chart,
			 * using the same color order as the chart's dataset so swatches line
			 * up with slices. Chart.js's own legend only shows labels — this adds
			 * the numbers that make a chart actually useful at a glance.
			 *
			 * @param array<int, string> $labels      Slice labels.
			 * @param array<int, int>    $values      Slice values (same order as $labels).
			 * @param array<int, string> $palette     Hex colors, reused cyclically.
			 * @param int                $other_count Distinct values beyond the ones shown (0 if none).
			 * @return void
			 */
			$whochanged_render_legend = function ( $labels, $values, $palette, $other_count = 0 ) {
				if ( empty( $labels ) ) {
					return;
				}
				$total = array_sum( $values );
				echo '<ul class="whochanged-stat-legend">';
				foreach ( $labels as $i => $label ) {
					$value   = isset( $values[ $i ] ) ? (int) $values[ $i ] : 0;
					$percent = $total > 0 ? round( ( $value / $total ) * 100 ) : 0;
					$color   = $palette[ $i % count( $palette ) ];
					echo '<li class="whochanged-stat-legend-row">';
					echo '<span class="whochanged-stat-legend-swatch" style="background:' . esc_attr( $color ) . '"></span>';
					echo '<span class="whochanged-stat-legend-label">' . esc_html( $label ) . '</span>';
					echo '<span class="whochanged-stat-legend-value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
					echo '<span class="whochanged-stat-legend-percent">' . esc_html( $percent . '%' ) . '</span>';
					echo '</li>';
				}
				if ( $other_count > 0 ) {
					echo '<li class="whochanged-stat-legend-row whochanged-stat-legend-row--more">';
					echo '<span>' .
						/* translators: %d: number of additional action/user types not shown in this top-6 list. */
						esc_html( sprintf( _n( '+%d more not shown', '+%d more not shown', $other_count, 'whochanged' ), $other_count ) ) .
						'</span>';
					echo '</li>';
				}
				echo '</ul>';
			};
		?>

			<div class="whochanged-statistics-grid">
				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Top action types', 'whochanged' ); ?></div>
					<div class="whochanged-stat-card-body">
						<div class="whochanged-stat-canvas whochanged-stat-canvas--doughnut">
							<canvas id="whochangedChartActions"></canvas>
						</div>
						<?php $whochanged_render_legend( $action_labels, $action_values, $chart_palette, $action_other_count ); ?>
					</div>
				</div>

				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Top users', 'whochanged' ); ?></div>
					<div class="whochanged-stat-card-body">
						<div class="whochanged-stat-canvas whochanged-stat-canvas--doughnut">
							<canvas id="whochangedChartUsers"></canvas>
						</div>
						<?php $whochanged_render_legend( $user_labels, $user_values, $chart_palette, $user_other_count ); ?>
					</div>
				</div>

				<div class="whochanged-stat-card whochanged-stat-card--wide">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Activity trend (by day)', 'whochanged' ); ?></div>
					<div class="whochanged-stat-canvas">
						<canvas id="whochangedChartDays"></canvas>
					</div>
				</div>

				<div class="whochanged-stat-card whochanged-stat-card--wide whochanged-stat-card--heatmap">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Activity heatmap', 'whochanged' ); ?></div>
					<p class="whochanged-stat-card-subtitle"><?php echo esc_html__( 'Darker cells mean more activity — spot patterns and off-hours changes at a glance.', 'whochanged' ); ?></p>
					<?php
					if ( $heatmap_max > 0 ) :
						?>
						<div class="whochanged-stat-heatmap-scroll">
							<table class="whochanged-stat-heatmap">
								<thead>
									<tr>
										<th></th>
										<?php foreach ( $hours_labels as $h => $hour_label ) : ?>
											<th class="whochanged-stat-heatmap-hour<?php echo 0 === $h % 3 ? '' : ' whochanged-stat-heatmap-hour--minor'; ?>"><?php echo 0 === $h % 3 ? esc_html( $hour_label ) : ''; ?></th>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $weekday_labels as $wd => $weekday_label ) : ?>
										<tr>
											<th class="whochanged-stat-heatmap-day"><?php echo esc_html( $weekday_label ); ?></th>
											<?php foreach ( $heatmap_grid[ $wd ] as $h => $count ) : ?>
												<?php
												$intensity  = $heatmap_max > 0 ? $count / $heatmap_max : 0;
												$text_color = $intensity > 0.45 ? '#fff' : '#1f2937';
												?>
												<td
													class="whochanged-stat-heatmap-cell"
													style="background-color: rgba(34, 113, 177, <?php echo esc_attr( (string) round( 0.08 + $intensity * 0.82, 3 ) ); ?>); color: <?php echo esc_attr( $text_color ); ?>;"
													title="<?php echo esc_attr( sprintf( /* translators: 1: weekday, 2: hour of day, 3: number of events. */ __( '%1$s, %2$s: %3$s', 'whochanged' ), $weekday_label, $hour_label, number_format_i18n( $count ) ) ); ?>"
												><?php echo $count > 0 ? esc_html( number_format_i18n( $count ) ) : ''; ?></td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php else : ?>
						<p class="whochanged-stat-empty"><?php echo esc_html__( 'Not enough data yet to show a heatmap.', 'whochanged' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Activity by hour of day', 'whochanged' ); ?></div>
					<p class="whochanged-stat-card-subtitle"><?php echo esc_html__( 'When changes happen — useful for spotting activity outside business hours.', 'whochanged' ); ?></p>
					<div class="whochanged-stat-canvas">
						<canvas id="whochangedChartHours"></canvas>
					</div>
				</div>

				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Activity by day of week', 'whochanged' ); ?></div>
					<p class="whochanged-stat-card-subtitle"><?php echo esc_html__( 'Which days of the week see the most changes.', 'whochanged' ); ?></p>
					<div class="whochanged-stat-canvas">
						<canvas id="whochangedChartWeekdays"></canvas>
					</div>
				</div>

				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Top object types', 'whochanged' ); ?></div>
					<div class="whochanged-stat-canvas">
						<canvas id="whochangedChartObjects"></canvas>
					</div>
				</div>

				<div class="whochanged-stat-card">
					<div class="whochanged-stat-card-title"><?php echo esc_html__( 'Most-changed items', 'whochanged' ); ?></div>
					<p class="whochanged-stat-card-subtitle"><?php echo esc_html__( 'The specific posts, pages or settings edited most often.', 'whochanged' ); ?></p>
					<?php if ( ! empty( $changed_items ) ) : ?>
						<ol class="whochanged-stat-ranked-list">
							<?php
							$changed_items_max = max( array_column( $changed_items, 'value' ) );
							foreach ( $changed_items as $item ) :
								$bar_percent = $changed_items_max > 0 ? round( ( $item['value'] / $changed_items_max ) * 100 ) : 0;
								?>
								<li class="whochanged-stat-ranked-row">
									<span class="whochanged-stat-ranked-label"><?php echo esc_html( $item['label'] ); ?></span>
									<span class="whochanged-stat-ranked-bar-track">
										<span class="whochanged-stat-ranked-bar-fill" style="--whochanged-bar-width: <?php echo esc_attr( (string) $bar_percent ); ?>%;"></span>
									</span>
									<span class="whochanged-stat-ranked-value"><?php echo esc_html( number_format_i18n( $item['value'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="whochanged-stat-empty"><?php echo esc_html__( 'No named items recorded yet for this filter.', 'whochanged' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php
			wp_localize_script(
				'whochanged-stats-charts',
				'WhoChangedStats',
				array(
					'palette'  => $chart_palette,
					'actions'  => array(
						'labels'      => $action_labels,
						'values'      => $action_values,
						'centerLabel' => $action_other_count > 0 ? __( 'Top 6', 'whochanged' ) : __( 'Total', 'whochanged' ),
					),
					'users'    => array(
						'labels'      => $user_labels,
						'values'      => $user_values,
						'centerLabel' => $user_other_count > 0 ? __( 'Top 6', 'whochanged' ) : __( 'Total', 'whochanged' ),
					),
					'objects'  => array(
						'labels' => $object_labels,
						'values' => $object_values,
					),
					'days'     => array(
						'labels' => $days_labels,
						'values' => $days_values,
					),
					'hours'    => array(
						'labels' => $hours_labels,
						'values' => $hours_values,
					),
					'weekdays' => array(
						'labels' => $weekday_labels,
						'values' => $weekday_values,
					),
					'i18n'     => array(
						'items' => __( 'Items', 'whochanged' ),
					),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Validate log date query (site calendar day).
	 *
	 * @param string $raw Raw query value.
	 * @return string Y-m-d or empty.
	 */
	private function sanitize_log_date_query( $raw ) {
		$raw = sanitize_text_field( (string) $raw );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return '';
		}
		$parts = array_map( 'absint', explode( '-', $raw ) );
		if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Parse time range from request (presets, custom from/to, legacy single date).
	 *
	 * @return array{dr:string,df:string,dt:string,from:string,to:string}
	 */
	private function parse_log_date_filter_state() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display filters, no state change; the listing page nonce is verified separately for filtering actions.
		$legacy = isset( $_GET['whochanged_date'] ) ? $this->sanitize_log_date_query( sanitize_text_field( wp_unslash( (string) $_GET['whochanged_date'] ) ) ) : '';
		$dr_raw = isset( $_GET['whochanged_dr'] ) ? sanitize_key( wp_unslash( (string) $_GET['whochanged_dr'] ) ) : '';
		$df     = isset( $_GET['whochanged_df'] ) ? $this->sanitize_log_date_query( sanitize_text_field( wp_unslash( (string) $_GET['whochanged_df'] ) ) ) : '';
		$dt     = isset( $_GET['whochanged_dt'] ) ? $this->sanitize_log_date_query( sanitize_text_field( wp_unslash( (string) $_GET['whochanged_dt'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$allowed = array( 'all', 'today', 'yesterday', '7d', '30d', 'custom' );
		$dr      = $dr_raw;

		if ( '' !== $legacy && '' === $dr_raw ) {
			$dr = 'custom';
			$df = $legacy;
			$dt = $legacy;
		}

		if ( '' === $dr || ! in_array( $dr, $allowed, true ) ) {
			$dr = 'all';
		}

		$from = '';
		$to   = '';
		$tz   = wp_timezone();

		if ( 'custom' === $dr ) {
			if ( '' !== $df && '' !== $dt ) {
				$from = $df;
				$to   = $dt;
				if ( $from > $to ) {
					$swap = $from;
					$from = $to;
					$to   = $swap;
				}
			} elseif ( '' !== $df ) {
				$from = $df;
				$to   = $df;
			} elseif ( '' !== $dt ) {
				$from = $dt;
				$to   = $dt;
			} else {
				$dr = 'all';
			}
		} elseif ( 'all' !== $dr ) {
			$now   = new DateTimeImmutable( 'now', $tz );
			$today = $now->format( 'Y-m-d' );
			switch ( $dr ) {
				case 'today':
					$from = $today;
					$to   = $today;
					break;
				case 'yesterday':
					$yesterday = $now->modify( '-1 day' );
					$from      = $yesterday->format( 'Y-m-d' );
					$to        = $from;
					break;
				case '7d':
					$to   = $today;
					$from = $now->modify( '-6 days' )->format( 'Y-m-d' );
					break;
				case '30d':
					$to   = $today;
					$from = $now->modify( '-29 days' )->format( 'Y-m-d' );
					break;
			}
		}
		// 'all' falls through with $from/$to left empty (no bounds).

		if ( 'custom' !== $dr ) {
			$df = '';
			$dt = '';
		}

		return array(
			'dr'   => $dr,
			'df'   => $df,
			'dt'   => $dt,
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Query args for pagination / redirects from parsed date state.
	 *
	 * @param array<string, string> $state From parse_log_date_filter_state().
	 * @return array<string, string>
	 */
	private function build_date_range_query_args( array $state ) {
		$args = array();
		if ( ! isset( $state['dr'] ) || 'all' === $state['dr'] ) {
			return $args;
		}
		$args['whochanged_dr'] = sanitize_key( (string) $state['dr'] );
		if ( 'custom' === $state['dr'] ) {
			if ( ! empty( $state['df'] ) ) {
				$args['whochanged_df'] = $this->sanitize_log_date_query( $state['df'] );
			}
			if ( ! empty( $state['dt'] ) ) {
				$args['whochanged_dt'] = $this->sanitize_log_date_query( $state['dt'] );
			}
		}
		return array_filter( $args );
	}

	/**
	 * Admin URL for a log list tab preserving filters and date range.
	 *
	 * @param string                $tab         user|system.
	 * @param int                   $filter_user Current user filter (forced to 0 for system tab in URL).
	 * @param string                $action_type Action filter.
	 * @param int                   $paged       Page number.
	 * @param array<string, string> $date_state Date state.
	 * @param string                $search_q    Search keyword.
	 * @param string                $page_slug   Target admin page slug (defaults to the main menu slug).
	 * @return string
	 */
	private function build_log_tab_url( $tab, $filter_user, $action_type, $paged, array $date_state, $search_q = '', $page_slug = '' ) {
		$tab = sanitize_key( (string) $tab );
		if ( ! in_array( $tab, array( 'user', 'system' ), true ) ) {
			$tab = 'user';
		}
		$page_slug = '' !== (string) $page_slug ? sanitize_key( (string) $page_slug ) : $this->menu_slug;
		$uid       = (int) $filter_user;
		if ( 'system' === $tab ) {
			$uid = 0;
		}
		$args = array_merge(
			array(
				'page'                    => $page_slug,
				'user_id'                 => $uid,
				'action_type'             => $action_type,
				'paged'                   => max( 1, (int) $paged ),
				'whochanged_tab'          => $tab,
				'whochanged_filter_nonce' => wp_create_nonce( 'whochanged_filter' ),
				'whochanged_q'            => sanitize_text_field( (string) $search_q ),
			),
			$this->build_date_range_query_args( $date_state )
		);
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render pagination links.
	 *
	 * @param int    $current_page Current page.
	 * @param int    $total_pages  Total pages.
	 * @param int    $total_items  Total items.
	 * @param int    $user_id      User filter.
	 * @param string $action_type  Action filter.
	 * @param string $log_tab      user|system.
	 * @param array  $date_state   Output of parse_log_date_filter_state().
	 * @param string $search_q     Search keyword.
	 * @param string $placement    footer (below list) or tab_bar (right of User/System tabs).
	 * @return void
	 */
	private function render_pagination( $current_page, $total_pages, $total_items, $user_id, $action_type, $log_tab = 'user', array $date_state = array(), $search_q = '', $placement = 'footer' ) {
		$current_page = max( 1, (int) $current_page );
		$total_pages  = max( 1, (int) $total_pages );
		$total_items  = max( 0, (int) $total_items );
		$log_tab      = sanitize_key( (string) $log_tab );
		if ( ! in_array( $log_tab, array( 'user', 'system' ), true ) ) {
			$log_tab = 'user';
		}
		$pagination_user_id = (int) $user_id;
		if ( 'system' === $log_tab ) {
			$pagination_user_id = 0;
		}

		$base_args = array_merge(
			array(
				'page'                    => $this->menu_slug,
				'user_id'                 => $pagination_user_id,
				'action_type'             => $action_type,
				'whochanged_tab'          => $log_tab,
				'whochanged_filter_nonce' => wp_create_nonce( 'whochanged_filter' ),
				'whochanged_q'            => sanitize_text_field( (string) $search_q ),
			),
			$this->build_date_range_query_args( $date_state )
		);

		$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

		$first_link = add_query_arg( 'paged', 1, $base_url );
		$prev_link  = add_query_arg( 'paged', max( 1, $current_page - 1 ), $base_url );
		$next_link  = add_query_arg( 'paged', min( $total_pages, $current_page + 1 ), $base_url );
		$last_link  = add_query_arg( 'paged', $total_pages, $base_url );

		$placement  = in_array( $placement, array( 'footer', 'tab_bar' ), true ) ? $placement : 'footer';
		$wrap_class = 'tablenav whochanged-pagination-wrap';
		if ( 'tab_bar' === $placement ) {
			$wrap_class .= ' whochanged-pagination-wrap--tab-bar';
		}
		$pagination_class = 'whochanged-pagination';
		if ( 'tab_bar' === $placement ) {
			$pagination_class .= ' whochanged-pagination--tab-inline';
		}

		echo '<div class="' . esc_attr( $wrap_class ) . '"><div class="' . esc_attr( $pagination_class ) . '">';
		echo '<div class="whochanged-pagination-count">';
		printf(
			/* translators: %d: total items */
			esc_html__( '%d items', 'whochanged' ),
			absint( $total_items )
		);
		echo '</div>';

		echo '<div class="whochanged-pagination-controls">';
		if ( $current_page > 1 ) {
			echo '<a class="whochanged-page-btn" href="' . esc_url( $first_link ) . '" aria-label="' . esc_attr__( 'First page', 'whochanged' ) . '">«</a>';
			echo '<a class="whochanged-page-btn" href="' . esc_url( $prev_link ) . '" aria-label="' . esc_attr__( 'Previous page', 'whochanged' ) . '">‹</a>';
		} else {
			echo '<span class="whochanged-page-btn is-disabled">«</span>';
			echo '<span class="whochanged-page-btn is-disabled">‹</span>';
		}

		echo '<span class="whochanged-page-indicator">' . esc_html( $current_page ) . ' ' . esc_html__( 'of', 'whochanged' ) . ' ' . esc_html( $total_pages ) . '</span>';

		if ( $current_page < $total_pages ) {
			echo '<a class="whochanged-page-btn" href="' . esc_url( $next_link ) . '" aria-label="' . esc_attr__( 'Next page', 'whochanged' ) . '">›</a>';
			echo '<a class="whochanged-page-btn" href="' . esc_url( $last_link ) . '" aria-label="' . esc_attr__( 'Last page', 'whochanged' ) . '">»</a>';
		} else {
			echo '<span class="whochanged-page-btn is-disabled">›</span>';
			echo '<span class="whochanged-page-btn is-disabled">»</span>';
		}
		echo '</div></div></div>';
	}

	/**
	 * Build diff HTML.
	 *
	 * @param array<string, mixed> $details Diff details.
	 * @return string
	 */
	private function render_diff_html( array $details ) {
		if ( empty( $details ) ) {
			return '<p>' . esc_html__( 'No diff details available.', 'whochanged' ) . '</p>';
		}

		$html = '<ul class="whochanged-diff-list">';
		foreach ( $details as $key => $change ) {
			$before = isset( $change['before'] ) ? $this->format_value( $change['before'] ) : 'null';
			$after  = isset( $change['after'] ) ? $this->format_value( $change['after'] ) : 'null';

			$html .= '<li class="whochanged-diff-item">';
			$html .= '<span class="whochanged-diff-key">' . esc_html( (string) $key ) . '</span>';
			$html .= '<div><span class="whochanged-before-label">' . esc_html__( 'Before:', 'whochanged' ) . '</span> ';
			$html .= '<span class="whochanged-before-value">' . esc_html( $before ) . '</span></div>';
			$html .= '<div><span class="whochanged-after-label">' . esc_html__( 'After:', 'whochanged' ) . '</span> ';
			$html .= '<span class="whochanged-after-value">' . esc_html( $after ) . '</span></div>';
			$html .= '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * Group logs by user/action/time window.
	 *
	 * Public: also used by the PRO export modules (includes/pro/) to render
	 * XLS/PDF exports from the same grouped rows as the CSV export below.
	 *
	 * @param array<int, array<string, mixed>> $items Log rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function group_logs( array $items ) {
		$groups = array();

		foreach ( $items as $item ) {
			$created_at = isset( $item['created_at'] ) ? (string) $item['created_at'] : '';
			// $created_at is stored in GMT; parse it as GMT directly instead of converting
			// to site-local time first, otherwise the resulting timestamp is shifted by the
			// site's UTC offset and wp_date() below double-applies that offset on display.
			$timestamp = '' !== $created_at ? strtotime( $created_at . ' GMT' ) : false;
			$timestamp = $timestamp ? $timestamp : time();
			$changes   = json_decode( isset( $item['changes'] ) ? (string) $item['changes'] : '[]', true );
			$details   = is_array( $changes ) ? $changes : array();
			$group_key = isset( $item['group_id'] ) && '' !== $item['group_id'] ? sanitize_text_field( (string) $item['group_id'] ) : $this->get_group_key( $item );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'id'        => absint( isset( $item['id'] ) ? $item['id'] : 0 ),
					'user_id'   => absint( isset( $item['user_id'] ) ? $item['user_id'] : 0 ),
					'time'      => wp_date( 'Y-m-d H:i:s', $timestamp ),
					'timestamp' => $timestamp,
					'count'     => 0,
					'group_key' => $group_key,
					'items'     => array(),
					'is_bulk'   => false,
				);
			}

			$groups[ $group_key ]['count']  += 1;
			$groups[ $group_key ]['items'][] = array(
				'type'         => isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '',
				'action_label' => $this->humanize_action_label( $item ),
				'object_label' => $this->humanize_object_label( $item ),
				'details'      => $details,
			);

			if ( $timestamp > $groups[ $group_key ]['timestamp'] ) {
				$groups[ $group_key ]['timestamp'] = $timestamp;
				$groups[ $group_key ]['time']      = wp_date( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$normalized_groups = array_values( $groups );
		foreach ( $normalized_groups as &$group ) {
			$main_event = $this->get_main_event( $group['items'] );
			$summary    = $this->get_activity_summary_from_item( $main_event );

			$group_lines = isset( $summary['lines'] ) && is_array( $summary['lines'] ) ? $summary['lines'] : array();
			$is_bulk     = count( $group['items'] ) > 1;
			if ( $is_bulk ) {
				$bulk_lines = $this->build_bulk_group_lines( $group['items'] );
				if ( ! empty( $bulk_lines ) ) {
					$group_lines = $bulk_lines;
				}
			}
			$hidden_count = $is_bulk ? max( 0, count( $group_lines ) - 1 ) : 0;

			$group['title']      = $summary['title'];
			$group['subtitle']   = $summary['subtitle'];
			$group['icon']       = $summary['icon'];
			$group['lines']      = $group_lines;
			$group['hidden']     = $hidden_count;
			$group['is_bulk']    = $is_bulk;
			$group['main_event'] = $main_event;
			if ( $is_bulk ) {
				$group['subtitle'] = $this->get_bulk_subtitle( $main_event, count( $group['items'] ) );
			}
		}

		usort(
			$normalized_groups,
			function ( $a, $b ) {
				return (int) $b['timestamp'] <=> (int) $a['timestamp'];
			}
		);

		return $normalized_groups;
	}

	/**
	 * Build compact subtitle for grouped bulk actions.
	 *
	 * @param array<string, mixed> $main_event Main event.
	 * @param int                  $count      Number of grouped items.
	 * @return string
	 */
	private function get_bulk_subtitle( array $main_event, $count ) {
		$count = max( 2, absint( $count ) );
		$type  = isset( $main_event['type'] ) ? sanitize_key( (string) $main_event['type'] ) : '';

		if ( false !== strpos( $type, 'page_' ) ) {
			return sprintf(
				/* translators: %d: number of changes */
				__( 'Multiple changes in one action (%d)', 'whochanged' ),
				$count
			);
		}

		if ( false !== strpos( $type, 'post_' ) ) {
			return sprintf(
				/* translators: %d: number of changes */
				__( 'Multiple changes in one action (%d)', 'whochanged' ),
				$count
			);
		}

		if ( false !== strpos( $type, 'comment_' ) ) {
			return sprintf(
				/* translators: %d: number of changes */
				__( 'Multiple changes in one action (%d)', 'whochanged' ),
				$count
			);
		}

		if ( false !== strpos( $type, 'cpt_' ) ) {
			$label = '';
			if ( isset( $main_event['details'] ) && is_array( $main_event['details'] ) ) {
				$meta  = isset( $main_event['details']['meta'] ) && is_array( $main_event['details']['meta'] ) ? $main_event['details']['meta'] : array();
				$label = isset( $meta['post_type_label'] ) ? sanitize_text_field( (string) $meta['post_type_label'] ) : '';
			}
			if ( '' === $label ) {
				$label = __( 'Content', 'whochanged' );
			}
			if ( false !== strpos( $type, '_trashed' ) ) {
				return sprintf(
					/* translators: %s: content type label (e.g. Post, Page, Product) */
					__( '%s: multiple items moved to trash', 'whochanged' ),
					$label
				);
			}
			if ( false !== strpos( $type, '_restored' ) ) {
				return sprintf(
					/* translators: %s: content type label (e.g. Post, Page, Product) */
					__( '%s: multiple items restored', 'whochanged' ),
					$label
				);
			}
			return sprintf(
				/* translators: %s: content type label (e.g. Post, Page, Product) */
				__( '%s: multiple items deleted', 'whochanged' ),
				$label
			);
		}

		return sprintf(
			/* translators: %d: number of changes */
			__( 'Multiple changes in one action (%d)', 'whochanged' ),
			$count
		);
	}

	/**
	 * Build readable lines for grouped bulk actions.
	 *
	 * @param array<int, array<string, mixed>> $events Group events.
	 * @return array<int, array<string, string>>
	 */
	private function build_bulk_group_lines( array $events ) {
		$lines = array();
		foreach ( $events as $event ) {
			$details = isset( $event['details'] ) && is_array( $event['details'] ) ? $event['details'] : array();
			if ( empty( $details ) ) {
				continue;
			}
			$mapped = WhoChanged_Mapper::map( $details );
			$title  = isset( $mapped['title'] ) ? sanitize_text_field( (string) $mapped['title'] ) : '';
			$desc   = isset( $mapped['description'] ) ? sanitize_text_field( (string) $mapped['description'] ) : '';
			if ( '' === $title && '' === $desc ) {
				continue;
			}
			$lines[] = array(
				'label' => '' !== $title ? $title : __( 'Change', 'whochanged' ),
				'from'  => '',
				'to'    => '' !== $desc ? $desc : '—',
			);
		}
		return $lines;
	}

	/**
	 * Human-readable activity summary (icon + title + subtitle) from stored payload.
	 *
	 * @param array<string, mixed> $item Log row.
	 * @return array{icon:string,title:string,subtitle:string,lines:array<int, array<string, string>>}
	 */
	private function get_activity_summary_from_item( array $item ) {
		$changes = isset( $item['details'] ) && is_array( $item['details'] ) ? $item['details'] : array();

		if ( ! is_array( $changes ) || ! isset( $changes['type'] ) ) {
			return array(
				'icon'     => '',
				'title'    => isset( $item['action_label'] ) ? (string) $item['action_label'] : __( 'Site activity', 'whochanged' ),
				'subtitle' => '',
				'lines'    => array(),
			);
		}

		$mapped = WhoChanged_Mapper::map( $changes );

		return array(
			'icon'     => '',
			'title'    => isset( $mapped['title'] ) ? sanitize_text_field( (string) $mapped['title'] ) : __( '📋 Site activity', 'whochanged' ),
			'subtitle' => isset( $mapped['description'] ) ? sanitize_text_field( (string) $mapped['description'] ) : '',
			'lines'    => isset( $mapped['lines'] ) && is_array( $mapped['lines'] ) ? $mapped['lines'] : array(),
		);
	}

	/**
	 * Pick main event in group by priority.
	 *
	 * @param array<int, array<string, mixed>> $events Group events.
	 * @return array<string, mixed>
	 */
	private function get_main_event( array $events ) {
		$priority = array(
			'theme_changed',
			'post_deleted',
			'page_deleted',
			'product_deleted',
			'order_deleted',
			'cpt_deleted',
			'comment_deleted',
			'post_trashed',
			'post_restored',
			'page_trashed',
			'page_restored',
			'product_trashed',
			'product_restored',
			'order_trashed',
			'order_restored',
			'cpt_trashed',
			'cpt_restored',
			'comment_trashed',
			'comment_restored',
			'menu_saved',
			'menu_assigned',
			'plugin_upgraded',
			'theme_upgraded',
			'wordpress_updated',
			'option_updated',
		);

		foreach ( $priority as $type ) {
			foreach ( $events as $event ) {
				$event_type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
				if ( $type === $event_type ) {
					return $event;
				}
			}
		}

		return ! empty( $events ) ? $events[0] : array();
	}

	/**
	 * Build grouped detail HTML.
	 *
	 * @param array<string, mixed> $group Group payload.
	 * @return string
	 */
	private function render_group_details_html( array $group ) {
		$title    = isset( $group['title'] ) ? (string) $group['title'] : '';
		$subtitle = isset( $group['subtitle'] ) ? (string) $group['subtitle'] : '';
		$lines    = isset( $group['lines'] ) && is_array( $group['lines'] ) ? $group['lines'] : array();

		if ( empty( $title ) && isset( $group['main_event']['details'] ) && is_array( $group['main_event']['details'] ) ) {
			$mapped   = WhoChanged_Mapper::map( $group['main_event']['details'] );
			$title    = isset( $mapped['title'] ) ? (string) $mapped['title'] : '';
			$subtitle = isset( $mapped['description'] ) ? (string) $mapped['description'] : '';
			$lines    = isset( $mapped['lines'] ) && is_array( $mapped['lines'] ) ? $mapped['lines'] : array();
		}

		$html  = '<div class="whochanged-group-item">';
		$html .= '<p><strong>' . esc_html( $title ) . '</strong></p>';
		if ( '' !== $subtitle ) {
			$html .= '<p class="whochanged-desc">' . esc_html( $subtitle ) . '</p>';
		}
		if ( ! empty( $lines ) ) {
			$html .= wp_kses( $this->render_mapped_lines_html( $lines ), $this->allowed_html() );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Determine whether a group has meaningful detail lines.
	 *
	 * @param array<string, mixed> $main_event Main event payload.
	 * @return bool
	 */
	private function has_group_details( array $main_event ) {
		$details = isset( $main_event['details'] ) && is_array( $main_event['details'] ) ? $main_event['details'] : array();
		$mapped  = WhoChanged_Mapper::map( $details );

		return isset( $mapped['lines'] ) && is_array( $mapped['lines'] ) && ! empty( $mapped['lines'] );
	}

	/**
	 * Return highlight key for important events.
	 *
	 * @param array<string, mixed> $main_event Main event payload.
	 * @return string One of: admin_role_changed, plugin_installed, theme_switched, logs_purged. Empty when not important.
	 */
	private function get_important_event_key( array $main_event ) {
		$type = isset( $main_event['type'] ) ? sanitize_key( (string) $main_event['type'] ) : '';

		if ( 'installed_plugin' === $type ) {
			return 'plugin_installed';
		}

		if ( 'theme_changed' === $type ) {
			return 'theme_switched';
		}

		if ( 'logs_purged' === $type ) {
			return 'logs_purged';
		}

		if ( 'option_updated' === $type ) {
			$object_label = isset( $main_event['object_label'] ) ? strtolower( (string) $main_event['object_label'] ) : '';
			if ( '' !== $object_label && false !== strpos( $object_label, 'default_role' ) ) {
				return 'admin_role_changed';
			}
		}

		return '';
	}

	/* Details now always come from main event only (no group-wide loops). */

	/**
	 * Render stored payload: normalized events include meta + technical diff.
	 *
	 * @param array<string, mixed> $payload Changes JSON (decoded).
	 * @return string
	 */
	private function render_payload_details_html( array $payload ) {
		if ( isset( $payload['type'] ) ) {
			$mapped = WhoChanged_Mapper::map( $payload );

			if ( isset( $mapped['lines'] ) && is_array( $mapped['lines'] ) && ! empty( $mapped['lines'] ) ) {
				return $this->render_mapped_lines_html( $mapped['lines'] );
			}

			if ( isset( $mapped['description'] ) && '' !== (string) $mapped['description'] ) {
				return '<p class="whochanged-desc">' . esc_html( (string) $mapped['description'] ) . '</p>';
			}

			return '';
		}

		return '';
	}

	/**
	 * Render mapped readable lines.
	 *
	 * @param array<int, array<string, string>> $lines Mapped lines.
	 * @param int                               $max    Maximum lines to render.
	 * @param string                            $row_id Related detail row id.
	 * @return string
	 */
	private function render_mapped_lines_html( array $lines, $max = 0, $row_id = '' ) {
		if ( empty( $lines ) ) {
			return '';
		}

		$html  = '';
		$total = count( $lines );
		if ( $max > 0 ) {
			$lines = array_slice( $lines, 0, $max );
		}

		foreach ( $lines as $line ) {
			$label      = isset( $line['label'] ) ? (string) $line['label'] : '';
			$from       = isset( $line['from'] ) ? (string) $line['from'] : '';
			$to         = isset( $line['to'] ) ? (string) $line['to'] : '';
			$from_short = ( $max > 0 ) ? $this->truncate_change_text( $from, 70 ) : $from;
			$to_short   = ( $max > 0 ) ? $this->truncate_change_text( $to, 70 ) : $to;

			$html .= '<div class="whochanged-line">';
			$html .= '<strong>' . esc_html( $label ) . ':</strong> ';
			$html .= '<span class="old" title="' . esc_attr( $from ) . '">' . esc_html( $from_short ) . '</span> ';
			$html .= '<span>→</span> ';
			$html .= '<span class="new" title="' . esc_attr( $to ) . '">' . esc_html( $to_short ) . '</span>';
			$html .= '</div>';
		}

		if ( $max > 0 && $total > $max ) {
			$remaining = $total - $max;
			if ( '' !== $row_id ) {
				$html .= '<div class="whochanged-meta"><button type="button" class="whochanged-more-badge whochanged-more-toggle" aria-expanded="false" aria-controls="' . esc_attr( $row_id ) . '">+' . esc_html( (string) $remaining ) . ' ' . esc_html__( 'more changes', 'whochanged' ) . '</button></div>';
			} else {
				$html .= '<div class="whochanged-meta"><span class="whochanged-more-badge">+' . esc_html( (string) $remaining ) . ' ' . esc_html__( 'more changes', 'whochanged' ) . '</span></div>';
			}
		}

		return $html;
	}

	/**
	 * Truncate long text for compact Changes column.
	 *
	 * @param string $text   Source text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate_change_text( $text, $length = 70 ) {
		$text        = (string) $text;
		$url_compact = $this->compact_url_for_display( $text );
		if ( '' !== $url_compact ) {
			return $url_compact;
		}

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		return mb_substr( $text, 0, $length - 1 ) . '…';
	}

	/**
	 * Compact URL to domain + filename format.
	 * Example: http://127.0.0.1:8888/.../blue-ocean.jpg
	 *
	 * @param string $text Raw value.
	 * @return string
	 */
	private function compact_url_for_display( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! filter_var( $text, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$parts = wp_parse_url( $text );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$file   = basename( $path );

		if ( '' === $file || '/' === $file || '.' === $file ) {
			return $scheme . $host . $port;
		}

		return $scheme . $host . $port . '/.../' . $file;
	}

	/**
	 * Render human-readable menu assignment changes.
	 *
	 * @param array<int, array<string, mixed>> $changes Menu assignment changes.
	 * @return string
	 */
	/* Raw technical meta renderers intentionally removed: UI is human-mapped only. */

	/**
	 * Grouping key for related rows.
	 *
	 * @param array<string, mixed> $item Log item.
	 * @return string
	 */
	private function get_group_key( array $item ) {
		$action      = isset( $item['action_type'] ) ? sanitize_key( (string) $item['action_type'] ) : '';
		$object_type = isset( $item['object_type'] ) ? sanitize_key( (string) $item['object_type'] ) : '';
		$object_name = isset( $item['object_name'] ) ? sanitize_text_field( (string) $item['object_name'] ) : '';
		$user_id     = absint( isset( $item['user_id'] ) ? $item['user_id'] : 0 );

		if ( 'option' === $object_type && $this->is_theme_related_option( $object_name ) ) {
			return $user_id . '|theme_settings';
		}

		if ( 'option' === $object_type && false !== strpos( $object_name, 'woocommerce' ) ) {
			return $user_id . '|woocommerce_settings';
		}

		return $user_id . '|' . $action . '|' . $object_type . '|' . $object_name;
	}

	/**
	 * Human title for grouped row.
	 *
	 * @param array<string, mixed> $item Log item.
	 * @return string
	 */
	private function humanize_group_title( array $item ) {
		$action      = isset( $item['action_type'] ) ? sanitize_key( (string) $item['action_type'] ) : '';
		$object_type = isset( $item['object_type'] ) ? sanitize_key( (string) $item['object_type'] ) : '';
		$object_name = isset( $item['object_name'] ) ? sanitize_text_field( (string) $item['object_name'] ) : '';

		if ( 'theme_changed' === $action ) {
			return __( 'Theme changed', 'whochanged' );
		}

		if ( 'option' === $object_type && $this->is_theme_related_option( $object_name ) ) {
			return __( 'Admin changed theme settings', 'whochanged' );
		}

		if ( false !== strpos( $object_name, 'woocommerce' ) ) {
			return __( 'WooCommerce settings updated', 'whochanged' );
		}

		return $this->humanize_action_label( $item );
	}

	/**
	 * Human action label.
	 *
	 * @param array<string, mixed> $item Log item.
	 * @return string
	 */
	private function humanize_action_label( array $item ) {
		$action = isset( $item['action_type'] ) ? sanitize_key( (string) $item['action_type'] ) : '';

		$labels = array(
			'update_option'        => __( 'Option updated', 'whochanged' ),
			'add_option'           => __( 'Option added', 'whochanged' ),
			'delete_option'        => __( 'Option deleted', 'whochanged' ),
			'menu_assigned'        => __( 'Menu assigned', 'whochanged' ),
			'menu_saved'           => __( 'Menu updated', 'whochanged' ),
			'menu_created'         => __( 'Menu created', 'whochanged' ),
			'menu_updated'         => __( 'Menu updated', 'whochanged' ),
			'menu_deleted'         => __( 'Menu deleted', 'whochanged' ),
			'customize_save_after' => __( 'Customizer saved', 'whochanged' ),
			'activated_plugin'     => __( 'Plugin activated', 'whochanged' ),
			'deactivated_plugin'   => __( 'Plugin deactivated', 'whochanged' ),
			'installed_plugin'     => __( 'Plugin installed', 'whochanged' ),
			'deleted_plugin'       => __( 'Plugin deleted', 'whochanged' ),
			'logs_purged'          => __( '🗑️ All activity logs purged', 'whochanged' ),
			'comment_deleted'      => __( 'Comment deleted', 'whochanged' ),
			'comment_trashed'      => __( 'Comment moved to trash', 'whochanged' ),
			'comment_restored'     => __( 'Comment restored', 'whochanged' ),
			'post_deleted'         => __( 'Post deleted', 'whochanged' ),
			'post_trashed'         => __( 'Post moved to trash', 'whochanged' ),
			'post_restored'        => __( 'Post restored', 'whochanged' ),
			'page_deleted'         => __( 'Page deleted', 'whochanged' ),
			'page_trashed'         => __( 'Page moved to trash', 'whochanged' ),
			'page_restored'        => __( 'Page restored', 'whochanged' ),
			'product_deleted'      => __( 'Product deleted', 'whochanged' ),
			'product_trashed'      => __( 'Product moved to trash', 'whochanged' ),
			'product_restored'     => __( 'Product restored', 'whochanged' ),
			'order_deleted'        => __( 'Order deleted', 'whochanged' ),
			'order_trashed'        => __( 'Order moved to trash', 'whochanged' ),
			'order_restored'       => __( 'Order restored', 'whochanged' ),
			'cpt_deleted'          => __( 'Custom content deleted', 'whochanged' ),
			'cpt_trashed'          => __( 'Custom content moved to trash', 'whochanged' ),
			'cpt_restored'         => __( 'Custom content restored', 'whochanged' ),
			'updated_plugin'       => __( 'Plugin updated', 'whochanged' ),
			'plugin_upgraded'      => __( 'Plugin upgraded', 'whochanged' ),
			'theme_upgraded'       => __( 'Theme upgraded', 'whochanged' ),
			'wordpress_updated'    => __( 'WordPress updated', 'whochanged' ),
			'theme_changed'        => __( 'Theme changed', 'whochanged' ),
			'user_login'           => __( 'Logged in', 'whochanged' ),
			'user_logout'          => __( 'Logged out', 'whochanged' ),
			'login_failed'         => __( 'Login failed', 'whochanged' ),
		);

		if ( isset( $labels[ $action ] ) ) {
			return $labels[ $action ];
		}

		return $action;
	}

	/**
	 * Human-readable label for action type filter values.
	 *
	 * @param string $action_type Action key from DB.
	 * @return string
	 */
	private function humanize_action_type_label( $action_type ) {
		$action_type = sanitize_key( (string) $action_type );

		$labels = array(
			'option_updated'       => __( 'Setting updated', 'whochanged' ),
			'option_added'         => __( 'Setting added', 'whochanged' ),
			'option_deleted'       => __( 'Setting deleted', 'whochanged' ),
			'menu_saved'           => __( 'Menu updated', 'whochanged' ),
			'menu_created'         => __( 'Menu created', 'whochanged' ),
			'menu_updated'         => __( 'Menu updated', 'whochanged' ),
			'menu_deleted'         => __( 'Menu deleted', 'whochanged' ),
			'customize_save_after' => __( 'Customizer saved', 'whochanged' ),
			'activated_plugin'     => __( 'Plugin activated', 'whochanged' ),
			'deactivated_plugin'   => __( 'Plugin deactivated', 'whochanged' ),
			'installed_plugin'     => __( 'Plugin installed', 'whochanged' ),
			'deleted_plugin'       => __( 'Plugin deleted', 'whochanged' ),
			'logs_purged'          => __( '🗑️ All activity logs purged', 'whochanged' ),
			'comment_deleted'      => __( 'Comment deleted', 'whochanged' ),
			'comment_trashed'      => __( 'Comment moved to trash', 'whochanged' ),
			'comment_restored'     => __( 'Comment restored', 'whochanged' ),
			'post_deleted'         => __( 'Post deleted', 'whochanged' ),
			'post_trashed'         => __( 'Post moved to trash', 'whochanged' ),
			'post_restored'        => __( 'Post restored', 'whochanged' ),
			'page_deleted'         => __( 'Page deleted', 'whochanged' ),
			'page_trashed'         => __( 'Page moved to trash', 'whochanged' ),
			'page_restored'        => __( 'Page restored', 'whochanged' ),
			'product_deleted'      => __( 'Product deleted', 'whochanged' ),
			'product_trashed'      => __( 'Product moved to trash', 'whochanged' ),
			'product_restored'     => __( 'Product restored', 'whochanged' ),
			'order_deleted'        => __( 'Order deleted', 'whochanged' ),
			'order_trashed'        => __( 'Order moved to trash', 'whochanged' ),
			'order_restored'       => __( 'Order restored', 'whochanged' ),
			'cpt_deleted'          => __( 'Custom content deleted', 'whochanged' ),
			'cpt_trashed'          => __( 'Custom content moved to trash', 'whochanged' ),
			'cpt_restored'         => __( 'Custom content restored', 'whochanged' ),
			'plugin_upgraded'      => __( 'Plugin upgraded', 'whochanged' ),
			'theme_upgraded'       => __( 'Theme upgraded', 'whochanged' ),
			'theme_changed'        => __( 'Theme changed', 'whochanged' ),
			'wordpress_updated'    => __( 'WordPress updated', 'whochanged' ),
			'user_login'           => __( 'Logged in', 'whochanged' ),
			'user_logout'          => __( 'Logged out', 'whochanged' ),
			'login_failed'         => __( 'Login failed', 'whochanged' ),
		);

		if ( isset( $labels[ $action_type ] ) ) {
			return $labels[ $action_type ];
		}

		return ucwords( str_replace( '_', ' ', $action_type ) );
	}

	/**
	 * Human object label.
	 *
	 * @param array<string, mixed> $item Log item.
	 * @return string
	 */
	private function humanize_object_label( array $item ) {
		$object_type = isset( $item['object_type'] ) ? sanitize_key( (string) $item['object_type'] ) : '';
		$object_name = isset( $item['object_name'] ) ? sanitize_text_field( (string) $item['object_name'] ) : '';

		if ( 'option' === $object_type && false !== strpos( $object_name, 'woocommerce' ) ) {
			return __( 'WooCommerce Settings', 'whochanged' );
		}

		if ( 'theme_changed' === ( isset( $item['action_type'] ) ? sanitize_key( (string) $item['action_type'] ) : '' ) ) {
			return __( 'Active theme', 'whochanged' );
		}

		return $object_type . ': ' . $object_name;
	}

	/**
	 * Check whether option is related to themes.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private function is_theme_related_option( $option_name ) {
		if ( 'stylesheet' === $option_name || 'template' === $option_name ) {
			return true;
		}

		if ( 0 === strpos( $option_name, 'theme_mods_' ) ) {
			return true;
		}

		if ( false !== strpos( $option_name, 'customize' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Format diff value for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			if ( false === $encoded ) {
				return '';
			}

			return $encoded;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		return (string) $value;
	}

	/**
	 * Number of days shown in the admin bar history panel.
	 *
	 * @return int
	 */
	private function get_admin_bar_days() {
		return max( 1, min( 90, (int) apply_filters( 'whochanged_admin_bar_days', 7 ) ) );
	}

	/**
	 * Enqueue admin bar dropdown assets (admin + front when admin bar visible).
	 *
	 * @return void
	 */
	public function enqueue_admin_bar_assets() {
		if ( ! is_admin_bar_showing() || ! $this->current_user_can_view_logs() ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'whochanged-admin-bar',
			WHOCHANGED_PLUGIN_URL . 'assets/css/admin-bar.css',
			array(),
			WHOCHANGED_VERSION
		);

		wp_enqueue_script(
			'whochanged-admin-bar',
			WHOCHANGED_PLUGIN_URL . 'assets/js/admin-bar.js',
			array( 'jquery' ),
			WHOCHANGED_VERSION,
			true
		);

		$days = $this->get_admin_bar_days();

		wp_localize_script(
			'whochanged-admin-bar',
			'WhoChangedBar',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'whochanged_admin_bar' ),
				'subtitle' => sprintf(
					/* translators: %d: number of days */
					esc_html__( 'Signed-in users only · last %d days', 'whochanged' ),
					$days
				),
				'empty'    => esc_html__( 'No user activity in this window.', 'whochanged' ),
				'loading'  => esc_html__( 'Fetching…', 'whochanged' ),
			)
		);
	}

	/**
	 * Add History node to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function register_admin_bar( $wp_admin_bar ) {
		if ( ! $this->current_user_can_view_logs() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'whochanged-history',
				'title' => '<span class="ab-icon dashicons dashicons-backup" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Activity', 'whochanged' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . rawurlencode( $this->menu_slug ) ),
				'meta'  => array(
					'class' => 'whochanged-ab-root',
					'title' => esc_attr__( 'WhoChanged: recent user activity', 'whochanged' ),
				),
			)
		);
	}

	/**
	 * Output the flyout panel markup (once per page).
	 *
	 * @return void
	 */
	public function render_admin_bar_panel() {
		static $done = false;

		if ( $done || ! is_admin_bar_showing() || ! $this->current_user_can_view_logs() ) {
			return;
		}

		$done = true;
		?>
		<div id="whochanged-ab-panel" class="whochanged-ab-panel" hidden data-whochanged-ab-panel role="region" aria-label="<?php echo esc_attr__( 'WhoChanged recent activity', 'whochanged' ); ?>">
			<div class="whochanged-ab-panel__inner">
				<div class="whochanged-ab-panel__head">
					<div class="whochanged-ab-panel__title">
						<span class="dashicons dashicons-backup whochanged-ab-panel__title-icon" aria-hidden="true"></span>
						<span class="whochanged-ab-panel__title-text"><?php echo esc_html__( 'WhoChanged', 'whochanged' ); ?></span>
					</div>
					<p class="whochanged-ab-panel__subtitle"></p>
				</div>
				<div class="whochanged-ab-panel__body">
					<div class="whochanged-ab-timeline" data-whochanged-ab-timeline aria-live="polite"></div>
				</div>
				<div class="whochanged-ab-panel__foot">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . rawurlencode( $this->menu_slug ) ) ); ?>" class="whochanged-ab-foot-link whochanged-ab-foot-full"><?php echo esc_html__( 'Open activity log', 'whochanged' ); ?></a>
					<div class="whochanged-ab-foot-actions">
						<button type="button" class="whochanged-ab-reload button-link" data-whochanged-ab-reload>
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<span class="whochanged-ab-reload-label"><?php echo esc_html__( 'Refresh', 'whochanged' ); ?></span>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: JSON payload for admin bar timeline.
	 *
	 * @return void
	 */
	public function ajax_admin_bar_events() {
		check_ajax_referer( 'whochanged_admin_bar', 'nonce' );

		if ( ! $this->current_user_can_view_logs() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ) );
		}

		$days   = $this->get_admin_bar_days();
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows   = $this->logger->get_logs_since_gmt( $since, 50, false );
		$groups = $this->group_logs( $rows );

		$events = array();

		foreach ( $groups as $group ) {
			$uid  = (int) $group['user_id'];
			$user = $uid ? get_user_by( 'id', $uid ) : false;
			$name = $user ? $user->display_name : __( 'System', 'whochanged' );
			$ago  = human_time_diff( (int) $group['timestamp'], time() );

			$meta = $name . ' · ' . $ago . ' ' . __( 'ago', 'whochanged' );

			$text = isset( $group['title'] ) ? (string) $group['title'] : '';
			if ( ! empty( $group['subtitle'] ) ) {
				$text .= ( '' !== $text ? ' ' : '' ) . (string) $group['subtitle'];
			}
			if ( '' === $text ) {
				$text = __( 'Site activity', 'whochanged' );
			}

			$events[] = array(
				'meta' => $meta,
				'text' => $text,
			);
		}

		wp_send_json_success( array( 'events' => $events ) );
	}

	/**
	 * Export current filtered log groups as CSV.
	 *
	 * @param int    $filter_user User filter (0 for system/all users depending on tab).
	 * @param string $action_type Action filter.
	 * @param string $log_tab user|system.
	 * @param array  $date_state Parsed date state.
	 * @param string $search_q Search keyword.
	 * @return void
	 */
	private function export_logs_csv( $filter_user, $action_type, $log_tab, array $date_state, $search_q ) {
		$per_page     = 200;
		$max_raw_rows = 5000; // Safety cap.

		$page           = 1;
		$all_rows       = array();
		$all_rows_count = 0;
		$total_items    = 0;
		$truncated      = false;

		do {
			$result = $this->logger->get_logs(
				$page,
				$per_page,
				$filter_user,
				$action_type,
				$log_tab,
				$date_state['from'],
				$date_state['to'],
				$search_q
			);

			$rows        = is_array( $result['items'] ) ? $result['items'] : array();
			$rows_count  = count( $rows );
			$total_items = isset( $result['total_items'] ) ? (int) $result['total_items'] : 0;

			if ( $all_rows_count + $rows_count > $max_raw_rows ) {
				$remaining  = max( 0, $max_raw_rows - $all_rows_count );
				$rows       = array_slice( $rows, 0, $remaining );
				$rows_count = count( $rows );
				$truncated  = true;
			}

			$all_rows        = array_merge( $all_rows, $rows );
			$all_rows_count += $rows_count;
			++$page;
		} while ( ! empty( $rows ) && $all_rows_count < $max_raw_rows && $page <= 1000 );

		$groups = $this->group_logs( $all_rows );

		$filename = 'whochanged-logs-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( false === $out ) {
			wp_die( esc_html__( 'Unable to create CSV export.', 'whochanged' ) );
		}

		fputcsv(
			$out,
			array(
				'Time',
				'User',
				'Event',
				'Description',
				'Details',
				'EventType',
			)
		);

		foreach ( $groups as $group ) {
			$uid  = (int) ( isset( $group['user_id'] ) ? $group['user_id'] : 0 );
			$user = $uid ? get_user_by( 'id', $uid ) : false;
			$name = $user ? $user->display_name : __( 'System', 'whochanged' );

			$event_title      = isset( $group['title'] ) ? (string) $group['title'] : '';
			$subtitle         = isset( $group['subtitle'] ) ? (string) $group['subtitle'] : '';
			$lines            = isset( $group['lines'] ) && is_array( $group['lines'] ) ? $group['lines'] : array();
			$description_text = trim( $subtitle );
			if ( '' === $description_text ) {
				$description_text = $this->lines_to_export_text( $lines );
			}
			$details_text = $this->lines_to_export_text( $lines );
			$event_type   = '';
			if ( isset( $group['main_event'] ) && is_array( $group['main_event'] ) && isset( $group['main_event']['type'] ) ) {
				$event_type = (string) $group['main_event']['type'];
			}

			fputcsv(
				$out,
				array_map(
					array( $this, 'sanitize_csv_cell' ),
					array(
						isset( $group['time'] ) ? (string) $group['time'] : '',
						$name,
						$event_title,
						$description_text,
						$details_text,
						$event_type,
					)
				)
			);
		}

		if ( $truncated ) {
			fputcsv(
				$out,
				array_map(
					array( $this, 'sanitize_csv_cell' ),
					array(
						'',
						'',
						'',
						'',
						sprintf(
							/* translators: %d: maximum number of raw log rows included in the export. */
							__( 'Export truncated (limit: %d raw rows).', 'whochanged' ),
							$max_raw_rows
						),
						'',
					)
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writing a direct file-download stream, not a filesystem file.
		exit;
	}

	/**
	 * Convert mapped lines into a one-line export text.
	 *
	 * Public: shared with the PRO export modules (includes/pro/) for the
	 * XLS/PDF exports, which reuse the same one-line format as CSV.
	 *
	 * @param array<int, array<string, string>> $lines Mapped lines.
	 * @return string
	 */
	public function lines_to_export_text( array $lines ) {
		if ( empty( $lines ) ) {
			return '';
		}

		$parts = array();
		foreach ( $lines as $line ) {
			$label   = isset( $line['label'] ) ? (string) $line['label'] : '';
			$from    = isset( $line['from'] ) ? (string) $line['from'] : '';
			$to      = isset( $line['to'] ) ? (string) $line['to'] : '';
			$label   = '' !== $label ? $label . ': ' : '';
			$parts[] = $label . $from . ' -> ' . $to;
		}

		return implode( ' | ', $parts );
	}

	/**
	 * Neutralize CSV/formula injection ("CSV injection"): spreadsheet apps may
	 * execute a cell as a formula when it starts with =, +, -, @, tab, or CR.
	 * Prefixing with a single quote forces those apps to treat it as plain text.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private function sanitize_csv_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		if ( 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Send a demo PRO alert email.
	 *
	 * @param string $recipient Recipient email.
	 * @return bool
	 */
	private function send_test_alert_email( $recipient ) {
		$recipient = sanitize_email( (string) $recipient );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return false;
		}

		$subject = __( 'WhoChanged PRO alert: test email', 'whochanged' );
		$body    = implode(
			"\n",
			array(
				__( 'This is a test alert from WhoChanged PRO settings.', 'whochanged' ),
				sprintf(
					/* translators: %s: site URL. */
					__( 'Site: %s', 'whochanged' ),
					home_url()
				),
				sprintf(
					/* translators: %s: date/time in GMT. */
					__( 'Sent at (GMT): %s', 'whochanged' ),
					gmdate( 'Y-m-d H:i:s' )
				),
			)
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return (bool) wp_mail( $recipient, $subject, $body, $headers );
	}

	/**
	 * Determine whether current user can view WhoChanged logs pages.
	 *
	 * @return bool
	 */
	private function current_user_can_view_logs() {
		// Admins (and higher) always retain access.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Extending viewer access to non-admin roles is a PRO feature — and
		// requires the local premium module code to even exist (Free package
		// never ships includes/pro/), so Free always requires manage_options.
		if ( ! WhoChanged_Pro::ships_premium_modules() || ! WhoChanged_Pro::is_active() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return false;
		}

		$viewer_roles = get_option( 'whochanged_pro_viewer_roles', array( 'administrator' ) );
		if ( ! is_array( $viewer_roles ) ) {
			return false;
		}

		$viewer_roles = array_values( array_filter( array_map( 'sanitize_key', $viewer_roles ) ) );
		if ( empty( $viewer_roles ) ) {
			return false;
		}

		return ! empty( array_intersect( $user->roles, $viewer_roles ) );
	}

	/**
	 * Whitelisted tags for wp_kses.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function allowed_html() {
		return array(
			'ul'     => array( 'class' => true ),
			'li'     => array( 'class' => true ),
			'div'    => array( 'class' => true ),
			'span'   => array( 'class' => true ),
			'p'      => array(),
			'strong' => array(),
			'hr'     => array(),
			'h4'     => array( 'class' => true ),
			'button' => array(
				'type'          => true,
				'class'         => true,
				'aria-expanded' => true,
				'aria-controls' => true,
			),
		);
	}
}

