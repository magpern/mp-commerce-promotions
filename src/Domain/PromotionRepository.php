<?php
/**
 * Repository contract for promotions (no persistence layer yet).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

interface PromotionRepository {

	public function find( int $id ): ?Promotion;

	public function save( Promotion $promotion ): void;

	public function delete( int $id ): bool;
}
