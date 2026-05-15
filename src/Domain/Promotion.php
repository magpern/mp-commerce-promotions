<?php
/**
 * Promotion domain model (table row projection; validation on construct).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class Promotion {

	private ?int $id;

	private string $uuid;

	private string $name;

	private ?string $description;

	private string $status;

	private int $priority;

	private ?string $starts_at;

	private ?string $ends_at;

	private array $conditions;

	private array $actions;

	private array $restrictions;

	private ?int $usage_limit;

	private int $usage_count;

	private ?int $created_by;

	private ?string $created_at;

	private ?string $updated_at;

	public function __construct(
		?int $id,
		string $uuid,
		string $name,
		?string $description,
		string $status,
		int $priority,
		?string $starts_at,
		?string $ends_at,
		array $conditions,
		array $actions,
		array $restrictions,
		?int $usage_limit,
		int $usage_count,
		?int $created_by,
		?string $created_at,
		?string $updated_at
	) {
		$uuid = trim( $uuid );
		$name  = trim( $name );

		if ( $uuid === '' ) {
			throw new InvalidArgumentException( 'Promotion uuid must not be empty.' );
		}
		if ( $name === '' ) {
			throw new InvalidArgumentException( 'Promotion name must not be empty.' );
		}
		if ( ! PromotionStatus::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Invalid promotion status.' );
		}
		if ( $priority < 0 ) {
			throw new InvalidArgumentException( 'Promotion priority must be >= 0.' );
		}
		if ( $usage_count < 0 ) {
			throw new InvalidArgumentException( 'Promotion usage_count must be >= 0.' );
		}
		if ( $usage_limit !== null && $usage_limit < 0 ) {
			throw new InvalidArgumentException( 'Promotion usage_limit must be null or >= 0.' );
		}

		$this->id            = $id;
		$this->uuid          = $uuid;
		$this->name          = $name;
		$this->description   = $description;
		$this->status        = $status;
		$this->priority      = $priority;
		$this->starts_at     = $starts_at;
		$this->ends_at       = $ends_at;
		$this->conditions    = $conditions;
		$this->actions       = $actions;
		$this->restrictions  = $restrictions;
		$this->usage_limit   = $usage_limit;
		$this->usage_count   = $usage_count;
		$this->created_by    = $created_by;
		$this->created_at    = $created_at;
		$this->updated_at    = $updated_at;
	}

	public static function from_array( array $data ): self {
		$conditions   = self::normalize_jsonish_to_array( $data['conditions'] ?? null );
		$actions      = self::normalize_jsonish_to_array( $data['actions'] ?? null );
		$restrictions = self::normalize_jsonish_to_array( $data['restrictions'] ?? null );

		$raw_id = self::optional_int( $data['id'] ?? null );
		$id     = ( $raw_id !== null && $raw_id > 0 ) ? $raw_id : null;
		$usage_limit = self::optional_int( $data['usage_limit'] ?? null );
		$created_by  = self::optional_int( $data['created_by'] ?? null );

		return new self(
			$id,
			(string) ( $data['uuid'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			self::optional_string( $data['description'] ?? null ),
			(string) ( $data['status'] ?? '' ),
			(int) ( $data['priority'] ?? 0 ),
			self::optional_string( $data['starts_at'] ?? null ),
			self::optional_string( $data['ends_at'] ?? null ),
			$conditions,
			$actions,
			$restrictions,
			$usage_limit,
			(int) ( $data['usage_count'] ?? 0 ),
			$created_by,
			self::optional_string( $data['created_at'] ?? null ),
			self::optional_string( $data['updated_at'] ?? null )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'uuid'          => $this->uuid,
			'name'          => $this->name,
			'description'   => $this->description,
			'status'        => $this->status,
			'priority'      => $this->priority,
			'starts_at'     => $this->starts_at,
			'ends_at'       => $this->ends_at,
			'conditions'    => $this->conditions,
			'actions'       => $this->actions,
			'restrictions'  => $this->restrictions,
			'usage_limit'   => $this->usage_limit,
			'usage_count'   => $this->usage_count,
			'created_by'    => $this->created_by,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_uuid(): string {
		return $this->uuid;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_description(): ?string {
		return $this->description;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_priority(): int {
		return $this->priority;
	}

	public function get_starts_at(): ?string {
		return $this->starts_at;
	}

	public function get_ends_at(): ?string {
		return $this->ends_at;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_conditions(): array {
		return $this->conditions;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_actions(): array {
		return $this->actions;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_restrictions(): array {
		return $this->restrictions;
	}

	public function get_usage_limit(): ?int {
		return $this->usage_limit;
	}

	public function get_usage_count(): int {
		return $this->usage_count;
	}

	public function get_created_by(): ?int {
		return $this->created_by;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	public function with_name( string $name ): self {
		return new self(
			$this->id,
			$this->uuid,
			$name,
			$this->description,
			$this->status,
			$this->priority,
			$this->starts_at,
			$this->ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_description( ?string $description ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$description,
			$this->status,
			$this->priority,
			$this->starts_at,
			$this->ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_status( string $status ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$this->description,
			$status,
			$this->priority,
			$this->starts_at,
			$this->ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_priority( int $priority ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$this->description,
			$this->status,
			$priority,
			$this->starts_at,
			$this->ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_date_window( ?string $starts_at, ?string $ends_at ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$this->description,
			$this->status,
			$this->priority,
			$starts_at,
			$ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_usage_count( int $usage_count ): self {
		if ( $usage_count < 0 ) {
			throw new InvalidArgumentException( 'Promotion usage_count must be >= 0.' );
		}

		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$this->description,
			$this->status,
			$this->priority,
			$this->starts_at,
			$this->ends_at,
			$this->conditions,
			$this->actions,
			$this->restrictions,
			$this->usage_limit,
			$usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	/**
	 * @param array<mixed> $conditions
	 * @param array<mixed> $actions
	 * @param array<mixed> $restrictions
	 */
	public function with_rules( array $conditions, array $actions, array $restrictions ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->name,
			$this->description,
			$this->status,
			$this->priority,
			$this->starts_at,
			$this->ends_at,
			$conditions,
			$actions,
			$restrictions,
			$this->usage_limit,
			$this->usage_count,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	/**
	 * @param mixed $value Raw DB value or array.
	 * @return array<mixed>
	 */
	private static function normalize_jsonish_to_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || $value === '' ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param mixed $value
	 */
	private static function optional_int( $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * @param mixed $value
	 */
	private static function optional_string( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}

		return (string) $value;
	}
}
