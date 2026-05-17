<?php
/**
 * Persisted automation runner execution record.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class AutomationRun {

	public const STATUS_COMPLETED = 'completed';

	public const STATUS_FAILED = 'failed';

	public const TYPE_RUN_ALL = 'run_all';

	private ?int $id;

	private string $run_type;

	private string $status;

	/** @var array<string, mixed> */
	private array $summary;

	private int $warnings_count;

	private int $errors_count;

	private string $created_at;

	private ?string $finished_at;

	/**
	 * @param array<string, mixed> $summary
	 */
	public function __construct(
		?int $id,
		string $run_type,
		string $status,
		array $summary,
		int $warnings_count,
		int $errors_count,
		string $created_at,
		?string $finished_at
	) {
		$run_type = trim( $run_type );
		if ( $run_type === '' ) {
			throw new InvalidArgumentException( 'AutomationRun run_type must not be empty.' );
		}

		$this->id             = $id;
		$this->run_type       = $run_type;
		$this->status         = $status;
		$this->summary        = $summary;
		$this->warnings_count = max( 0, $warnings_count );
		$this->errors_count   = max( 0, $errors_count );
		$this->created_at    = $created_at;
		$this->finished_at   = $finished_at;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_row( array $row ): self {
		$raw_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$id     = $raw_id > 0 ? $raw_id : null;

		$json = isset( $row['summary_json'] ) ? (string) $row['summary_json'] : '';
		$summary = json_decode( $json, true );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}

		return new self(
			$id,
			(string) ( $row['run_type'] ?? '' ),
			(string) ( $row['status'] ?? self::STATUS_COMPLETED ),
			$summary,
			(int) ( $row['warnings_count'] ?? 0 ),
			(int) ( $row['errors_count'] ?? 0 ),
			(string) ( $row['created_at'] ?? '' ),
			isset( $row['finished_at'] ) && $row['finished_at'] !== '' ? (string) $row['finished_at'] : null
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_run_type(): string {
		return $this->run_type;
	}

	public function get_status(): string {
		return $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_summary(): array {
		return $this->summary;
	}

	public function get_warnings_count(): int {
		return $this->warnings_count;
	}

	public function get_errors_count(): int {
		return $this->errors_count;
	}

	public function get_created_at(): string {
		return $this->created_at;
	}

	public function get_finished_at(): ?string {
		return $this->finished_at;
	}
}
