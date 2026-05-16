<?php
/**
 * Result of a code batch generation run (batch row + show-once plain codes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionCodeBatch;
use MP\CommercePromotions\Domain\PromotionCodeFactory;

final class PromotionCodeBatchGenerationOutcome {

	private PromotionCodeBatch $batch;

	/**
	 * @var list<string>
	 */
	private array $plain_codes;

	private int $inserted_count;

	private int $requested_quantity;

	private ?string $warning;

	private string $generated_at;

	/**
	 * @param list<string> $plain_codes
	 */
	public function __construct(
		PromotionCodeBatch $batch,
		array $plain_codes,
		int $inserted_count,
		int $requested_quantity,
		?string $warning = null,
		?string $generated_at = null
	) {
		$this->batch              = $batch;
		$this->plain_codes        = $plain_codes;
		$this->inserted_count     = $inserted_count;
		$this->requested_quantity = $requested_quantity;
		$this->warning            = $warning;
		$this->generated_at       = $generated_at ?? current_time( 'mysql' );
	}

	public function get_batch(): PromotionCodeBatch {
		return $this->batch;
	}

	/**
	 * @return list<string>
	 */
	public function get_plain_codes(): array {
		return $this->plain_codes;
	}

	public function get_inserted_count(): int {
		return $this->inserted_count;
	}

	public function get_requested_quantity(): int {
		return $this->requested_quantity;
	}

	public function get_warning(): ?string {
		return $this->warning;
	}

	public function get_generated_at(): string {
		return $this->generated_at;
	}

	public function is_complete(): bool {
		return $this->inserted_count >= $this->requested_quantity;
	}

	public function to_csv_string(): string {
		$batch_id = $this->batch->get_id();

		return self::build_csv_string(
			$this->plain_codes,
			$this->batch->get_promotion_id(),
			$batch_id !== null && $batch_id > 0 ? $batch_id : 0,
			$this->generated_at
		);
	}

	/**
	 * @param list<string> $plain_codes
	 */
	public static function build_csv_string(
		array $plain_codes,
		int $promotion_id,
		int $batch_id,
		string $generated_at
	): string {
		$lines   = array();
		$lines[] = self::format_csv_row(
			array( 'code', 'promotion_id', 'batch_id', 'generated_at' )
		);

		foreach ( $plain_codes as $code ) {
			$lines[] = self::format_csv_row(
				array(
					$code,
					(string) $promotion_id,
					(string) $batch_id,
					$generated_at,
				)
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param list<string> $plain_codes
	 */
	public static function encode_download_payload(
		array $plain_codes,
		int $promotion_id,
		int $batch_id,
		string $generated_at
	): string {
		$payload = wp_json_encode(
			array(
				'promotion_id'  => $promotion_id,
				'batch_id'      => $batch_id,
				'generated_at'  => $generated_at,
				'codes'         => array_values( $plain_codes ),
			)
		);

		if ( ! is_string( $payload ) || $payload === '' ) {
			return '';
		}

		return base64_encode( $payload );
	}

	/**
	 * @return array{promotion_id: int, batch_id: int, generated_at: string, codes: list<string>}|null
	 */
	public static function decode_download_payload( string $encoded ): ?array {
		$encoded = trim( $encoded );
		if ( $encoded === '' ) {
			return null;
		}

		$json = base64_decode( $encoded, true );
		if ( ! is_string( $json ) || $json === '' ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$promotion_id = isset( $data['promotion_id'] ) ? (int) $data['promotion_id'] : 0;
		$batch_id     = isset( $data['batch_id'] ) ? (int) $data['batch_id'] : 0;
		$generated_at = isset( $data['generated_at'] ) ? sanitize_text_field( (string) $data['generated_at'] ) : '';

		if ( $promotion_id <= 0 || $batch_id <= 0 || $generated_at === '' ) {
			return null;
		}

		if ( ! isset( $data['codes'] ) || ! is_array( $data['codes'] ) ) {
			return null;
		}

		if ( count( $data['codes'] ) > PromotionCodeBatch::MAX_QUANTITY ) {
			return null;
		}

		$codes = array();
		foreach ( $data['codes'] as $raw_code ) {
			if ( ! is_string( $raw_code ) && ! is_numeric( $raw_code ) ) {
				return null;
			}

			$normalized = PromotionCodeFactory::normalize_plain_code( (string) $raw_code );
			try {
				PromotionCodeFactory::assert_plain_code_valid( $normalized );
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}

			$codes[] = $normalized;
		}

		if ( count( $codes ) === 0 ) {
			return null;
		}

		return array(
			'promotion_id' => $promotion_id,
			'batch_id'     => $batch_id,
			'generated_at' => $generated_at,
			'codes'        => $codes,
		);
	}

	/**
	 * @param list<string> $fields
	 */
	private static function format_csv_row( array $fields ): string {
		$escaped = array();
		foreach ( $fields as $field ) {
			$value = (string) $field;
			if ( strpbrk( $value, ",\"\n\r" ) !== false ) {
				$value = '"' . str_replace( '"', '""', $value ) . '"';
			}
			$escaped[] = $value;
		}

		return implode( ',', $escaped );
	}
}
