<?php
/**
 * 「我的授权」—— 站点运营方（Pro 授权持有者）的后台管理页。
 *
 * 同站直连模式：用户中心与授权中心部署在同一 WordPress 时，
 * 直接读取 at8lic_ 表（仅当前用户的授权），解绑直接调用授权中心函数（带属主校验）。
 *
 * 位置：**只出现在 wp-admin**，前台账户中心不展示。
 * 理由：授权码（谁买了 Pro、绑在哪个域名、何时到期、怎么续期/解绑）属于
 * 站点运营动作，不是前台会员功能 —— 出现在账户中心会让会员困惑「什么是 Pro」。
 * 管理入口按理应放在后台，这里用后台子菜单承载。
 *
 * 展示：授权列表（产品/类型/状态/到期/掩码 key）+ 绑定站点 + 自助解绑。
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Licenses_Tab
{
    public static function boot()
    {
        $i = new self();
        add_action('admin_menu', array($i, 'register_admin_menu'), 20);
    }

    /**
     * 授权中心表是否存在且当前用户有管理权。
     *
     * @return bool
     */
    protected function is_available()
    {
        global $wpdb;
        if (!current_user_can('manage_options')) {
            return false;
        }
        $table = $wpdb->prefix . 'at8lic_licenses';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * 注册为后台子菜单。跟随用户中心设置页的挂载位置：
     * 独立插件模式挂在「用户中心」下，单插件模式挂在商城菜单下。
     */
    public function register_admin_menu()
    {
        // 不再单独占一个后台菜单：授权相关配置已统一到「商城设置 → 授权中心」，
        // 这里只保留 render() 供该区块调用（如需列出当前用户的授权）。
        return;
        // @phpstan-ignore-next.codePath.unreachable
        if (!$this->is_available()) {
            return;
        }
        $parent = 'mlshop';
        if (class_exists('MLUC_Settings') && method_exists('MLUC_Settings', 'submenu_parent_slug')) {
            $parent = MLUC_Settings::submenu_parent_slug();
        }
        // 父菜单不存在时退回顶级菜单，避免 add_submenu_page 静默失败
        global $admin_page_hooks;
        if (!isset($admin_page_hooks[$parent]) && 'mlshop' !== $parent) {
            $parent = 'mlshop';
        }

        add_submenu_page(
            $parent,
            __('我的授权', 'moonlight-shop'),
            __('我的授权', 'moonlight-shop'),
            'manage_options',
            'mluc-licenses',
            array($this, 'render')
        );
    }

    /**
     * 兼容旧调用：前台账户中心不再注册「我的授权」Tab。
     *
     * @param array $tabs
     * @return array
     */
    public function register_tab($tabs)
    {
        // 前台不展示：入口已挪到 wp-admin
        return $tabs;
    }

    /* ---------------- 解绑（用户自助） ---------------- */

    public function render()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('请先登录。', 'moonlight-shop') . '</p>';
            return;
        }

        // 处理解绑请求
        $notice = $this->maybe_handle_deactivate($user_id);

        global $wpdb;
        $table = $wpdb->prefix . 'at8lic_licenses';
        $licenses = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC", $user_id)
        );

        if ($notice) {
            $class = $notice['ok'] ? 'mluc-message' : 'mluc-message mluc-error';
            echo '<p class="' . esc_attr($class) . '">' . esc_html($notice['text']) . '</p>';
        }

        if (!$licenses) {
            echo '<p>' . esc_html__('暂无授权。前往商店查看 Pro 产品：', 'moonlight-shop')
               . '<a href="' . esc_url(home_url('/store/')) . '">' . esc_html__('授权商店', 'moonlight-shop') . '</a></p>';
            return;
        }

        echo '<div class="mluc-licenses">';
        foreach ($licenses as $license) {
            $product = $wpdb->prefix . 'at8lic_products';
            $product_row = $wpdb->get_row(
                $wpdb->prepare("SELECT product_name, product_slug FROM $product WHERE id = %d", (int) $license->product_id)
            );

            $status_color = ('active' === $license->status) ? '#00a32a' : '#b32d2e';
            $expires = (null === $license->expires_at)
                ? esc_html__('终身', 'moonlight-shop')
                : esc_html(wp_date('Y-m-d', (int) $license->expires_at));

            echo '<div class="mluc-license-card" style="border:1px solid #ddd;border-radius:6px;padding:14px 18px;margin-bottom:14px">';
            echo '<h3 style="margin:0 0 8px">' . esc_html($product_row ? $product_row->product_name : ('#' . $license->product_id)) . '</h3>';
            echo '<p style="margin:0 0 4px">'
               . '<span style="color:' . esc_attr($status_color) . ';font-weight:600">' . esc_html($license->status) . '</span>'
               . ' ｜ ' . esc_html($license->license_type)
               . ' ｜ ' . esc_html__('到期', 'moonlight-shop') . '：' . $expires
               . '</p>';
            echo '<p style="margin:0 0 4px;color:#646970">'
               . esc_html__('授权码', 'moonlight-shop') . '：<code>' . esc_html($license->license_key_masked) . '</code>'
               . ' ｜ ' . esc_html__('站点额度', 'moonlight-shop') . '：'
               . esc_html((string) $license->max_sites) . '</p>';

            // Pro 安装包下载（签名短时链接，仅授权中心同站可用）
            $product_slug = $product_row ? $product_row->product_slug : '';
            $download = '';
            if ($product_slug !== '' && $license->status === 'active' && class_exists('AT8LIC_REST') && class_exists('AT8LIC_Products')) {
                $prow = AT8LIC_Products::get((int) $license->product_id);
                if ($prow && (string) ($prow->download_path ?? '') !== '') {
                    $download = AT8LIC_REST::signed_download_url($product_slug);
                }
            }
            if ($download !== '') {
                echo '<p style="margin:4px 0"><a class="button button-small" href="' . esc_url($download) . '">'
                   . esc_html__('下载 Pro 安装包（最新版）', 'moonlight-shop') . '</a></p>';
            }

            // 绑定站点
            $act_table = $wpdb->prefix . 'at8lic_activations';
            $acts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $act_table WHERE license_id = %d AND status = 'active' ORDER BY id ASC",
                    (int) $license->id
                )
            );

            if ($acts) {
                echo '<p style="margin:10px 0 4px"><strong>' . esc_html__('已绑定站点', 'moonlight-shop') . '</strong></p>';
                echo '<table style="width:100%;border-collapse:collapse"><tbody>';
                foreach ($acts as $act) {
                    echo '<tr>';
                    echo '<td style="padding:4px 0">' . esc_html($act->domain)
                       . ' <span style="color:#999">(' . esc_html($act->environment) . ')</span></td>';
                    echo '<td style="padding:4px 0;text-align:right">';

                    if ('production' === $act->environment) {
                        // 本页已挪到 wp-admin，解绑链接必须回到后台页
                        // （前台账户中心不再有 licenses Tab，指过去会 404）
                        $base = add_query_arg(array(
                            'mluc_unbind' => (int) $act->id,
                            'lic'         => (int) $license->id,
                        ), admin_url('admin.php?page=mluc-licenses'));
                        $url = wp_nonce_url($base, 'mluc_unbind_' . (int) $act->id);

                        echo '<a href="' . esc_url($url) . '" style="color:#b32d2e"'
                           . ' onclick="return confirm(\'' . esc_js(__('确认解绑该站点？解绑后可在新站点重新激活。', 'moonlight-shop')) . '\');">'
                           . esc_html__('解绑', 'moonlight-shop') . '</a>';
                    } else {
                        echo '<span style="color:#999">' . esc_html__('开发环境', 'moonlight-shop') . '</span>';
                    }

                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p style="margin:8px 0 0;color:#646970">' . esc_html__('暂未绑定站点：在目标站点安装对应的 Pro 插件并输入授权码即可。', 'moonlight-shop') . '</p>';
            }

            echo '</div>';
        }
        echo '</div>';
    }

    private function maybe_handle_deactivate($user_id)
    {
        if (empty($_GET['mluc_unbind']) || empty($_GET['lic'])) {
            return null;
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mluc_unbind_' . (int) $_GET['mluc_unbind'])) {
            return array('ok' => false, 'text' => __('操作已过期，请重试。', 'moonlight-shop'));
        }

        global $wpdb;

        // 属主校验：授权必须属于当前用户
        $table = $wpdb->prefix . 'at8lic_licenses';
        $license = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", (int) $_GET['lic'], $user_id)
        );
        if (!$license) {
            return array('ok' => false, 'text' => __('授权不存在或无权操作。', 'moonlight-shop'));
        }

        // 授权中心类可用（同站启用）时走其内部逻辑（含日志与换域计数）
        if (!class_exists('AT8LIC_Activations') || !class_exists('AT8LIC_Logs')) {
            return array('ok' => false, 'text' => __('授权中心插件未启用，无法解绑。', 'moonlight-shop'));
        }

        $act_table = $wpdb->prefix . 'at8lic_activations';
        $act = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $act_table WHERE id = %d AND license_id = %d AND status = 'active'",
                (int) $_GET['mluc_unbind'], (int) $license->id
            )
        );
        if (!$act) {
            return array('ok' => false, 'text' => __('站点绑定不存在。', 'moonlight-shop'));
        }

        $done = AT8LIC_Activations::deactivate($license, $act->site_url);
        if (is_wp_error($done) || $done !== true) {
            return array('ok' => false, 'text' => __('解绑失败，请联系管理员。', 'moonlight-shop'));
        }

        AT8LIC_Logs::add('domain_change', 'ok', array(
            'license_id' => (int) $license->id,
            'user_id'    => $user_id,
            'domain'     => $act->domain,
            'detail'     => array('via' => 'user_center'),
        ));

        return array('ok' => true, 'text' => __('站点已解绑，可在新站点重新激活。', 'moonlight-shop'));
    }
}
