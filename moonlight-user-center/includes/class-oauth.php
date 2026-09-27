<?php
/**
 * 第三方登录（OAuth2）：微信 / QQ / GitHub / Google / Apple。
 *
 * 各提供商的 client_id / client_secret 在后台「第三方登录」区块配置；
 * 未配置则对应按钮不显示。回调地址固定为 home_url('/?mluc_oauth=提供商')。
 * 真实密钥由站点管理员填写，本类只负责标准 OAuth2 流程与账号绑定。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_OAuth
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
        add_action('init', array($this, 'maybe_handle_callback'), 5);
        add_shortcode('mluc_oauth_buttons', array($this, 'shortcode_buttons'));
        add_action('mluc_oauth_buttons', array($this, 'render_buttons'));
    }

    /**
     * 提供商配置表（端点与展示信息）。
     */
    public static function providers()
    {
        return apply_filters('mluc_oauth_providers', array(
            'wechat' => array(
                'label' => __('微信', 'moonlight-user-center'),
                'color' => '#07c160',
                'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M8.69 4C4.54 4 1.2 6.94 1.2 10.5c0 2.04 1.08 3.86 2.76 5.08L3.04 18l2.62-1.36c.9.25 1.86.39 2.86.39.26 0 .51-.01.76-.03a5.7 5.7 0 0 1-.34-1.96c0-3.39 3.22-6.09 7.2-6.09.26 0 .51.01.76.04C16.62 6.27 13.1 4 8.69 4zm-2.29 4.6a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm4.6 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/><path fill="#fff" d="M22.8 14.5c0-2.9-2.82-5.25-6.3-5.25s-6.3 2.35-6.3 5.25 2.82 5.25 6.3 5.25c.74 0 1.46-.1 2.12-.3l2.02 1.05-.55-1.86c1.44-1.02 2.41-2.5 2.41-4.14zm-8.3-.9a.8.8 0 1 1 0-1.6.8.8 0 0 1 0 1.6zm3.9 0a.8.8 0 1 1 0-1.6.8.8 0 0 1 0 1.6z"/></svg>',
                'auth_url'   => 'https://open.weixin.qq.com/connect/qrconnect',
                'token_url'  => 'https://api.weixin.qq.com/sns/oauth2/access_token',
                'scope'      => 'snsapi_login',
                'response_type' => 'code',
                'format'     => 'json',
                'type'       => 'wechat',
            ),
            'qq' => array(
                'label' => __('QQ', 'moonlight-user-center'),
                'color' => '#12b7f5',
                'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M12 2.2c-3.6 0-6.4 2.9-6.4 6.6 0 1.4.4 2.7 1.1 3.8-.9 1.2-1.5 2.7-1.5 3.9 0 .7.2 1.3.6 1.8-.2.5-.2 1.1 0 1.7.3.9 1.2 1.6 2.3 1.6.6 1.1 1.9 1.9 3.5 1.9.9 0 1.7-.2 2.4-.5.5.1 1 .3 1.6.3.7 0 1.4-.2 2-.5.7.3 1.6.5 2.4.5 1.6 0 2.9-.8 3.5-1.9 1.1 0 2-.7 2.3-1.6.2-.6.2-1.2 0-1.7.4-.5.6-1.1.6-1.8 0-1.2-.6-2.7-1.5-3.9.7-1.1 1.1-2.4 1.1-3.8 0-3.7-2.8-6.6-6.4-6.6zm-2.6 9.2c.6 0 1 .5 1 1.1s-.4 1.1-1 1.1-1-.5-1-1.1.4-1.1 1-1.1zm5.2 0c.6 0 1 .5 1 1.1s-.4 1.1-1 1.1-1-.5-1-1.1.4-1.1 1-1.1z"/></svg>',
                'auth_url'   => 'https://graph.qq.com/oauth2.0/authorize',
                'token_url'  => 'https://graph.qq.com/oauth2.0/token',
                'scope'      => 'get_user_info',
                'response_type' => 'code',
                'format'     => 'query',
                'type'       => 'qq',
            ),
            'github' => array(
                'label' => __('GitHub', 'moonlight-user-center'),
                'color' => '#24292e',
                'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>',
                'auth_url'   => 'https://github.com/login/oauth/authorize',
                'token_url'  => 'https://github.com/login/oauth/access_token',
                'scope'      => 'read:user user:email',
                'response_type' => 'code',
                'format'     => 'json',
                'type'       => 'github',
            ),
            'google' => array(
                'label' => __('Google', 'moonlight-user-center'),
                'color' => '#ea4335',
                'icon_svg' => '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
                'auth_url'   => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url'  => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
                'scope'      => 'openid email profile',
                'response_type' => 'code',
                'format'     => 'json',
                'type'       => 'google',
            ),
            'apple' => array(
                'label' => __('Apple', 'moonlight-user-center'),
                'color' => '#000000',
                'icon_svg' => '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="#fff" d="M17.05 12.04c-.03-2.65 2.16-3.92 2.26-3.98-1.23-1.8-3.15-2.05-3.83-2.08-1.63-.16-3.18.96-4.01.96-.83 0-2.11-.94-3.47-.91-1.78.03-3.43 1.04-4.35 2.64-1.86 3.23-.48 8.01 1.34 10.63.89 1.28 1.95 2.72 3.34 2.67 1.34-.05 1.85-.87 3.47-.87 1.62 0 2.08.87 3.49.84 1.44-.03 2.35-1.31 3.23-2.6 1.02-1.49 1.44-2.93 1.46-3.01-.03-.01-2.8-1.08-2.83-4.27zM14.6 4.59c.73-.89 1.22-2.12 1.09-3.35-1.05.04-2.32.7-3.08 1.58-.68.78-1.27 2.04-1.11 3.24 1.17.09 2.37-.6 3.1-1.47z"/></svg>',
                'auth_url'   => 'https://appleid.apple.com/auth/authorize',
                'token_url'  => 'https://appleid.apple.com/auth/token',
                'scope'      => 'name email',
                'response_type' => 'code',
                // Apple 默认 response_mode=form_post 会把 code 放到 POST 体，WP 的 $_GET 读不到 → 登录必失败。
                // 显式 query 让 code/state 走 GET，便于本站标准回调处理。
                'response_mode' => 'query',
                'format'     => 'json',
                'type'       => 'apple',
            ),
        ));
    }

    /**
     * 读取某提供商的 client_id / client_secret（存于 mluc_options['oauth']）。
     */
    private function get_creds($id)
    {
        $all = mluc_get_option('oauth', array());
        $c   = isset($all[$id]) ? $all[$id] : array();
        return array(
            'client_id'     => isset($c['client_id']) ? trim($c['client_id']) : '',
            'client_secret' => isset($c['client_secret']) ? trim($c['client_secret']) : '',
        );
    }

    public function is_enabled($id)
    {
        $creds = $this->get_creds($id);
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    public function get_redirect_uri($id)
    {
        return home_url('/?mluc_oauth=' . rawurlencode($id));
    }

    /**
     * 生成授权 URL（带 state 防 CSRF，并把回跳地址存入 state）。
     *
     * @param string $id         提供商
     * @param string $redirect_to 登录后期望回跳的同站地址（内部白名单校验后才用）
     */
    public function get_auth_url($id, $redirect_to = '')
    {
        $providers = self::providers();
        if (!isset($providers[$id])) {
            return '';
        }
        $p = $providers[$id];
        $creds = $this->get_creds($id);
        $state = wp_generate_password(12, false);
        // state 同时记录 provider、回跳意图与发起时的登录身份，回调据此还原
        set_transient('mluc_oauth_state_' . $state, array(
            'provider'  => $id,
            'redirect'  => $redirect_to ? esc_url_raw($redirect_to) : '',
            // 安全：记录发起授权时的用户。回调时若当前登录用户与之不同，
            // 说明是别人发起的流程被用到本浏览器上（账户绑定劫持），一律拒绝。
            'init_user' => get_current_user_id(),
        ), 600);
        // 安全：state 需与浏览器会话绑定。仅靠 transient 校验时，攻击者可拿自己
        // 生成的 state + 自己的授权 code 拼出回调链接，诱导已登录的受害者点击，
        // 从而把攻击者的第三方身份绑到受害者账户（账户接管）。写入 cookie 后，
        // 回调必须出示同一浏览器持有的 state 才被接受。
        $this->set_state_cookie($state);

        $params = array(
            'response_type' => $p['response_type'],
            'client_id'     => $creds['client_id'],
            'redirect_uri'  => $this->get_redirect_uri($id),
            'scope'         => $p['scope'],
            'state'         => $state,
        );
        if (!empty($p['response_mode'])) {
            $params['response_mode'] = $p['response_mode'];
        }
        return add_query_arg($params, $p['auth_url']);
    }

    /**
     * 把 state 写入会话 cookie（HttpOnly + SameSite=Lax，10 分钟有效）。
     * SameSite=Lax 保证第三方完成授权后的顶层 GET 回跳仍会携带本 cookie。
     */
    private function set_state_cookie($state)
    {
        if (headers_sent()) {
            return;
        }
        $opts = array(
            'expires'  => time() + 600,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (PHP_VERSION_ID >= 70300) {
            setcookie('mluc_oauth_state', $state, $opts);
        } else {
            setcookie(
                'mluc_oauth_state',
                $state,
                $opts['expires'],
                $opts['path'] . '; SameSite=Lax',
                $opts['domain'],
                $opts['secure'],
                $opts['httponly']
            );
        }
    }

    private function clear_state_cookie()
    {
        if (headers_sent()) {
            return;
        }
        $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie('mluc_oauth_state', '', time() - 3600, $path, $domain, is_ssl(), true);
    }

    /**
     * 渲染登录按钮（仅显示已配置的提供商），使用官方品牌图标。
     *
     * @param string $redirect_to 透传到授权 URL 的回跳地址（当前页面带的 redirect_to）
     */
    public function render_buttons($redirect_to = '')
    {
        $providers = self::providers();
        $enabled = array();
        foreach ($providers as $id => $p) {
            if ($this->is_enabled($id)) {
                $enabled[$id] = $p;
            }
        }
        if (empty($enabled)) {
            return;
        }
        // 优先采用当前页面传入的 redirect_to（如登录页 ?redirect_to=...），保证登录后回原处
        if (!$redirect_to && !empty($_GET['redirect_to'])) {
            $redirect_to = esc_url_raw($_GET['redirect_to']);
        }
        echo '<div class="mluc-oauth">';
        echo '<div class="mluc-oauth-divider"><span>' . esc_html__('或使用第三方账号登录', 'moonlight-user-center') . '</span></div>';
        echo '<div class="mluc-oauth-buttons">';
        foreach ($enabled as $id => $p) {
            $url  = esc_url($this->get_auth_url($id, $redirect_to));
            $svg  = isset($p['icon_svg']) ? $p['icon_svg'] : '';
            $aria = sprintf(esc_attr__('使用 %s 登录', 'moonlight-user-center'), $p['label']);
            printf(
                '<a class="mluc-oauth-btn mluc-oauth-%s" href="%s" style="background:%s" aria-label="%s" rel="noopener">%s<span>%s</span></a>',
                esc_attr($id),
                $url,
                esc_attr($p['color']),
                $aria,
                $svg,
                esc_html($p['label'])
            );
        }
        echo '</div></div>';
    }

    public function shortcode_buttons()
    {
        ob_start();
        $this->render_buttons();
        return ob_get_clean();
    }

    /**
     * 回调处理：?mluc_oauth=provider&code=...&state=...
     * Apple 在某些配置下以 form_post 回传，故 code/state 同时兼容 GET 与 POST。
     */
    public function maybe_handle_callback()
    {
        $id = isset($_GET['mluc_oauth']) ? sanitize_key($_GET['mluc_oauth']) : '';
        if (!$id) {
            return;
        }
        $providers = self::providers();
        if (!isset($providers[$id])) {
            $this->oauth_fail('unsupported', __('不支持的登录方式。', 'moonlight-user-center'));
        }

        // 用户取消授权（各平台回传 error 参数）
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : (isset($_POST['error']) ? sanitize_text_field($_POST['error']) : '');
        if ($error) {
            $this->oauth_fail('cancelled', __('已取消授权，请重试或使用其他方式登录。', 'moonlight-user-center'));
        }

        // 兼容 GET（多数平台）与 POST（Apple form_post）两种回传方式
        $code  = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : (isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '');
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : (isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '');

        $st = $state ? get_transient('mluc_oauth_state_' . $state) : null;
        if (!$code || !$state || !is_array($st) || empty($st['provider']) || $st['provider'] !== $id) {
            $this->oauth_fail('state', __('安全校验失败（state 无效），请重试。', 'moonlight-user-center'));
        }
        // 安全校验一：state 必须来自本浏览器会话（本浏览器确实发起过这次授权），
        // 防止攻击者用自己的 state+code 构造回调链接让他人点击（登录 CSRF）。
        $cookie_state = isset($_COOKIE['mluc_oauth_state']) ? sanitize_text_field(wp_unslash($_COOKIE['mluc_oauth_state'])) : '';
        if ('' === $cookie_state || !hash_equals((string) $cookie_state, (string) $state)) {
            $this->clear_state_cookie();
            $this->oauth_fail('state', __('安全校验失败（state 不匹配），请重试。', 'moonlight-user-center'));
        }
        // 安全校验二：已登录时，发起授权的身份必须与当前身份一致，
        // 否则就是别人的授权流程被套用到当前账户上（账户绑定劫持）。
        if (is_user_logged_in() && isset($st['init_user']) && (int) $st['init_user'] !== get_current_user_id()) {
            $this->clear_state_cookie();
            $this->oauth_fail('state', __('安全校验失败（登录身份不一致），请重试。', 'moonlight-user-center'));
        }
        delete_transient('mluc_oauth_state_' . $state);
        $this->clear_state_cookie();
        $redirect_to = !empty($st['redirect']) ? $st['redirect'] : '';

        $token = $this->fetch_token($id, $code);
        if (is_wp_error($token)) {
            $this->oauth_fail('token', $token->get_error_message());
        }

        $profile = $this->fetch_profile($id, $token);
        if (is_wp_error($profile)) {
            $this->oauth_fail('profile', $profile->get_error_message());
        }

        $this->login_or_register($id, $profile, $redirect_to);
    }

    /**
     * OAuth 失败统一跳回登录页并带错误码（不再白屏 wp_die）。
     * 模板按错误码展示对应中文提示（见 templates/login.php）。
     */
    private function oauth_fail($code, $message)
    {
        $url = add_query_arg('oauth_error', $code, mluc_get_login_url());
        wp_safe_redirect($url);
        exit;
    }

    /**
     * 用授权码换取 access_token（按提供商格式差异处理）。
     */
    private function fetch_token($id, $code)
    {
        $providers = self::providers();
        $p = $providers[$id];
        $creds = $this->get_creds($id);

        $body = array(
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'client_id'    => $creds['client_id'],
            'client_secret'=> $creds['client_secret'],
            'redirect_uri' => $this->get_redirect_uri($id),
        );

        $headers = array();
        if ('github' === $p['type']) {
            $headers['Accept'] = 'application/json';
        }

        $response = wp_remote_post($p['token_url'], array(
            'body'    => $body,
            'headers' => $headers,
            'timeout' => 20,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $raw  = wp_remote_retrieve_body($response);
        $data = ('query' === $p['format']) ? wp_parse_args($raw) : json_decode($raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return new WP_Error('oauth_token', __('换取令牌失败。', 'moonlight-user-center'));
        }
        return $data;
    }

    /**
     * 取用户信息（按提供商差异处理），返回统一 profile 数组。
     */
    private function fetch_profile($id, $token)
    {
        $providers = self::providers();
        $p = $providers[$id];
        $access_token = $token['access_token'];
        $profile = array('provider' => $id, 'provider_id' => '', 'email' => '', 'name' => '', 'avatar' => '', 'email_verified' => false);

        switch ($p['type']) {
            case 'wechat':
                if (empty($token['openid'])) {
                    return new WP_Error('oauth_openid', __('获取微信 openid 失败。', 'moonlight-user-center'));
                }
                $profile['provider_id'] = $token['openid'];
                $info = $this->get_json('https://api.weixin.qq.com/sns/userinfo', array(
                    'access_token' => $access_token,
                    'openid'       => $token['openid'],
                ));
                if (!is_wp_error($info)) {
                    $profile['name']   = isset($info['nickname']) ? $info['nickname'] : '';
                    $profile['avatar'] = isset($info['headimgurl']) ? $info['headimgurl'] : '';
                }
                break;

            case 'qq':
                $me = wp_remote_get('https://graph.qq.com/oauth2.0/me?access_token=' . rawurlencode($access_token), array('timeout' => 20));
                if (is_wp_error($me)) {
                    return $me;
                }
                if (!preg_match('/"openid"\s*:\s*"([^"]+)"/', wp_remote_retrieve_body($me), $m)) {
                    return new WP_Error('oauth_openid', __('获取 QQ openid 失败。', 'moonlight-user-center'));
                }
                $openid = $m[1];
                $profile['provider_id'] = $openid;
                $info = $this->get_json('https://graph.qq.com/user/get_user_info', array(
                    'access_token'       => $access_token,
                    'oauth_consumer_key' => $this->get_creds($id)['client_id'],
                    'openid'             => $openid,
                ));
                if (!is_wp_error($info)) {
                    $profile['name']   = isset($info['nickname']) ? $info['nickname'] : '';
                    $profile['avatar'] = isset($info['figureurl_qq_2']) ? $info['figureurl_qq_2'] : (isset($info['figureurl_qq_1']) ? $info['figureurl_qq_1'] : '');
                }
                break;

            case 'github':
                $user = $this->get_json('https://api.github.com/user', array(), array('Authorization' => 'Bearer ' . $access_token, 'User-Agent' => 'moonlight-user-center', 'Accept' => 'application/vnd.github+json'));
                if (is_wp_error($user)) {
                    return $user;
                }
                $profile['provider_id'] = isset($user['id']) ? (string) $user['id'] : '';
                $profile['name']   = isset($user['name']) ? $user['name'] : (isset($user['login']) ? $user['login'] : '');
                $profile['avatar'] = isset($user['avatar_url']) ? $user['avatar_url'] : '';
                $profile['email']  = isset($user['email']) ? $user['email'] : '';
                // 仅采用已验证邮箱，避免用他人未验证邮箱接管账户
                $profile['email_verified'] = !empty($user['email']) && !empty($user['email_verified']);
                if (empty($profile['email'])) {
                    $emails = $this->get_json('https://api.github.com/user/emails', array(), array('Authorization' => 'Bearer ' . $access_token, 'User-Agent' => 'moonlight-user-center', 'Accept' => 'application/vnd.github+json'));
                    if (!is_wp_error($emails) && is_array($emails)) {
                        foreach ($emails as $e) {
                            if (!empty($e['email']) && !empty($e['verified']) && !empty($e['primary'])) {
                                $profile['email'] = $e['email'];
                                $profile['email_verified'] = true;
                                break;
                            }
                        }
                        // 无已验证主邮箱则不采纳任何邮箱
                        if (empty($profile['email_verified'])) {
                            $profile['email'] = '';
                        }
                    }
                }
                break;

            case 'google':
                $info = $this->get_json($p['userinfo_url'], array(), array('Authorization' => 'Bearer ' . $access_token));
                if (is_wp_error($info)) {
                    return $info;
                }
                $profile['provider_id'] = isset($info['sub']) ? $info['sub'] : '';
                $profile['email']  = isset($info['email']) ? $info['email'] : '';
                $profile['name']   = isset($info['name']) ? $info['name'] : '';
                $profile['avatar'] = isset($info['picture']) ? $info['picture'] : '';
                // Google 返回 email_verified 声明
                $profile['email_verified'] = !empty($info['email_verified']) && !empty($info['email']);
                break;

            case 'apple':
                // Apple 通过 id_token(JWT) 返回 sub 与 email；必须校验签名，否则可伪造身份接管账户。
                if (empty($token['id_token'])) {
                    return new WP_Error('oauth_apple', __('获取 Apple 用户信息失败。', 'moonlight-user-center'));
                }
                $payload = $this->verify_apple_id_token($token['id_token']);
                if (is_wp_error($payload)) {
                    return $payload;
                }
                if (empty($payload['sub'])) {
                    return new WP_Error('oauth_apple', __('解析 Apple 身份失败。', 'moonlight-user-center'));
                }
                $profile['provider_id'] = $payload['sub'];
                $profile['email'] = isset($payload['email']) ? $payload['email'] : '';
                // Apple 私邮中继域名不做邮箱匹配，避免与其它账户误绑
                $profile['email_verified'] = !empty($profile['email']) && false === strpos($profile['email'], '@privaterelay.appleid.com');
                $profile['name']  = isset($_POST['user']) && is_array($_POST['user']) ? trim($_POST['user']['firstName'] . ' ' . $_POST['user']['lastName']) : '';
                if (empty($profile['name'])) {
                    $profile['name'] = $profile['email'] ? strtok($profile['email'], '@') : __('Apple 用户', 'moonlight-user-center');
                }
                break;

            default:
                return new WP_Error('oauth_unsupported', __('暂不支持该登录方式。', 'moonlight-user-center'));
        }

        if (empty($profile['provider_id'])) {
            return new WP_Error('oauth_noid', __('未能获取第三方用户标识。', 'moonlight-user-center'));
        }
        return $profile;
    }

    /**
     * 登录或注册并绑定。
     *
     * @param string $redirect_to 登录后回跳地址（已通过 state 白名单校验）
     */
    private function login_or_register($id, $profile, $redirect_to = '')
    {
        $meta_key = 'mluc_oauth_' . $id . '_id';

        // 已登录：直接绑定到当前账户
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, $meta_key, $profile['provider_id']);
            $this->do_login($user_id, $profile, $redirect_to);
        }

        // 按 provider_id 查找已有绑定
        $users = get_users(array('meta_key' => $meta_key, 'meta_value' => $profile['provider_id'], 'number' => 1));
        if (!empty($users)) {
            $this->do_login($users[0]->ID, $profile, $redirect_to);
        }

        // 按邮箱查找：仅当第三方已验证该邮箱归属时才自动登录既有账户，
        // 否则任意未验证邮箱可冒用他人账户（账户接管）。
        if (!empty($profile['email']) && !empty($profile['email_verified'])) {
            $u = get_user_by('email', $profile['email']);
            if ($u) {
                update_user_meta($u->ID, $meta_key, $profile['provider_id']);
                $this->do_login($u->ID, $profile, $redirect_to);
            }
        }

        // 自动注册新用户
        if (!get_option('users_can_register')) {
            $this->oauth_fail('register_closed', __('站点已关闭注册，且未找到匹配的账号。', 'moonlight-user-center'));
        }
        $login = $this->generate_login($id, $profile);
        // 无邮箱的提供商（微信/QQ）必须保证邮箱唯一，否则第二个用户会撞 @oauth.local 导致注册失败。
        // 用 provider+provider_id 派生唯一本地邮箱，避免与既有用户冲突。
        $email = !empty($profile['email'])
            ? $profile['email']
            : ('ml' . $id . '_' . substr(md5($profile['provider_id']), 0, 8) . '@oauth.local');
        $user_id = wp_create_user($login, wp_generate_password(24, false), $email);
        if (is_wp_error($user_id)) {
            $this->oauth_fail('register_fail', $user_id->get_error_message());
        }
        update_user_meta($user_id, $meta_key, $profile['provider_id']);
        if (!empty($profile['name'])) {
            wp_update_user(array('ID' => $user_id, 'display_name' => $profile['name']));
        }
        $this->do_login($user_id, $profile, $redirect_to);
    }

    private function do_login($user_id, $profile, $redirect_to = '')
    {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('mluc_oauth_login', $user_id, $profile);
        // 优先回跳到 OAuth 发起时携带的同站地址；否则回账户中心
        if ($redirect_to && wp_validate_redirect($redirect_to, false)) {
            wp_safe_redirect($redirect_to);
        } else {
            wp_safe_redirect(mluc_get_account_url());
        }
        exit;
    }

    private function generate_login($id, $profile)
    {
        $base = 'ml' . $id . '_' . substr(md5($profile['provider_id']), 0, 8);
        $login = $base;
        $i = 1;
        while (username_exists($login)) {
            $login = $base . $i;
            $i++;
        }
        return $login;
    }

    /**
     * GET 请求并解析 JSON。
     */
    private function get_json($url, $query = array(), $headers = array())
    {
        $u = $query ? add_query_arg($query, $url) : $url;
        $response = wp_remote_get($u, array('headers' => $headers, 'timeout' => 20));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('oauth_json', __('第三方返回数据解析失败。', 'moonlight-user-center'));
        }
        return $data;
    }

    /**
     * 校验 Apple id_token（RS256 签名 + 受众 + 签发方 + 过期），返回 payload 数组或 WP_Error。
     * 不验签会被伪造身份接管账户，故校验失败一律拒绝。
     */
    private function verify_apple_id_token($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return new WP_Error('oauth_apple', __('Apple 令牌格式错误。', 'moonlight-user-center'));
        }
        list($head_b64, $payload_b64, $sig_b64) = $parts;
        $header = json_decode($this->b64url($head_b64), true);
        if (empty($header['kid']) || empty($header['alg']) || 'RS256' !== $header['alg']) {
            return new WP_Error('oauth_apple', __('Apple 令牌算法不支持。', 'moonlight-user-center'));
        }
        $keys = $this->get_json('https://appleid.apple.com/auth/keys');
        if (is_wp_error($keys) || empty($keys['keys'])) {
            return new WP_Error('oauth_apple', __('无法获取 Apple 公钥。', 'moonlight-user-center'));
        }
        $pub = null;
        foreach ($keys['keys'] as $k) {
            if (isset($k['kid']) && $k['kid'] === $header['kid']) {
                $pub = $k;
                break;
            }
        }
        if (empty($pub['n']) || empty($pub['e'])) {
            return new WP_Error('oauth_apple', __('Apple 公钥不匹配。', 'moonlight-user-center'));
        }
        $pem = $this->rsa_pem_from_mod_exp($pub['n'], $pub['e']);
        if (!$pem) {
            return new WP_Error('oauth_apple', __('构建 Apple 公钥失败。', 'moonlight-user-center'));
        }
        $key = openssl_pkey_get_public($pem);
        if (!$key) {
            return new WP_Error('oauth_apple', __('加载 Apple 公钥失败。', 'moonlight-user-center'));
        }
        $signing = $head_b64 . '.' . $payload_b64;
        $sig = $this->b64url_raw($sig_b64);
        $ok = openssl_verify($signing, $sig, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);
        if (1 !== $ok) {
            return new WP_Error('oauth_apple', __('Apple 令牌签名校验失败。', 'moonlight-user-center'));
        }
        $payload = json_decode($this->b64url($payload_b64), true);
        if (!is_array($payload)) {
            return new WP_Error('oauth_apple', __('Apple 令牌解析失败。', 'moonlight-user-center'));
        }
        $client_id = $this->get_creds('apple')['client_id'];
        if ($client_id && (!empty($payload['aud']) && $payload['aud'] !== $client_id)) {
            return new WP_Error('oauth_apple', __('Apple 令牌受众不符。', 'moonlight-user-center'));
        }
        if (empty($payload['iss']) || 'https://appleid.apple.com' !== $payload['iss']) {
            return new WP_Error('oauth_apple', __('Apple 令牌签发方不符。', 'moonlight-user-center'));
        }
        if (!empty($payload['exp']) && (int) $payload['exp'] < time()) {
            return new WP_Error('oauth_apple', __('Apple 令牌已过期。', 'moonlight-user-center'));
        }
        return $payload;
    }

    private function b64url($b64)
    {
        return base64_decode(strtr($b64, '-_', '+/'), true);
    }

    private function b64url_raw($b64)
    {
        $s = strtr($b64, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($s, true);
    }

    private function rsa_pem_from_mod_exp($n, $e)
    {
        $mod = $this->b64url_raw($n);
        $exp = $this->b64url_raw($e);
        if (!$mod || !$exp) {
            return false;
        }
        $rsa_pub = $this->der_sequence(array(
            $this->der_integer($mod),
            $this->der_integer($exp),
        ));
        $bitstr = "\x03" . $this->der_len(strlen($rsa_pub) + 1) . "\x00" . $rsa_pub;
        // AlgorithmIdentifier: rsaEncryption OID 1.2.840.113549.1.1.1 + NULL
        $oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algid = "\x30" . $this->der_len(strlen($oid) + 2) . $oid . "\x05\x00";
        $spki = "\x30" . $this->der_len(strlen($algid) + strlen($bitstr)) . $algid . $bitstr;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function der_integer($bytes)
    {
        $bytes = ltrim($bytes, "\x00");
        if ('' === $bytes) {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->der_len(strlen($bytes)) . $bytes;
    }

    private function der_sequence($items)
    {
        $data = implode('', $items);
        return "\x30" . $this->der_len(strlen($data)) . $data;
    }

    private function der_len($len)
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $b = '';
        while ($len > 0) {
            $b = chr($len & 0xff) . $b;
            $len >>= 8;
        }
        return chr(0x80 | strlen($b)) . $b;
    }
}
