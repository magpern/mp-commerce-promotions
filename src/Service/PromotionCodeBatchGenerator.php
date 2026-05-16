<?php
/**
 * Generates unique promotion codes for a batch (hashed at rest).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\PromotionCodeBatch;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use RuntimeException;

final class PromotionCodeBatchGenerator {

	private const RANDOM_LENGTH = 12;

	private const MAX_INSERT_ATTEMPTS_PER_CODE = 25;

	/**
	 * Excludes ambiguous O, 0, I, 1.
	 */
	private const RANDOM_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	private PromotionCodeRepository $codes;

	private PromotionCodeFactory $code_factory;

	private PromotionCodeBatchRepository $batches;

	private AuditLogger $audit_logger;

	public function __construct(
		PromotionCodeRepository $codes,
		PromotionCodeFactory $code_factory,
		PromotionCodeBatchRepository $batches,
		AuditLogger $audit_logger
	) {
		$this->codes        = $codes;
		$this->code_factory = $code_factory;
		$this->batches      = $batches;
		$this->audit_logger = $audit_logger;
	}

	/**
	 * Creates a batch row and inserts unique codes. Plain codes are returned for one-time admin display only.
	 *
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	public function generate(
		int $promotion_id,
		string $batch_name,
		int $quantity,
		?string $prefix = null,
		?int $usage_limit = null,
		?string $expires_at = null,
		?int $created_by = null
	): PromotionCodeBatchGenerationOutcome {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'promotion_id must be > 0.' );
		}

		$batch_name = trim( $batch_name );
		if ( $batch_name === '' ) {
			throw new InvalidArgumentException( 'Batch name must not be empty.' );
		}

		if ( $quantity <= 0 || $quantity > PromotionCodeBatch::MAX_QUANTITY ) {
			throw new InvalidArgumentException(
				sprintf( 'Quantity must be between 1 and %d.', PromotionCodeBatch::MAX_QUANTITY )
			);
		}

		if ( $usage_limit !== null && $usage_limit < 0 ) {
			throw new InvalidArgumentException( 'Usage limit must be null or >= 0.' );
		}

		$normalized_prefix = self::normalize_prefix( $prefix );
		$expires_at        = self::normalize_expires_at( $expires_at );

		$batch_uuid = self::generate_batch_uuid();

		$batch = new PromotionCodeBatch(
			null,
			$promotion_id,
			$batch_uuid,
			$batch_name,
			$quantity,
			$normalized_prefix,
			$usage_limit,
			$expires_at,
			$created_by,
			null
		);

		$batch_id = $this->batches->insert( $batch );
		if ( $batch_id <= 0 ) {
			throw new RuntimeException( 'Could not create the code batch record.' );
		}

		$batch = $batch->with_id( $batch_id );

		/** @var list<string> $plain_codes */
		$plain_codes     = array();
		$inserted        = 0;
		$seen_hashes     = array();

		for ( $i = 0; $i < $quantity; $i++ ) {
			$inserted_one = false;
			for ( $attempt = 0; $attempt < self::MAX_INSERT_ATTEMPTS_PER_CODE; $attempt++ ) {
				$plain = $this->build_plain_code( $normalized_prefix );
				$hash  = PromotionCodeRepository::hash_plain_code( $plain );
				if ( isset( $seen_hashes[ $hash ] ) ) {
					continue;
				}

				try {
					$code = $this->code_factory->create_manual_code(
						$promotion_id,
						$plain,
						$usage_limit,
						$expires_at
					);
				} catch ( InvalidArgumentException $e ) {
					continue;
				}

				$new_id = $this->codes->insert( $code );
				if ( $new_id <= 0 ) {
					if ( $this->codes->find_by_plain_code( $plain ) !== null ) {
						continue;
					}
					continue;
				}

				$seen_hashes[ $hash ] = true;
				$plain_codes[]        = $plain;
				++$inserted;
				$inserted_one         = true;
				break;
			}

			if ( ! $inserted_one ) {
				break;
			}
		}

		$warning = null;
		if ( $inserted < $quantity ) {
			$warning = sprintf(
				'Only %1$d of %2$d codes were generated. Try again or reduce the batch size.',
				$inserted,
				$quantity
			);
		}

		if ( $inserted > 0 ) {
			$this->audit_logger->log(
				'promotion_code.batch_generated',
				$promotion_id,
				array(
					'batch_id' => $batch_id,
					'quantity' => $quantity,
					'prefix'   => $normalized_prefix,
				)
			);
		} elseif ( $inserted === 0 ) {
			throw new RuntimeException( 'No promotion codes could be generated for this batch.' );
		}

		return new PromotionCodeBatchGenerationOutcome(
			$batch,
			$plain_codes,
			$inserted,
			$quantity,
			$warning
		);
	}

	public static function normalize_prefix( ?string $prefix ): ?string {
		if ( $prefix === null ) {
			return null;
		}

		$normalized = strtoupper( trim( $prefix ) );
		if ( $normalized === '' ) {
			return null;
		}

		$normalized = preg_replace( '/[^A-Z0-9-]+/', '', $normalized ) ?? '';
		if ( $normalized === '' ) {
			throw new InvalidArgumentException( 'Code prefix may only contain A-Z, 0-9, and hyphens.' );
		}

		if ( strlen( $normalized ) > 32 ) {
			throw new InvalidArgumentException( 'Code prefix must be at most 32 characters.' );
		}

		return $normalized;
	}

	private static function normalize_expires_at( ?string $expires_at ): ?string {
		if ( $expires_at === null ) {
			return null;
		}

		$trimmed = trim( $expires_at );
		if ( $trimmed === '' ) {
			return null;
		}

		return $trimmed;
	}

	private static function generate_batch_uuid(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = wp_generate_uuid4();
			if ( is_string( $uuid ) && $uuid !== '' ) {
				return $uuid;
			}
		}

		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}

	private function build_plain_code( ?string $prefix ): string {
		$random = $this->random_part( self::RANDOM_LENGTH );
		if ( $prefix !== null && $prefix !== '' ) {
			return $prefix . '-' . $random;
		}

		return $random;
	}

	private function random_part( int $length ): string {
		$alphabet = self::RANDOM_ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$out      = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}

		return $out;
	}
}
