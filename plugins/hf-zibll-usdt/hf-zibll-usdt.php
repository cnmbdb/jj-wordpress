<?php
/**
 * Plugin Name: HF 子比 USDT 收款
 * Description: 向子比主题收款接口和结账页面加入 USDT 支付，首期支持易支付。
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: HF
 */
defined('ABSPATH') || exit;

final class HF_Zibll_USDT {
    public static function boot() {
        add_filter('csf_zibll_options_sections', [__CLASS__, 'settings']);
        add_filter('zibpay_payment_methods', [__CLASS__, 'methods']);
        add_filter('zibpay_initiate_paysdk', [__CLASS__, 'sdk'], 10, 2);
        add_filter('zibpay_initiate_hf_usdt', [__CLASS__, 'initiate']);
        foreach (['notify', 'return'] as $action) {
            add_action('admin_post_hf_usdt_' . $action, [__CLASS__, $action . '_handler']);
            add_action('admin_post_nopriv_hf_usdt_' . $action, [__CLASS__, $action . '_handler']);
        }
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    public static function notice() {
        if (!function_exists('zibpay_get_payment_methods') && current_user_can('activate_plugins')) {
            echo '<div class="notice notice-warning"><p>HF USDT 收款需要启用子比主题或其子主题。</p></div>';
        }
    }

    public static function settings($sections) {
        foreach ($sections as &$section) {
            if (empty($section['fields'])) { continue; }
            $ids = array_column($section['fields'], 'id');
            if (!in_array('pay_wechat_sdk_options', $ids, true) || in_array('hf_usdt_enabled', $ids, true)) { continue; }
            $fields = [
                ['id'=>'hf_usdt_enabled', 'type'=>'switcher', 'title'=>'USDT 收款接口', 'default'=>false, 'label'=>'启用后在结账页面显示 USDT'],
                ['id'=>'hf_usdt_provider', 'type'=>'select', 'title'=>'USDT 接口类型', 'default'=>'epay', 'options'=>['epay'=>'易支付', 'epusdt'=>'epusdt（待接入）', 'tokenpay'=>'TokenPay（待接入）'], 'dependency'=>['hf_usdt_enabled','==','true'], 'desc'=>'当前仅易支付可收款；选择待接入接口时不显示 USDT 支付入口。'],
                ['id'=>'hf_usdt_epay', 'type'=>'fieldset', 'title'=>'USDT 易支付配置', 'dependency'=>['hf_usdt_enabled|hf_usdt_provider','==|==','true|epay'], 'fields'=>[
                    ['id'=>'apiurl','type'=>'text','title'=>'易支付地址','desc'=>'填写 HTTPS 站点地址，例如 https://epay.example.com/。'],
                    ['id'=>'partner','type'=>'text','title'=>'商户 ID（PID）'],
                    ['id'=>'key','type'=>'text','title'=>'商户密钥','attributes'=>['type'=>'password','autocomplete'=>'new-password']],
                    ['type'=>'content','content'=>'独立配置 USDT 商户参数，不影响微信和支付宝。易支付商户须已开通 usdt 通道。订单按人民币金额提交，USDT 汇率与网络由易支付处理。异步通知和返回地址由插件自动生成。'],
                ]],
            ];
            $index = array_search('pay_stripe_sdk_s', $ids, true);
            array_splice($section['fields'], $index === false ? 1 : $index + 1, 0, $fields);
        }
        unset($section);
        return $sections;
    }

    public static function config() {
        $value = function_exists('_pz') ? (array) _pz('hf_usdt_epay', []) : [];
        return array_merge(['apiurl'=>'', 'partner'=>'', 'key'=>''], $value);
    }

    public static function ready() {
        $c = self::config();
        return function_exists('_pz') && _pz('hf_usdt_enabled') && _pz('hf_usdt_provider', 'epay') === 'epay'
            && preg_match('~^https://[^\s?#]+/?$~i', $c['apiurl']) && ctype_digit((string) $c['partner']) && trim($c['key']) !== '';
    }

    public static function methods($methods) {
        if (self::ready()) {
            $methods['usdt'] = ['name'=>'USDT', 'img'=>'<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:1.4em;height:1.4em;border-radius:50%;background:#26a17b;color:white;font-weight:bold">₮</span>'];
        }
        return $methods;
    }

    public static function sdk($sdk, $order) {
        return ($order['payment_method'] ?? '') === 'usdt' ? 'hf_usdt' : $sdk;
    }

    public static function sign($params, $key) {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && (string) $v !== '') { $pairs[] = $k . '=' . $v; }
        }
        return md5(implode('&', $pairs) . $key);
    }

    public static function cents($value) {
        return is_scalar($value) && preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $value) ? (int) round((float) $value * 100) : null;
    }

    private static function intent_key($number) { return 'hf_usdt_' . hash('sha256', $number); }

    public static function initiate($order) {
        if (!self::ready()) { return ['error'=>1, 'msg'=>'USDT 收款接口尚未启用或配置未完成']; }
        $money = number_format((float) $order['order_price'], 2, '.', '');
        if (self::cents($money) <= 0 || empty($order['order_num'])) { return ['error'=>1, 'msg'=>'USDT 订单金额无效']; }
        $c = self::config();
        $intent = ['money'=>$money, 'pid'=>(string) $c['partner'], 'return_url'=>wp_validate_redirect($order['return_url'], home_url('/')), 'paid'=>false];
        $intent_key = self::intent_key($order['order_num']);
        if (!set_transient($intent_key, $intent, 30 * DAY_IN_SECONDS) && get_transient($intent_key) !== $intent) {
            return ['error'=>1, 'msg'=>'USDT 支付记录保存失败，请重试'];
        }
        $params = ['pid'=>$intent['pid'], 'type'=>'usdt', 'out_trade_no'=>$order['order_num'], 'name'=>$order['order_name'], 'money'=>$money,
            'notify_url'=>admin_url('admin-post.php?action=hf_usdt_notify'), 'return_url'=>admin_url('admin-post.php?action=hf_usdt_return'), 'sitename'=>get_bloginfo('name')];
        $params['sign'] = self::sign($params, $c['key']);
        $params['sign_type'] = 'MD5';
        $html = '<form id="hf-usdt-checkout" method="post" action="' . esc_url(rtrim($c['apiurl'], '/') . '/submit.php') . '">';
        foreach ($params as $k => $v) { $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">'; }
        $html .= '</form><script>document.getElementById("hf-usdt-checkout").submit();</script>';
        return ['form_html'=>'<div class="hide">' . $html . '</div>'];
    }

    public static function settle($params) {
        if (!class_exists('ZibPay')) { return false; }
        unset($params['action']);
        foreach ($params as $value) { if (!is_scalar($value)) { return false; } }
        $c = self::config();
        if (empty($c['key']) || empty($params['sign']) || !hash_equals(self::sign($params, $c['key']), (string) $params['sign'])
            || ($params['sign_type'] ?? 'MD5') !== 'MD5' || ($params['type'] ?? '') !== 'usdt'
            || ($params['trade_status'] ?? '') !== 'TRADE_SUCCESS' || empty($params['trade_no']) || empty($params['out_trade_no'])) { return false; }
        $intent = get_transient(self::intent_key($params['out_trade_no']));
        if (!$intent || ($params['pid'] ?? '') !== $intent['pid'] || $intent['pid'] !== (string) $c['partner']
            || self::cents($params['money'] ?? '') === null || self::cents($params['money']) !== self::cents($intent['money'])) { return false; }
        $order = ZibPay::is_payment_order($params['out_trade_no']) ? ZibPay::get_payment($params['out_trade_no']) : ZibPay::get_order($params['out_trade_no']);
        if (!$order) { return false; }
        if ((int) $order['status'] === 1) {
            return ($order['pay_num'] ?? '') === $params['trade_no'] ? $intent : false;
        }
        $result = ZibPay::payment_order(['order_num'=>$params['out_trade_no'], 'pay_type'=>'hf_usdt', 'pay_num'=>$params['trade_no'], 'pay_detail'=>['usdt'=>$intent['money']]]);
        if (!$result) { return false; }
        $intent['paid'] = true;
        $intent['trade_no'] = $params['trade_no'];
        set_transient(self::intent_key($params['out_trade_no']), $intent, 30 * DAY_IN_SECONDS);
        return $intent;
    }

    private static function callback_params() {
        return wp_unslash($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
    }

    public static function notify_handler() {
        $ok = self::settle(self::callback_params());
        status_header($ok ? 200 : 400);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo $ok ? 'success' : 'fail';
        exit;
    }

    public static function return_handler() {
        $intent = self::settle(self::callback_params());
        if (!$intent) { wp_die('支付结果尚未确认，请返回订单页面查看或等待异步通知。', 'USDT 支付', ['response'=>400]); }
        wp_safe_redirect($intent['return_url']);
        exit;
    }
}
HF_Zibll_USDT::boot();
