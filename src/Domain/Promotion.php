<?php
/**
 * Promotion aggregate root (skeletal; no persistence).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class Promotion {

	private int $id;

	private string $name;

	private string $status;

	public function __construct( int $id, string $name, string $status ) {
		if ( ! PromotionStatus::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Invalid promotion status.' );
		}
		$this->id     = $id;
		$this->name   = $name;
		$this->status = $status;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_status(): string {
		return $this->status;
	}
}
