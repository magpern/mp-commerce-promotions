<?php
/**
 * Safe construction of new Promotion instances (no persistence).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class PromotionFactory {

	public function create_draft( string $name, ?int $created_by = null ): Promotion {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
		if ( ! is_string( $uuid ) || $uuid === '' ) {
			throw new \RuntimeException( 'Unable to generate promotion UUID.' );
		}

		return new Promotion(
			null,
			$uuid,
			$name,
			null,
			PromotionStatus::DRAFT,
			100,
			null,
			null,
			array(),
			array(),
			array(),
			null,
			0,
			$created_by,
			null,
			null
		);
	}
}
