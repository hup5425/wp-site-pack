<?php
/**
 * 모듈: 예약글 발행 보장 (MWW Scheduled Post Trigger 대체).
 *  WP-Cron 은 방문이 있어야만 돌아 예약 시각이 지나도 'future' 로 누락될 수 있다.
 *  세 겹 안전망:
 *   1) 외부 트리거 엔드포인트  ?wsp_publish_due={KEY}  (실크론/외부핑/허브가 호출)
 *   2) 자체 WP-Cron 짧은 주기 이벤트
 *   3) 요청 시(wp_loaded) 게이트로 가볍게 누락분 보정
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Scheduled_Publish extends WSP_Module {

	const CRON_HOOK = 'wsp_publish_due_cron';
	const GATE_KEY  = 'wsp_sp_last_run'; // 요청 시 보정 과부하 방지 게이트.

	public function id()   { return 'scheduled_publish'; }
	public function name() { return '예약글 발행 보장'; }
	public function desc() { return '방문자가 없어도 예약 시각이 지난 글을 강제로 발행합니다(발행 누락 방지).'; }
	public function icon() { return 'dashicons-calendar-alt'; }

	public function default_settings() {
		return array(
			'mode'          => 'both',   // cron | ping | both
			'interval_min'  => 10,       // 자체 크론 점검 주기(분)
			'secret'        => '',       // 외부 트리거 키
			'log'           => array(),  // 최근 발행 로그(옵션 캡)
		);
	}

	public function register() {
		// 1) 외부 트리거 엔드포인트(키 검증 → 즉시 처리). 프론트 아주 이른 시점.
		add_action( 'init', array( $this, 'maybe_handle_endpoint' ), 1 );

		$s = $this->settings();

		// 2) 자체 WP-Cron.
		if ( 'ping' !== $s['mode'] ) {
			add_filter( 'cron_schedules', array( $this, 'add_interval' ) );
			add_action( self::CRON_HOOK, array( $this, 'publish_due' ) );
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 60, 'wsp_sp_interval', self::CRON_HOOK );
			}
			// 3) 요청 시 보정(게이트).
			add_action( 'wp_loaded', array( $this, 'maybe_gate_run' ) );
		}
	}

	public function on_activate() {
		$s = $this->settings();
		if ( empty( $s['secret'] ) ) {
			$s['secret'] = wp_generate_password( 24, false );
			WSP_Settings::set( $this->id(), $s );
		}
	}

	public function on_deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/** 커스텀 크론 주기 등록. */
	public function add_interval( $schedules ) {
		$min = max( 1, (int) $this->settings()['interval_min'] );
		$schedules['wsp_sp_interval'] = array(
			'interval' => $min * MINUTE_IN_SECONDS,
			'display'  => 'WP Site Pack 예약발행 점검(' . $min . '분)',
		);
		return $schedules;
	}

	/** 외부 트리거 URL 처리. */
	public function maybe_handle_endpoint() {
		if ( empty( $_GET['wsp_publish_due'] ) ) {
			return;
		}
		$key    = sanitize_text_field( wp_unslash( $_GET['wsp_publish_due'] ) );
		$secret = (string) $this->settings()['secret'];
		if ( '' === $secret || ! hash_equals( $secret, $key ) ) {
			status_header( 403 );
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		$n = $this->publish_due();
		nocache_headers(); // CDN/프록시가 발행 트리거 응답을 캐시하지 않도록.
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'wsp published: ' . (int) $n;
		exit;
	}

	/** 요청 시 보정(게이트: 주기 절반보다 자주 안 돎). */
	public function maybe_gate_run() {
		if ( is_admin() ) {
			return;
		}
		$min  = max( 1, (int) $this->settings()['interval_min'] );
		$gate = (int) get_transient( self::GATE_KEY );
		if ( $gate ) {
			return; // 최근 실행됨.
		}
		set_transient( self::GATE_KEY, 1, max( 60, $min * 30 ) ); // 주기 절반 동안 재실행 금지.
		$this->publish_due();
	}

	/**
	 * future 상태이면서 예약 시각이 지난 글을 발행.
	 *
	 * @return int 발행 건수.
	 */
	public function publish_due() {
		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		$ids = get_posts( array(
			'post_status'    => 'future',
			'post_type'      => 'any',
			'posts_per_page' => 50, // 처리량 캡.
			'fields'         => 'ids',
			'date_query'     => array(
				array( 'column' => 'post_date_gmt', 'before' => $now_gmt, 'inclusive' => true ),
			),
			'no_found_rows'  => true,
			'suppress_filters' => true,
		) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $ids as $id ) {
			// wp_publish_post 는 멱등(이미 publish 면 무해). MWW 동시 동작해도 안전.
			wp_publish_post( $id );
			if ( 'publish' === get_post_status( $id ) ) {
				$count++;
				$this->log_publish( $id );
			}
		}
		return $count;
	}

	/** 최근 발행 로그(옵션 캡 30건). */
	protected function log_publish( $id ) {
		$s   = $this->settings();
		$log = is_array( $s['log'] ) ? $s['log'] : array();
		array_unshift( $log, array(
			'id'    => (int) $id,
			'title' => get_the_title( $id ),
			'time'  => current_time( 'mysql' ),
		) );
		$s['log'] = array_slice( $log, 0, 30 );
		WSP_Settings::set( $this->id(), $s );
	}

	public function sanitize( $input ) {
		$s    = $this->settings();
		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'both';
		if ( ! in_array( $mode, array( 'cron', 'ping', 'both' ), true ) ) {
			$mode = 'both';
		}
		$out = array(
			'mode'         => $mode,
			'interval_min' => max( 1, min( 120, (int) ( $input['interval_min'] ?? 10 ) ) ),
			'secret'       => $s['secret'] ? $s['secret'] : wp_generate_password( 24, false ),
			'log'          => is_array( $s['log'] ) ? $s['log'] : array(),
		);
		// 키 재발급 요청.
		if ( ! empty( $input['regen_secret'] ) ) {
			$out['secret'] = wp_generate_password( 24, false );
		}
		return $out;
	}

	public function render_settings() {
		$s   = $this->settings();
		$url = add_query_arg( 'wsp_publish_due', $s['secret'] ?: 'KEY', home_url( '/' ) );
		?>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>트리거 방식</strong>
				<span class="wsp-row-help">외부 핑이 가장 확실합니다(방문 없이도 보장).</span></div>
			<div class="wsp-row-control">
				<label><input type="radio" name="mode" value="cron" <?php checked( $s['mode'], 'cron' ); ?>> 자체 크론만</label><br>
				<label><input type="radio" name="mode" value="ping" <?php checked( $s['mode'], 'ping' ); ?>> 외부 핑만</label><br>
				<label><input type="radio" name="mode" value="both" <?php checked( $s['mode'], 'both' ); ?>> 둘 다(권장)</label>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>점검 주기(분)</strong>
				<span class="wsp-row-help">자체 크론용. 방문이 조금이라도 있으면 이 주기로 보정.</span></div>
			<div class="wsp-row-control">
				<input type="number" name="interval_min" min="1" max="120" value="<?php echo esc_attr( $s['interval_min'] ); ?>"> 분
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>외부 트리거 URL</strong>
				<span class="wsp-row-help">실서버 크론(crontab)이나 허브가 주기적으로 이 주소를 호출하면 됩니다.</span></div>
			<div class="wsp-row-control">
				<code class="wsp-code wsp-copy" data-copy="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url ); ?></code>
				<p><label><input type="checkbox" name="regen_secret" value="1"> 비밀 키 재발급(저장 시)</label></p>
				<div class="wsp-note">crontab 예시:
					<code class="wsp-code">*/<?php echo (int) $s['interval_min']; ?> * * * * curl -s '<?php echo esc_html( $url ); ?>' &gt;/dev/null</code>
				</div>
			</div>
		</div>

		<?php if ( ! empty( $s['log'] ) ) : ?>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>최근 발행 로그</strong></div>
				<div class="wsp-row-control">
					<table class="widefat striped"><thead><tr><th>글</th><th>발행 시각</th></tr></thead><tbody>
					<?php foreach ( $s['log'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['title'] ); ?></td><td><?php echo esc_html( $row['time'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}
}
