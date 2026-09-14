<?php
/**
 * 모듈: 애드 프로텍터. (가장 복잡 — 통계 브릿지 사용)
 *  - 광고(ins.adsbygoogle) 과다 클릭 IP 차단(시간창 기준).
 *  - 허용/차단 IP·차단 국가. 차단 시 광고 숨김/모달. CloudFlare 연동(옵션).
 *  - IP 해시·국가는 통계(class-geo)에서 읽어 재사용, 없으면 자체 폴백.
 *  - 차단 기록은 전용 테이블 {prefix}wsp_ad_blocks (설정 옵션과 따로라 저장 충돌 없음).
 *
 * 🔴 페이지 캐시(Breeze)+CDN 전제 — HTML 에는 방문자마다 달라지는 값을 넣지 않는다.
 *    HTML(캐시됨)   : ajax 주소 · 액션 이름 같은 **모든 방문자에게 같은 설정**만.
 *    admin-ajax(캐시 안 됨): 차단 여부 · 안내 문구 · nonce 를 그때그때 판단해 돌려준다.
 *    예전에는 차단 여부와 nonce 를 HTML 에 박아서, 한 사람이 차단되면 그 HTML 이 25분간
 *    남들에게도 나가 안내창이 뜨고(①), 캐시된 낡은 nonce 로 보낸 클릭 집계는 조용히 버려졌다(②).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Ad_Protector extends WSP_Module {

	public function id()   { return 'ad_protector'; }
	public function name() { return '애드 프로텍터'; }
	public function desc() { return '광고를 반복 클릭하는 IP를 감지·차단해 무효 클릭을 줄입니다.'; }
	public function icon() { return 'dashicons-shield'; }

	public function default_settings() {
		return array(
			'max_clicks'   => 3,
			'window_min'   => 30,
			'unblock_days' => 30,
			'use_cf'       => 0,
			'cf_token'     => '',
			'cf_zone'      => '',
			'proxy_cdn'    => 0,
			'allow_ips'    => array(),
			'block_ips'    => array(),
			'block_countries' => array(),
			'modal_text'   => '비정상적인 광고 클릭이 감지되어 이 페이지의 광고 표시가 제한되었습니다.',
		);
	}

	protected function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_ad_blocks';
	}

	public function on_activate() {
		global $wpdb;
		$table   = $this->table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip_hash CHAR(32) NOT NULL,
			ip VARCHAR(64) NOT NULL DEFAULT '',
			country CHAR(2) NOT NULL DEFAULT '',
			clicks INT UNSIGNED NOT NULL DEFAULT 0,
			blocked_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY ip_hash (ip_hash)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function register() {
		// 프런트: 광고 클릭 감지 스크립트(방문자와 무관한 설정만 실어 보낸다).
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		// AJAX: 지금 이 방문자가 차단 대상인지(캐시를 타지 않는 자리).
		add_action( 'wp_ajax_wsp_ad_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_wsp_ad_status', array( $this, 'ajax_status' ) );
		// AJAX: 클릭 카운트.
		add_action( 'wp_ajax_wsp_ad_click', array( $this, 'ajax_click' ) );
		add_action( 'wp_ajax_nopriv_wsp_ad_click', array( $this, 'ajax_click' ) );
		// 만료 차단 자동 해제(가벼운 게이트).
		add_action( 'wp_loaded', array( $this, 'maybe_purge_expired' ) );
	}

	/**
	 * 프런트 자원 등록.
	 *
	 * 🔴 여기서 내보내는 값은 **누가 보든 똑같아야 한다**(이 HTML 이 캐시되어 25분간 모두에게 나간다).
	 *    그래서 차단 여부·안내 문구·nonce 는 넣지 않는다 — 페이지가 뜬 뒤 JS 가 admin-ajax 로 물어본다.
	 *    "로그인 편집자면 스크립트를 아예 빼는" 분기도 없앴다. 그것도 사람마다 HTML 이 달라지는 자리라,
	 *    편집자가 연 페이지가 캐시되면 남들에게도 감지가 빠진 HTML 이 나간다. 제외 판단 역시 admin-ajax 가 한다.
	 */
	public function assets() {
		if ( is_admin() ) {
			return;
		}
		WSP_Assets::front_style( 'ad-protector' );
		WSP_Assets::front_script( 'ad-protector', array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'status' => 'wsp_ad_status',
			'click'  => 'wsp_ad_click',
		), 'WSP_ADP' );
	}

	/** 로그인한 편집자(관리자·에디터 등)는 추적/차단에서 제외 — 본인이 차단되는 사고 방지. */
	protected function is_exempt_user() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/** 이 방문자의 IP. 전달 헤더는 '프록시/CDN 뒤에 있음' 을 켠 경우에만 읽는다. */
	protected function client_ip() {
		$s = $this->settings();
		return WSP_Stats_Bridge::client_ip( ! empty( $s['proxy_cdn'] ) );
	}

	/**
	 * 지금 이 방문자의 상태를 알려 준다(캐시 안 되는 자리).
	 *
	 * admin-ajax.php 는 워드프레스가 맨 앞에서 nocache_headers() 를 부르고 POST 로만 보내므로
	 * Breeze 페이지 캐시도 CDN 도 저장하지 않는다 → 사람마다 다른 이 답은 여기서만 만든다.
	 *
	 *  state: off(추적 제외) · watch(감시만) · blocked(차단)
	 *  nonce: 방금 만든 것이라 캐시 때문에 낡을 일이 없다. 클릭 보고에 그대로 쓴다.
	 */
	public function ajax_status() {
		nocache_headers();
		if ( $this->is_exempt_user() ) {
			wp_send_json_success( array( 'state' => 'off' ) );
		}
		$s  = $this->settings();
		$ip = $this->client_ip();
		$blocked = ( '' !== $ip && $this->is_blocked( $ip ) );
		wp_send_json_success( array(
			'state' => $blocked ? 'blocked' : 'watch',
			'text'  => $blocked ? $s['modal_text'] : '',
			// 비로그인 방문자에게 워드프레스 nonce 는 뜻이 약하지만, 남의 사이트에서 쏘는
			// 요청을 걸러 주기는 한다. 캐시된 HTML 이 아니라 이 답으로 주므로 늘 유효하다.
			'nonce' => wp_create_nonce( 'wsp_ad' ),
		) );
	}

	/**
	 * 이 IP가 차단 대상인지(수동 차단 IP / 차단 국가 / 시간창 초과 기록).
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function is_blocked( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$s = $this->settings();

		// 허용 IP 는 항상 통과.
		if ( in_array( $ip, (array) $s['allow_ips'], true ) ) {
			return false;
		}
		// 수동 차단 IP.
		if ( in_array( $ip, (array) $s['block_ips'], true ) ) {
			return true;
		}
		// 차단 국가.
		if ( ! empty( $s['block_countries'] ) ) {
			$country = WSP_Stats_Bridge::country_code( $ip );
			if ( $country && in_array( $country, (array) $s['block_countries'], true ) ) {
				return true;
			}
		}
		// 자동 차단 기록(미만료).
		global $wpdb;
		$hash  = WSP_Stats_Bridge::ip_hash( $ip );
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE ip_hash = %s AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1",
			$hash, current_time( 'mysql' )
		) );
		return (bool) $row;
	}

	/** 광고 클릭 AJAX — 시간창 카운트 → 초과 시 차단 기록. */
	public function ajax_click() {
		nocache_headers();
		$s = $this->settings();
		// nonce 는 상태 응답에서 갓 받은 것이라 정상이면 반드시 맞는다.
		// 틀리면(페이지를 12시간 넘게 열어 둔 경우 등) 조용히 버리지 말고 다시 받아 오라고 알린다.
		if ( ! check_ajax_referer( 'wsp_ad', 'nonce', false ) ) {
			wp_send_json_error( array( 'renew' => 1 ) );
		}
		// 로그인 편집자는 집계·차단하지 않음.
		if ( $this->is_exempt_user() ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		if ( in_array( $ip, (array) $s['allow_ips'], true ) ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		if ( $this->is_blocked( $ip ) ) {
			wp_send_json_success( array( 'blocked' => 1, 'text' => $s['modal_text'] ) );
		}

		$hash   = WSP_Stats_Bridge::ip_hash( $ip );
		$key    = 'wsp_adc_' . $hash;
		$window = max( 1, (int) $s['window_min'] ) * MINUTE_IN_SECONDS;
		$count  = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, $window );

		if ( $count > max( 1, (int) $s['max_clicks'] ) ) {
			$this->block_ip( $ip, $hash, $count );
			wp_send_json_success( array( 'blocked' => 1, 'text' => $s['modal_text'] ) );
		}
		wp_send_json_success( array( 'blocked' => 0 ) );
	}

	protected function block_ip( $ip, $hash, $clicks ) {
		global $wpdb;
		$s       = $this->settings();
		$country = WSP_Stats_Bridge::country_code( $ip );
		$expires = $s['unblock_days'] > 0
			? gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + (int) $s['unblock_days'] * DAY_IN_SECONDS )
			: null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $this->table(), array(
			'ip_hash'    => $hash,
			'ip'         => $ip,
			'country'    => $country,
			'clicks'     => (int) $clicks,
			'blocked_at' => current_time( 'mysql' ),
			'expires_at' => $expires,
		) );

		// CloudFlare 연동(옵션): 엣지에서 IP 차단.
		if ( ! empty( $s['use_cf'] ) && $s['cf_token'] && $s['cf_zone'] ) {
			$this->cf_block( $ip, $s['cf_token'], $s['cf_zone'] );
		}
	}

	protected function cf_block( $ip, $token, $zone ) {
		wp_remote_post( "https://api.cloudflare.com/client/v4/zones/{$zone}/firewall/access_rules/rules", array(
			'timeout' => 8,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'mode'          => 'block',
				'configuration' => array( 'target' => 'ip', 'value' => $ip ),
				'notes'         => 'WP Site Pack ad-protector',
			) ),
		) );
	}

	/** 만료된 차단 자동 해제(하루 1회 게이트). */
	public function maybe_purge_expired() {
		if ( get_transient( 'wsp_adp_purge' ) ) {
			return;
		}
		set_transient( 'wsp_adp_purge', 1, DAY_IN_SECONDS );
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= %s",
			current_time( 'mysql' )
		) );
	}

	public function sanitize( $input ) {
		$s = $this->settings();

		// 차단 기록 전체 삭제 요청(오탐 복구용).
		if ( ! empty( $input['clear_blocks'] ) ) {
			global $wpdb;
			$table = $this->table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
		}

		$parse_ips = function ( $raw ) {
			$out = array();
			foreach ( preg_split( '/[\s,]+/', (string) $raw ) as $line ) {
				$line = trim( $line );
				if ( $line && filter_var( $line, FILTER_VALIDATE_IP ) ) {
					$out[] = $line;
				}
			}
			return array_values( array_unique( $out ) );
		};
		$parse_cc = function ( $raw ) {
			$out = array();
			foreach ( preg_split( '/[\s,]+/', strtoupper( (string) $raw ) ) as $c ) {
				$c = trim( $c );
				// 정확히 2글자 알파벳(ISO 3166-1 alpha-2)만 허용 — 'xx1' 같은 잘못된 입력은 버림.
				if ( preg_match( '/^[A-Z]{2}$/', $c ) ) {
					$out[] = $c;
				}
			}
			return array_values( array_unique( $out ) );
		};

		return array(
			'max_clicks'      => max( 1, min( 100, (int) ( $input['max_clicks'] ?? 3 ) ) ),
			'window_min'      => max( 1, min( 1440, (int) ( $input['window_min'] ?? 30 ) ) ),
			'unblock_days'    => max( 0, min( 3650, (int) ( $input['unblock_days'] ?? 30 ) ) ),
			'use_cf'          => empty( $input['use_cf'] ) ? 0 : 1,
			'cf_token'        => isset( $input['cf_token'] ) ? sanitize_text_field( (string) $input['cf_token'] ) : '',
			'cf_zone'         => isset( $input['cf_zone'] ) ? sanitize_text_field( (string) $input['cf_zone'] ) : '',
			'proxy_cdn'       => empty( $input['proxy_cdn'] ) ? 0 : 1,
			'allow_ips'       => $parse_ips( $input['allow_ips'] ?? '' ),
			'block_ips'       => $parse_ips( $input['block_ips'] ?? '' ),
			'block_countries' => $parse_cc( $input['block_countries'] ?? '' ),
			'modal_text'      => isset( $input['modal_text'] ) ? sanitize_textarea_field( (string) $input['modal_text'] ) : $s['modal_text'],
		);
	}

	protected function recent_blocks( $limit = 50 ) {
		global $wpdb;
		$table = $this->table();
		// 테이블 미생성 상태(모듈 방금 켬) 대비.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY blocked_at DESC LIMIT %d", $limit
		), ARRAY_A );
	}

	public function render_settings() {
		$s = $this->settings();
		// '연결됨' 은 통계 플러그인의 그 함수를 **실제로 찾았을 때만** 뜬다(클래스만 있는지 보지 않는다).
		$reuse = array();
		if ( WSP_Stats_Bridge::has_client_ip() ) {
			$reuse[] = 'IP 판정 재사용';
		}
		if ( WSP_Stats_Bridge::has_geo() ) {
			$reuse[] = '국가 재사용';
		}
		$bridge = $reuse ? '연결됨(' . implode( ' · ', $reuse ) . ')' : '미연결(자체 수집으로 동작)';
		$now_ip = $this->client_ip();
		?>
		<div class="wsp-note">통계 플러그인: <strong><?php echo esc_html( $bridge ); ?></strong>
			<?php if ( WSP_Stats_Bridge::has_client_ip() && empty( $s['proxy_cdn'] ) ) : ?>
				— IP 판정은 아래 <strong>프록시/CDN 뒤에 있음</strong>을 켰을 때만 씁니다.
			<?php endif; ?>
		</div>
		<div class="wsp-note">지금 이 화면에서 보이는 내 IP: <strong><?php echo esc_html( $now_ip ? $now_ip : '알 수 없음' ); ?></strong>
			(허용 IP 칸에 넣을 값입니다. 프록시/CDN 설정을 바꾸면 이 값도 바뀝니다.)</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>최대 허용 클릭 수 / 감지 시간</strong>
				<span class="wsp-row-help">한 IP가 시간창 내 광고를 이 횟수 초과 클릭하면 차단.</span></div>
			<div class="wsp-row-control">
				<input type="number" name="max_clicks" min="1" max="100" value="<?php echo esc_attr( $s['max_clicks'] ); ?>"> 회 /
				<input type="number" name="window_min" min="1" max="1440" value="<?php echo esc_attr( $s['window_min'] ); ?>"> 분
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>차단 자동 해제(일)</strong>
				<span class="wsp-row-help">0 이면 영구 차단.</span></div>
			<div class="wsp-row-control"><input type="number" name="unblock_days" min="0" max="3650" value="<?php echo esc_attr( $s['unblock_days'] ); ?>"> 일</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>프록시/CDN 뒤에 있음</strong>
				<span class="wsp-row-help">Cloudflare 같은 CDN·프록시를 거쳐 들어오는 사이트면 켭니다.
					켜야 방문자의 진짜 IP(CF-Connecting-IP 등)를 읽습니다.
					끄면 서버가 직접 본 접속 주소만 씁니다 — 이 값은 아무나 지어낼 수 없어 더 안전합니다.
					CDN 뒤인데 꺼 두면 방문자가 모두 같은 IP 로 보여 엉뚱한 차단이 납니다.</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="proxy_cdn" value="1" <?php checked( $s['proxy_cdn'], 1 ); ?>> 프록시/CDN 뒤에 있음</label>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>CloudFlare 사용</strong>
				<span class="wsp-row-help">켜면 차단 IP를 CF 엣지에서도 차단(토큰·존 ID 필요).</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="use_cf" value="1" <?php checked( $s['use_cf'], 1 ); ?>> 사용</label><br>
				API 토큰: <input type="text" name="cf_token" value="<?php echo esc_attr( $s['cf_token'] ); ?>"><br>
				Zone ID: <input type="text" name="cf_zone" value="<?php echo esc_attr( $s['cf_zone'] ); ?>">
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>허용 IP</strong><span class="wsp-row-help">한 줄에 하나 또는 쉼표.</span></div>
			<div class="wsp-row-control"><textarea name="allow_ips" rows="3"><?php echo esc_textarea( implode( "\n", (array) $s['allow_ips'] ) ); ?></textarea></div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>차단 IP</strong></div>
			<div class="wsp-row-control"><textarea name="block_ips" rows="3"><?php echo esc_textarea( implode( "\n", (array) $s['block_ips'] ) ); ?></textarea></div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>차단 국가</strong><span class="wsp-row-help">ISO 2자리 코드(예: CN, RU).</span></div>
			<div class="wsp-row-control"><textarea name="block_countries" rows="2"><?php echo esc_textarea( implode( ', ', (array) $s['block_countries'] ) ); ?></textarea></div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>차단 모달 문구</strong></div>
			<div class="wsp-row-control"><textarea name="modal_text" rows="2"><?php echo esc_textarea( $s['modal_text'] ); ?></textarea></div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>차단 로그</strong></div>
			<div class="wsp-row-control">
				<?php $blocks = $this->recent_blocks(); ?>
				<?php if ( empty( $blocks ) ) : ?>
					<p>아직 차단 기록이 없습니다.</p>
				<?php else : ?>
					<p><label><input type="checkbox" name="clear_blocks" value="1"> <strong>차단 기록 전체 삭제</strong>(저장 시) — 모든 차단 즉시 해제</label></p>
					<table class="widefat striped"><thead><tr><th>IP</th><th>국가</th><th>클릭</th><th>차단 시각</th><th>해제 예정</th></tr></thead><tbody>
					<?php foreach ( $blocks as $b ) : ?>
						<tr>
							<td><?php echo esc_html( $b['ip'] ); ?></td>
							<td><?php echo esc_html( $b['country'] ); ?></td>
							<td><?php echo (int) $b['clicks']; ?></td>
							<td><?php echo esc_html( $b['blocked_at'] ); ?></td>
							<td><?php echo esc_html( $b['expires_at'] ?: '영구' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
			</div>
		</div>
		<div class="wsp-note">로그인한 관리자·에디터는 <strong>추적/차단에서 제외</strong>됩니다(본인 차단 방지). 실제 클릭(광고를 눌러 광고 iframe으로 포커스 이동)만 집계하도록 엄격히 동작합니다. 그래도 애드 프로텍터는 광고 차단의 무결성을 보장하지 않습니다.</div>
		<?php
	}
}
