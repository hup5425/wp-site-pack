<?php
/**
 * 대시보드 — 카드 그리드. 각 모듈: 활성/비활성 배지 + 이름 + 설명 + [설정] + On/Off 토글.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$modules = WSP_Core::modules();
?>
<h1 class="wsp-title">
	사이트 팩
	<span class="wsp-ver">v<?php echo esc_html( WSP_VERSION ); ?></span>
</h1>
<p class="wsp-sub">필요한 기능만 켜서 쓰는 모듈형 팩입니다. 각 카드의 스위치로 켜고 끌 수 있어요.</p>

<div class="wsp-update-box">
	<span class="wsp-update-cur">현재 버전 <strong>v<?php echo esc_html( WSP_VERSION ); ?></strong></span>
	<button type="button" class="button" id="wsp-check-update">업데이트 확인</button>
	<span id="wsp-update-status"></span>
	<button type="button" class="button button-primary" id="wsp-do-update" style="display:none">지금 업데이트</button>
</div>

<?php if ( empty( $modules ) ) : ?>
	<div class="notice notice-warning"><p>등록된 모듈이 없습니다.</p></div>
<?php else : ?>
	<div class="wsp-grid">
		<?php foreach ( $modules as $slug => $mod ) : ?>
			<?php $active = WSP_Settings::is_active( $slug ); ?>
			<div class="wsp-card <?php echo $active ? 'is-active' : ''; ?>">
				<div class="wsp-card-head">
					<span class="dashicons <?php echo esc_attr( $mod->icon() ); ?>"></span>
					<span class="wsp-badge <?php echo $active ? 'on' : 'off'; ?>">
						<?php echo $active ? '활성화됨' : '비활성화됨'; ?>
					</span>
				</div>
				<h2 class="wsp-card-title"><?php echo esc_html( $mod->name() ); ?></h2>
				<p class="wsp-card-desc"><?php echo esc_html( $mod->desc() ); ?></p>
				<div class="wsp-card-foot">
					<form method="post" class="wsp-toggle-form">
						<?php wp_nonce_field( 'wsp_toggle' ); ?>
						<input type="hidden" name="wsp_action" value="toggle_module">
						<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>">
						<input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>">
						<label class="wsp-switch" title="<?php echo $active ? '끄기' : '켜기'; ?>">
							<input type="checkbox" <?php checked( $active ); ?> onchange="this.form.submit()">
							<span class="wsp-slider"></span>
						</label>
					</form>
					<a class="button" href="<?php echo esc_url( $mod->settings_url() ); ?>">설정</a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
