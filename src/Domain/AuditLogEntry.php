<?php
/**
 * Single audit log row (append-only domain projection).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class AuditLogEntry {

	private ?int $id;

	private ?int $promotion_id;

	private ?int $actor_user_id;

	private string $action;

	/** @var array<mixed> */
	private array $context;

	private ?string $ip_hash;

	private ?string $created_at;

	/**
	 * @param array<mixed> $context
	 */
	public function __construct(
		?int $id,
		?int $promotion_id,
		?int $actor_user_id,
		string $action,
		array $context,
		?string $ip_hash,
		?string $created_at
	) {
		$action = trim( $action );
		if ( $action === '' ) {
			throw new InvalidArgumentException( 'Audit log action must not be empty.' );
		}

		$this->id            = $id;
		$this->promotion_id = $promotion_id;
		$this->actor_user_id = $actor_user_id;
		$this->action        = $action;
		$this->context       = $context;
		$this->ip_hash       = $ip_hash;
		$this->created_at    = $created_at;
	}

	public static function from_array( array $data ): self {
		$raw_id = self::optional_int( $data['id'] ?? null );
		$id     = ( $raw_id !== null && $raw_id > 0 ) ? $raw_id : null;

		$raw_pid = self::optional_int( $data['promotion_id'] ?? null );
		$pid     = ( $raw_pid !== null && $raw_pid > 0 ) ? $raw_pid : null;

		$raw_aid = self::optional_int( $data['actor_user_id'] ?? null );
		$aid     = ( $raw_aid !== null && $raw_aid > 0 ) ? $raw_aid : null;

		$action = isset( $data['action'] ) ? (string) $data['action'] : '';

		$context = self::normalize_context( $data['context'] ?? null );

		$ip_hash = self::optional_string( $data['ip_hash'] ?? null );
		if ( $ip_hash !== null && strlen( $ip_hash ) > 64 ) {
			$ip_hash = substr( $ip_hash, 0, 64 );
		}

		$created_at = self::optional_string( $data['created_at'] ?? null );

		return new self(
			$id,
			$pid,
			$aid,
			$action,
			$context,
			$ip_hash,
			$created_at
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'promotion_id'  => $this->promotion_id,
			'actor_user_id' => $this->actor_user_id,
			'action'        => $this->action,
			'context'       => $this->context,
			'ip_hash'       => $this->ip_hash,
			'created_at'    => $this->created_at,
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_promotion_id(): ?int {
		return $this->promotion_id;
	}

	public function get_actor_user_id(): ?int {
		return $this->actor_user_id;
	}

	public function get_action(): string {
		return $this->action;
	}

	/**
	 * @return array<mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	public function get_ip_hash(): ?string {
		return $this->ip_hash;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	/**
	 * @param mixed $value
	 * @return array<mixed>
	 */
	private static function normalize_context( $value ): array {
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
