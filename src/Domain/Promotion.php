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

	private ?int $customer_usage_limit;

	private int $usage_count;

	private string $application_mode;

	private bool $stop_processing;

	private ?int $max_applications;

	/** @var list<int> */
	private array $excluded_promotion_ids;

	/** @var list<int> */
	private array $excluded_product_ids;

	/** @var list<int> */
	private array $excluded_category_ids;

	private ?string $campaign_label;

	private ?string $internal_notes;

	private ?string $admin_color;

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
		?int $customer_usage_limit,
		int $usage_count,
		string $application_mode,
		bool $stop_processing,
		?int $max_applications,
		array $excluded_promotion_ids,
		array $excluded_product_ids,
		array $excluded_category_ids,
		?string $campaign_label,
		?string $internal_notes,
		?string $admin_color,
		?int $created_by,
		?string $created_at,
		?string $updated_at
	) {
		$uuid = trim( $uuid );
		$name = trim( $name );

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
		if ( $usage_limit !== null && $usage_limit < 1 ) {
			throw new InvalidArgumentException( 'Promotion usage_limit must be null or >= 1.' );
		}
		if ( $customer_usage_limit !== null && $customer_usage_limit < 1 ) {
			throw new InvalidArgumentException( 'Promotion customer_usage_limit must be null or >= 1.' );
		}
		$application_mode = trim( $application_mode );
		if ( ! PromotionApplicationMode::is_valid( $application_mode ) ) {
			throw new InvalidArgumentException( 'Invalid promotion application_mode.' );
		}
		if ( $max_applications !== null && $max_applications < 1 ) {
			throw new InvalidArgumentException( 'Promotion max_applications must be null or >= 1.' );
		}

		$excluded_promotion_ids = self::normalize_excluded_promotion_ids( $excluded_promotion_ids, $id );
		$excluded_product_ids   = self::normalize_positive_id_list( $excluded_product_ids );
		$excluded_category_ids  = self::normalize_positive_id_list( $excluded_category_ids );
		$campaign_label         = self::normalize_campaign_label( $campaign_label );
		$internal_notes         = self::normalize_internal_notes( $internal_notes );
		$admin_color            = self::normalize_admin_color( $admin_color );

		$this->id           = $id;
		$this->uuid         = $uuid;
		$this->name         = $name;
		$this->description  = $description;
		$this->status       = $status;
		$this->priority     = $priority;
		$this->starts_at    = $starts_at;
		$this->ends_at      = $ends_at;
		$this->conditions   = $conditions;
		$this->actions      = $actions;
		$this->restrictions = $restrictions;
		$this->usage_limit          = $usage_limit;
		$this->customer_usage_limit = $customer_usage_limit;
		$this->usage_count          = $usage_count;
		$this->application_mode   = $application_mode;
		$this->stop_processing    = $stop_processing;
		$this->max_applications       = $max_applications;
		$this->excluded_promotion_ids = $excluded_promotion_ids;
		$this->excluded_product_ids   = $excluded_product_ids;
		$this->excluded_category_ids  = $excluded_category_ids;
		$this->campaign_label         = $campaign_label;
		$this->internal_notes         = $internal_notes;
		$this->admin_color            = $admin_color;
		$this->created_by             = $created_by;
		$this->created_at   = $created_at;
		$this->updated_at   = $updated_at;
	}

	public static function from_array( array $data ): self {
		$conditions   = self::normalize_jsonish_to_array( $data['conditions'] ?? null );
		$actions      = self::normalize_jsonish_to_array( $data['actions'] ?? null );
		$restrictions = self::normalize_jsonish_to_array( $data['restrictions'] ?? null );

		$raw_id      = self::optional_int( $data['id'] ?? null );
		$id          = ( $raw_id !== null && $raw_id > 0 ) ? $raw_id : null;
		$usage_limit          = self::optional_int( $data['usage_limit'] ?? null );
		$customer_usage_limit = self::optional_int( $data['customer_usage_limit'] ?? null );
		$created_by           = self::optional_int( $data['created_by'] ?? null );
		$max_apps    = self::optional_int( $data['max_applications'] ?? null );

		$application_mode = isset( $data['application_mode'] )
			? trim( (string) $data['application_mode'] )
			: PromotionApplicationMode::EXCLUSIVE;
		if ( $application_mode === '' ) {
			$application_mode = PromotionApplicationMode::EXCLUSIVE;
		}

		$excluded_raw = $data['excluded_promotion_ids'] ?? null;
		if ( is_string( $excluded_raw ) && $excluded_raw !== '' ) {
			$decoded_excluded = json_decode( $excluded_raw, true );
			$excluded_raw     = is_array( $decoded_excluded ) ? $decoded_excluded : array();
		}
		if ( ! is_array( $excluded_raw ) ) {
			$excluded_raw = array();
		}

		$excluded_products_raw = $data['excluded_product_ids'] ?? null;
		if ( is_string( $excluded_products_raw ) && $excluded_products_raw !== '' ) {
			$decoded_products = json_decode( $excluded_products_raw, true );
			$excluded_products_raw = is_array( $decoded_products ) ? $decoded_products : array();
		}
		if ( ! is_array( $excluded_products_raw ) ) {
			$excluded_products_raw = array();
		}

		$excluded_categories_raw = $data['excluded_category_ids'] ?? null;
		if ( is_string( $excluded_categories_raw ) && $excluded_categories_raw !== '' ) {
			$decoded_categories = json_decode( $excluded_categories_raw, true );
			$excluded_categories_raw = is_array( $decoded_categories ) ? $decoded_categories : array();
		}
		if ( ! is_array( $excluded_categories_raw ) ) {
			$excluded_categories_raw = array();
		}

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
			$customer_usage_limit,
			(int) ( $data['usage_count'] ?? 0 ),
			$application_mode,
			self::normalize_stop_processing( $data['stop_processing'] ?? true ),
			$max_apps,
			$excluded_raw,
			$excluded_products_raw,
			$excluded_categories_raw,
			self::normalize_campaign_label( self::optional_string( $data['campaign_label'] ?? null ) ),
			self::normalize_internal_notes( self::optional_string( $data['internal_notes'] ?? null ) ),
			self::normalize_admin_color( self::optional_string( $data['admin_color'] ?? null ) ),
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
			'id'           => $this->id,
			'uuid'         => $this->uuid,
			'name'         => $this->name,
			'description'  => $this->description,
			'status'       => $this->status,
			'priority'     => $this->priority,
			'starts_at'    => $this->starts_at,
			'ends_at'      => $this->ends_at,
			'conditions'   => $this->conditions,
			'actions'      => $this->actions,
			'restrictions' => $this->restrictions,
			'usage_limit'          => $this->usage_limit,
			'customer_usage_limit' => $this->customer_usage_limit,
			'usage_count'          => $this->usage_count,
			'application_mode'   => $this->application_mode,
			'stop_processing'    => $this->stop_processing,
			'max_applications'       => $this->max_applications,
			'excluded_promotion_ids' => $this->excluded_promotion_ids,
			'excluded_product_ids'   => $this->excluded_product_ids,
			'excluded_category_ids'  => $this->excluded_category_ids,
			'campaign_label'         => $this->campaign_label,
			'internal_notes'         => $this->internal_notes,
			'admin_color'            => $this->admin_color,
			'created_by'             => $this->created_by,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
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

	public function get_customer_usage_limit(): ?int {
		return $this->customer_usage_limit;
	}

	public function get_usage_count(): int {
		return $this->usage_count;
	}

	public function get_application_mode(): string {
		return $this->application_mode;
	}

	public function should_stop_processing(): bool {
		return $this->stop_processing;
	}

	public function get_max_applications(): ?int {
		return $this->max_applications;
	}

	/**
	 * @return list<int>
	 */
	public function get_excluded_promotion_ids(): array {
		return $this->excluded_promotion_ids;
	}

	/**
	 * @return list<int>
	 */
	public function get_excluded_product_ids(): array {
		return $this->excluded_product_ids;
	}

	/**
	 * @return list<int>
	 */
	public function get_excluded_category_ids(): array {
		return $this->excluded_category_ids;
	}

	public function get_campaign_label(): ?string {
		return $this->campaign_label;
	}

	public function get_internal_notes(): ?string {
		return $this->internal_notes;
	}

	public function get_admin_color(): ?string {
		return $this->admin_color;
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_usage_limits( ?int $usage_limit, ?int $customer_usage_limit ): self {
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
			$usage_limit,
			$customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	/**
	 * @param array<mixed> $ids
	 */
	public function with_excluded_promotion_ids( array $ids ): self {
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	/**
	 * @param array<mixed> $product_ids
	 * @param array<mixed> $category_ids
	 */
	public function with_excluded_product_targeting( array $product_ids, array $category_ids ): self {
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			self::normalize_positive_id_list( $product_ids ),
			self::normalize_positive_id_list( $category_ids ),
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_campaign_metadata( ?string $label, ?string $notes, ?string $color ): self {
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
			$this->customer_usage_limit,
			$this->usage_count,
			$this->application_mode,
			$this->stop_processing,
			$this->max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			self::normalize_campaign_label( $label ),
			self::normalize_internal_notes( $notes ),
			self::normalize_admin_color( $color ),
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public function with_application_rules(
		string $application_mode,
		bool $stop_processing,
		?int $max_applications
	): self {
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
			$this->customer_usage_limit,
			$this->usage_count,
			$application_mode,
			$stop_processing,
			$max_applications,
			$this->excluded_promotion_ids,
			$this->excluded_product_ids,
			$this->excluded_category_ids,
			$this->campaign_label,
			$this->internal_notes,
			$this->admin_color,
			$this->created_by,
			$this->created_at,
			$this->updated_at
		);
	}

	public static function normalize_campaign_label( ?string $label ): ?string {
		if ( $label === null ) {
			return null;
		}

		$label = sanitize_text_field( $label );
		if ( $label === '' ) {
			return null;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$label = mb_substr( $label, 0, 191 );
		} elseif ( strlen( $label ) > 191 ) {
			$label = substr( $label, 0, 191 );
		}

		return $label;
	}

	public static function normalize_internal_notes( ?string $notes ): ?string {
		if ( $notes === null ) {
			return null;
		}

		$notes = sanitize_textarea_field( $notes );
		if ( $notes === '' ) {
			return null;
		}

		return $notes;
	}

	public static function normalize_admin_color( ?string $color ): ?string {
		if ( $color === null ) {
			return null;
		}

		$color = trim( $color );
		if ( $color === '' ) {
			return null;
		}

		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			throw new InvalidArgumentException( 'admin_color must be empty or a 6-digit hex color like #336699.' );
		}

		return strtolower( $color );
	}

	/**
	 * @param array<mixed> $ids
	 * @return list<int>
	 */
	private static function normalize_positive_id_list( array $ids ): array {
		$normalized = array();
		foreach ( $ids as $raw ) {
			if ( ! is_int( $raw ) && ! is_string( $raw ) && ! is_float( $raw ) ) {
				throw new InvalidArgumentException( 'ID lists must be arrays of positive integers.' );
			}
			if ( is_string( $raw ) && $raw !== '' && ! ctype_digit( $raw ) ) {
				throw new InvalidArgumentException( 'ID lists must be arrays of positive integers.' );
			}
			$id = (int) $raw;
			if ( $id <= 0 ) {
				throw new InvalidArgumentException( 'ID lists must be arrays of positive integers.' );
			}
			$normalized[ $id ] = $id;
		}

		$result = array_values( $normalized );
		sort( $result, SORT_NUMERIC );

		return $result;
	}

	/**
	 * @param array<mixed> $ids
	 * @return list<int>
	 */
	private static function normalize_excluded_promotion_ids( array $ids, ?int $own_id ): array {
		$normalized = array();
		foreach ( $ids as $raw ) {
			if ( ! is_int( $raw ) && ! is_string( $raw ) && ! is_float( $raw ) ) {
				throw new InvalidArgumentException( 'excluded_promotion_ids must be an array of positive integers.' );
			}
			if ( is_string( $raw ) && $raw !== '' && ! ctype_digit( $raw ) ) {
				throw new InvalidArgumentException( 'excluded_promotion_ids must be an array of positive integers.' );
			}
			$id = (int) $raw;
			if ( $id <= 0 ) {
				throw new InvalidArgumentException( 'excluded_promotion_ids must be an array of positive integers.' );
			}
			$normalized[ $id ] = $id;
		}

		if ( $own_id !== null && $own_id > 0 ) {
			unset( $normalized[ $own_id ] );
		}

		$result = array_values( $normalized );
		sort( $result, SORT_NUMERIC );

		return $result;
	}

	/**
	 * @param mixed $value
	 */
	private static function normalize_stop_processing( $value ): bool {
		if ( $value === true || $value === 1 || $value === '1' ) {
			return true;
		}
		if ( $value === false || $value === 0 || $value === '0' ) {
			return false;
		}

		return true;
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
