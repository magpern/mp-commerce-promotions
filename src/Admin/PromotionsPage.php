<?php
/**
 * WooCommerce submenu: promotions list, creation, and edit routing.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Service\PromotionBulkCampaignWorkflow;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionLifecycle;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use RuntimeException;

final class PromotionsPage {

	private const NONCE_ACTION = 'mp_cp_create_promotion';

	private const BULK_NONCE_ACTION = 'mp_cp_bulk_promotions';

	private const BULK_NONCE_FIELD = 'mp_cp_bulk_promotions_nonce';

	private const CAMPAIGN_BULK_NONCE_ACTION = 'mp_cp_campaign_bulk';

	private const CAMPAIGN_BULK_NONCE_FIELD = 'mp_cp_campaign_bulk_nonce';

	private const PER_PAGE = 20;

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private PromotionEditPage $edit_page;

	private ?PromotionCodeRepository $promotion_codes;

	private ?PromotionCodeBatchRepository $code_batches;

	private ?RedemptionRepository $redemptions;

	private PromotionRuleValidator $rule_validator;

	private ?PromotionHealthMonitor $health_monitor;

	private ?PromotionBulkCampaignWorkflow $campaign_bulk;

	public function __construct(
		PromotionRepository $promotions,
		PromotionService $promotion_service,
		PromotionEditPage $edit_page,
		?PromotionCodeRepository $promotion_codes = null,
		?PromotionCodeBatchRepository $code_batches = null,
		?RedemptionRepository $redemptions = null,
		?PromotionRuleValidator $rule_validator = null,
		?PromotionHealthMonitor $health_monitor = null,
		?PromotionBulkCampaignWorkflow $campaign_bulk = null
	) {
		$this->promotions        = $promotions;
		$this->promotion_service = $promotion_service;
		$this->edit_page         = $edit_page;
		$this->promotion_codes   = $promotion_codes;
		$this->code_batches      = $code_batches;
		$this->redemptions       = $redemptions;
		$this->rule_validator    = $rule_validator ?? new PromotionRuleValidator();
		$this->health_monitor    = $health_monitor;
		$this->campaign_bulk     = $campaign_bulk;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['promotion'] ) ) {
			$promotion_key = sanitize_text_field( wp_unslash( (string) $_GET['promotion'] ) );
			if ( $promotion_key !== '' ) {
				$this->edit_page->render( $promotion_key );
				return;
			}
		}

		$list_query = $this->parse_list_query_args();

		$this->handle_post_bulk( $list_query );
		$this->handle_post_campaign_bulk( $list_query );
		$this->handle_post_create();
		$repo_args  = array(
			'status'              => $list_query['status'],
			'search'              => $list_query['search'],
			'campaign_label'      => $list_query['campaign_label'],
			'lifecycle_phase'     => $list_query['lifecycle_phase'],
			'orchestration_group' => $list_query['orchestration_group'],
			'limit'               => self::PER_PAGE,
			'offset'              => $list_query['offset'],
		);

		if ( $list_query['quick_filter'] === 'health_issues' && $this->health_monitor !== null ) {
			$repo_args['promotion_ids'] = $this->health_issue_promotion_ids();
		}

		try {
			$total = $this->promotions->count_filtered( $repo_args );
			$list  = $this->promotions->find_filtered( $repo_args );
		} catch ( InvalidArgumentException $e ) {
			$total = 0;
			$list  = array();
		}

		$total_pages = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;
		if ( $list_query['paged'] > $total_pages && $total_pages > 0 ) {
			$list_query['paged'] = $total_pages;
			$repo_args['offset'] = ( $total_pages - 1 ) * self::PER_PAGE;
			$list                = $this->promotions->find_filtered( $repo_args );
		}

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Commerce Promotions', 'mp-commerce-promotions' ) . '</h1>';
		AdminNavigation::render_tabs( AdminNavigation::TAB_ALL );
		echo '<p>' . esc_html__( 'Create draft promotions, then use Edit to change details, raw JSON rules, and status (via action buttons on the edit screen). Hard delete and visual rule builder are not implemented yet.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_create_form();
		$this->render_recently_modified_section();
		$this->render_list_filters( $list_query );
		$this->render_list_summary( $list_query, $total, $total_pages );
		$this->render_promotions_list_form( $list, $list_query );

		if ( $total_pages > 1 ) {
			$this->render_pagination( $list_query, $total_pages );
		}

		echo '</div>';
	}

	/**
	 * @return array{
	 *     status: string|null,
	 *     search: string|null,
	 *     campaign_label: string|null,
	 *     lifecycle_phase: string|null,
	 *     paged: int,
	 *     offset: int
	 * }
	 */
	private function parse_list_query_args(): array {
		$status = null;
		if ( isset( $_GET['promotion_status'] ) ) {
			$raw = sanitize_key( wp_unslash( (string) $_GET['promotion_status'] ) );
			if ( $raw !== '' && PromotionStatus::is_valid( $raw ) ) {
				$status = $raw;
			}
		}

		$search = null;
		if ( isset( $_GET['s'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) );
			if ( $raw !== '' ) {
				$search = $raw;
			}
		}

		$campaign_label = null;
		if ( isset( $_GET['campaign_label'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['campaign_label'] ) );
			if ( $raw !== '' ) {
				$campaign_label = $raw;
			}
		}

		$lifecycle_phase = null;
		if ( isset( $_GET['lifecycle_phase'] ) ) {
			$raw = sanitize_key( wp_unslash( (string) $_GET['lifecycle_phase'] ) );
			if ( $raw !== '' ) {
				$lifecycle_phase = $raw;
			}
		}

		$quick_filter = null;
		if ( isset( $_GET['quick_filter'] ) ) {
			$raw = sanitize_key( wp_unslash( (string) $_GET['quick_filter'] ) );
			$allowed = array( 'budget_exhausted', 'ending_soon', 'orchestration_group', 'health_issues' );
			if ( in_array( $raw, $allowed, true ) ) {
				$quick_filter = $raw;
				if ( $raw === 'budget_exhausted' ) {
					$lifecycle_phase = PromotionLifecycle::PHASE_BUDGET_EXHAUSTED;
				} elseif ( $raw === 'ending_soon' ) {
					$lifecycle_phase = PromotionLifecycle::PHASE_ENDING_SOON;
				}
			}
		}

		$orchestration_group = null;
		if ( isset( $_GET['orchestration_group'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_GET['orchestration_group'] ) );
			if ( $raw !== '' ) {
				$orchestration_group = $raw;
			}
		}

		$compact = isset( $_GET['compact'] ) && sanitize_text_field( wp_unslash( (string) $_GET['compact'] ) ) === '1';

		$paged = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1;
		if ( $paged < 1 ) {
			$paged = 1;
		}

		return array(
			'status'              => $status,
			'search'              => $search,
			'campaign_label'      => $campaign_label,
			'lifecycle_phase'     => $lifecycle_phase,
			'quick_filter'        => $quick_filter,
			'orchestration_group' => $orchestration_group,
			'compact'             => $compact,
			'paged'               => $paged,
			'offset'              => ( $paged - 1 ) * self::PER_PAGE,
		);
	}

	/**
	 * @return list<int>
	 */
	private function health_issue_promotion_ids(): array {
		if ( $this->health_monitor === null ) {
			return array();
		}

		$ids = array();
		foreach ( $this->health_monitor->analyze( 500 ) as $issue ) {
			if ( ! isset( $issue['promotion_ids'] ) || ! is_array( $issue['promotion_ids'] ) ) {
				continue;
			}
			foreach ( $issue['promotion_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		return array_values( $ids );
	}

	private function render_recently_modified_section(): void {
		$recent = $this->promotions->find_recently_modified( 5 );
		if ( $recent === array() ) {
			return;
		}

		echo '<h2 style="margin-top:1em;font-size:1.1em;">' . esc_html__( 'Recently modified', 'mp-commerce-promotions' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $recent as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null ) {
				continue;
			}
			$url = $this->list_url( array( 'promotion' => (string) $id ) );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $promotion->get_name() ) . '</a></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array{status: string|null, search: string|null, campaign_label: string|null, paged: int, offset: int} $list_query
	 */
	private function render_list_filters( array $list_query ): void {
		$current_status = $list_query['status'];
		$search_value   = $list_query['search'] ?? '';

		echo '<div class="tablenav top" style="margin:12px 0;">';

		echo '<ul class="subsubsub" style="margin:0 0 12px;">';
		$filters = array(
			null                      => __( 'All', 'mp-commerce-promotions' ),
			PromotionStatus::DRAFT    => __( 'Draft', 'mp-commerce-promotions' ),
			PromotionStatus::ACTIVE   => __( 'Active', 'mp-commerce-promotions' ),
			PromotionStatus::PAUSED   => __( 'Paused', 'mp-commerce-promotions' ),
			PromotionStatus::ARCHIVED => __( 'Archived', 'mp-commerce-promotions' ),
		);

		$link_parts = array();
		foreach ( $filters as $status_key => $label ) {
			$query = array();
			if ( $status_key !== null ) {
				$query['promotion_status'] = $status_key;
			}
			if ( $list_query['search'] !== null && $list_query['search'] !== '' ) {
				$query['s'] = $list_query['search'];
			}
			if ( $list_query['campaign_label'] !== null && $list_query['campaign_label'] !== '' ) {
				$query['campaign_label'] = $list_query['campaign_label'];
			}
			$url          = $this->list_url( $query );
			$class        = ( $current_status === $status_key ) || ( $status_key === null && $current_status === null )
				? 'current'
				: '';
			$link_parts[] = '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<li>' . implode( '</li> | <li>', $link_parts ) . '</li>';
		echo '</ul>';

		echo '<p class="description" style="margin:0 0 8px;">' . esc_html__( 'Quick filters:', 'mp-commerce-promotions' ) . ' ';
		$quick_links = array(
			'budget_exhausted' => __( 'Budget exhausted', 'mp-commerce-promotions' ),
			'ending_soon'      => __( 'Ending soon', 'mp-commerce-promotions' ),
			'health_issues'    => __( 'Health issues', 'mp-commerce-promotions' ),
		);
		$quick_parts = array();
		foreach ( $quick_links as $key => $label ) {
			$quick_parts[] = '<a href="' . esc_url( $this->list_url( array( 'quick_filter' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo implode( ' · ', $quick_parts );
		$compact_url = $this->list_url(
			array_merge(
				array_filter(
					array(
						'promotion_status'    => $list_query['status'],
						's'                   => $list_query['search'],
						'campaign_label'      => $list_query['campaign_label'],
						'lifecycle_phase'     => $list_query['lifecycle_phase'],
						'orchestration_group' => $list_query['orchestration_group'],
						'quick_filter'        => $list_query['quick_filter'],
					),
					static fn ( $v ): bool => $v !== null && $v !== ''
				),
				array( 'compact' => empty( $list_query['compact'] ) ? '1' : '0' )
			)
		);
		echo ' · <a href="' . esc_url( $compact_url ) . '">';
		echo empty( $list_query['compact'] )
			? esc_html__( 'Compact mode', 'mp-commerce-promotions' )
			: esc_html__( 'Full columns', 'mp-commerce-promotions' );
		echo '</a></p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="search-form" style="display:flex;align-items:center;gap:8px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminNavigation::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( AdminNavigation::TAB_ALL ) . '" />';
		if ( $current_status !== null ) {
			echo '<input type="hidden" name="promotion_status" value="' . esc_attr( $current_status ) . '" />';
		}
		if ( $list_query['campaign_label'] !== null && $list_query['campaign_label'] !== '' ) {
			echo '<input type="hidden" name="campaign_label" value="' . esc_attr( $list_query['campaign_label'] ) . '" />';
		}
		if ( $list_query['lifecycle_phase'] !== null && $list_query['lifecycle_phase'] !== '' ) {
			echo '<input type="hidden" name="lifecycle_phase" value="' . esc_attr( $list_query['lifecycle_phase'] ) . '" />';
		}

		echo '<label for="mp_cp_lifecycle_phase_filter" class="screen-reader-text">' . esc_html__( 'Lifecycle', 'mp-commerce-promotions' ) . '</label>';
		echo '<select name="lifecycle_phase" id="mp_cp_lifecycle_phase_filter" style="margin-right:8px;">';
		echo '<option value="">' . esc_html__( 'All lifecycles', 'mp-commerce-promotions' ) . '</option>';
		$lifecycle_options = array(
			PromotionLifecycle::PHASE_UPCOMING         => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_UPCOMING ),
			PromotionLifecycle::PHASE_LIVE             => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_LIVE ),
			PromotionLifecycle::PHASE_ENDING_SOON      => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_ENDING_SOON ),
			PromotionLifecycle::PHASE_EXPIRED_ACTIVE   => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_EXPIRED_ACTIVE ),
			PromotionLifecycle::PHASE_BUDGET_EXHAUSTED => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_BUDGET_EXHAUSTED ),
			PromotionLifecycle::PHASE_ARCHIVED         => PromotionLifecycle::badge_label( PromotionLifecycle::PHASE_ARCHIVED ),
		);
		foreach ( $lifecycle_options as $phase_key => $phase_label ) {
			echo '<option value="' . esc_attr( $phase_key ) . '"' . selected( $list_query['lifecycle_phase'] ?? '', $phase_key, false ) . '>' . esc_html( $phase_label ) . '</option>';
		}
		echo '</select>';

		$labels = $this->promotions->find_distinct_campaign_labels( 50 );
		if ( $labels !== array() ) {
			echo '<label for="mp_cp_campaign_label_filter" class="screen-reader-text">' . esc_html__( 'Campaign label', 'mp-commerce-promotions' ) . '</label>';
			echo '<select name="campaign_label" id="mp_cp_campaign_label_filter" style="margin-right:8px;">';
			echo '<option value="">' . esc_html__( 'All campaigns', 'mp-commerce-promotions' ) . '</option>';
			foreach ( $labels as $label ) {
				echo '<option value="' . esc_attr( $label ) . '"' . selected( $list_query['campaign_label'] ?? '', $label, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}

		echo '<label class="screen-reader-text" for="mp_cp_promotion_search">' . esc_html__( 'Search promotions', 'mp-commerce-promotions' ) . '</label>';
		echo '<input type="search" id="mp_cp_promotion_search" name="s" value="' . esc_attr( $search_value ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Search', 'mp-commerce-promotions' ) . '</button>';
		if ( $search_value !== '' ) {
			$clear_query = array();
			if ( $current_status !== null ) {
				$clear_query['promotion_status'] = $current_status;
			}
			echo '<a href="' . esc_url( $this->list_url( $clear_query ) ) . '" class="button">' . esc_html__( 'Clear search', 'mp-commerce-promotions' ) . '</a>';
		}
		echo '</form>';

		echo '</div>';
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function render_list_summary( array $list_query, int $total, int $total_pages ): void {
		echo '<p class="description">';
		echo esc_html(
			sprintf(
				/* translators: 1: total promotions, 2: current page, 3: total pages */
				_n(
					'%1$d promotion total. Page %2$d of %3$d.',
					'%1$d promotions total. Page %2$d of %3$d.',
					$total,
					'mp-commerce-promotions'
				),
				$total,
				$list_query['paged'],
				max( 1, $total_pages )
			)
		);
		echo '</p>';
	}

	/**
	 * @param list<Promotion>                                                          $list
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function render_promotions_list_form( array $list, array $list_query ): void {
		if ( count( $list ) === 0 ) {
			echo '<p>' . esc_html__( 'No promotions found.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<form id="mp-cp-promotions-list-form" method="post" action="' . esc_url( $this->list_url( $this->list_query_to_url_args( $list_query ) ) ) . '">';
		wp_nonce_field( self::BULK_NONCE_ACTION, self::BULK_NONCE_FIELD );
		$this->render_bulk_hidden_fields( $list_query );
		$this->render_campaign_bulk_panel();
		$this->render_bulk_actions_bar( 'top' );

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		echo '<td id="cb" class="manage-column column-cb check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'mp-commerce-promotions' ) . '</span></td>';
		$headers = array(
			__( 'ID', 'mp-commerce-promotions' ),
			__( 'Name', 'mp-commerce-promotions' ),
			__( 'Campaign', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
			__( 'Codes', 'mp-commerce-promotions' ),
			__( 'Batches', 'mp-commerce-promotions' ),
			__( 'Redemptions', 'mp-commerce-promotions' ),
			__( 'Validation', 'mp-commerce-promotions' ),
			__( 'Application', 'mp-commerce-promotions' ),
			__( 'Priority', 'mp-commerce-promotions' ),
			__( 'Usage', 'mp-commerce-promotions' ),
			__( 'Starts', 'mp-commerce-promotions' ),
			__( 'Ends', 'mp-commerce-promotions' ),
			__( 'Created', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $list as $promo ) {
			if ( ! $promo instanceof Promotion ) {
				continue;
			}
			$pid  = $promo->get_id();
			$edit = '';
			if ( $pid !== null && $pid > 0 ) {
				$edit_url = add_query_arg(
					array(
						'promotion' => (string) $pid,
					),
					AdminNavigation::tab_url( AdminNavigation::TAB_ALL )
				);
				$edit     = ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</a>';
			}
			echo '<tr>';
			echo '<th scope="row" class="check-column">';
			if ( $pid !== null && $pid > 0 ) {
				printf(
					'<input type="checkbox" name="mp_cp_promotion_ids[]" value="%1$d" />',
					(int) $pid
				);
			}
			echo '</th>';
			echo '<td>' . esc_html( (string) ( $pid ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $promo->get_name() ) . $edit . '</td>';
			echo '<td>' . $this->format_campaign_list_cell( $promo ) . '</td>';
			echo '<td>' . $this->format_status_cell( $promo ) . '</td>';
			echo '<td>' . esc_html( $this->format_codes_summary( $pid ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_batches_summary( $pid ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_redemptions_summary( $pid ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_validation_summary( $promo ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_application_summary( $promo ) ) . '</td>';
			echo '<td>' . esc_html( (string) $promo->get_priority() ) . '</td>';
			echo '<td>' . esc_html( $this->format_usage( $promo ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_starts_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_ends_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_created_at() ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->render_bulk_actions_bar( 'bottom' );
		echo '</form>';
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function render_bulk_hidden_fields( array $list_query ): void {
		if ( $list_query['status'] !== null ) {
			echo '<input type="hidden" name="promotion_status" value="' . esc_attr( $list_query['status'] ) . '" />';
		}
		if ( $list_query['search'] !== null && $list_query['search'] !== '' ) {
			echo '<input type="hidden" name="s" value="' . esc_attr( $list_query['search'] ) . '" />';
		}
		if ( $list_query['campaign_label'] !== null && $list_query['campaign_label'] !== '' ) {
			echo '<input type="hidden" name="campaign_label" value="' . esc_attr( $list_query['campaign_label'] ) . '" />';
		}
		if ( $list_query['lifecycle_phase'] !== null && $list_query['lifecycle_phase'] !== '' ) {
			echo '<input type="hidden" name="lifecycle_phase" value="' . esc_attr( $list_query['lifecycle_phase'] ) . '" />';
		}
		if ( $list_query['paged'] > 1 ) {
			echo '<input type="hidden" name="paged" value="' . esc_attr( (string) $list_query['paged'] ) . '" />';
		}
	}

	private function render_bulk_actions_bar( string $which ): void {
		$id_suffix = $which === 'bottom' ? '_bottom' : '_top';

		echo '<div class="tablenav ' . esc_attr( $which ) . '" style="margin:8px 0;"><div class="alignleft actions bulkactions">';
		echo '<label for="mp_cp_bulk_action' . esc_attr( $id_suffix ) . '" class="screen-reader-text">';
		echo esc_html__( 'Bulk actions', 'mp-commerce-promotions' );
		echo '</label>';
		echo '<select name="mp_cp_bulk_action" id="mp_cp_bulk_action' . esc_attr( $id_suffix ) . '">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="activate">' . esc_html__( 'Activate', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="pause">' . esc_html__( 'Pause', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="archive">' . esc_html__( 'Archive', 'mp-commerce-promotions' ) . '</option>';
		echo '</select> ';
		submit_button( __( 'Apply', 'mp-commerce-promotions' ), 'secondary', 'mp_cp_bulk_submit', false );
		echo '</div></div>';
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function handle_post_bulk( array $list_query ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_bulk_submit'] ) ) {
			return;
		}

		$redirect = $this->list_url( $this->list_query_to_url_args( $list_query ) );

		if ( ! isset( $_POST[ self::BULK_NONCE_FIELD ] ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'missing_nonce', $redirect ) );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::BULK_NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::BULK_NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'invalid_nonce', $redirect ) );
			exit;
		}

		$action = isset( $_POST['mp_cp_bulk_action'] )
			? sanitize_key( wp_unslash( (string) $_POST['mp_cp_bulk_action'] ) )
			: '';

		$target_status = $this->bulk_action_to_status( $action );
		if ( $target_status === null ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'invalid_bulk_action', $redirect ) );
			exit;
		}

		$raw_ids = isset( $_POST['mp_cp_promotion_ids'] ) && is_array( $_POST['mp_cp_promotion_ids'] )
			? $_POST['mp_cp_promotion_ids']
			: array();

		$ids = array();
		foreach ( $raw_ids as $raw ) {
			if ( is_scalar( $raw ) ) {
				$id = (int) $raw;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		if ( $ids === array() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'mp_cp_bulk_changed' => '0',
						'mp_cp_bulk_skipped' => '0',
					),
					$redirect
				)
			);
			exit;
		}

		$actor   = (int) get_current_user_id();
		$changed = 0;
		$skipped = 0;

		foreach ( $ids as $promotion_id ) {
			$promotion = $this->promotions->find( $promotion_id );
			if ( $promotion === null ) {
				++$skipped;
				continue;
			}

			if ( ! PromotionService::is_allowed_status_transition( $promotion->get_status(), $target_status ) ) {
				++$skipped;
				continue;
			}

			try {
				$this->promotion_service->change_status( $promotion, $target_status, $actor > 0 ? $actor : null );
				++$changed;
			} catch ( RuntimeException $e ) {
				++$skipped;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'mp_cp_bulk_changed' => (string) $changed,
					'mp_cp_bulk_skipped' => (string) $skipped,
				),
				$redirect
			)
		);
		exit;
	}

	private function bulk_action_to_status( string $action ): ?string {
		switch ( $action ) {
			case 'activate':
				return PromotionStatus::ACTIVE;
			case 'pause':
				return PromotionStatus::PAUSED;
			case 'archive':
				return PromotionStatus::ARCHIVED;
			default:
				return null;
		}
	}

	private function render_campaign_bulk_panel(): void {
		if ( $this->campaign_bulk === null ) {
			return;
		}

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:0 0 16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Campaign bulk updates', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Select promotions in the list below, then set schedule, orchestration, label, budget, or cooldown fields. Uses the same checkboxes as status bulk actions.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><label>' . esc_html__( 'Workflow', 'mp-commerce-promotions' ) . ' ';
		echo '<select name="mp_cp_campaign_workflow">';
		echo '<option value="schedule">' . esc_html__( 'Mass scheduling', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="orchestration">' . esc_html__( 'Orchestration group + cooldown', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="label">' . esc_html__( 'Campaign label', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="budget">' . esc_html__( 'Budget amount', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="cooldown">' . esc_html__( 'Cooldown hours', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Starts at (Y-m-d H:i:s)', 'mp-commerce-promotions' ) . ' <input type="text" name="mp_cp_bulk_starts_at" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Ends at', 'mp-commerce-promotions' ) . ' <input type="text" name="mp_cp_bulk_ends_at" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Orchestration group', 'mp-commerce-promotions' ) . ' <input type="text" name="mp_cp_bulk_orchestration_group" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Campaign label', 'mp-commerce-promotions' ) . ' <input type="text" name="mp_cp_bulk_campaign_label" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Budget amount', 'mp-commerce-promotions' ) . ' <input type="number" step="0.01" name="mp_cp_bulk_budget_amount" class="small-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Cooldown (hours)', 'mp-commerce-promotions' ) . ' <input type="number" name="mp_cp_bulk_cooldown_hours" class="small-text" /></label></p>';
		wp_nonce_field( self::CAMPAIGN_BULK_NONCE_ACTION, self::CAMPAIGN_BULK_NONCE_FIELD );
		echo '<button type="submit" name="mp_cp_campaign_bulk_submit" value="1" class="button button-secondary">';
		echo esc_html__( 'Apply campaign bulk update', 'mp-commerce-promotions' );
		echo '</button>';
		echo '</div>';
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function handle_post_campaign_bulk( array $list_query ): void {
		if ( $this->campaign_bulk === null || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_campaign_bulk_submit'] ) ) {
			return;
		}

		$redirect = $this->list_url( $this->list_query_to_url_args( $list_query ) );

		if ( ! isset( $_POST[ self::CAMPAIGN_BULK_NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::CAMPAIGN_BULK_NONCE_FIELD ] ) ), self::CAMPAIGN_BULK_NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'invalid_nonce', $redirect ) );
			exit;
		}

		$ids = $this->parse_bulk_promotion_ids();
		if ( $ids === array() ) {
			wp_safe_redirect( add_query_arg( array( 'mp_cp_bulk_changed' => '0', 'mp_cp_bulk_skipped' => '0' ), $redirect ) );
			exit;
		}

		$workflow = isset( $_POST['mp_cp_campaign_workflow'] )
			? sanitize_key( wp_unslash( (string) $_POST['mp_cp_campaign_workflow'] ) )
			: 'schedule';
		$actor    = (int) get_current_user_id();

		$result = match ( $workflow ) {
			'orchestration' => $this->campaign_bulk->bulk_assign_orchestration(
				$ids,
				$this->optional_post_string( 'mp_cp_bulk_orchestration_group' ),
				$this->optional_post_int( 'mp_cp_bulk_cooldown_hours' ),
				$actor
			),
			'label' => $this->campaign_bulk->bulk_assign_campaign_label(
				$ids,
				$this->optional_post_string( 'mp_cp_bulk_campaign_label' ),
				$actor
			),
			'budget' => $this->campaign_bulk->bulk_adjust_budget(
				$ids,
				$this->optional_post_float( 'mp_cp_bulk_budget_amount' ),
				null,
				$actor
			),
			'cooldown' => $this->campaign_bulk->bulk_assign_cooldown(
				$ids,
				$this->optional_post_int( 'mp_cp_bulk_cooldown_hours' ),
				$actor
			),
			default => $this->campaign_bulk->bulk_update_schedule(
				$ids,
				$this->optional_post_string( 'mp_cp_bulk_starts_at' ),
				$this->optional_post_string( 'mp_cp_bulk_ends_at' ),
				$actor
			),
		};

		wp_safe_redirect(
			add_query_arg(
				array(
					'mp_cp_bulk_changed' => (string) (int) ( $result['changed'] ?? 0 ),
					'mp_cp_bulk_skipped' => (string) (int) ( $result['skipped'] ?? 0 ),
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * @return list<int>
	 */
	private function parse_bulk_promotion_ids(): array {
		$raw_ids = isset( $_POST['mp_cp_promotion_ids'] ) && is_array( $_POST['mp_cp_promotion_ids'] )
			? $_POST['mp_cp_promotion_ids']
			: array();
		$ids     = array();
		foreach ( $raw_ids as $raw ) {
			if ( is_scalar( $raw ) ) {
				$id = (int) $raw;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		return array_values( $ids );
	}

	private function optional_post_string( string $key ): ?string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}
		$val = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		return $val === '' ? null : $val;
	}

	private function optional_post_int( string $key ): ?int {
		if ( ! isset( $_POST[ $key ] ) || $_POST[ $key ] === '' ) {
			return null;
		}
		return (int) $_POST[ $key ];
	}

	private function optional_post_float( string $key ): ?float {
		if ( ! isset( $_POST[ $key ] ) || $_POST[ $key ] === '' ) {
			return null;
		}
		return (float) $_POST[ $key ];
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 */
	private function render_pagination( array $list_query, int $total_pages ): void {
		$paged = $list_query['paged'];

		echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0;">';

		if ( $paged > 1 ) {
			$prev_args          = $this->list_query_to_url_args( $list_query );
			$prev_args['paged'] = (string) ( $paged - 1 );
			echo '<a class="button" href="' . esc_url( $this->list_url( $prev_args ) ) . '">' . esc_html__( '← Previous', 'mp-commerce-promotions' ) . '</a> ';
		}

		if ( $paged < $total_pages ) {
			$next_args          = $this->list_query_to_url_args( $list_query );
			$next_args['paged'] = (string) ( $paged + 1 );
			echo '<a class="button" href="' . esc_url( $this->list_url( $next_args ) ) . '">' . esc_html__( 'Next →', 'mp-commerce-promotions' ) . '</a>';
		}

		echo '</div></div>';
	}

	/**
	 * @param array{status: string|null, search: string|null, paged: int, offset: int} $list_query
	 * @return array<string, string>
	 */
	private function list_query_to_url_args( array $list_query ): array {
		$args = array();
		if ( $list_query['status'] !== null ) {
			$args['promotion_status'] = $list_query['status'];
		}
		if ( $list_query['search'] !== null && $list_query['search'] !== '' ) {
			$args['s'] = $list_query['search'];
		}
		if ( $list_query['campaign_label'] !== null && $list_query['campaign_label'] !== '' ) {
			$args['campaign_label'] = $list_query['campaign_label'];
		}
		if ( $list_query['lifecycle_phase'] !== null && $list_query['lifecycle_phase'] !== '' ) {
			$args['lifecycle_phase'] = $list_query['lifecycle_phase'];
		}
		if ( $list_query['paged'] > 1 ) {
			$args['paged'] = (string) $list_query['paged'];
		}
		return $args;
	}

	private function format_status_cell( Promotion $promotion ): string {
		$phase = PromotionLifecycle::primary_phase( $promotion );
		$badge = PromotionLifecycle::badge_label( $phase );

		$html = esc_html( $promotion->get_status() );
		if ( $phase !== $promotion->get_status() ) {
			$html .= ' <span class="description">(' . esc_html( $badge ) . ')</span>';
		}

		return $html;
	}

	private function format_campaign_list_cell( Promotion $promotion ): string {
		$label = $promotion->get_campaign_label();
		$color = $promotion->get_admin_color();

		if ( ( $label === null || $label === '' ) && ( $color === null || $color === '' ) ) {
			return esc_html( '—' );
		}

		$html = '';
		if ( $color !== null && $color !== '' ) {
			$html .= '<span style="display:inline-block;width:10px;height:10px;border-radius:2px;border:1px solid rgba(0,0,0,.15);background-color:' . esc_attr( $color ) . ';margin-right:4px;vertical-align:middle;"></span>';
		}
		if ( $label !== null && $label !== '' ) {
			$html .= esc_html( $label );
		}

		return $html;
	}

	/**
	 * @param array<string, string> $extra_query
	 */
	private function list_url( array $extra_query = array() ): string {
		return AdminUrl::list_promotions( $extra_query );
	}

	private function handle_post_create(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_create_promotion_submit'] ) ) {
			return;
		}

		$redirect_base = $this->promotions_admin_url();

		if ( ! isset( $_POST[ self::NONCE_ACTION ] ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'missing_nonce', $redirect_base ) );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_ACTION ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'invalid_nonce', $redirect_base ) );
			exit;
		}

		$name = '';
		if ( isset( $_POST['promotion_name'] ) ) {
			$name = sanitize_text_field( wp_unslash( (string) $_POST['promotion_name'] ) );
		}

		if ( $name === '' ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'empty_name', $redirect_base ) );
			exit;
		}

		try {
			$this->promotion_service->create_draft( $name, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'create_failed', $redirect_base ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'mp_cp_created', '1', $redirect_base ) );
		exit;
	}

	private function promotions_admin_url(): string {
		return $this->list_url();
	}

	private function render_notices(): void {
		if ( isset( $_GET['mp_cp_created'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_created'] ) ) === '1' ) {
			AdminNotice::success( __( 'Draft promotion created.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_bulk_changed'] ) || isset( $_GET['mp_cp_bulk_skipped'] ) ) {
			$changed = isset( $_GET['mp_cp_bulk_changed'] ) ? (int) $_GET['mp_cp_bulk_changed'] : 0;
			$skipped = isset( $_GET['mp_cp_bulk_skipped'] ) ? (int) $_GET['mp_cp_bulk_skipped'] : 0;
			if ( $changed > 0 || $skipped > 0 ) {
				AdminNotice::success(
					sprintf(
						/* translators: 1: number of promotions updated, 2: number skipped */
						__( 'Bulk update complete: %1$d changed, %2$d skipped.', 'mp-commerce-promotions' ),
						$changed,
						$skipped
					)
				);
			} else {
				AdminNotice::error( __( 'No promotions were selected for bulk update.', 'mp-commerce-promotions' ) );
			}
		}

		if ( isset( $_GET['mp_cp_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_error'] ) );
			$msg  = $this->error_message_for_code( $code );
			if ( $msg !== '' ) {
				AdminNotice::error( $msg );
			}
		}
	}

	private function error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			case 'empty_name':
				return __( 'Please enter a promotion name.', 'mp-commerce-promotions' );
			case 'create_failed':
				return __( 'Could not create the promotion. Please try again.', 'mp-commerce-promotions' );
			case 'invalid_bulk_action':
				return __( 'Please choose a bulk action.', 'mp-commerce-promotions' );
			case 'promotion_not_found':
				return __( 'That promotion could not be found.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Create draft promotion', 'mp-commerce-promotions' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->promotions_admin_url() ) . '" style="margin-bottom:1.5em;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_ACTION );
		echo '<p><label for="mp_cp_promotion_name">' . esc_html__( 'Promotion name', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="mp_cp_promotion_name" name="promotion_name" maxlength="191" required /></p>';
		echo '<p><button type="submit" name="mp_cp_create_promotion_submit" value="1" class="button button-primary">' . esc_html__( 'Create draft promotion', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function format_codes_summary( ?int $promotion_id ): string {
		if ( $promotion_id === null || $promotion_id <= 0 || $this->promotion_codes === null ) {
			return '—';
		}

		$total  = $this->promotion_codes->count_for_promotion( $promotion_id );
		$active = $this->promotion_codes->count_active_for_promotion( $promotion_id );

		return sprintf(
			/* translators: 1: active code count, 2: total code count */
			__( '%1$d / %2$d', 'mp-commerce-promotions' ),
			$active,
			$total
		);
	}

	private function format_batches_summary( ?int $promotion_id ): string {
		if ( $promotion_id === null || $promotion_id <= 0 || $this->code_batches === null ) {
			return '—';
		}

		return (string) $this->code_batches->count_for_promotion( $promotion_id );
	}

	private function format_redemptions_summary( ?int $promotion_id ): string {
		if ( $promotion_id === null || $promotion_id <= 0 || $this->redemptions === null ) {
			return '—';
		}

		$recorded = $this->redemptions->count_recorded_for_promotion( $promotion_id );
		$reversed = $this->redemptions->count_reversed_for_promotion( $promotion_id );

		return sprintf(
			/* translators: 1: recorded redemption count, 2: reversed redemption count */
			__( '%1$d / %2$d', 'mp-commerce-promotions' ),
			$recorded,
			$reversed
		);
	}

	private function format_application_summary( Promotion $promotion ): string {
		$parts = array();

		if ( $promotion->get_application_mode() === PromotionApplicationMode::EXCLUSIVE ) {
			$parts[] = __( 'Exclusive', 'mp-commerce-promotions' );
		} else {
			$parts[] = __( 'Stackable', 'mp-commerce-promotions' );
		}

		$excluded_count = count( $promotion->get_excluded_promotion_ids() );
		if ( $excluded_count > 0 ) {
			$parts[] = __( 'Has exclusions', 'mp-commerce-promotions' );
			$parts[] = sprintf(
				/* translators: %d: number of excluded promotion IDs */
				__( 'Conflicts: %d', 'mp-commerce-promotions' ),
				$excluded_count
			);
		}

		if ( $this->promotion_has_scoped_rules( $promotion ) ) {
			$parts[] = __( 'Scoped', 'mp-commerce-promotions' );
		}

		if ( $promotion->should_stop_processing() ) {
			$parts[] = __( 'Stop', 'mp-commerce-promotions' );
		}

		return implode( ' · ', $parts );
	}

	private function promotion_has_scoped_rules( Promotion $promotion ): bool {
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT || $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				if ( ! empty( $action['category_ids'] ) || ! empty( $action['product_ids'] ) || ! empty( $action['variation_ids'] ) ) {
					return true;
				}
			}
			if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				return true;
			}
		}

		foreach ( $promotion->get_conditions() as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			if ( ( $condition['type'] ?? '' ) === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL ) {
				return true;
			}
		}

		return false;
	}

	private function format_validation_summary( Promotion $promotion ): string {
		$issues = $this->rule_validator->validate( $promotion );

		$error_count   = 0;
		$warning_count = 0;

		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$level = isset( $issue['level'] ) ? (string) $issue['level'] : '';
			if ( $level === 'error' ) {
				++$error_count;
			} elseif ( $level === 'warning' ) {
				++$warning_count;
			}
		}

		if ( $error_count === 0 && $warning_count === 0 ) {
			return __( 'OK', 'mp-commerce-promotions' );
		}

		$parts = array();

		if ( $error_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: error count */
				_n( '%d error', '%d errors', $error_count, 'mp-commerce-promotions' ),
				$error_count
			);
		}

		if ( $warning_count > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: warning count */
				_n( '%d warning', '%d warnings', $warning_count, 'mp-commerce-promotions' ),
				$warning_count
			);
		}

		return implode( ', ', $parts );
	}

	private function format_usage( Promotion $promo ): string {
		$used = $promo->get_usage_count();
		$lim  = $promo->get_usage_limit();

		if ( $lim === null ) {
			return sprintf(
				/* translators: 1: usage count */
				__( '%1$s / —', 'mp-commerce-promotions' ),
				(string) $used
			);
		}

		return sprintf(
			/* translators: 1: usage count, 2: usage limit */
			__( '%1$s / %2$s', 'mp-commerce-promotions' ),
			(string) $used,
			(string) $lim
		);
	}

	private function format_datetime( ?string $value ): string {
		if ( $value === null || $value === '' ) {
			return '—';
		}

		return $value;
	}
}
