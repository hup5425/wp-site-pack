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

	<div class="wsp-settings-body">
		<?php $mod->render_settings(); ?>
	</div>

	<div class="wsp-settings-foot">
		<button type="submit" class="button button-primary button-hero">저장하기</button>
	</div>
</form>
