<?php
/**
 * Logger.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists log entries to custom table.
 */
class WhoChanged_Logger {

	/**
	 * Log an event.
	 *
	 * @param string $action_type Action type.
	 * @param string $object_type Object type.
	 * @param string $object_name Object name.
	 * @param array  $changes     Structured changes.
	 * @param int    $user_id     User ID.
	 * @return void
	 */
	public function log( $action_type, $object_type, $object_name, $changes, $user_id = 0 ) {
		$event = array(
			'type'        => sanitize_key( $action_type ),
			'label'       => sanitize_text_field( str_replace( '_', ' ', (string) $action_type ) ),
			'meta'        => array(
				'object_type' => sanitize_key( $object_type ),
				'object_name' => sanitize_text_field( $object_name ),
			),
			'diff'        => is_array( $changes ) ? $changes : array(),
			'action_type' => sanitize_key( $action_type ),
			'object_type' => sanitize_key( $object_type ),
			'object_name' => sanitize_text_field( $object_name ),
		);

		$this->log_event( $event, $user_id );
	}

	/**
	 * Log normalized event.
	 *
	 * @param array<string, mixed> $event   Event payload.
	 * @param int                  $user_id User ID.
	 * @return void
	 */
	public function log_event( array $event, $user_id = 0 ) {
		global $wpdb;

		$table_name = WhoChanged_Database::table_name();

		$normalized         = $this->normalize_event( $event );
		$normalized['diff'] = $this->normalize_diff_payload(
			isset( $normalized['diff'] ) && is_array( $normalized['diff'] ) ? $normalized['diff'] : array()
		);
		$sanitized_changes  = $this->sanitize_changes( $normalized );
		$meta_payload       = isset( $normalized['meta'] ) && is_array( $normalized['meta'] ) ? $normalized['meta'] : array();
		$encoded_changes    = wp_json_encode( $sanitized_changes );
		$encoded_meta       = wp_json_encode( $meta_payload );
		$resolved_user_id   = absint( $user_id );
		$group_id           = $this->get_active_group_id( $resolved_user_id, (string) $normalized['type'] );

		if ( ! $this->should_log_user( $resolved_user_id ) ) {
			return;
		}

		if ( false === $encoded_changes ) {
			$encoded_changes = wp_json_encode( array() );
		}
		if ( false === $encoded_meta ) {
			$encoded_meta = wp_json_encode( array() );
		}

		if ( ! $this->event_has_meaningful_change( $normalized ) ) {
			return;
		}

		if ( $this->should_skip_duplicate( $table_name, $resolved_user_id, $normalized ) ) {
			return;
		}

		if ( $this->merge_into_active_group( $table_name, $resolved_user_id, $group_id, $normalized ) ) {
			return;
		}

		$created_at_gmt = current_time( 'mysql', 1 );

		$wpdb->insert(
			$table_name,
			array(
				'user_id'     => $resolved_user_id,
				'group_id'    => sanitize_text_field( $group_id ),
				'type'        => sanitize_key( (string) $normalized['type'] ),
				'label'       => sanitize_text_field( (string) $normalized['label'] ),
				'meta'        => $encoded_meta,
				'action_type' => sanitize_key( (string) $normalized['action_type'] ),
				'object_type' => sanitize_key( (string) $normalized['object_type'] ),
				'object_name' => sanitize_text_field( (string) $normalized['object_name'] ),
				'changes'     => $encoded_changes,
				'created_at'  => $created_at_gmt,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		/**
		 * Fires after a WhoChanged activity log row is inserted.
		 *
		 * Premium packages may listen for email alerts; the Free package has
		 * no listeners for this action.
		 *
		 * @param array<string, mixed> $normalized Normalized event.
		 * @param int                  $resolved_user_id User id.
		 * @param string               $created_at_gmt Created at (mysql, GMT).
		 */
		do_action( 'whochanged_log_inserted', $normalized, $resolved_user_id, $created_at_gmt );
	}

	/**
	 * Decide whether to log this event for the given user.
	 *
	 * Free behavior: skip system events when disabled; log all real users.
	 * Premium packages may further restrict roles via the
	 * `whochanged_should_log_user` filter.
	 *
	 * @param int $user_id Resolved user id (0 = system).
	 * @return bool
	 */
	private function should_log_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return 1 === (int) get_option( 'whochanged_pro_include_system_logs', 1 );
		}

		/**
		 * Filters whether a user event should be logged.
		 *
		 * @param bool $should_log Whether to log.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'whochanged_should_log_user', true, $user_id );
	}

	/**
	 * Decide if event should be logged based on meaningful changes.
	 *
	 * @param array<string, mixed> $event Normalized event.
	 * @return bool
	 */
	private function event_has_meaningful_change( array $event ) {
		$type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$diff = isset( $event['diff'] ) && is_array( $event['diff'] ) ? $event['diff'] : array();

		$update_types = array(
			'option_updated',
			'option_added',
			'option_deleted',
			'theme_changed',
			'menu_saved',
			'customize_save_after',
			'plugin_upgraded',
			'theme_upgraded',
			'wordpress_updated',
		);

		if ( in_array( $type, $update_types, true ) ) {
			return $this->diff_has_change( $diff );
		}

		return true;
	}

	/**
	 * Check if diff contains at least one actual before/after change.
	 *
	 * @param array<string, mixed> $diff Diff payload.
	 * @return bool
	 */
	private function diff_has_change( array $diff ) {
		if ( empty( $diff ) ) {
			return false;
		}

		foreach ( $diff as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$has_before = array_key_exists( 'before', $change );
			$has_after  = array_key_exists( 'after', $change );

			if ( $has_before || $has_after ) {
				$before = $has_before ? $change['before'] : null;
				$after  = $has_after ? $change['after'] : null;
				if ( wp_json_encode( $before ) !== wp_json_encode( $after ) ) {
					return true;
				}
				continue;
			}

			if ( $this->diff_has_change( $change ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Skip duplicate event types within 2 seconds.
	 *
	 * @param string               $table_name Table name.
	 * @param int                  $user_id    User ID.
	 * @param array<string, mixed> $event      Event payload.
	 * @return bool
	 */
	private function should_skip_duplicate( $table_name, $user_id, array $event ) {
		global $wpdb;

		$type        = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$object_type = isset( $event['object_type'] ) ? sanitize_key( (string) $event['object_type'] ) : '';
		$object_name = isset( $event['object_name'] ) ? sanitize_text_field( (string) $event['object_name'] ) : '';
		if ( $this->is_auth_event_type( $type ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$last = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT type, object_type, object_name, created_at, changes
				FROM {$table_name}
				WHERE user_id = %d
				ORDER BY id DESC
				LIMIT 1",
				absint( $user_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $last ) ) {
			return false;
		}

		$last_type = isset( $last['type'] ) ? sanitize_key( (string) $last['type'] ) : '';

		if ( $last_type !== $type ) {
			return false;
		}

		$last_object_type = isset( $last['object_type'] ) ? sanitize_key( (string) $last['object_type'] ) : '';
		$last_object_name = isset( $last['object_name'] ) ? sanitize_text_field( (string) $last['object_name'] ) : '';
		if ( $last_object_type !== $object_type || $last_object_name !== $object_name ) {
			return false;
		}

		$last_time = isset( $last['created_at'] ) ? strtotime( (string) $last['created_at'] . ' UTC' ) : 0;
		if ( $last_time <= ( time() - 2 ) ) {
			return false;
		}

		$last_event = json_decode( isset( $last['changes'] ) ? (string) $last['changes'] : '[]', true );
		if ( ! is_array( $last_event ) || ! isset( $last_event['type'] ) ) {
			return false;
		}

		return sanitize_key( (string) $last_event['type'] ) === $type;
	}

	/**
	 * Merge event into existing row in current active group.
	 *
	 * @param string               $table_name Table name.
	 * @param int                  $user_id    User ID.
	 * @param string               $group_id   Active group ID.
	 * @param array<string, mixed> $event      Event payload.
	 * @return bool
	 */
	private function merge_into_active_group( $table_name, $user_id, $group_id, array $event ) {
		global $wpdb;

		$incoming_type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		if ( $this->is_plugin_lifecycle_event_type( $incoming_type ) ) {
			return false;
		}
		if ( $this->is_content_bulk_event_type( $incoming_type ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, changes, meta
				FROM {$table_name}
				WHERE user_id = %d AND group_id = %s
				ORDER BY id DESC
				LIMIT 1",
				absint( $user_id ),
				sanitize_text_field( $group_id )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $existing ) ) {
			return false;
		}

		$existing_changes = json_decode( isset( $existing['changes'] ) ? (string) $existing['changes'] : '[]', true );
		$existing_type    = is_array( $existing_changes ) && isset( $existing_changes['type'] ) ? sanitize_key( (string) $existing_changes['type'] ) : '';

		if ( $this->is_auth_event_type( $incoming_type ) || $this->is_auth_event_type( $existing_type ) ) {
			return false;
		}
		if ( $this->is_plugin_lifecycle_event_type( $existing_type ) ) {
			return false;
		}
		if ( $this->is_content_bulk_event_type( $existing_type ) ) {
			return false;
		}
		$existing_meta = json_decode( isset( $existing['meta'] ) ? (string) $existing['meta'] : '[]', true );

		$merged_changes = $this->merge_event_payloads(
			is_array( $existing_changes ) ? $existing_changes : array(),
			$event
		);
		$merged_meta    = $this->merge_event_meta(
			is_array( $existing_meta ) ? $existing_meta : array(),
			isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array()
		);

		$encoded_changes = wp_json_encode( $this->sanitize_changes( $merged_changes ) );
		$encoded_meta    = wp_json_encode( $merged_meta );

		if ( false === $encoded_changes ) {
			$encoded_changes = wp_json_encode( array() );
		}

		if ( false === $encoded_meta ) {
			$encoded_meta = wp_json_encode( array() );
		}

		$wpdb->update(
			$table_name,
			array(
				'changes'    => $encoded_changes,
				'meta'       => $encoded_meta,
				'created_at' => current_time( 'mysql', 1 ),
			),
			array(
				'id' => absint( $existing['id'] ),
			),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Merge event payloads preferring high-priority event type.
	 *
	 * @param array<string, mixed> $current Current event payload.
	 * @param array<string, mixed> $incoming Incoming event payload.
	 * @return array<string, mixed>
	 */
	private function merge_event_payloads( array $current, array $incoming ) {
		$current_type  = isset( $current['type'] ) ? sanitize_key( (string) $current['type'] ) : '';
		$incoming_type = isset( $incoming['type'] ) ? sanitize_key( (string) $incoming['type'] ) : '';

		$priority = array(
			'theme_changed'   => 100,
			'plugin_upgraded' => 90,
			'theme_upgraded'  => 80,
			'menu_saved'      => 70,
			'option_updated'  => 30,
		);

		$current_priority  = isset( $priority[ $current_type ] ) ? $priority[ $current_type ] : 0;
		$incoming_priority = isset( $priority[ $incoming_type ] ) ? $priority[ $incoming_type ] : 0;

		$base = $incoming_priority >= $current_priority ? $incoming : $current;

		$base['diff'] = $this->merge_diff_payloads(
			isset( $current['diff'] ) && is_array( $current['diff'] ) ? $current['diff'] : array(),
			isset( $incoming['diff'] ) && is_array( $incoming['diff'] ) ? $incoming['diff'] : array()
		);
		$base['diff'] = $this->normalize_diff_payload( $base['diff'] );

		$base['meta'] = $this->merge_event_meta(
			isset( $current['meta'] ) && is_array( $current['meta'] ) ? $current['meta'] : array(),
			isset( $incoming['meta'] ) && is_array( $incoming['meta'] ) ? $incoming['meta'] : array()
		);

		return $base;
	}

	/**
	 * Merge diff payloads while preserving meaningful "before" values.
	 *
	 * @param array<string, mixed> $current_diff Existing diff.
	 * @param array<string, mixed> $incoming_diff New diff.
	 * @return array<string, mixed>
	 */
	private function merge_diff_payloads( array $current_diff, array $incoming_diff ) {
		$merged = $current_diff;

		foreach ( $incoming_diff as $key => $change ) {
			if ( ! is_array( $change ) ) {
				$merged[ $key ] = $change;
				continue;
			}

			if ( ! isset( $merged[ $key ] ) || ! is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = $change;
				continue;
			}

			$current_change = $merged[ $key ];

			$before_incoming = isset( $change['before'] ) ? $change['before'] : null;
			$after_incoming  = isset( $change['after'] ) ? $change['after'] : null;
			$before_current  = isset( $current_change['before'] ) ? $current_change['before'] : null;

			$merged_change = array(
				'before' => $this->is_empty_like( $before_current ) ? $before_incoming : $before_current,
				'after'  => $after_incoming,
			);

			if ( WhoChanged_Diff::values_are_equal( $merged_change['before'], $merged_change['after'] ) ) {
				unset( $merged[ $key ] );
				continue;
			}

			$merged[ $key ] = $merged_change;
		}

		return $merged;
	}

	/**
	 * Remove unchanged entries from diff payload recursively.
	 *
	 * @param array<string, mixed> $diff Diff payload.
	 * @return array<string, mixed>
	 */
	private function normalize_diff_payload( array $diff ) {
		$normalized = array();

		foreach ( $diff as $key => $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$has_before = array_key_exists( 'before', $change );
			$has_after  = array_key_exists( 'after', $change );

			if ( $has_before || $has_after ) {
				$before = $has_before ? $change['before'] : null;
				$after  = $has_after ? $change['after'] : null;

				if ( WhoChanged_Diff::values_are_equal( $before, $after ) ) {
					continue;
				}

				$normalized[ $key ] = array(
					'before' => $before,
					'after'  => $after,
				);
				continue;
			}

			$child = $this->normalize_diff_payload( $change );
			if ( ! empty( $child ) ) {
				$normalized[ $key ] = $child;
			}
		}

		return $normalized;
	}

	/**
	 * Merge meta arrays preserving menu assignment list entries.
	 *
	 * @param array<string, mixed> $current_meta Existing meta.
	 * @param array<string, mixed> $incoming_meta New meta.
	 * @return array<string, mixed>
	 */
	private function merge_event_meta( array $current_meta, array $incoming_meta ) {
		if ( array_values( $current_meta ) === $current_meta && array_values( $incoming_meta ) === $incoming_meta ) {
			return array_values( array_merge( $current_meta, $incoming_meta ) );
		}

		return array_merge( $current_meta, $incoming_meta );
	}

	/**
	 * Check if a value should be treated as empty-like.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_empty_like( $value ) {
		return null === $value || '' === $value;
	}

	/**
	 * Add created_at bounds (inclusive) from site-local calendar dates.
	 *
	 * @param array<int, string>     $clauses WHERE fragments.
	 * @param array<int, string|int> $params  Prepared values.
	 * @param string                 $from_ymd Start Y-m-d.
	 * @param string                 $to_ymd   End Y-m-d.
	 * @return void
	 */
	private function append_created_at_range_clauses( array &$clauses, array &$params, $from_ymd, $to_ymd ) {
		$from_ymd = sanitize_text_field( (string) $from_ymd );
		$to_ymd   = sanitize_text_field( (string) $to_ymd );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_ymd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_ymd ) ) {
			return;
		}
		$pf = array_map( 'absint', explode( '-', $from_ymd ) );
		$pt = array_map( 'absint', explode( '-', $to_ymd ) );
		if ( 3 !== count( $pf ) || 3 !== count( $pt ) ) {
			return;
		}
		if ( ! checkdate( $pf[1], $pf[2], $pf[0] ) || ! checkdate( $pt[1], $pt[2], $pt[0] ) ) {
			return;
		}
		if ( $from_ymd > $to_ymd ) {
			$swap     = $from_ymd;
			$from_ymd = $to_ymd;
			$to_ymd   = $swap;
		}
		$start_local = $from_ymd . ' 00:00:00';
		$end_local   = $to_ymd . ' 23:59:59';
		$start_gmt   = get_gmt_from_date( $start_local, 'Y-m-d H:i:s' );
		$end_gmt     = get_gmt_from_date( $end_local, 'Y-m-d H:i:s' );
		if ( is_string( $start_gmt ) && '' !== $start_gmt && is_string( $end_gmt ) && '' !== $end_gmt ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $start_gmt;
			$clauses[] = 'created_at <= %s';
			$params[]  = $end_gmt;
		}
	}

	/**
	 * Get logs with pagination and filters.
	 *
	 * @param int    $page             Current page.
	 * @param int    $per_page         Per-page limit.
	 * @param int    $user_id          User filter (0 = all users).
	 * @param string $action_type      Action filter.
	 * @param string $log_scope        'user' = real users only (user_id > 0); 'system' = background (user_id = 0).
	 * @param string $date_from_ymd    Start Y-m-d site timezone; empty with empty $date_to = no date filter.
	 * @param string $date_to_ymd      End Y-m-d site timezone (inclusive).
	 * @param string $search           Search keyword.
	 * @return array<string, mixed>
	 */
	public function get_logs( $page = 1, $per_page = 20, $user_id = 0, $action_type = '', $log_scope = 'user', $date_from_ymd = '', $date_to_ymd = '', $search = '' ) {
		global $wpdb;

		$table_name = WhoChanged_Database::table_name();
		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, absint( $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;

		$sanitized_user_id     = absint( $user_id );
		$sanitized_action_type = sanitize_key( $action_type );
		$log_scope             = sanitize_key( (string) $log_scope );
		if ( ! in_array( $log_scope, array( 'user', 'system' ), true ) ) {
			$log_scope = 'user';
		}
		$date_from_ymd = sanitize_text_field( (string) $date_from_ymd );
		$date_to_ymd   = sanitize_text_field( (string) $date_to_ymd );
		$search        = sanitize_text_field( (string) $search );

		$clauses = array();
		$params  = array();

		if ( 'system' === $log_scope ) {
			$clauses[] = 'user_id = %d';
			$params[]  = 0;
		} elseif ( $sanitized_user_id > 0 ) {
			$clauses[] = 'user_id = %d';
			$params[]  = $sanitized_user_id;
		} else {
			$clauses[] = 'user_id > 0';
		}

		if ( '' !== $sanitized_action_type ) {
			$clauses[] = 'action_type = %s';
			$params[]  = $sanitized_action_type;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// Keep it on a few searchable string columns to avoid heavy JSON searches.
			$clauses[] = '(label LIKE %s OR action_type LIKE %s OR object_type LIKE %s OR object_name LIKE %s OR type LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( '' !== $date_from_ymd && '' !== $date_to_ymd ) {
			$this->append_created_at_range_clauses( $clauses, $params, $date_from_ymd, $date_to_ymd );
		}

		$where_sql = ! empty( $clauses ) ? implode( ' AND ', $clauses ) : '1=1';

		$count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built only from fixed clauses + placeholders above.
			$total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no placeholders when only literal clauses (e.g. user_id > 0).
			$total_items = (int) $wpdb->get_var( $count_sql );
		}

		$data_params = array_merge( $params, array( $per_page, $offset ) );
		$data_sql    = "SELECT id, user_id, group_id, type, label, meta, action_type, object_type, object_name, changes, created_at
			FROM {$table_name}
			WHERE {$where_sql}
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is built from controlled fragments; values passed via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A );

		return array(
			'items'       => is_array( $rows ) ? $rows : array(),
			'total_items' => $total_items,
			'total_pages' => (int) ceil( max( 1, $total_items ) / $per_page ),
		);
	}

	/**
	 * Get lightweight analytics for current filter.
	 *
	 * @param int    $user_id User filter (0 = all users / system depends on scope).
	 * @param string $action_type Action filter.
	 * @param string $log_scope 'user' (user_id > 0) or 'system' (user_id = 0 only).
	 * @param string $date_from_ymd Start Y-m-d site timezone.
	 * @param string $date_to_ymd End Y-m-d site timezone (inclusive).
	 * @param string $search Search keyword.
	 * @return array<string, mixed>
	 */
	public function get_analytics_counts( $user_id = 0, $action_type = '', $log_scope = 'user', $date_from_ymd = '', $date_to_ymd = '', $search = '' ) {
		global $wpdb;

		$table_name = WhoChanged_Database::table_name();

		list( $clauses, $params ) = $this->build_analytics_base_where( $user_id, $action_type, $log_scope, $search );

		$date_from_ymd = sanitize_text_field( (string) $date_from_ymd );
		$date_to_ymd   = sanitize_text_field( (string) $date_to_ymd );

		if ( '' !== $date_from_ymd && '' !== $date_to_ymd ) {
			$this->append_created_at_range_clauses( $clauses, $params, $date_from_ymd, $date_to_ymd );
		}

		$where_sql = ! empty( $clauses ) ? implode( ' AND ', $clauses ) : '1=1';

		$count_sql   = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		$total_items = 0;
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built only from fixed clauses + placeholders above.
			$total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no placeholders when only literal clauses (e.g. user_id > 0).
			$total_items = (int) $wpdb->get_var( $count_sql );
		}

		$analytics = array(
			'total_items'          => $total_items,
			'unique_users'         => 0,
			'unique_action_types'  => 0,
			'unique_object_types'  => 0,
			'top_action_types'     => array(),
			'top_users'            => array(),
			'top_object_types'     => array(),
			'top_days'             => array(),
			'top_hours'            => array(),
			'heatmap'              => array(),
			'top_changed_items'    => array(),
			'first_activity_gmt'   => '',
			'previous_total_items' => 0,
			'previous_available'   => false,
		);

		$distinct_users_sql        = "SELECT COUNT(DISTINCT user_id) FROM {$table_name} WHERE {$where_sql}";
		$analytics['unique_users'] = ! empty( $params ) ? (int) $wpdb->get_var( $wpdb->prepare( $distinct_users_sql, $params ) ) : (int) $wpdb->get_var( $distinct_users_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$distinct_actions_sql             = "SELECT COUNT(DISTINCT action_type) FROM {$table_name} WHERE {$where_sql}";
		$analytics['unique_action_types'] = ! empty( $params ) ? (int) $wpdb->get_var( $wpdb->prepare( $distinct_actions_sql, $params ) ) : (int) $wpdb->get_var( $distinct_actions_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$distinct_object_sql              = "SELECT COUNT(DISTINCT object_type) FROM {$table_name} WHERE {$where_sql}";
		$analytics['unique_object_types'] = ! empty( $params ) ? (int) $wpdb->get_var( $wpdb->prepare( $distinct_object_sql, $params ) ) : (int) $wpdb->get_var( $distinct_object_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$top_types_sql                 = "SELECT action_type, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY action_type ORDER BY c DESC LIMIT 6";
		$analytics['top_action_types'] = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $top_types_sql, $params ), ARRAY_A ) : $wpdb->get_results( $top_types_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$top_users_sql          = "SELECT user_id, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY user_id ORDER BY c DESC LIMIT 6";
		$analytics['top_users'] = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $top_users_sql, $params ), ARRAY_A ) : $wpdb->get_results( $top_users_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$top_object_types_sql          = "SELECT object_type, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY object_type ORDER BY c DESC LIMIT 6";
		$analytics['top_object_types'] = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $top_object_types_sql, $params ), ARRAY_A ) : $wpdb->get_results( $top_object_types_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// LIMIT is a safety cap, not a day-count filter — the WHERE clause already
		// scopes rows to the selected range. 90 comfortably covers every built-in
		// preset (today/7d/30d) plus most custom ranges.
		$top_days_sql          = "SELECT DATE(created_at) AS day, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 90";
		$analytics['top_days'] = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $top_days_sql, $params ), ARRAY_A ) : $wpdb->get_results( $top_days_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Hour-of-day distribution, shifted to the site's local timezone so "peak
		// hour" insights match what the admin sees on their clock, not UTC.
		$offset_seconds         = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$top_hours_sql          = "SELECT HOUR(DATE_ADD(created_at, INTERVAL %d SECOND)) AS h, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY h ORDER BY h ASC";
		$top_hours_params       = array_merge( array( $offset_seconds ), $params );
		$analytics['top_hours'] = $wpdb->get_results( $wpdb->prepare( $top_hours_sql, $top_hours_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Weekday × hour heatmap (site-local time) — the same offset shift as
		// top_hours, applied to both the day-of-week and hour extraction so the
		// two stay in sync. DAYOFWEEK() returns 1 (Sunday) .. 7 (Saturday).
		$heatmap_sql          = "SELECT DAYOFWEEK(DATE_ADD(created_at, INTERVAL %d SECOND)) AS dow, HOUR(DATE_ADD(created_at, INTERVAL %d SECOND)) AS h, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} GROUP BY dow, h";
		$heatmap_params       = array_merge( array( $offset_seconds, $offset_seconds ), $params );
		$analytics['heatmap'] = $wpdb->get_results( $wpdb->prepare( $heatmap_sql, $heatmap_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Most-changed specific items — e.g. "Homepage" edited 12 times — the
		// concrete answer to "what keeps changing", one level more specific than
		// top_object_types (which only knows the type, e.g. "Post").
		$top_names_sql                  = "SELECT object_type, object_name, COUNT(*) AS c FROM {$table_name} WHERE {$where_sql} AND object_name <> '' GROUP BY object_type, object_name ORDER BY c DESC LIMIT 5";
		$analytics['top_changed_items'] = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $top_names_sql, $params ), ARRAY_A ) : $wpdb->get_results( $top_names_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Earliest matching record — powers "tracking since" context and the
		// average-per-day metric when no explicit date range is selected.
		$first_activity_sql              = "SELECT MIN(created_at) FROM {$table_name} WHERE {$where_sql}";
		$analytics['first_activity_gmt'] = ! empty( $params ) ? $wpdb->get_var( $wpdb->prepare( $first_activity_sql, $params ) ) : $wpdb->get_var( $first_activity_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Period-over-period comparison: only meaningful when the admin picked a
		// bounded range (a preset or custom dates). "All time" has no comparable
		// "previous period", so we leave it unavailable rather than showing a
		// misleading 0% delta.
		if ( '' !== $date_from_ymd && '' !== $date_to_ymd ) {
			$previous_range = $this->shift_date_range_back( $date_from_ymd, $date_to_ymd );
			if ( null !== $previous_range ) {
				list( $previous_clauses, $previous_params ) = $this->build_analytics_base_where( $user_id, $action_type, $log_scope, $search );
				$this->append_created_at_range_clauses( $previous_clauses, $previous_params, $previous_range['from'], $previous_range['to'] );
				$previous_where_sql = ! empty( $previous_clauses ) ? implode( ' AND ', $previous_clauses ) : '1=1';
				$previous_count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$previous_where_sql}";

				$analytics['previous_total_items'] = ! empty( $previous_params )
					? (int) $wpdb->get_var( $wpdb->prepare( $previous_count_sql, $previous_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					: (int) $wpdb->get_var( $previous_count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$analytics['previous_available']   = true;
			}
		}

		return $analytics;
	}

	/**
	 * Shared WHERE clauses (user/scope/action/search) used by analytics queries,
	 * without any date-range restriction — callers append their own date bounds
	 * so the same filters can be reused for both the current and previous period.
	 *
	 * @param int    $user_id     User filter (0 = all users / system depends on scope).
	 * @param string $action_type Action filter.
	 * @param string $log_scope   'user' (user_id > 0) or 'system' (user_id = 0 only).
	 * @param string $search      Search keyword.
	 * @return array{0: array<int, string>, 1: array<int, mixed>}
	 */
	private function build_analytics_base_where( $user_id, $action_type, $log_scope, $search ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		$sanitized_user_id     = absint( $user_id );
		$sanitized_action_type = sanitize_key( $action_type );
		$log_scope             = sanitize_key( (string) $log_scope );
		if ( ! in_array( $log_scope, array( 'user', 'system' ), true ) ) {
			$log_scope = 'user';
		}
		$search = sanitize_text_field( (string) $search );

		if ( 'system' === $log_scope ) {
			$clauses[] = 'user_id = %d';
			$params[]  = 0;
		} elseif ( $sanitized_user_id > 0 ) {
			$clauses[] = 'user_id = %d';
			$params[]  = $sanitized_user_id;
		} else {
			$clauses[] = 'user_id > 0';
		}

		if ( '' !== $sanitized_action_type ) {
			$clauses[] = 'action_type = %s';
			$params[]  = $sanitized_action_type;
		}

		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(label LIKE %s OR action_type LIKE %s OR object_type LIKE %s OR object_name LIKE %s OR type LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		return array( $clauses, $params );
	}

	/**
	 * Compute the adjacent, equal-length period immediately before a given
	 * Y-m-d range — e.g. "Jul 15–21" back-shifts to "Jul 8–14" — so analytics
	 * can show a genuine like-for-like trend (same number of days).
	 *
	 * @param string $from_ymd Range start (Y-m-d, site-local).
	 * @param string $to_ymd   Range end (Y-m-d, site-local).
	 * @return array{from: string, to: string}|null
	 */
	private function shift_date_range_back( $from_ymd, $to_ymd ) {
		try {
			$from = new DateTime( sanitize_text_field( (string) $from_ymd ) );
			$to   = new DateTime( sanitize_text_field( (string) $to_ymd ) );
		} catch ( Exception $e ) {
			return null;
		}

		if ( $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$day_count = (int) $from->diff( $to )->days + 1;

		$previous_to = clone $from;
		$previous_to->modify( '-1 day' );

		$previous_from = clone $previous_to;
		if ( $day_count > 1 ) {
			$previous_from->modify( '-' . ( $day_count - 1 ) . ' days' );
		}

		return array(
			'from' => $previous_from->format( 'Y-m-d' ),
			'to'   => $previous_to->format( 'Y-m-d' ),
		);
	}

	/**
	 * Log rows since a GMT datetime (inclusive), newest first.
	 *
	 * @param string $since_gmt        MySQL datetime in GMT (matches stored created_at).
	 * @param int    $limit            Max rows (capped).
	 * @param bool   $include_system   When false, exclude user_id = 0.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs_since_gmt( $since_gmt, $limit = 40, $include_system = true ) {
		global $wpdb;

		$table_name = WhoChanged_Database::table_name();
		$limit      = max( 1, min( 100, absint( $limit ) ) );
		$since_gmt  = sanitize_text_field( (string) $since_gmt );

		if ( '' === $since_gmt ) {
			return array();
		}

		$include_system = (bool) $include_system;

		$clauses = array( 'created_at >= %s' );
		$params  = array( $since_gmt );

		if ( ! $include_system ) {
			$clauses[] = 'user_id > 0';
		}

		$where_sql   = implode( ' AND ', $clauses );
		$data_params = array_merge( $params, array( $limit ) );
		$data_sql    = "SELECT id, user_id, group_id, type, label, meta, action_type, object_type, object_name, changes, created_at
			FROM {$table_name}
			WHERE {$where_sql}
			ORDER BY created_at DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built from fixed clauses + placeholders above.
		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List distinct action types.
	 *
	 * @return array<int, string>
	 */
	public function get_action_types() {
		global $wpdb;

		$table_name = WhoChanged_Database::table_name();
		$rows       = $wpdb->get_col( "SELECT DISTINCT action_type FROM {$table_name} ORDER BY action_type ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_key', $rows )
			)
		);
	}

	/**
	 * Mask potentially sensitive values in diff data.
	 *
	 * @param mixed  $value Current value.
	 * @param string $path  Dot path for key checks.
	 * @return mixed
	 */
	private function sanitize_changes( $value, $path = '' ) {
		if ( is_array( $value ) ) {
			$sanitized = array();

			foreach ( $value as $key => $item ) {
				$key_str           = is_int( $key ) ? (string) $key : sanitize_text_field( (string) $key );
				$child_path        = '' === $path ? $key_str : $path . '.' . $key_str;
				$sanitized[ $key ] = $this->sanitize_changes( $item, $child_path );
			}

			return $sanitized;
		}

		if ( $this->is_sensitive_key( $path ) ) {
			return '[redacted]';
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return wp_json_encode( $value );
	}

	/**
	 * Normalize event payload shape.
	 *
	 * @param array<string, mixed> $event Raw event.
	 * @return array<string, mixed>
	 */
	private function normalize_event( array $event ) {
		$type        = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$label       = isset( $event['label'] ) ? sanitize_text_field( (string) $event['label'] ) : '';
		$meta        = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$diff        = isset( $event['diff'] ) && is_array( $event['diff'] ) ? $event['diff'] : array();
		$action_type = isset( $event['action_type'] ) ? sanitize_key( (string) $event['action_type'] ) : $type;
		$object_type = isset( $event['object_type'] ) ? sanitize_key( (string) $event['object_type'] ) : '';
		$object_name = isset( $event['object_name'] ) ? sanitize_text_field( (string) $event['object_name'] ) : '';

		if ( '' === $action_type ) {
			$action_type = $type;
		}

		if ( 'option_updated' === $type && isset( $meta['option'] ) && isset( $diff['value'] ) && is_array( $diff['value'] ) ) {
			$option_name          = sanitize_text_field( (string) $meta['option'] );
			$diff[ $option_name ] = $diff['value'];
			unset( $diff['value'] );
		}

		return array(
			'type'        => $type,
			'label'       => $label,
			'meta'        => $meta,
			'diff'        => $diff,
			'action_type' => $action_type,
			'object_type' => $object_type,
			'object_name' => $object_name,
		);
	}

	/**
	 * Get current active group id for this user in a 3-second window.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Event type.
	 * @return string
	 */
	private function get_active_group_id( $user_id, $type ) {
		$user_id = absint( $user_id );
		$type    = sanitize_key( $type );

		if ( $this->is_auth_event_type( $type ) ) {
			return sanitize_text_field( (string) wp_generate_uuid4() );
		}

		$key   = 'whochanged_group_' . $user_id;
		$group = get_transient( $key );

		$force_new_group_types = array(
			'theme_changed',
			'installed_plugin',
			'activated_plugin',
			'deactivated_plugin',
			'deleted_plugin',
			'plugin_upgraded',
			'theme_upgraded',
			'wordpress_updated',
		);

		if ( empty( $group ) || in_array( $type, $force_new_group_types, true ) ) {
			$group = wp_generate_uuid4();
		}

		set_transient( $key, $group, 3 );

		return sanitize_text_field( (string) $group );
	}

	/**
	 * Login / logout / failed-login events: own group row, never merge with settings/content logs.
	 *
	 * @param string $type Event type.
	 * @return bool
	 */
	private function is_auth_event_type( $type ) {
		$type = sanitize_key( (string) $type );

		return in_array( $type, array( 'user_login', 'user_logout', 'login_failed' ), true );
	}

	/**
	 * Plugin lifecycle events should keep independent rows.
	 *
	 * @param string $type Event type.
	 * @return bool
	 */
	private function is_plugin_lifecycle_event_type( $type ) {
		$type = sanitize_key( (string) $type );

		return in_array(
			$type,
			array( 'installed_plugin', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin' ),
			true
		);
	}

	/**
	 * Content mutation events should never be merged.
	 * Needed for bulk actions (trash/delete/restore multiple items).
	 *
	 * @param string $type Event type.
	 * @return bool
	 */
	private function is_content_bulk_event_type( $type ) {
		$type = sanitize_key( (string) $type );

		return in_array(
			$type,
			array(
				'post_deleted',
				'post_trashed',
				'post_restored',
				'page_deleted',
				'page_trashed',
				'page_restored',
				'comment_deleted',
				'comment_trashed',
				'comment_restored',
				'cpt_deleted',
				'cpt_trashed',
				'cpt_restored',
				'product_deleted',
				'product_trashed',
				'product_restored',
				'order_deleted',
				'order_trashed',
				'order_restored',
			),
			true
		);
	}

	/**
	 * Check if key path appears sensitive.
	 *
	 * @param string $path Dot key path.
	 * @return bool
	 */
	private function is_sensitive_key( $path ) {
		$path = strtolower( $path );

		$sensitive_tokens = array(
			'password',
			'passwd',
			'secret',
			'token',
			'api_key',
			'apikey',
			'auth',
			'license_key',
			'private_key',
			'client_secret',
			'recovery',
			'passkey',
			'webauthn',
			'hashed_key',
			'credential',
		);

		foreach ( $sensitive_tokens as $token ) {
			if ( false !== strpos( $path, $token ) ) {
				return true;
			}
		}

		return false;
	}
}
