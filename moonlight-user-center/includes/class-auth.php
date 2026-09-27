<?php
/**
 * 前端认证：登录 / 注册 / 找回密码（短代码 + AJAX）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Auth
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // 短代码
        add_shortcode('mluc_login', array($this, 'shortcode_login'));
        add_shortcode('mluc_register', array($this, 'shortcode_register'));
        add_shortcode('mluc_lostpassword', array($this, 'shortcode_lostpassword'));

        // AJAX
        add_action('wp_ajax_nopriv_mluc_login', array($this, 'ajax_login'));
        add_action('wp_ajax_mluc_login', array($this, 'ajax_login'));
        add_action('wp_ajax_nopriv_mluc_register', array($this, 'ajax_register'));
        add_action('wp_ajax_mluc_register', array($this, 'ajax_register'));
        add_action('wp_ajax_nopriv_mluc_lost_password', array($this, 'ajax_lost_password'));
        add_action('wp_ajax_mluc_lost_password', array($this, 'ajax_lost_password'));
        add_action('wp_ajax_nopriv_mluc_logout', array($this, 'ajax_logout'));
        add_action('wp_ajax_mluc_logout', array($this, 'ajax_logout'));

        // 前台 GET 方式退出（兼容链接）
        add_action('init', array($this, 'handle_get_logout'));
    }

    /**
     * 已登录用户访问认证表单时，跳转到账户中心。
     */
    private function maybe_redirect_logged_in()
    {
        if (is_user_logged_in()) {
        $redirect = mluc_get_account_url();
        if (!empty($_GET['redirect_to'])) {
            $redirect = wp_validate_redirect($_GET['redirect_to'], mluc_get_account_url());
        }
        wp_safe_redirect($redirect);
        exit;
        }
    }

    public function shortcode_login($atts)
    {
        if (is_user_logged_in()) {
            return $this->logged_in_notice();
        }
        $view = isset($_GET['mluc_view']) ? sanitize_key($_GET['mluc_view']) : 'login';
        if ($view === 'register') {
            return $this->shortcode_register($atts);
        }
        if ($view === 'lostpassword') {
            return $this->shortcode_lostpassword($atts);
        }
        ob_start();
        mluc_get_template('login');
        return ob_get_clean();
    }

    public function shortcode_register($atts)
    {
        if (is_user_logged_in()) {
            return $this->logged_in_notice();
        }
        if (!get_option('users_can_register')) {
            return '<p class="mluc-message">' . esc_html__('当前站点已关闭注册。', 'moonlight-user-center') . '</p>';
        }
        $view = isset($_GET['mluc_view']) ? sanitize_key($_GET['mluc_view']) : 'register';
        if ($view === 'login') {
            return $this->shortcode_login($atts);
        }
        ob_start();
        mluc_get_template('register');
        return ob_get_clean();
    }

    public function shortcode_lostpassword($atts)
    {
        if (is_user_logged_in()) {
            return $this->logged_in_notice();
        }
        $view = isset($_GET['mluc_view']) ? sanitize_key($_GET['mluc_view']) : 'lostpassword';
        if ($view === 'login') {
            return $this->shortcode_login($atts);
        }
        ob_start();
        mluc_get_template('lost-password');
        return ob_get_clean();
    }

    private function logged_in_notice()
    {
        return '<p class="mluc-message">' .
            sprintf(
                esc_html__('您已登录，前往 %s 或 %s。', 'moonlight-user-center'),
                '<a href="' . esc_url(mluc_get_account_url()) . '">' . esc_html__('账户中心', 'moonlight-user-center') . '</a>',
                '<a href="' . esc_url(wp_logout_url(mluc_get_account_url())) . '">' . esc_html__('退出登录', 'moonlight-user-center') . '</a>'
            ) . '</p>';
    }

    /**
     * 基于 IP 的简单节流：超限直接拒绝。防撞库爆破 / 垃圾注册 / 重置邮件轰炸。
     *
     * @param string $bucket 场景标识（login/register/lostpw）
     * @param int    $limit  窗口内允许次数
     * @param int    $window 窗口秒数
     */
    private function throttle($bucket, $limit, $window)
    {
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
        $key = 'mluc_rl_' . md5($bucket . '|' . $ip);
        $hits = (int) get_transient($key);
        if ($hits >= $limit) {
            mluc_send_json(false, __('尝试过于频繁，请稍后再试。', 'moonlight-user-center'));
        }
        set_transient($key, $hits + 1, $window);
    }

    /**
     * AJAX 登录。
     */
    public function ajax_login()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        $this->throttle('login', 10, 10 * MINUTE_IN_SECONDS);

        $user_login = sanitize_user(isset($_POST['user_login']) ? $_POST['user_login'] : '');
        $password   = isset($_POST['password']) ? $_POST['password'] : '';
        $remember   = !empty($_POST['remember']);

        if (empty($user_login) || empty($password)) {
            mluc_send_json(false, __('请输入账号和密码。', 'moonlight-user-center'));
        }

        $creds = array(
            'user_login'    => $user_login,
            'user_password' => $password,
            'remember'      => $remember,
        );

        $user = wp_signon($creds, is_ssl());

        if (is_wp_error($user)) {
            // 防用户名枚举：账号不存在与密码错误返回统一提示。
            $code = $user->get_error_code();
            if ('invalid_username' === $code || 'incorrect_password' === $code) {
                mluc_send_json(false, __('Incorrect username or password.', 'moonlight-user-center'));
            }
            mluc_send_json(false, mluc_translate_wp_error($user));
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);

        $redirect = mluc_get_account_url();
        $cfg      = mluc_get_option('redirect_after_login', '');
        if ($cfg && wp_http_validate_url($cfg)) {
            $redirect = $cfg;
        }
        if (!empty($_POST['redirect_to'])) {
            // 仅允许同站跳转，防止开放重定向钓鱼
            $redirect = wp_validate_redirect($_POST['redirect_to'], $redirect);
        }

        mluc_send_json(true, __('登录成功，正在跳转…', 'moonlight-user-center'), array('redirect' => $redirect));
    }

    /**
     * AJAX 注册。
     */
    public function ajax_register()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        $this->throttle('register', 5, HOUR_IN_SECONDS);

        if (!get_option('users_can_register')) {
            mluc_send_json(false, __('当前站点已关闭注册。', 'moonlight-user-center'));
        }

        // 蜜罐字段：正常用户不可见不填写，机器人常全量填写。
        $hp = isset($_POST['mluc_hp']) ? trim((string) $_POST['mluc_hp']) : '';
        if ('' !== $hp) {
            mluc_send_json(false, __('Registration failed. Please try again.', 'moonlight-user-center'));
        }
        // 一次性渲染令牌：注册页渲染时由服务端签发 transient，提交时校验即焚。
        // 既保证最短填写时间（≥3 秒），又防重放，且不依赖客户端时钟。
        $tk = isset($_POST['mluc_tk']) ? sanitize_text_field(wp_unslash($_POST['mluc_tk'])) : '';
        $tk_key = 'mluc_reg_tk_' . md5($tk);
        $tk_time = $tk ? get_transient($tk_key) : false;
        if ($tk) {
            delete_transient($tk_key);
        }
        if (false === $tk_time || (time() - (int) $tk_time) < 3) {
            mluc_send_json(false, __('Registration failed. Please try again.', 'moonlight-user-center'));
        }

        $user_login = sanitize_user(isset($_POST['user_login']) ? $_POST['user_login'] : '');
        $email      = sanitize_email(isset($_POST['email']) ? $_POST['email'] : '');
        $password   = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($user_login) || empty($email) || empty($password)) {
            mluc_send_json(false, __('请填写用户名、邮箱和密码。', 'moonlight-user-center'));
        }
        if (!is_email($email)) {
            mluc_send_json(false, __('邮箱格式不正确。', 'moonlight-user-center'));
        }
        if (mb_strlen($password) < 6) {
            mluc_send_json(false, __('密码至少 6 位。', 'moonlight-user-center'));
        }
        if (username_exists($user_login)) {
            mluc_send_json(false, __('该用户名已被使用。', 'moonlight-user-center'));
        }
        if (email_exists($email)) {
            mluc_send_json(false, __('该邮箱已被注册。', 'moonlight-user-center'));
        }

        $user_id = wp_create_user($user_login, $password, $email);
        if (is_wp_error($user_id)) {
            mluc_send_json(false, mluc_translate_wp_error($user_id));
        }

        // 自动登录
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        do_action('mluc_after_register', $user_id);

        $redirect = mluc_get_account_url();
        mluc_send_json(true, __('注册成功，正在跳转…', 'moonlight-user-center'), array('redirect' => $redirect));
    }

    /**
     * AJAX 找回密码。
     */
    public function ajax_lost_password()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        $this->throttle('lostpw', 5, HOUR_IN_SECONDS);

        $user_login = isset($_POST['user_login']) ? trim($_POST['user_login']) : '';
        if (empty($user_login)) {
            mluc_send_json(false, __('请输入用户名或邮箱。', 'moonlight-user-center'));
        }

        $user = is_email($user_login)
            ? get_user_by('email', $user_login)
            : get_user_by('login', $user_login);

        if (!$user) {
            // 出于安全不透露用户是否存在
            mluc_send_json(true, __('如果该账户存在，重置链接已发送至邮箱。', 'moonlight-user-center'));
        }

        $reset_key = get_password_reset_key($user);
        if (is_wp_error($reset_key)) {
            mluc_send_json(false, __('生成重置密钥失败，请稍后再试。', 'moonlight-user-center'));
        }

        $reset_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($user->user_login), 'login');
        $message   = sprintf(__('亲爱的 %s：', 'moonlight-user-center'), $user->display_name) . "\r\n";
        $message  .= __('我们收到您的找回密码请求，请点击以下链接重置密码：', 'moonlight-user-center') . "\r\n";
        $message  .= $reset_url . "\r\n\r\n";
        $message  .= __('若非本人操作，请忽略本邮件。', 'moonlight-user-center');

        wp_mail($user->user_email, __('【重置密码】', 'moonlight-user-center') . get_bloginfo('name'), $message);

        mluc_send_json(true, __('如果该账户存在，重置链接已发送至邮箱。', 'moonlight-user-center'));
    }

    /**
     * AJAX 退出。
     */
    public function ajax_logout()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        wp_logout();
        mluc_send_json(true, __('已退出登录。', 'moonlight-user-center'), array('redirect' => home_url()));
    }

    /**
     * 处理 GET 方式退出（?mluc_logout=1&nonce=xxx）。
     */
    public function handle_get_logout()
    {
        if (empty($_GET['mluc_logout'])) {
            return;
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mluc_logout')) {
            wp_die(__('安全校验失败。', 'moonlight-user-center'));
        }
        wp_logout();
        wp_safe_redirect(home_url());
        exit;
    }
}

/**
 * 将 WP_Error 转为可读中文（简单映射常见错误）。
 */
function mluc_translate_wp_error($wp_error)
{
    if (!is_wp_error($wp_error)) {
        return '';
    }
    $code = $wp_error->get_error_code();
    $map  = array(
        'invalid_username' => __('用户名不存在。', 'moonlight-user-center'),
        'incorrect_password' => __('密码错误。', 'moonlight-user-center'),
        'empty_username'   => __('请输入用户名。', 'moonlight-user-center'),
        'empty_password'   => __('请输入密码。', 'moonlight-user-center'),
    );
    return isset($map[$code]) ? $map[$code] : $wp_error->get_error_message();
}
