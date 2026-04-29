<?php
/**
 * Admin page and actions.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Admin;

use WPHostingBenchmark\Benchmark\Runner;
use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Page {
	/**
	 * Required capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Benchmark runner.
	 *
	 * @var Runner
	 */
	protected $runner;

	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Runner  $runner  Benchmark runner.
	 * @param Storage $storage Result storage.
	 */
	public function __construct( Runner $runner, Storage $storage ) {
		$this->runner  = $runner;
		$this->storage = $storage;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_wp_hosting_benchmark_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_wp_hosting_benchmark_clear_history', array( $this, 'handle_clear_history' ) );
	}

	/**
	 * Register the benchmark menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Hosting Benchmark', 'wp-hosting-benchmark' ),
			__( 'Hosting Benchmark', 'wp-hosting-benchmark' ),
			self::CAPABILITY,
			'wp-hosting-benchmark',
			array( $this, 'render_page' ),
			'dashicons-performance',
			59
		);
	}

	/**
	 * Handle benchmark submission.
	 *
	 * @return void
	 */
	public function handle_run() {
		$this->authorize_action( 'wp_hosting_benchmark_run' );

		try {
			$intensity = isset( $_POST['intensity'] ) ? sanitize_key( wp_unslash( $_POST['intensity'] ) ) : 'standard';
			$run       = $this->runner->run_benchmark( $intensity );

			wp_safe_redirect(
				$this->get_page_url(
					array(
						'notice'       => 'benchmark-complete',
						'benchmark_id' => $run['id'],
					)
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->set_flash_notice( 'error', $this->build_safe_error_message( $throwable, __( 'The benchmark run could not be completed.', 'wp-hosting-benchmark' ) ) );
			wp_safe_redirect( $this->get_page_url() );
		}

		exit;
	}

	/**
	 * Handle history clearing.
	 *
	 * @return void
	 */
	public function handle_clear_history() {
		$this->authorize_action( 'wp_hosting_benchmark_clear_history' );

		try {
			$this->storage->clear_history();

			wp_safe_redirect(
				$this->get_page_url(
					array(
						'notice' => 'history-cleared',
					)
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->set_flash_notice( 'error', $this->build_safe_error_message( $throwable, __( 'The benchmark history could not be cleared.', 'wp-hosting-benchmark' ) ) );
			wp_safe_redirect( $this->get_page_url() );
		}

		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-hosting-benchmark' ) );
		}

		$latest_run   = $this->storage->get_latest_run();
		$selected_id  = isset( $_GET['benchmark_id'] ) ? sanitize_text_field( wp_unslash( $_GET['benchmark_id'] ) ) : '';
		$selected_run = $selected_id ? $this->storage->get_run( $selected_id ) : $latest_run;
		$missing_run  = $selected_id && ! $selected_run;

		if ( ! $selected_run ) {
			$selected_run = $latest_run;
			$selected_id  = $selected_run ? $selected_run['id'] : '';
		}

		$environment  = $selected_run ? $selected_run['environment'] : $this->runner->get_environment_snapshot();
		$history      = $this->storage->get_history( 20 );

		echo '<div class="wrap wp-hosting-benchmark-page">';
		$this->render_inline_assets();
		echo '<div class="wp-hosting-benchmark-shell">';
		echo '<div class="wp-hosting-benchmark-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-kicker">' . esc_html__( 'Hosting Health Dashboard', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h1>' . esc_html__( 'Hosting Benchmark', 'wp-hosting-benchmark' ) . '</h1>';
		echo '<p class="wp-hosting-benchmark-intro">' . esc_html__( 'Run short, hosting-safe benchmarks from the admin area, review real-world WordPress performance signals, and translate the results into a clear hosting verdict.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';

		if ( $selected_run ) {
			$header_verdict = $this->get_final_verdict( $selected_run );
			echo '<div class="wp-hosting-benchmark-header-meta">';
			echo '<span class="wp-hosting-benchmark-header-chip">' . esc_html__( 'Selected report', 'wp-hosting-benchmark' ) . '</span>';
			echo '<strong>' . esc_html( $header_verdict['title'] ) . '</strong>';
			echo '<span class="wp-hosting-benchmark-subtle">' . esc_html( sprintf( __( 'Score %1$s / Confidence %2$s%%', 'wp-hosting-benchmark' ), number_format_i18n( (int) $selected_run['scores']['overall'] ), number_format_i18n( (int) $selected_run['scores']['confidence'] ) ) ) . '</span>';
			echo '<span class="wp-hosting-benchmark-subtle">' . esc_html( $this->format_run_timestamp( $selected_run['created_at'] ) ) . '</span>';
			echo '</div>';
		} else {
			echo '<div class="wp-hosting-benchmark-header-meta">';
			echo '<span class="wp-hosting-benchmark-header-chip">' . esc_html__( 'Awaiting first report', 'wp-hosting-benchmark' ) . '</span>';
			echo '<strong>' . esc_html__( 'Run a benchmark to generate a live hosting verdict.', 'wp-hosting-benchmark' ) . '</strong>';
			echo '</div>';
		}

		echo '</div>';
		$this->render_notice( $missing_run );
		echo '<div class="wp-hosting-benchmark-dashboard">';
		echo '<div class="wp-hosting-benchmark-main">';
		$this->render_summary( $selected_run );
		$this->render_results( $selected_run );
		$this->render_history( $history, $selected_id );
		echo '</div>';
		echo '<div class="wp-hosting-benchmark-sidebar">';
		$this->render_controls( $selected_run );
		$this->render_interpretation_guide( $selected_run );
		$this->render_environment( $environment );
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Authorize a benchmark action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	protected function authorize_action( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-hosting-benchmark' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Render admin notices.
	 *
	 * @param bool $missing_run Whether the requested run was unavailable.
	 * @return void
	 */
	protected function render_notice( $missing_run = false ) {
		$flash_notice = $this->get_flash_notice();

		if ( $flash_notice ) {
			echo '<div class="notice notice-' . esc_attr( $flash_notice['type'] ) . '"><p>' . esc_html( $flash_notice['message'] ) . '</p></div>';
		}

		if ( $missing_run ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The selected benchmark run could not be found. Showing the latest available report instead.', 'wp-hosting-benchmark' ) . '</p></div>';
		}

		if ( empty( $_GET['notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['notice'] ) );
		$text   = '';

		if ( 'benchmark-complete' === $notice ) {
			$text = __( 'Benchmark run completed.', 'wp-hosting-benchmark' );
		} elseif ( 'history-cleared' === $notice ) {
			$text = __( 'Benchmark history cleared.', 'wp-hosting-benchmark' );
		}

		if ( '' === $text ) {
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Store a short-lived admin notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	protected function set_flash_notice( $type, $message ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			Storage::NOTICE_TRANSIENT_PREFIX . $user_id,
			array(
				'type'    => in_array( $type, array( 'success', 'warning', 'error' ), true ) ? $type : 'warning',
				'message' => sanitize_text_field( $message ),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear a flash notice.
	 *
	 * @return array|null
	 */
	protected function get_flash_notice() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		$key    = Storage::NOTICE_TRANSIENT_PREFIX . $user_id;
		$notice = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		return array(
			'type'    => isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'warning',
			'message' => sanitize_text_field( $notice['message'] ),
		);
	}

	/**
	 * Build a safe admin-facing error message.
	 *
	 * @param \Throwable $throwable Throwable instance.
	 * @param string     $fallback  Fallback message.
	 * @return string
	 */
	protected function build_safe_error_message( \Throwable $throwable, $fallback ) {
		$message = sanitize_text_field( $throwable->getMessage() );

		return '' !== $message ? $message : $fallback;
	}

	/**
	 * Render small inline UI helpers.
	 *
	 * @return void
	 */
	protected function render_inline_assets() {
		$high_warning  = __( 'High intensity runs more iterations. It remains shared-hosting safe, but it should only be used when you can tolerate a slightly longer admin request.', 'wp-hosting-benchmark' );
		$clear_warning = __( 'This clears all stored benchmark history. Continue?', 'wp-hosting-benchmark' );
		$running_label = __( 'Running...', 'wp-hosting-benchmark' );
		$clearing_label = __( 'Clearing...', 'wp-hosting-benchmark' );

		echo '<style>';
		echo '.wp-hosting-benchmark-page{--wp-hosting-benchmark-ink:#0f172a;--wp-hosting-benchmark-muted:#475569;--wp-hosting-benchmark-border:#d9e2ec;--wp-hosting-benchmark-surface:#ffffff;--wp-hosting-benchmark-surface-muted:#f8fafc;--wp-hosting-benchmark-shadow:0 18px 40px rgba(15,23,42,.08);--wp-hosting-benchmark-tone:#0f766e;--wp-hosting-benchmark-tone-soft:#dff6f2;}';
		echo '.wp-hosting-benchmark-page *{box-sizing:border-box;}';
		echo '.wp-hosting-benchmark-shell{width:100%;max-width:1460px;display:grid;gap:24px;container-type:inline-size;container-name:benchmark-shell;}';
		echo '.wp-hosting-benchmark-header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;padding:28px 30px;border-radius:26px;border:1px solid var(--wp-hosting-benchmark-border);background:linear-gradient(135deg,#fbfdff 0%,#edf8f5 55%,#ffffff 100%);box-shadow:var(--wp-hosting-benchmark-shadow);}';
		echo '.wp-hosting-benchmark-kicker,.wp-hosting-benchmark-section-label{margin:0;text-transform:uppercase;letter-spacing:.14em;font-size:11px;font-weight:700;color:#0f766e;}';
		echo '.wp-hosting-benchmark-header h1{margin:10px 0 8px;font-size:34px;line-height:1.08;color:var(--wp-hosting-benchmark-ink);}';
		echo '.wp-hosting-benchmark-intro{margin:0;max-width:72ch;font-size:14px;line-height:1.6;color:var(--wp-hosting-benchmark-muted);}';
		echo '.wp-hosting-benchmark-header-meta{display:grid;gap:8px;justify-items:start;padding:18px 20px;border-radius:20px;background:rgba(255,255,255,.8);border:1px solid rgba(15,118,110,.16);min-width:min(100%,280px);}';
		echo '.wp-hosting-benchmark-header-chip,.wp-hosting-benchmark-verdict-chip,.wp-hosting-benchmark-breakdown-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;}';
		echo '.wp-hosting-benchmark-header-chip{background:#e0f2fe;color:#0c4a6e;}';
		echo '.wp-hosting-benchmark-dashboard{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,400px);gap:24px;align-items:start;}';
		echo '.wp-hosting-benchmark-main,.wp-hosting-benchmark-sidebar{display:grid;gap:24px;}';
		echo '.wp-hosting-benchmark-card{width:100%;max-width:none;margin:0;padding:24px;border-radius:24px;border:1px solid var(--wp-hosting-benchmark-border);background:var(--wp-hosting-benchmark-surface);box-shadow:var(--wp-hosting-benchmark-shadow);min-width:0;}';
		echo '.wp-hosting-benchmark-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px;}';
		echo '.wp-hosting-benchmark-card-header h2{margin:8px 0 0;font-size:24px;line-height:1.15;color:var(--wp-hosting-benchmark-ink);}';
		echo '.wp-hosting-benchmark-card-intro,.wp-hosting-benchmark-card .description,.wp-hosting-benchmark-subtle{color:var(--wp-hosting-benchmark-muted);}';
		echo '.wp-hosting-benchmark-card-intro{margin:10px 0 0;line-height:1.6;}';
		echo '.wp-hosting-benchmark-select{width:100%;max-width:100%;margin-top:8px;}';
		echo '.wp-hosting-benchmark-button-group{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}';
		echo '.wp-hosting-benchmark-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}';
		echo '.wp-hosting-benchmark-guide-list{margin:0;padding-left:18px;}';
		echo '.wp-hosting-benchmark-guide-list li{margin:0 0 10px;line-height:1.5;color:#334155;}';
		echo '.wp-hosting-benchmark-summary-card{background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);}';
		echo '.wp-hosting-benchmark-tone--excellent{--wp-hosting-benchmark-tone:#0f766e;--wp-hosting-benchmark-tone-soft:#dff6f2;}';
		echo '.wp-hosting-benchmark-tone--good{--wp-hosting-benchmark-tone:#0369a1;--wp-hosting-benchmark-tone-soft:#e0f2fe;}';
		echo '.wp-hosting-benchmark-tone--fair{--wp-hosting-benchmark-tone:#b45309;--wp-hosting-benchmark-tone-soft:#fef3c7;}';
		echo '.wp-hosting-benchmark-tone--critical{--wp-hosting-benchmark-tone:#b91c1c;--wp-hosting-benchmark-tone-soft:#fee2e2;}';
		echo '.wp-hosting-benchmark-summary-grid{display:grid;grid-template-columns:minmax(260px,320px) minmax(320px,1fr);gap:28px;align-items:start;margin-bottom:24px;}';
		echo '.wp-hosting-benchmark-summary-grid>*{min-width:0;}';
		echo '.wp-hosting-benchmark-speedometer{display:grid;justify-items:center;gap:10px;padding:20px 18px 16px;border-radius:22px;background:linear-gradient(180deg,#ffffff 0%,#f6fbff 100%);border:1px solid #dbe7ee;color:var(--wp-hosting-benchmark-tone);}';
		echo '.wp-hosting-benchmark-speedometer-svg{display:block;width:min(100%,300px);height:auto;}';
		echo '.wp-hosting-benchmark-speedometer-caption{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;}';
		echo '.wp-hosting-benchmark-speedometer-value{font-size:58px;line-height:1;font-weight:700;color:var(--wp-hosting-benchmark-tone);}';
		echo '.wp-hosting-benchmark-speedometer-note{margin:0;color:#64748b;}';
		echo '.wp-hosting-benchmark-verdict-chip{background:var(--wp-hosting-benchmark-tone-soft);color:var(--wp-hosting-benchmark-tone);}';
		echo '.wp-hosting-benchmark-verdict-title{margin:14px 0 12px;font-size:30px;line-height:1.12;color:var(--wp-hosting-benchmark-ink);}';
		echo '.wp-hosting-benchmark-verdict-copy{margin:0 0 18px;font-size:15px;line-height:1.65;color:#334155;max-width:68ch;}';
		echo '.wp-hosting-benchmark-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0 0;}';
		echo '.wp-hosting-benchmark-stat-card{padding:16px;border-radius:18px;border:1px solid #dde6ef;background:#f8fafc;}';
		echo '.wp-hosting-benchmark-stat-label{display:block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#64748b;}';
		echo '.wp-hosting-benchmark-stat-value{display:block;margin-top:8px;font-size:24px;line-height:1.2;font-weight:700;color:var(--wp-hosting-benchmark-ink);overflow-wrap:anywhere;}';
		echo '.wp-hosting-benchmark-stat-copy{display:block;margin-top:6px;font-size:12px;line-height:1.45;color:#64748b;}';
		echo '.wp-hosting-benchmark-result-breakdown{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;}';
		echo '.wp-hosting-benchmark-breakdown-pill{background:#fff;border:1px solid #dbe4ee;color:#334155;text-transform:none;letter-spacing:0;font-size:13px;}';
		echo '.wp-hosting-benchmark-category-grid,.wp-hosting-benchmark-environment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}';
		echo '.wp-hosting-benchmark-category-card,.wp-hosting-benchmark-environment-item{padding:16px;border-radius:18px;border:1px solid #dbe4ee;background:#fff;}';
		echo '.wp-hosting-benchmark-category-top{display:flex;justify-content:space-between;align-items:baseline;gap:12px;}';
		echo '.wp-hosting-benchmark-category-title{font-size:14px;font-weight:600;color:var(--wp-hosting-benchmark-ink);}';
		echo '.wp-hosting-benchmark-category-score{font-size:24px;line-height:1;font-weight:700;color:var(--wp-hosting-benchmark-ink);}';
		echo '.wp-hosting-benchmark-category-bar{height:10px;margin:14px 0 10px;border-radius:999px;background:#e8eef5;overflow:hidden;}';
		echo '.wp-hosting-benchmark-category-bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--wp-hosting-benchmark-tone) 0%,#38bdf8 100%);}';
		echo '.wp-hosting-benchmark-category-meta,.wp-hosting-benchmark-environment-label{margin:0;font-size:12px;line-height:1.45;color:#64748b;}';
		echo '.wp-hosting-benchmark-environment-value{display:block;margin-top:8px;font-size:18px;font-weight:700;line-height:1.35;color:var(--wp-hosting-benchmark-ink);overflow-wrap:anywhere;}';
		echo '.wp-hosting-benchmark-table-wrap{width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;padding-bottom:8px;-webkit-overflow-scrolling:touch;}';
		echo '.wp-hosting-benchmark-table{width:100%;border-collapse:separate;border-spacing:0;min-width:100%;table-layout:auto;}';
		echo '.wp-hosting-benchmark-table th,.wp-hosting-benchmark-table td{vertical-align:top;padding:12px 14px;}';
		echo '.wp-hosting-benchmark-table th{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;background:#f8fafc;}';
		echo '.wp-hosting-benchmark-table td{line-height:1.55;overflow-wrap:break-word;}';
		echo '.wp-hosting-benchmark-table tr.active td{background:#f3fbff;}';
		echo '.wp-hosting-benchmark-table .column-actions{white-space:nowrap;}';
		echo '.wp-hosting-benchmark-results-table{min-width:0;table-layout:fixed;}';
		echo '.wp-hosting-benchmark-results-table th,.wp-hosting-benchmark-results-table td{white-space:normal;overflow-wrap:anywhere;}';
		echo '.wp-hosting-benchmark-results-table th:nth-child(1){width:21%;}.wp-hosting-benchmark-results-table th:nth-child(2){width:14%;}.wp-hosting-benchmark-results-table th:nth-child(3){width:10%;}.wp-hosting-benchmark-results-table th:nth-child(4){width:10%;}.wp-hosting-benchmark-results-table th:nth-child(5){width:9%;}.wp-hosting-benchmark-results-table th:nth-child(6){width:9%;}.wp-hosting-benchmark-results-table th:nth-child(7){width:14%;}.wp-hosting-benchmark-results-table th:nth-child(8){width:13%;}';
		echo '.wp-hosting-benchmark-history-table{min-width:740px;}';
		echo '.wp-hosting-benchmark-pill{display:inline-block;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;background:#f0f0f1;}';
		echo '.wp-hosting-benchmark-pill--success{background:#d1fae5;color:#065f46;}';
		echo '.wp-hosting-benchmark-pill--failed{background:#fee2e2;color:#991b1b;}';
		echo '.wp-hosting-benchmark-pill--unavailable{background:#fef3c7;color:#92400e;}';
		echo '.wp-hosting-benchmark-pill--unknown{background:#e5e7eb;color:#374151;}';
		echo '.wp-hosting-benchmark-page form[aria-busy="true"] .button[disabled]{cursor:wait;opacity:.75;}';
		echo '.wp-hosting-benchmark-page .notice{margin:0 0 18px;}';
		echo '@container benchmark-shell (max-width:1180px){.wp-hosting-benchmark-dashboard{grid-template-columns:1fr;}.wp-hosting-benchmark-sidebar{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));}}';
		echo '@container benchmark-shell (max-width:860px){.wp-hosting-benchmark-summary-grid{grid-template-columns:1fr;}}';
		echo '@container benchmark-shell (max-width:720px){.wp-hosting-benchmark-header{flex-direction:column;align-items:stretch;padding:24px;}.wp-hosting-benchmark-header-meta{min-width:0;}.wp-hosting-benchmark-summary-grid{grid-template-columns:1fr;}}';
		echo '@container benchmark-shell (max-width:640px){.wp-hosting-benchmark-card{padding:20px;border-radius:20px;}.wp-hosting-benchmark-header h1{font-size:28px;}.wp-hosting-benchmark-verdict-title{font-size:24px;}.wp-hosting-benchmark-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}';
		echo '@container benchmark-shell (max-width:460px){.wp-hosting-benchmark-stat-grid,.wp-hosting-benchmark-category-grid,.wp-hosting-benchmark-environment-grid{grid-template-columns:1fr;}.wp-hosting-benchmark-speedometer-value{font-size:48px;}}';
		echo '@media (max-width:1280px){.wp-hosting-benchmark-dashboard{grid-template-columns:1fr;}.wp-hosting-benchmark-sidebar{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));}}';
		echo '@media (max-width:960px){.wp-hosting-benchmark-summary-grid{grid-template-columns:1fr;}}';
		echo '@media (max-width:782px){.wp-hosting-benchmark-header{flex-direction:column;align-items:stretch;padding:24px;}.wp-hosting-benchmark-header-meta{min-width:0;}}';
		echo '@media (max-width:782px){.wp-hosting-benchmark-results-table,.wp-hosting-benchmark-results-table thead,.wp-hosting-benchmark-results-table tbody,.wp-hosting-benchmark-results-table tr,.wp-hosting-benchmark-results-table td{display:block;width:100%;}.wp-hosting-benchmark-results-table thead{position:absolute;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;overflow:hidden;white-space:nowrap;width:1px;}.wp-hosting-benchmark-results-table tr{margin:0 0 12px;border:1px solid #dbe4ee;border-radius:14px;background:#fff;overflow:hidden;}.wp-hosting-benchmark-results-table td{display:grid;grid-template-columns:minmax(110px,34%) minmax(0,1fr);gap:12px;align-items:start;padding:10px 12px;border-bottom:1px solid #eef2f7;}.wp-hosting-benchmark-results-table td:last-child{border-bottom:0;}.wp-hosting-benchmark-results-table td::before{content:attr(data-label);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b;}}';
		echo '@media (max-width:600px){.wp-hosting-benchmark-page{margin-right:0;}.wp-hosting-benchmark-card{padding:20px;border-radius:20px;}.wp-hosting-benchmark-header h1{font-size:28px;}.wp-hosting-benchmark-verdict-title{font-size:24px;}.wp-hosting-benchmark-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}';
		echo '@media (max-width:480px){.wp-hosting-benchmark-stat-grid,.wp-hosting-benchmark-category-grid,.wp-hosting-benchmark-environment-grid{grid-template-columns:1fr;}.wp-hosting-benchmark-speedometer-value{font-size:48px;}}';
		echo '</style>';

		echo '<script>';
		echo 'document.addEventListener("DOMContentLoaded",function(){';
		echo 'var markBusy=function(form,label){var button=form.querySelector("button[type=submit]");form.setAttribute("aria-busy","true");if(button){button.disabled=true;button.textContent=label;}};';
		echo 'var runForm=document.getElementById("wp-hosting-benchmark-run-form");';
		echo 'if(runForm){runForm.addEventListener("submit",function(event){var intensity=document.getElementById("wp-hosting-benchmark-intensity");if(intensity&&"high"===intensity.value&&!window.confirm(' . wp_json_encode( $high_warning ) . ')){event.preventDefault();return;}markBusy(runForm,' . wp_json_encode( $running_label ) . ');});}';
		echo 'var clearForm=document.getElementById("wp-hosting-benchmark-clear-form");';
		echo 'if(clearForm){clearForm.addEventListener("submit",function(event){if(!window.confirm(' . wp_json_encode( $clear_warning ) . ')){event.preventDefault();return;}markBusy(clearForm,' . wp_json_encode( $clearing_label ) . ');});}';
		echo '});';
		echo '</script>';
	}

	/**
	 * Render run, export, and clear controls.
	 *
	 * @param array|null $selected_run Selected run.
	 * @return void
	 */
	protected function render_controls( $selected_run ) {
		$levels = $this->runner->get_intensity_levels();

		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Start a new run', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Run benchmark', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'Use Low for a quick health check, Standard for routine benchmarking, and High when you want a denser sample and can spare a slightly longer admin request.', 'wp-hosting-benchmark' ) . '</p>';
		echo '<form id="wp-hosting-benchmark-run-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_hosting_benchmark_run" />';
		wp_nonce_field( 'wp_hosting_benchmark_run' );
		echo '<p><label for="wp-hosting-benchmark-intensity"><strong>' . esc_html__( 'Intensity', 'wp-hosting-benchmark' ) . '</strong></label></p>';
		echo '<select id="wp-hosting-benchmark-intensity" class="wp-hosting-benchmark-select" name="intensity">';

		foreach ( $levels as $key => $level ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( 'standard', $key, false ) . '>' . esc_html( $level['label'] ) . '</option>';
		}

		echo '</select>';
		echo '<ul class="wp-hosting-benchmark-guide-list">';
		foreach ( $levels as $level ) {
			echo '<li><strong>' . esc_html( $level['label'] ) . ':</strong> ' . esc_html( $level['description'] ) . '</li>';
		}
		echo '</ul>';
		echo '<div class="wp-hosting-benchmark-button-group"><button type="submit" class="button button-primary">' . esc_html__( 'Run benchmark', 'wp-hosting-benchmark' ) . '</button></div>';
		echo '</form>';
		echo '</div>';

		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Report actions', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Export or reset', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'Export the currently selected report as JSON, or clear stored history if you want to start a fresh measurement series.', 'wp-hosting-benchmark' ) . '</p>';

		if ( $selected_run ) {
			echo '<p class="wp-hosting-benchmark-subtle">' . esc_html( sprintf( __( 'Selected run: %s', 'wp-hosting-benchmark' ), $this->format_run_timestamp( $selected_run['created_at'] ) ) ) . '</p>';
			echo '<div class="wp-hosting-benchmark-button-group"><a class="button button-secondary" href="' . esc_url( $this->get_export_url( $selected_run['id'] ) ) . '">' . esc_html__( 'Export JSON', 'wp-hosting-benchmark' ) . '</a></div>';
		} else {
			echo '<p class="wp-hosting-benchmark-subtle">' . esc_html__( 'Run a benchmark to enable JSON export.', 'wp-hosting-benchmark' ) . '</p>';
		}

		echo '<form id="wp-hosting-benchmark-clear-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_hosting_benchmark_clear_history" />';
		wp_nonce_field( 'wp_hosting_benchmark_clear_history' );
		echo '<div class="wp-hosting-benchmark-button-group"><button type="submit" class="button button-link-delete" ' . disabled( ! $selected_run, true, false ) . '>' . esc_html__( 'Clear history', 'wp-hosting-benchmark' ) . '</button></div>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the selected benchmark summary.
	 *
	 * @param array|null $run Selected run.
	 * @return void
	 */
	protected function render_summary( $run ) {
		echo '<div class="card wp-hosting-benchmark-card wp-hosting-benchmark-summary-card">';
		echo '<div class="wp-hosting-benchmark-card-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Performance snapshot', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Benchmark summary', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'This report converts raw benchmark data into a quick verdict you can share with a site owner, hosting provider, or developer without digging through every row first.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';

		echo '</div>';

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'No benchmarks have been recorded yet.', 'wp-hosting-benchmark' ) . '</p>';
			echo '</div>';
			return;
		}

		$overall_score = isset( $run['scores']['overall'] ) ? (int) $run['scores']['overall'] : 0;
		$confidence    = isset( $run['scores']['confidence'] ) ? (int) $run['scores']['confidence'] : 0;
		$verdict       = $this->get_final_verdict( $run );
		$weakest       = $this->get_weakest_category( $run );
		$success_count = isset( $run['summary']['success'] ) ? (int) $run['summary']['success'] : 0;
		$failed_count  = isset( $run['summary']['failed'] ) ? (int) $run['summary']['failed'] : 0;
		$missing_count = isset( $run['summary']['unavailable'] ) ? (int) $run['summary']['unavailable'] : 0;

		echo '<div class="wp-hosting-benchmark-summary-grid wp-hosting-benchmark-tone--' . esc_attr( $verdict['tone'] ) . '">';
		echo '<div class="wp-hosting-benchmark-speedometer">';
		$this->render_speedometer( $overall_score );
		echo '<div class="wp-hosting-benchmark-speedometer-caption">' . esc_html__( 'Overall score', 'wp-hosting-benchmark' ) . '</div>';
		echo '<div class="wp-hosting-benchmark-speedometer-value">' . esc_html( number_format_i18n( $overall_score ) ) . '</div>';
		echo '<p class="wp-hosting-benchmark-speedometer-note">' . esc_html( $this->get_score_interpretation( $overall_score ) ) . '</p>';
		echo '</div>';
		echo '<div class="wp-hosting-benchmark-tone--' . esc_attr( $verdict['tone'] ) . '">';
		echo '<span class="wp-hosting-benchmark-verdict-chip">' . esc_html__( 'Final verdict', 'wp-hosting-benchmark' ) . '</span>';
		echo '<h3 class="wp-hosting-benchmark-verdict-title">' . esc_html( $verdict['title'] ) . '</h3>';
		echo '<p class="wp-hosting-benchmark-verdict-copy">' . esc_html( $verdict['message'] ) . '</p>';
		echo '<div class="wp-hosting-benchmark-stat-grid">';
		echo '<div class="wp-hosting-benchmark-stat-card"><span class="wp-hosting-benchmark-stat-label">' . esc_html__( 'Confidence', 'wp-hosting-benchmark' ) . '</span><span class="wp-hosting-benchmark-stat-value">' . esc_html( number_format_i18n( $confidence ) ) . '%</span><span class="wp-hosting-benchmark-stat-copy">' . esc_html( $this->get_confidence_interpretation( $confidence ) ) . '</span></div>';
		echo '<div class="wp-hosting-benchmark-stat-card"><span class="wp-hosting-benchmark-stat-label">' . esc_html__( 'Intensity', 'wp-hosting-benchmark' ) . '</span><span class="wp-hosting-benchmark-stat-value">' . esc_html( $this->get_intensity_label( $run['intensity'] ) ) . '</span><span class="wp-hosting-benchmark-stat-copy">' . esc_html__( 'Benchmark profile used for this run', 'wp-hosting-benchmark' ) . '</span></div>';
		echo '<div class="wp-hosting-benchmark-stat-card"><span class="wp-hosting-benchmark-stat-label">' . esc_html__( 'Total runtime', 'wp-hosting-benchmark' ) . '</span><span class="wp-hosting-benchmark-stat-value">' . esc_html( number_format_i18n( (float) $run['total_duration'], 2 ) ) . ' ' . esc_html__( 'ms', 'wp-hosting-benchmark' ) . '</span><span class="wp-hosting-benchmark-stat-copy">' . esc_html__( 'Total request time spent on the benchmark', 'wp-hosting-benchmark' ) . '</span></div>';
		echo '<div class="wp-hosting-benchmark-stat-card"><span class="wp-hosting-benchmark-stat-label">' . esc_html__( 'Watch area', 'wp-hosting-benchmark' ) . '</span><span class="wp-hosting-benchmark-stat-value">' . esc_html( $weakest ? $weakest['label'] : __( 'Balanced', 'wp-hosting-benchmark' ) ) . '</span><span class="wp-hosting-benchmark-stat-copy">' . esc_html( $weakest ? sprintf( __( 'Lowest measured category at %d/100', 'wp-hosting-benchmark' ), (int) $weakest['score'] ) : __( 'No weak measured category detected', 'wp-hosting-benchmark' ) ) . '</span></div>';
		echo '</div>';
		echo '<div class="wp-hosting-benchmark-result-breakdown">';
		echo '<span class="wp-hosting-benchmark-breakdown-pill">' . esc_html( sprintf( __( '%d successful', 'wp-hosting-benchmark' ), $success_count ) ) . '</span>';
		echo '<span class="wp-hosting-benchmark-breakdown-pill">' . esc_html( sprintf( __( '%d failed', 'wp-hosting-benchmark' ), $failed_count ) ) . '</span>';
		echo '<span class="wp-hosting-benchmark-breakdown-pill">' . esc_html( sprintf( __( '%d unavailable', 'wp-hosting-benchmark' ), $missing_count ) ) . '</span>';
		echo '<span class="wp-hosting-benchmark-breakdown-pill">' . esc_html( sprintf( __( 'Recorded %s', 'wp-hosting-benchmark' ), $this->format_run_timestamp( $run['created_at'] ) ) ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<h3>' . esc_html__( 'Category performance', 'wp-hosting-benchmark' ) . '</h3>';

		if ( empty( $run['scores']['categories'] ) ) {
			echo '<p>' . esc_html__( 'Category scores are unavailable for this run.', 'wp-hosting-benchmark' ) . '</p>';
		} else {
			echo '<div class="wp-hosting-benchmark-category-grid wp-hosting-benchmark-tone--' . esc_attr( $verdict['tone'] ) . '">';

			foreach ( $run['scores']['categories'] as $category ) {
				$category_score = null === $category['score'] ? null : (int) $category['score'];
				$bar_width      = null === $category_score ? 0 : max( 0, min( 100, $category_score ) );
				echo '<div class="wp-hosting-benchmark-category-card">';
				echo '<div class="wp-hosting-benchmark-category-top"><span class="wp-hosting-benchmark-category-title">' . esc_html( $category['label'] ) . '</span><span class="wp-hosting-benchmark-category-score">' . esc_html( null === $category_score ? __( 'N/A', 'wp-hosting-benchmark' ) : number_format_i18n( $category_score ) ) . '</span></div>';
				echo '<div class="wp-hosting-benchmark-category-bar"><span style="width:' . esc_attr( $bar_width ) . '%"></span></div>';
				echo '<p class="wp-hosting-benchmark-category-meta">' . esc_html( sprintf( __( '%d measured tests in this category', 'wp-hosting-benchmark' ), (int) $category['test_count'] ) ) . '</p>';
				echo '</div>';
			}

			echo '</div>';
		}
		echo '<p class="description">' . esc_html__( 'The overall score is a weighted average of these categories. Database, PHP/CPU, and real request-path checks usually shape the final verdict most strongly.', 'wp-hosting-benchmark' ) . '</p>';

		if ( ! empty( $run['recommendations'] ) ) {
			echo '<h3>' . esc_html__( 'Recommendations', 'wp-hosting-benchmark' ) . '</h3><ul>';
			foreach ( $run['recommendations'] as $recommendation ) {
				echo '<li>' . esc_html( $recommendation ) . '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Render guidance for interpreting benchmark reports.
	 *
	 * @param array|null $run Selected run.
	 * @return void
	 */
	protected function render_interpretation_guide( $run ) {
		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<div class="wp-hosting-benchmark-card-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Reading the results', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'How to interpret this report', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'Use this guide to explain what the benchmark means to a site owner or to decide where to optimize first.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';
		echo '</div>';

		if ( $run ) {
			$current_reading = sprintf(
				__( 'Current reading: %1$s overall with %2$s confidence.', 'wp-hosting-benchmark' ),
				$this->get_score_interpretation( (int) $run['scores']['overall'] ),
				$this->get_confidence_interpretation( (int) $run['scores']['confidence'] )
			);

			echo '<p>' . esc_html( $current_reading ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Run the benchmark once, then use the guide below to understand what the numbers mean.', 'wp-hosting-benchmark' ) . '</p>';
		}

		echo '<div class="wp-hosting-benchmark-category-grid">';

		echo '<div>';
		echo '<h3>' . esc_html__( 'Score guide', 'wp-hosting-benchmark' ) . '</h3>';
		echo '<ul class="wp-hosting-benchmark-guide-list">';
		echo '<li><strong>90-100:</strong> ' . esc_html__( 'Excellent headroom for most WordPress workloads.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li><strong>70-89:</strong> ' . esc_html__( 'Good performance for most production sites, with only targeted tuning likely needed.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li><strong>50-69:</strong> ' . esc_html__( 'Fair performance. The site is usable, but optimization work is likely worthwhile.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li><strong>0-49:</strong> ' . esc_html__( 'Needs attention. Investigate slow database work, missing cache coverage, filesystem latency, or loopback restrictions.', 'wp-hosting-benchmark' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<div>';
		echo '<h3>' . esc_html__( 'Confidence guide', 'wp-hosting-benchmark' ) . '</h3>';
		echo '<ul class="wp-hosting-benchmark-guide-list">';
		echo '<li><strong>90-100%:</strong> ' . esc_html__( 'Most planned tests completed, so the score is a reliable summary of the host.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li><strong>60-89%:</strong> ' . esc_html__( 'Some tests were limited, skipped, or unavailable. Use the score with context.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li><strong>0-59%:</strong> ' . esc_html__( 'Too many tests were blocked or failed. Treat the score as directional rather than final.', 'wp-hosting-benchmark' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<div>';
		echo '<h3>' . esc_html__( 'Detailed table guide', 'wp-hosting-benchmark' ) . '</h3>';
		echo '<ul class="wp-hosting-benchmark-guide-list">';
		echo '<li>' . esc_html__( 'Success means the test completed. Failed means the test ran into a real problem. Unavailable usually means the host blocked or could not support that test.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li>' . esc_html__( 'Lower duration is better for time-based tests. Higher ops/sec is better for throughput-based tests.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li>' . esc_html__( 'Raw metric is the original measured value before the plugin converts it into a 0-100 score.', 'wp-hosting-benchmark' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<div>';
		echo '<h3>' . esc_html__( 'Best way to compare runs', 'wp-hosting-benchmark' ) . '</h3>';
		echo '<ul class="wp-hosting-benchmark-guide-list">';
		echo '<li>' . esc_html__( 'Compare runs taken at the same intensity level. Standard and high-intensity results are not meant to be mixed directly.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li>' . esc_html__( 'Use recommendations and weak category scores to decide what to tune first.', 'wp-hosting-benchmark' ) . '</li>';
		echo '<li>' . esc_html__( 'Re-run the benchmark after host, cache, database, theme, or plugin changes to see whether performance improved or regressed.', 'wp-hosting-benchmark' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render environment details.
	 *
	 * @param array $environment Environment data.
	 * @return void
	 */
	protected function render_environment( array $environment ) {
		$rows = array(
			array(
				'key'   => 'php_version',
				'label' => __( 'PHP version', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['php_version'] ) ? $environment['php_version'] : '',
			),
			array(
				'key'   => 'wordpress_version',
				'label' => __( 'WordPress version', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['wordpress_version'] ) ? $environment['wordpress_version'] : '',
			),
			array(
				'key'   => 'database_version',
				'label' => __( 'Database version', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['database_version'] ) ? $environment['database_version'] : '',
			),
			array(
				'key'   => 'object_cache_status',
				'label' => __( 'Active object cache status', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['object_cache_status'] ) ? $environment['object_cache_status'] : '',
			),
			array(
				'key'   => 'memory_limit',
				'label' => __( 'Memory limit', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['memory_limit'] ) ? $environment['memory_limit'] : '',
			),
			array(
				'key'   => 'max_execution_time',
				'label' => __( 'Max execution time', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['max_execution_time'] ) ? $environment['max_execution_time'] : '',
			),
			array(
				'key'   => 'server_software',
				'label' => __( 'Server software', 'wp-hosting-benchmark' ),
				'value' => isset( $environment['server_software'] ) ? $environment['server_software'] : '',
			),
		);

		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<div class="wp-hosting-benchmark-card-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Environment context', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Environment details', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'These values describe the runtime environment that produced the selected benchmark report.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '<div class="wp-hosting-benchmark-environment-grid">';

		foreach ( $rows as $row ) {
			echo '<div class="wp-hosting-benchmark-environment-item"><span class="wp-hosting-benchmark-environment-label">' . esc_html( $row['label'] ) . '</span><strong class="wp-hosting-benchmark-environment-value">' . esc_html( $this->format_environment_value( $row['key'], $row['value'] ) ) . '</strong></div>';
		}

		echo '</div></div>';
	}

	/**
	 * Render detailed results.
	 *
	 * @param array|null $run Selected run.
	 * @return void
	 */
	protected function render_results( $run ) {
		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<div class="wp-hosting-benchmark-card-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Deep dive', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Detailed result table', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'Use this table when you want to inspect the exact benchmark rows behind the verdict, including raw metrics and failure messages.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';
		echo '</div>';

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'Detailed results will appear after the first benchmark run.', 'wp-hosting-benchmark' ) . '</p></div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Read each row as one benchmark. Compare like-for-like tests, use duration and ops/sec together, and treat the message column as the first place to look when a result is failed or unavailable.', 'wp-hosting-benchmark' ) . '</p>';

		if ( empty( $run['results'] ) ) {
			echo '<p>' . esc_html__( 'No detailed result rows are available for this run.', 'wp-hosting-benchmark' ) . '</p></div>';
			return;
		}

		echo '<div class="wp-hosting-benchmark-table-wrap">';
		echo '<table class="widefat striped wp-hosting-benchmark-table wp-hosting-benchmark-results-table"><thead><tr><th scope="col">' . esc_html__( 'Test', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Category', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Duration (ms)', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Operations', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Ops/sec', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Raw metric', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Message', 'wp-hosting-benchmark' ) . '</th></tr></thead><tbody>';

		foreach ( $run['results'] as $result ) {
			echo '<tr>';
			echo '<td data-label="' . esc_attr__( 'Test', 'wp-hosting-benchmark' ) . '">' . esc_html( $result['label'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Category', 'wp-hosting-benchmark' ) . '">' . esc_html( $result['category_label'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Status', 'wp-hosting-benchmark' ) . '">' . $this->render_status_badge( $result['status'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Duration (ms)', 'wp-hosting-benchmark' ) . '">' . esc_html( number_format_i18n( (float) $result['duration_ms'], 2 ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Operations', 'wp-hosting-benchmark' ) . '">' . esc_html( number_format_i18n( (int) $result['operations'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Ops/sec', 'wp-hosting-benchmark' ) . '">' . esc_html( null === $result['ops_per_second'] ? __( 'N/A', 'wp-hosting-benchmark' ) : number_format_i18n( (float) $result['ops_per_second'], 2 ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Raw metric', 'wp-hosting-benchmark' ) . '">' . esc_html( $this->format_metric( $result ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Message', 'wp-hosting-benchmark' ) . '">' . esc_html( '' !== $result['error_message'] ? $result['error_message'] : __( 'Completed successfully.', 'wp-hosting-benchmark' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Render benchmark history.
	 *
	 * @param array  $history     Benchmark history.
	 * @param string $selected_id Selected run ID.
	 * @return void
	 */
	protected function render_history( array $history, $selected_id ) {
		echo '<div class="card wp-hosting-benchmark-card">';
		echo '<div class="wp-hosting-benchmark-card-header">';
		echo '<div>';
		echo '<p class="wp-hosting-benchmark-section-label">' . esc_html__( 'Compare runs', 'wp-hosting-benchmark' ) . '</p>';
		echo '<h2>' . esc_html__( 'Benchmark history', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p class="wp-hosting-benchmark-card-intro">' . esc_html__( 'Use history to compare hosting changes over time. Select a run to refresh the verdict, summary, and detailed results for that measurement.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</div>';
		echo '</div>';

		if ( empty( $history ) ) {
			echo '<p>' . esc_html__( 'No history is stored yet.', 'wp-hosting-benchmark' ) . '</p></div>';
			return;
		}

		echo '<div class="wp-hosting-benchmark-table-wrap">';
		echo '<table class="widefat striped wp-hosting-benchmark-table wp-hosting-benchmark-history-table"><thead><tr><th scope="col">' . esc_html__( 'Date', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Intensity', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Score', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Confidence', 'wp-hosting-benchmark' ) . '</th><th scope="col">' . esc_html__( 'Results', 'wp-hosting-benchmark' ) . '</th><th scope="col" class="column-actions">' . esc_html__( 'Actions', 'wp-hosting-benchmark' ) . '</th></tr></thead><tbody>';

		foreach ( $history as $run ) {
			$is_selected = $selected_id && $selected_id === $run['id'];
			$run_date    = $this->format_run_timestamp( $run['created_at'] );

			echo '<tr' . ( $is_selected ? ' class="active" aria-current="true"' : '' ) . '>';
			echo '<td>' . ( $is_selected ? '<span class="screen-reader-text">' . esc_html__( 'Current selected report: ', 'wp-hosting-benchmark' ) . '</span>' : '' ) . esc_html( $run_date ) . '</td>';
			echo '<td>' . esc_html( $this->get_intensity_label( $run['intensity'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $run['scores']['overall'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $run['scores']['confidence'] ) ) . '%</td>';
			echo '<td>' . esc_html( sprintf( __( '%1$d success, %2$d failed, %3$d unavailable', 'wp-hosting-benchmark' ), (int) $run['summary']['success'], (int) $run['summary']['failed'], (int) $run['summary']['unavailable'] ) ) . '</td>';
			echo '<td class="column-actions"><div class="wp-hosting-benchmark-actions"><a class="button button-small" href="' . esc_url( $this->get_page_url( array( 'benchmark_id' => $run['id'] ) ) ) . '" aria-label="' . esc_attr( sprintf( __( 'View benchmark from %s', 'wp-hosting-benchmark' ), $run_date ) ) . '">' . esc_html__( 'View', 'wp-hosting-benchmark' ) . '</a><a class="button button-small" href="' . esc_url( $this->get_export_url( $run['id'] ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Export benchmark from %s as JSON', 'wp-hosting-benchmark' ), $run_date ) ) . '">' . esc_html__( 'Export', 'wp-hosting-benchmark' ) . '</a></div></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Format the raw metric column.
	 *
	 * @param array $result Result payload.
	 * @return string
	 */
	protected function format_metric( array $result ) {
		if ( empty( $result['metric_label'] ) || null === $result['metric_value'] ) {
			return __( 'N/A', 'wp-hosting-benchmark' );
		}

		$metric_unit = isset( $result['metric_unit'] ) ? trim( (string) $result['metric_unit'] ) : '';
		$metric_value = number_format_i18n( (float) $result['metric_value'], 2 );

		if ( '' === $metric_unit ) {
			return sprintf(
				/* translators: 1: metric label, 2: metric value */
				__( '%1$s: %2$s', 'wp-hosting-benchmark' ),
				$result['metric_label'],
				$metric_value
			);
		}

		return sprintf(
			/* translators: 1: metric label, 2: metric value, 3: metric unit */
			__( '%1$s: %2$s %3$s', 'wp-hosting-benchmark' ),
			$result['metric_label'],
			$metric_value,
			$metric_unit
		);
	}

	/**
	 * Render a result status badge.
	 *
	 * @param string $status Result status.
	 * @return string
	 */
	protected function render_status_badge( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'success'     => __( 'Success', 'wp-hosting-benchmark' ),
			'failed'      => __( 'Failed', 'wp-hosting-benchmark' ),
			'unavailable' => __( 'Unavailable', 'wp-hosting-benchmark' ),
		);
		$class_status = isset( $labels[ $status ] ) ? $status : 'unknown';
		$label        = isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'wp-hosting-benchmark' );
		$classes      = 'wp-hosting-benchmark-pill wp-hosting-benchmark-pill--' . sanitize_html_class( $class_status );

		return '<span class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Format a stored run timestamp for display.
	 *
	 * @param string $timestamp Stored MySQL-style timestamp.
	 * @return string
	 */
	protected function format_run_timestamp( $timestamp ) {
		$timestamp = sanitize_text_field( (string) $timestamp );

		if ( '' === $timestamp ) {
			return __( 'Unknown date', 'wp-hosting-benchmark' );
		}

		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		if ( '' === $format ) {
			$format = 'Y-m-d H:i:s';
		}

		$formatted = mysql2date( $format, $timestamp );

		return '' !== $formatted ? $formatted : $timestamp;
	}

	/**
	 * Get a translated display label for a benchmark intensity key.
	 *
	 * @param string $intensity Intensity key.
	 * @return string
	 */
	protected function get_intensity_label( $intensity ) {
		$intensity = sanitize_key( (string) $intensity );
		$levels    = $this->runner->get_intensity_levels();

		return isset( $levels[ $intensity ]['label'] ) ? $levels[ $intensity ]['label'] : __( 'Standard', 'wp-hosting-benchmark' );
	}

	/**
	 * Format an environment value for display.
	 *
	 * @param string $key   Environment key.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	protected function format_environment_value( $key, $value ) {
		if ( '' === (string) $value ) {
			return __( 'Unavailable', 'wp-hosting-benchmark' );
		}

		if ( 'max_execution_time' === $key && is_numeric( $value ) ) {
			$seconds = (int) $value;

			return sprintf(
				/* translators: %s: number of seconds */
				_n( '%s second', '%s seconds', $seconds, 'wp-hosting-benchmark' ),
				number_format_i18n( $seconds )
			);
		}

		return (string) $value;
	}

	/**
	 * Get the CSS tone for a score range.
	 *
	 * @param int $score Overall score.
	 * @return string
	 */
	protected function get_score_tone( $score ) {
		if ( $score >= 90 ) {
			return 'excellent';
		}

		if ( $score >= 70 ) {
			return 'good';
		}

		if ( $score >= 50 ) {
			return 'fair';
		}

		return 'critical';
	}

	/**
	 * Get the weakest measured category for a run.
	 *
	 * @param array $run Selected run.
	 * @return array|null
	 */
	protected function get_weakest_category( array $run ) {
		if ( empty( $run['scores']['categories'] ) || ! is_array( $run['scores']['categories'] ) ) {
			return null;
		}

		$weakest = null;

		foreach ( $run['scores']['categories'] as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['score'] ) || null === $category['score'] ) {
				continue;
			}

			$score = (int) $category['score'];

			if ( null === $weakest || $score < $weakest['score'] ) {
				$weakest = array(
					'label' => isset( $category['label'] ) ? (string) $category['label'] : '',
					'score' => $score,
				);
			}
		}

		return $weakest;
	}

	/**
	 * Build a user-facing verdict from the selected run.
	 *
	 * @param array $run Selected run.
	 * @return array
	 */
	protected function get_final_verdict( array $run ) {
		$score      = isset( $run['scores']['overall'] ) ? (int) $run['scores']['overall'] : 0;
		$confidence = isset( $run['scores']['confidence'] ) ? (int) $run['scores']['confidence'] : 0;
		$tone       = $this->get_score_tone( $score );
		$weakest    = $this->get_weakest_category( $run );

		if ( $score >= 90 ) {
			$title   = __( 'Fast and ready for production', 'wp-hosting-benchmark' );
			$message = __( 'Core WordPress paths are performing strongly, and the host shows good headroom for normal editorial, plugin, and admin workloads.', 'wp-hosting-benchmark' );
		} elseif ( $score >= 70 ) {
			$title   = __( 'Strong day-to-day performance', 'wp-hosting-benchmark' );
			$message = __( 'The site should feel responsive for routine WordPress work, with only targeted tuning likely needed in the weaker areas.', 'wp-hosting-benchmark' );
		} elseif ( $score >= 50 ) {
			$title   = __( 'Usable with room to optimize', 'wp-hosting-benchmark' );
			$message = __( 'The host can run the site reliably, but heavier queries, cache misses, or plugin overhead may become noticeable during busy periods.', 'wp-hosting-benchmark' );
		} else {
			$title   = __( 'Performance needs attention', 'wp-hosting-benchmark' );
			$message = __( 'The benchmark found enough friction that administrators or visitors may feel slowdowns during normal WordPress work.', 'wp-hosting-benchmark' );
		}

		if ( $weakest && $weakest['score'] < 80 ) {
			$message .= ' ' . sprintf( __( 'Watch %s first when you optimize next.', 'wp-hosting-benchmark' ), $weakest['label'] );
		}

		if ( $confidence < 90 ) {
			$message .= ' ' . __( 'Coverage was partial, so treat this verdict as directional rather than absolute.', 'wp-hosting-benchmark' );
		}

		return array(
			'title'   => $title,
			'message' => $message,
			'tone'    => $tone,
		);
	}

	/**
	 * Render an SVG speedometer for the overall score.
	 *
	 * @param int $score Overall score.
	 * @return void
	 */
	protected function render_speedometer( $score ) {
		$score           = max( 0, min( 100, (int) $score ) );
		$needle_rotation = -90 + ( $score * 1.8 );
		$aria_label      = sprintf( __( 'Overall benchmark score %d out of 100', 'wp-hosting-benchmark' ), $score );

		echo '<svg class="wp-hosting-benchmark-speedometer-svg" viewBox="0 0 224 144" role="img" aria-label="' . esc_attr( $aria_label ) . '">';
		echo '<path d="M 24 112 A 88 88 0 0 1 200 112" fill="none" stroke="#d5dee8" stroke-width="18" stroke-linecap="round"></path>';
		echo '<path d="M 24 112 A 88 88 0 0 1 200 112" fill="none" stroke="currentColor" stroke-width="18" stroke-linecap="round" pathLength="100" stroke-dasharray="' . esc_attr( $score ) . ' 100"></path>';
		echo '<g transform="rotate(' . esc_attr( round( $needle_rotation, 2 ) ) . ' 112 112)">';
		echo '<line x1="112" y1="112" x2="112" y2="38" stroke="currentColor" stroke-width="5" stroke-linecap="round"></line>';
		echo '</g>';
		echo '<circle cx="112" cy="112" r="8" fill="#0f172a"></circle>';
		echo '<text x="22" y="132" font-size="12" fill="#64748b">0</text>';
		echo '<text x="104" y="22" font-size="12" fill="#64748b">50</text>';
		echo '<text x="192" y="132" font-size="12" fill="#64748b">100</text>';
		echo '</svg>';
	}

	/**
	 * Get an end-user friendly overall score interpretation.
	 *
	 * @param int $score Overall score.
	 * @return string
	 */
	protected function get_score_interpretation( $score ) {
		if ( $score >= 90 ) {
			return __( 'Excellent', 'wp-hosting-benchmark' );
		}

		if ( $score >= 70 ) {
			return __( 'Good', 'wp-hosting-benchmark' );
		}

		if ( $score >= 50 ) {
			return __( 'Fair', 'wp-hosting-benchmark' );
		}

		return __( 'Needs attention', 'wp-hosting-benchmark' );
	}

	/**
	 * Get an end-user friendly confidence interpretation.
	 *
	 * @param int $confidence Confidence percentage.
	 * @return string
	 */
	protected function get_confidence_interpretation( $confidence ) {
		if ( $confidence >= 90 ) {
			return __( 'High reliability', 'wp-hosting-benchmark' );
		}

		if ( $confidence >= 60 ) {
			return __( 'Partial coverage', 'wp-hosting-benchmark' );
		}

		return __( 'Directional only', 'wp-hosting-benchmark' );
	}

	/**
	 * Build the admin page URL.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	protected function get_page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'wp-hosting-benchmark' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Build a JSON export URL.
	 *
	 * @param string $run_id Run identifier.
	 * @return string
	 */
	protected function get_export_url( $run_id ) {
		return add_query_arg(
			array(
				'action'       => 'wp_hosting_benchmark_export',
				'benchmark_id' => $run_id,
				'_wpnonce'     => wp_create_nonce( 'wp_hosting_benchmark_export_' . $run_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}
}
