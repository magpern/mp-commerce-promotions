<?php
/**
 * Persisted simulation scenario row.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class SimulationScenarioRecord {

	public const STATUS_ACTIVE = 'active';

	public const STATUS_ARCHIVED = 'archived';

	private ?int $id;

	private string $name;

	/** @var array<string, mixed> */
	private array $scenario_json;

	private string $status;

	private ?int $created_by;

	private string $created_at;

	private ?string $last_run_at;

	private int $run_count;

	/**
	 * @param array<string, mixed> $scenario_json
	 */
	public function __construct(
		?int $id,
		string $name,
		array $scenario_json,
		string $status,
		?int $created_by,
		string $created_at,
		?string $last_run_at,
		int $run_count
	) {
		$name = trim( $name );
		if ( $name === '' ) {
			throw new InvalidArgumentException( 'SimulationScenarioRecord name required.' );
		}

		$this->id            = $id;
		$this->name          = $name;
		$this->scenario_json = $scenario_json;
		$this->status        = $status !== '' ? $status : self::STATUS_ACTIVE;
		$this->created_by    = $created_by !== null && $created_by > 0 ? $created_by : null;
		$this->created_at    = $created_at;
		$this->last_run_at   = $last_run_at;
		$this->run_count     = max( 0, $run_count );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$json = isset( $row['scenario_json'] ) ? (string) $row['scenario_json'] : '';
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$raw_id = isset( $row['id'] ) ? (int) $row['id'] : 0;

		return new self(
			$raw_id > 0 ? $raw_id : null,
			(string) ( $row['name'] ?? '' ),
			$data,
			(string) ( $row['status'] ?? self::STATUS_ACTIVE ),
			isset( $row['created_by'] ) && $row['created_by'] !== null && $row['created_by'] !== ''
				? (int) $row['created_by']
				: null,
			(string) ( $row['created_at'] ?? current_time( 'mysql' ) ),
			isset( $row['last_run_at'] ) && $row['last_run_at'] !== null && $row['last_run_at'] !== ''
				? (string) $row['last_run_at']
				: null,
			isset( $row['run_count'] ) ? (int) $row['run_count'] : 0
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_name(): string {
		return $this->name;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_scenario_json(): array {
		return $this->scenario_json;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_run_count(): int {
		return $this->run_count;
	}

	public function get_created_by(): ?int {
		return $this->created_by;
	}
}
