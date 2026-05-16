<?php
/**
 * Structured trace for a single action evaluation step.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;

final class ActionTrace {

	public const REASON_SELECTED     = 'action_selected';
	public const REASON_NOT_REACHED  = 'action_not_reached';
	public const REASON_INVALID      = 'action_invalid';
	public const REASON_UNKNOWN      = 'action_unknown';

	private string $type;

	private bool $selected;

	private ?string $message;

	private string $reason_code;

	/** @var array<string, mixed> */
	private array $config;

	/** @var array<string, mixed> */
	private array $preview;

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $preview
	 */
	public function __construct(
		string $type,
		bool $selected,
		?string $message,
		string $reason_code,
		array $config,
		array $preview
	) {
		$type = trim( $type );
		if ( $type === '' ) {
			throw new InvalidArgumentException( 'ActionTrace type must be a non-empty string.' );
		}

		$reason_code = trim( $reason_code );
		if ( $reason_code === '' ) {
			throw new InvalidArgumentException( 'ActionTrace reason_code must be a non-empty string.' );
		}

		$this->type        = $type;
		$this->selected    = $selected;
		$this->message     = $message;
		$this->reason_code = $reason_code;
		$this->config      = $config;
		$this->preview     = $preview;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function is_selected(): bool {
		return $this->selected;
	}

	public function get_message(): ?string {
		return $this->message;
	}

	public function get_reason_code(): string {
		return $this->reason_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_preview(): array {
		return $this->preview;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'type'        => $this->type,
			'selected'    => $this->selected,
			'message'     => $this->message,
			'reason_code' => $this->reason_code,
			'config'      => $this->config,
			'preview'     => $this->preview,
		);
	}
}
