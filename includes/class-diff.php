<?php
/**
 * Diff engine.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates before/after diffs for scalar and array values.
 */
class WhoChanged_Diff {

	/**
	 * Build structured diff.
	 *
	 * @param mixed $before Before value.
	 * @param mixed $after  After value.
	 * @return array<string, array<string, mixed>>
	 */
	public static function build( $before, $after ) {
		$diff = array();

		if ( is_array( $before ) || is_array( $after ) ) {
			$before_array = is_array( $before ) ? $before : array();
			$after_array  = is_array( $after ) ? $after : array();

			$flat_before = self::flatten_array( $before_array );
			$flat_after  = self::flatten_array( $after_array );
			$all_keys    = array_unique( array_merge( array_keys( $flat_before ), array_keys( $flat_after ) ) );

			foreach ( $all_keys as $key ) {
				$old_exists = array_key_exists( $key, $flat_before );
				$new_exists = array_key_exists( $key, $flat_after );
				$old_value  = $old_exists ? $flat_before[ $key ] : null;
				$new_value  = $new_exists ? $flat_after[ $key ] : null;

				if ( ! $old_exists || ! $new_exists || ! self::values_are_equal( $old_value, $new_value ) ) {
					$diff[ $key ] = array(
						'before' => $old_exists ? $old_value : null,
						'after'  => $new_exists ? $new_value : null,
					);
				}
			}

			return $diff;
		}

		if ( ! self::values_are_equal( $before, $after ) ) {
			$diff['value'] = array(
				'before' => $before,
				'after'  => $after,
			);
		}

		return $diff;
	}

	/**
	 * Compare two values for "did this actually change" purposes.
	 *
	 * WordPress options/theme_mods are read back from the DB as strings even
	 * when the code that saved them passed an int or bool (e.g. "1" vs 1,
	 * "" vs null). A strict `!==` (or `wp_json_encode()`) comparison treats
	 * those as changes, which produces misleading "X → X" log lines. Mirrors
	 * the type-tolerant check WordPress core itself uses in `update_option()`.
	 * Shared by the diff builder, the hook capture layer, the group-merge
	 * logic, and the display mapper so "is this really a change?" is decided
	 * consistently everywhere.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return bool
	 */
	public static function values_are_equal( $old_value, $new_value ) {
		if ( $old_value === $new_value ) {
			return true;
		}

		if ( is_scalar( $old_value ) && is_scalar( $new_value ) ) {
			return (string) $old_value === (string) $new_value;
		}

		if ( is_array( $old_value ) && is_array( $new_value ) ) {
			return wp_json_encode( $old_value ) === wp_json_encode( $new_value );
		}

		return false;
	}

	/**
	 * Flatten nested arrays using dot notation.
	 *
	 * @param array<string, mixed> $input  Input array.
	 * @param string               $prefix Prefix for recursion.
	 * @return array<string, mixed>
	 */
	private static function flatten_array( array $input, $prefix = '' ) {
		$flat = array();

		foreach ( $input as $key => $value ) {
			$key_name = (string) $key;
			$new_key  = '' === $prefix ? $key_name : $prefix . '.' . $key_name;

			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, self::flatten_array( $value, $new_key ) );
			} else {
				$flat[ $new_key ] = $value;
			}
		}

		return $flat;
	}
}
