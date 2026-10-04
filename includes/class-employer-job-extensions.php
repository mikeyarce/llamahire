<?php
namespace LlamaHire;

use LlamaHire\Contracts\Extension_Access;

defined( 'ABSPATH' ) || exit;

/** Internal renderer for the documented employer job-summary extension filter. */
final class Employer_Job_Extensions {
	private $access;

	public function __construct( Extension_Access $access ) {
		$this->access = $access;
	}

	/** Read presentation data only after authorizing this job for the current user. */
	public function items( $job_id ) {
		$context = $this->access->job_context( $job_id );
		if ( ! is_array( $context ) || Settings::SITE_MODE_JOB_BOARD !== ( $context['mode'] ?? '' ) ) {
			return array();
		}
		/**
		 * Add bounded, plain-text summaries to an authorized employer's My Jobs row.
		 *
		 * This is presentation only; links confer no permission or publication rights.
		 * Callbacks must preserve existing entries and scope their data to the context.
		 *
		 * @param array $items   Summary entries with label, detail and optional action.
		 * @param array $context Authorized site_id, mode, job_id and owner_id.
		 */
		$items = apply_filters( 'llamahire_employer_job_summaries', array(), $context );
		if ( ! is_array( $items ) ) {
			return array();
		}
		$result = array();
		// Bound processing as well as rendered output; malformed entries still count.
		foreach ( array_slice( $items, 0, 3 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = $this->text( $item['label'] ?? '', 80 );
			if ( '' === $label ) {
				continue;
			}
			$summary = array( 'label' => $label, 'detail' => $this->text( $item['detail'] ?? '', 240 ) );
			$action  = $item['action'] ?? null;
			if ( is_array( $action ) ) {
				$action_label = $this->text( $action['label'] ?? '', 80 );
				$url          = $this->action_url( $action['url'] ?? '' );
				if ( '' !== $action_label && '' !== $url ) {
					$summary['action'] = array( 'label' => $action_label, 'url' => $url );
				}
			}
			$result[] = $summary;
		}
		return $result;
	}

	/** Render escaped summaries beside Free's unchanged publication status. */
	public function render( $job_id ) {
		foreach ( $this->items( $job_id ) as $item ) {
			?>
			<div class="llamahire-employer-portal__extension-summary">
				<strong><?php echo esc_html( $item['label'] ); ?></strong>
				<?php if ( '' !== $item['detail'] ) : ?><small class="llamahire-employer-portal__status-detail"><?php echo esc_html( $item['detail'] ); ?></small><?php endif; ?>
				<?php if ( isset( $item['action'] ) ) : ?><a href="<?php echo esc_url( $item['action']['url'], array( 'http', 'https' ) ); ?>"><?php echo esc_html( $item['action']['label'] ); ?></a><?php endif; ?>
			</div>
			<?php
		}
	}

	private function text( $value, $limit ) {
		return is_string( $value ) ? mb_substr( trim( $value ), 0, $limit, 'UTF-8' ) : '';
	}

	private function action_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return esc_url_raw( $value, array( 'http', 'https' ) );
	}
}
