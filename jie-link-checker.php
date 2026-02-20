<?php
/**
 * Plugin Name:       JIE Link Checker
 * Plugin URI:        https://jx-fund.org
 * Description:       Checks links within JIE content for validity.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            On Signals
 * Author URI:        https://onsignals.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jie-link-checker
 * Domain Path:       /languages
 *
 * @package JIE_Link_Checker
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'JIE_LINK_CHECKER_VERSION', '0.1.0' );
define( 'JIE_LINK_CHECKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIE_LINK_CHECKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JIE_LINK_CHECKER_PLUGIN_FILE', __FILE__ );

/**
 * Cron interval in seconds. Override in wp-config.php:
 *
 *   define( 'JIE_LINK_CHECKER_CRON_INTERVAL', 600 );
 *
 * @since 0.1.0
 */
if ( ! defined( 'JIE_LINK_CHECKER_CRON_INTERVAL' ) ) {
	define( 'JIE_LINK_CHECKER_CRON_INTERVAL', 600 );
}

/**
 * Number of posts to check per cron run. Override in wp-config.php:
 *
 *   define( 'JIE_LINK_CHECKER_BATCH_SIZE', 10 );
 *
 * @since 0.1.0
 */
if ( ! defined( 'JIE_LINK_CHECKER_BATCH_SIZE' ) ) {
	define( 'JIE_LINK_CHECKER_BATCH_SIZE', 10 );
}

/** Name of the custom WP-Cron schedule. */
const JIE_LINK_CHECKER_SCHEDULE = 'jie_link_checker_schedule';

/** Name of the WP-Cron event hook. */
const JIE_LINK_CHECKER_CRON_HOOK = 'jie_link_checker_run';

/**
 * Main plugin class.
 *
 * @since 0.1.0
 */
final class JIE_Link_Checker {

	/**
	 * Plugin instance.
	 *
	 * @since 0.1.0
	 * @var JIE_Link_Checker|null
	 */
	private static ?JIE_Link_Checker $instance = null;

	/**
	 * Returns the single plugin instance.
	 *
	 * @since 0.1.0
	 * @return JIE_Link_Checker
	 */
	public static function get_instance(): JIE_Link_Checker {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — registers runtime hooks.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( JIE_LINK_CHECKER_CRON_HOOK, array( $this, 'run' ) );
	}

	// -------------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------------

	/**
	 * Runs on plugin activation.
	 *
	 * Schedules the recurring cron event if it is not already scheduled.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( JIE_LINK_CHECKER_CRON_HOOK ) ) {
			wp_schedule_event( time(), JIE_LINK_CHECKER_SCHEDULE, JIE_LINK_CHECKER_CRON_HOOK );
		}
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Removes the scheduled cron event so it does not fire while inactive.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( JIE_LINK_CHECKER_CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, JIE_LINK_CHECKER_CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Cron schedule
	// -------------------------------------------------------------------------

	/**
	 * Adds the custom cron schedule interval to WordPress.
	 *
	 * @since 0.1.0
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public function register_cron_schedule( array $schedules ): array {
		$schedules[ JIE_LINK_CHECKER_SCHEDULE ] = array(
			'interval' => JIE_LINK_CHECKER_CRON_INTERVAL,
			'display'  => sprintf(
				/* translators: %d: number of seconds */
				__( 'Every %d seconds (JIE Link Checker)', 'jie-link-checker' ),
				JIE_LINK_CHECKER_CRON_INTERVAL
			),
		);

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Core logic
	// -------------------------------------------------------------------------

	/**
	 * Entry point called by the cron event.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function run(): void {
		$post_ids = $this->get_posts_to_check();
		$results  = array();

		foreach ( $post_ids as $post_id ) {
			$results[] = $this->check_post_link( $post_id );
		}

		$this->maybe_log( $post_ids, $results );
	}

	/**
	 * Returns an ordered list of post IDs to check this cron run.
	 *
	 * Fills up to JIE_LINK_CHECKER_BATCH_SIZE slots:
	 *   – Query A: published jie posts that have never been checked, oldest first.
	 *   – Query B: if A returned fewer than BATCH_SIZE results, fills the
	 *     remainder with posts checked longest ago, oldest timestamp first.
	 *
	 * @since 0.1.0
	 * @return int[]
	 */
	private function get_posts_to_check(): array {
		$batch_size = (int) JIE_LINK_CHECKER_BATCH_SIZE;

		$shared_args = array(
			'post_type'              => 'jie',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		// Query A — posts without a checked timestamp yet.
		$query_a = new WP_Query(
			array_merge(
				$shared_args,
				array(
					'posts_per_page' => $batch_size,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'meta_query'     => array(
						array(
							'key'     => 'jie-link-checker-checked',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);

		$ids = array_map( 'intval', $query_a->posts );

		// Query B — fill remaining slots with the least-recently-checked posts.
		$remaining = $batch_size - count( $ids );

		if ( $remaining > 0 ) {
			$query_b = new WP_Query(
				array_merge(
					$shared_args,
					array(
						'posts_per_page' => $remaining,
						'meta_key'       => 'jie-link-checker-checked',
						'orderby'        => 'meta_value_num',
						'order'          => 'ASC',
						// Exclude IDs already collected from Query A.
						'post__not_in'   => $ids ?: array( 0 ),
					)
				)
			);

			$ids = array_merge( $ids, array_map( 'intval', $query_b->posts ) );
		}

		return $ids;
	}

	/**
	 * Checks the teaserUrl of a single post and updates its link-checker meta.
	 *
	 * Meta written by this method:
	 *   jie-link-checker-checked — Unix timestamp, updated on every call.
	 *   jie-link-checker-broken  — Set to '1' on a confirmed 404.
	 *                              Deleted on any non-404 HTTP response
	 *                              (auto-heals previously flagged posts).
	 *                              Left untouched on network/WP_Error failures.
	 *
	 * @since 0.1.0
	 * @param int $post_id Post to check.
	 * @return array{post_id: int, url: string, outcome: string, code: int|null}
	 */
	private function check_post_link( int $post_id ): array {
		$timestamp = (string) time();

		// teaserUrl is an ACF URL field stored as standard post meta.
		$url = (string) get_post_meta( $post_id, 'teaserUrl', true );

		// Nothing to check — stamp and move on so the post rotates out of Query A.
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			update_post_meta( $post_id, 'jie-link-checker-checked', $timestamp );
			return array( 'post_id' => $post_id, 'url' => $url, 'outcome' => 'skipped', 'code' => null );
		}

		$request_args = array(
			'timeout'            => 10,
			'redirection'        => 5,
			'reject_unsafe_urls' => true,
			'sslverify'          => true,
		);

		// Prefer HEAD (no body download). Fall back to streaming GET when the
		// server returns 405 or the HEAD request itself fails at the network layer.
		$response = wp_remote_head( $url, $request_args );

		if ( is_wp_error( $response ) || 405 === (int) wp_remote_retrieve_response_code( $response ) ) {
			// stream => true writes the body to a temp file instead of memory.
			$response = wp_remote_get(
				$url,
				array_merge( $request_args, array( 'stream' => true ) )
			);
		}

		// Network / infrastructure failure — do not alter the broken flag.
		// The checked timestamp is still updated so the post rotates in the queue.
		if ( is_wp_error( $response ) ) {
			update_post_meta( $post_id, 'jie-link-checker-checked', $timestamp );
			return array( 'post_id' => $post_id, 'url' => $url, 'outcome' => 'network_error', 'code' => null );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			update_post_meta( $post_id, 'jie-link-checker-broken', '1' );
		} else {
			// Any confirmed non-404 response clears a previously set broken flag.
			delete_post_meta( $post_id, 'jie-link-checker-broken' );
		}

		update_post_meta( $post_id, 'jie-link-checker-checked', $timestamp );

		return array(
			'post_id' => $post_id,
			'url'     => $url,
			'outcome' => 404 === $status_code ? 'broken' : 'ok',
			'code'    => $status_code,
		);
	}

	/**
	 * Appends a structured entry to the log file — development environments only.
	 *
	 * The log is automatically trimmed to a maximum of 100 entries; the oldest
	 * entries are dropped when the limit is exceeded.
	 *
	 * Log file: {plugin_dir}/jie-link-checker.log
	 *
	 * @since 0.1.0
	 * @param int[]                                              $post_ids IDs selected for this run.
	 * @param array{post_id:int,url:string,outcome:string,code:int|null}[] $results  One result per post.
	 * @return void
	 */
	private function maybe_log( array $post_ids, array $results ): void {
		if ( 'development' !== wp_get_environment_type() ) {
			return;
		}

		$log_file  = JIE_LINK_CHECKER_PLUGIN_DIR . 'jie-link-checker.log';
		$separator = "\n---\n";

		// Build the entry header.
		$id_list = empty( $post_ids ) ? '(none)' : implode( ', ', $post_ids );
		$lines   = array(
			'[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC]  Batch: ' . count( $post_ids ) . ' post(s)  [' . $id_list . ']',
		);

		// One line per post result.
		foreach ( $results as $r ) {
			$label = match ( $r['outcome'] ) {
				'ok'            => sprintf( '%d OK', $r['code'] ),
				'broken'        => sprintf( '%d BROKEN', $r['code'] ),
				'network_error' => 'NETWORK ERROR',
				'skipped'       => 'SKIPPED',
				default         => strtoupper( $r['outcome'] ),
			};

			$url_part = ! empty( $r['url'] ) ? $r['url'] : '(no valid teaserUrl)';

			$lines[] = sprintf( '  Post %-8d  %-18s  %s', $r['post_id'], $label, $url_part );
		}

		$new_entry = implode( "\n", $lines );

		// Read existing content, trim to 99 entries, append the new one.
		$existing = file_exists( $log_file ) ? (string) file_get_contents( $log_file ) : '';

		if ( '' !== $existing ) {
			$entries = explode( $separator, trim( $existing ) );

			if ( count( $entries ) >= 100 ) {
				$entries = array_slice( $entries, -99 );
			}

			$content = implode( $separator, $entries ) . $separator . $new_entry;
		} else {
			$content = $new_entry;
		}

		file_put_contents( $log_file, $content, LOCK_EX );
	}

	// -------------------------------------------------------------------------
	// i18n
	// -------------------------------------------------------------------------

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'jie-link-checker',
			false,
			dirname( plugin_basename( JIE_LINK_CHECKER_PLUGIN_FILE ) ) . '/languages'
		);
	}
}

// Register activation / deactivation hooks before the plugin is initialised.
register_activation_hook( JIE_LINK_CHECKER_PLUGIN_FILE, array( 'JIE_Link_Checker', 'activate' ) );
register_deactivation_hook( JIE_LINK_CHECKER_PLUGIN_FILE, array( 'JIE_Link_Checker', 'deactivate' ) );

/**
 * Returns the plugin instance.
 *
 * @since 0.1.0
 * @return JIE_Link_Checker
 */
function jie_link_checker(): JIE_Link_Checker {
	return JIE_Link_Checker::get_instance();
}

// Initialise the plugin.
jie_link_checker();
