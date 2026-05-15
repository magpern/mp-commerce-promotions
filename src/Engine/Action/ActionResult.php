<?php
/**
 * Preview output for an action (no side effects).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;

final class ActionResult {

	private string $type;

	/** @var array<string, mixed> */
	private array $payload;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct( string $type, array $payload ) {
		$type = trim( $type );
		if ( $type === '' ) {
			throw new InvalidArgumentException( 'Action result type must not be empty.' );
		}

		$this->type    = $type;
		$this->payload = $payload;
	}

	public function get_type(): string {
		return $this->type;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_payload(): array {
		return $this->payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'type'    => $this->type,
			'payload' => $this->payload,
		);
	}
}
