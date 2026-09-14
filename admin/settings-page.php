<?php
/**
 * 모듈 설정 페이지 — 상단 활성화 토글 + 모듈 자체 폼 + 저장하기.
 * $mod (WSP_Module) 는 WSP_Admin::render() 에서 넘어온다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var WSP_Module $mod */
$slug   = $mod->id();
$active = WSP_Settings::is_active( $slug );
?>
<h1 class="wsp-title">
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSP_Admin::SLUG ) ); ?>" class="wsp-back">← 사이트 팩</a>
	<span class="dashicons <?php echo esc_attr( $mod->icon() ); ?>"></span>
	<?php echo esc_html( $mod->name() ); ?>
</h1>
<p class="wsp-sub"><?php echo esc_html( $mod->desc() ); ?></p>

<div class="wsp-row wsp-row--toggle">
	<div class="wsp-row-label">
		<strong>플러그인 기능 활성화</strong>
		<span class="wsp-row-help">이 모듈을 켜거나 끕니다.</span>
	</div>
	<div class="wsp-row-control">
		<form method="post" class="wsp-toggle-form">
			<?php wp_nonce_field( 'wsp_toggle' ); ?>
			<input type="hidden" name="wsp_action" value="toggle_module">
			<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>">
			<input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>">
			<label class="wsp-switch">
				<input type="checkbox" <?php checked( $active ); ?> onchange="this.form.submit()">
				<span class="wsp-slider"></span>
			</label>
			<span class="wsp-toggle-state"><?php echo $active ? '켜짐' : '꺼짐'; ?></span>
		</form>
	</div>
</div>

<form method="post" class="wsp-settings-form" enctype="multipart/form-data">
	<?php wp_nonce_field( 'wsp_save_' . $slug ); ?>
	<input type="hidden" name="wsp_action" value="save_module">
	<input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>">

	<?php
	/*
	 * 입력칸에서 엔터를 쳤을 때 쓰이는 기본 단추(워드프레스·HTML 관례).
	 * 브라우저는 엔터를 폼 안의 "첫 submit 단추"로 처리하는데, 그 자리에 모듈의
	 * [삭제] 단추가 있으면 엔터 한 번에 인증 파일이 지워진다. 눈에 안 보이는 저장 단추를
	 * 맨 앞에 두어 엔터 = 저장하기가 되게 한다. (display:none 대신 화면에서만 감춰
	 * 브라우저가 기본 단추로 인정하게 한다.)
	 */
	?>
	<button type="submit" class="wsp-default-submit" tabindex="-1" aria-hidden="true"
		style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);border:0">저장하기</button>

	<div class="wsp-settings-body">
		<?php $mod->render_settings(); ?>
	</div>

	<div class="wsp-settings-foot">
		<button type="submit" class="button button-primary button-hero">저장하기</button>
	</div>
</form>
