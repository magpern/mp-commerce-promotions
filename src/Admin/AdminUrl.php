<?php
/**
 * Centralized admin URLs for the Promotions module.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class AdminUrl {

	/**
	 * @param AdminNavigation::TAB_* $tab
	 */
	public static function tab( string $tab ): string {
		$tab = AdminNavigation::sanitize_tab( $tab );

		return add_query_arg(
			array(
				'page' => AdminNavigation::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array<string, string> $extra_query
	 */
	public static function list_promotions( array $extra_query = array() ): string {
		$base = array(
			'page' => AdminNavigation::PAGE_SLUG,
			'tab'  => AdminNavigation::TAB_ALL,
		);

		return add_query_arg( array_merge( $base, $extra_query ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param int|string            $promotion_identifier Promotion ID or public key.
	 * @param array<string, string> $extra_query
	 */
	public static function edit_promotion( $promotion_identifier, array $extra_query = array() ): string {
		$query = array_merge(
			array( 'promotion' => (string) $promotion_identifier ),
			$extra_query
		);

		return add_query_arg( $query, self::tab( AdminNavigation::TAB_ALL ) );
	}

	/**
	 * @param int $promotion_id Promotion ID.
	 * @param int $batch_id     Batch ID.
	 */
	public static function batch_detail( int $promotion_id, int $batch_id ): string {
		return add_query_arg(
			array(
				'promotion' => (string) $promotion_id,
				'batch'     => (string) $batch_id,
			),
			self::tab( AdminNavigation::TAB_ALL )
		);
	}

	/**
	 * @param array<string, string|int> $extra_query
	 */
	public static function settings( array $extra_query = array() ): string {
		return add_query_arg( $extra_query, self::tab( AdminNavigation::TAB_SETTINGS ) );
	}

	/**
	 * @param array<string, string|int> $extra_query
	 */
	public static function diagnostics( array $extra_query = array() ): string {
		return add_query_arg( $extra_query, self::tab( AdminNavigation::TAB_DIAGNOSTICS ) );
	}
}
