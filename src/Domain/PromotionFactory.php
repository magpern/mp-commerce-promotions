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
			PromotionApplicationMode::EXCLUSIVE,
			true,
			null,
			array(),
			$created_by,
			null,
			null
		);
	}

	public function create_draft_from_source( Promotion $source, string $name, ?int $created_by = null ): Promotion {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : '';
		if ( ! is_string( $uuid ) || $uuid === '' ) {
			throw new \RuntimeException( 'Unable to generate promotion UUID.' );
		}

		return new Promotion(
			null,
			$uuid,
			$name,
			$source->get_description(),
			PromotionStatus::DRAFT,
			$source->get_priority(),
			$source->get_starts_at(),
			$source->get_ends_at(),
			$source->get_conditions(),
			$source->get_actions(),
			$source->get_restrictions(),
			$source->get_usage_limit(),
			0,
			$source->get_application_mode(),
			$source->should_stop_processing(),
			$source->get_max_applications(),
			$source->get_excluded_promotion_ids(),
			$created_by,
			null,
			null
		);
	}
}
