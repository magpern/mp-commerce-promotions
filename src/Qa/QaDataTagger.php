<?php
/**
 * Tag QA-created WordPress entities for safe cleanup.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Qa;

final class QaDataTagger {

	public const META_CREATED = '_mp_cp_qa_created';

	public const META_RUN_ID = '_mp_cp_qa_run_id';

	public const META_CREATED_AT = '_mp_cp_qa_created_at';

	public const META_SCRIPT = '_mp_cp_qa_script';

	public const QA_NOTE_PREFIX = 'mp_cp_qa:';

	public static function tag_post( int $post_id, string $run_id, string $script ): void {
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_CREATED, 'yes' );
		update_post_meta( $post_id, self::META_RUN_ID, $run_id );
		update_post_meta( $post_id, self::META_CREATED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_SCRIPT, $script );
	}

	public static function is_tagged_post( int $post_id, ?string $run_id = null ): bool {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		if ( get_post_meta( $post_id, self::META_CREATED, true ) !== 'yes' ) {
			return false;
		}

		if ( $run_id === null || $run_id === '' ) {
			return true;
		}

		return (string) get_post_meta( $post_id, self::META_RUN_ID, true ) === $run_id;
	}

	public static function qa_note( string $run_id, string $detail = '' ): string {
		$note = self::QA_NOTE_PREFIX . $run_id;
		if ( $detail !== '' ) {
			$note .= ' ' . $detail;
		}

		return $note;
	}

	public static function note_matches_run( ?string $note, string $run_id ): bool {
		if ( $note === null || $note === '' || $run_id === '' ) {
			return false;
		}

		return str_starts_with( $note, self::QA_NOTE_PREFIX . $run_id );
	}

	private function __construct() {
	}
}
