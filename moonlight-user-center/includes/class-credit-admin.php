<?php
/**
 * 后台调账：用户编辑页管理员手工调整 余额 / 积分（正数增加，负数扣减）。
 *
 * 权限：仅 manage_options；写入走原子账本并留流水（操作人 + 备注）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Credit_Admin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('show_user_profile', array($this, 'render_fields'));
        add_action('edit_user_profile', array($this, 'render_fields'));
        add_action('personal_options_update', array($this, 'save_fields'));
        add_action('edit_user_profile_update', array($this, 'save_fields'));
    }

    /**
     * 渲染调账字段（仅管理员可见可改）。
     */
    public function render_fields($user)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!class_exists('MLUC_Credit') || !class_exists('MLUC_Wallet')) {
            return;
        }
        $credit_on   = mluc_credit_enabled();
        $balance_on  = mluc_balance_enabled();
        $credit_name = mluc_get_credit_name();
        $symbol      = class_exists('MLUC_Payments') ? MLUC_Payments::get_currency_symbol() : '$';
        ?>
        <h2 id="mluc-credit-admin"><?php esc_html_e('积分与余额', 'moonlight-user-center'); ?></h2>
        <table class="form-table" role="presentation">
            <?php if ($credit_on) : ?>
                <tr>
                    <th><label for="mluc_credit_adjust"><?php echo esc_html(sprintf(__('%s（当前 %s）', 'moonlight-user-center'), $credit_name, number_format(MLUC_Credit::get_balance($user->ID), 2))); ?></label></th>
                    <td>
                        <input type="number" step="0.01" id="mluc_credit_adjust" name="mluc_credit_adjust" value="" class="small-text">
                        <p class="description"><?php esc_html_e('填写变动量：正数增加，负数扣减，留空不调整。调整会记入积分流水。', 'moonlight-user-center'); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($balance_on) : ?>
                <tr>
                    <th><label for="mluc_balance_adjust"><?php echo esc_html(sprintf(__('余额（当前 %s）', 'moonlight-user-center'), $symbol . number_format(MLUC_Wallet::get_balance($user->ID), 2))); ?></label></th>
                    <td>
                        <input type="number" step="0.01" id="mluc_balance_adjust" name="mluc_balance_adjust" value="" class="small-text">
                        <p class="description"><?php esc_html_e('填写变动量：正数增加，负数扣减，留空不调整。调整会记入余额流水。', 'moonlight-user-center'); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ($credit_on || $balance_on) : ?>
                <tr>
                    <th><label for="mluc_credit_note"><?php esc_html_e('调整备注', 'moonlight-user-center'); ?></label></th>
                    <td>
                        <input type="text" id="mluc_credit_note" name="mluc_credit_note" value="" class="regular-text" placeholder="<?php esc_attr_e('如：活动赠送 / 客服补偿', 'moonlight-user-center'); ?>">
                        <p class="description"><?php esc_html_e('留空则流水备注为「管理员调整」。', 'moonlight-user-center'); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * 保存调账：变动量为空或 0 时不动账本；扣减前校验余额充足。
     */
    public function save_fields($user_id)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!class_exists('MLUC_Credit') || !class_exists('MLUC_Wallet')) {
            return;
        }
        $credit_adjust = isset($_POST['mluc_credit_adjust']) ? (float) $_POST['mluc_credit_adjust'] : 0.0;
        $balance_adjust = isset($_POST['mluc_balance_adjust']) ? (float) $_POST['mluc_balance_adjust'] : 0.0;
        if (0.0 === $credit_adjust && 0.0 === $balance_adjust) {
            return; // 未提交任何调整（其他表单触发 profile 保存时不动账本）。
        }
        $note   = isset($_POST['mluc_credit_note']) ? sanitize_text_field(wp_unslash($_POST['mluc_credit_note'])) : '';
        $note   = '' !== $note ? $note : __('管理员调整', 'moonlight-user-center');
        $admin  = wp_get_current_user();
        $note   = sprintf('%s（%s）', $note, $admin->user_login);

        if (0.0 !== $credit_adjust) {
            if ($credit_adjust > 0) {
                MLUC_Credit::add($user_id, $credit_adjust, $note);
            } elseif (false === MLUC_Credit::spend($user_id, abs($credit_adjust), $note)) {
                // 积分不足：跳过并在流水上留痕不可行（未扣减），直接放弃调整。
                return;
            }
        }
        if (0.0 !== $balance_adjust) {
            if ($balance_adjust > 0) {
                MLUC_Wallet::add($user_id, $balance_adjust, $note);
            } elseif (false === MLUC_Wallet::spend($user_id, abs($balance_adjust), $note)) {
                return;
            }
        }
    }
}
