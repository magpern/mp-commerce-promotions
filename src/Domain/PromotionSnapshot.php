<?php
/**
 * Serialized promotion state for rollback.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class PromotionSnapshot {

	public const TYPE_TEMPLATE_APPLY = 'template_apply';

	public const TYPE_BUILDER_APPLY = 'builder_apply';

	public const TYPE_DUPLICATION = 'duplication';

	public const TYPE_AUTOMATION = 'automation';

	private ?int $id;

	private int $promotion_id;

	private string $snapshot_type;

	/** @var array<string, mixed> */
	private array $snapshot_data;

	private ?string $notes;

	private ?int $created_by;

	private ?string $created_at;

	/**
	 * @param array<string, mixed> $snapshot_data
	 */
	public function __construct(
		?int $id,
		int $promotion_id,
		string $snapshot_type,
		array $snapshot_data,
		?string $notes,
		?int $created_by,
		?string $created_at
	) {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'PromotionSnapshot promotion_id must be > 0.' );
		}

		$snapshot_type = trim( $snapshot_type );
		if ( $snapshot_type === '' ) {
			throw new InvalidArgumentException( 'PromotionSnapshot snapshot_type must not be empty.' );
		}

		if ( $snapshot_data === array() ) {
			throw new InvalidArgumentException( 'PromotionSnapshot snapshot_data must not be empty.' );
		}

		$this->id            = $id;
		$this->promotion_id  = $promotion_id;
		$this->snapshot_type = $snapshot_type;
		$this->snapshot_data = $snapshot_data;
		$this->notes         = $notes !== null && trim( $notes ) !== '' ? sanitize_textarea_field( $notes ) : null;
		$this->created_by    = $created_by !== null && $created_by > 0 ? $created_by : null;
		$this->created_at    = $created_at;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$raw_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$id     = $raw_id > 0 ? $raw_id : null;

		$json = isset( $row['snapshot_json'] ) ? (string) $row['snapshot_json'] : '';
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'Invalid snapshot_json.' );
		}

		$created_by = isset( $row['created_by'] ) && $row['created_by'] !== null && $row['created_by'] !== ''
			? (int) $row['created_by']
			: null;

		return new self(
			$id,
			(int) ( $row['promotion_id'] ?? 0 ),
			(string) ( $row['snapshot_type'] ?? '' ),
			$data,
			isset( $row['notes'] ) ? (string) $row['notes'] : null,
			$created_by,
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_snapshot_type(): string {
		return $this->snapshot_type;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_snapshot_data(): array {
		return $this->snapshot_data;
	}

	public function get_notes(): ?string {
		return $this->notes;
	}

	public function get_created_by(): ?int {
		return $this->created_by;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->promotion_id,
			$this->snapshot_type,
			$this->snapshot_data,
			$this->notes,
			$this->created_by,
			$this->created_at
		);
	}
}
