<?php
/**
 * 모듈: 애드 프로텍터. (가장 복잡 — 통계 브릿지 사용)
 *  - 광고(ins.adsbygoogle) 과다 클릭 IP 차단(시간창 기준).
 *  - 허용/차단 IP·차단 국가. 차단 시 광고 숨김/모달. CloudFlare 연동(옵션).
 *  - IP 해시·국가는 통계(class-geo)에서 읽어 재사용, 없으면 자체 폴백.
 *  - 차단 기록은 전용 테이블 {prefix}wsp_ad_blocks.
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
		// 프런트: 광고 클릭 감지 스크립트 + 차단 상태 전달.
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		// AJAX: 클릭 카운트.
		add_action( 'wp_ajax_wsp_ad_click', array( $this, 'ajax_click' ) );
		add_action( 'wp_ajax_nopriv_wsp_ad_click', array( $this, 'ajax_click' ) );
		// 만료 차단 자동 해제(가벼운 게이트).
		add_action( 'wp_loaded', array( $this, 'maybe_purge_expired' ) );
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		// 로그인한 편집자(관리자·에디터 등)는 추적/차단 대상에서 완전 제외 — 본인이 차단되는 사고 방지.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}
		WSP_Assets::front_style( 'ad-protector' );
		$ip      = WSP_Stats_Bridge::client_ip();
		$blocked = $this->is_blocked( $ip );
		WSP_Assets::front_script( 'ad-protector', array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'wsp_ad' ),
			'blocked'   => $blocked ? 1 : 0,
			'modalText' => $blocked ? $this->settings()['modal_text'] : '',
		), 'WSP_ADP' );
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
		check_ajax_referer( 'wsp_ad', 'nonce' );
		// 로그인 편집자는 집계·차단하지 않음.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		$ip = WSP_Stats_Bridge::client_ip();
		if ( '' === $ip ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		$s = $this->settings();
		if ( in_array( $ip, (array) $s['allow_ips'], true ) ) {
			wp_send_json_success( array( 'blocked' => 0 ) );
		}
		if ( $this->is_blocked( $ip ) ) {
			wp_send_json_success( array( 'blocked' => 1 ) );
		}

		$hash   = WSP_Stats_Bridge::ip_hash( $ip );
		$key    = 'wsp_adc_' . $hash;
		$window = max( 1, (int) $s['window_min'] ) * MINUTE_IN_SECONDS;
		$count  = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, $window );

		if ( $count > max( 1, (int) $s['max_clicks'] ) ) {
			$this->block_ip( $ip, $hash, $count );
			wp_send_json_success( array( 'blocked' => 1 ) );
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
		$s      = $this->settings();
		$bridge = WSP_Stats_Bridge::available() ? '연결됨(IP·국가 재사용)' : '미연결(자체 수집으로 동작)';
		?>
		<div class="wsp-note">통계 플러그인: <strong><?php echo esc_html( $bridge ); ?></strong></div>

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
