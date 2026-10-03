<?php
// Standalone contract tests; no database or real payment is involved.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
$options = ['hf_usdt_enabled'=>true,'hf_usdt_provider'=>'epay','hf_usdt_epay'=>['apiurl'=>'https://gateway.example/','partner'=>'12','key'=>'test-secret']];
$transients = [];
function add_filter(...$args) {}
function add_action(...$args) {}
function _pz($key, $default = null) { global $options; return $options[$key] ?? $default; }
function set_transient($key,$value,$ttl) { global $transients; $transients[$key]=$value; return true; }
function get_transient($key) { global $transients; return $transients[$key] ?? false; }
function home_url($path='') { return 'https://shop.example'.$path; }
function admin_url($path) { return home_url('/wp-admin/'.$path); }
function wp_validate_redirect($url,$fallback) { return strpos($url,home_url())===0 ? $url : $fallback; }
function get_bloginfo($key) { return 'Test shop'; }
function esc_attr($s) { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function esc_url($s) { return esc_attr($s); }
class ZibPay {
    public static $calls=0;
    public static $order=['status'=>0];
    public static function is_payment_order($number) { return strpos($number,'520')===0; }
    public static function get_order($number) { return self::$order; }
    public static function get_payment($number) { return self::$order; }
    public static function payment_order($data) { self::$calls++; self::$order=['status'=>1,'pay_num'=>$data['pay_num']]; return self::$order; }
}
require dirname(__DIR__).'/hf-zibll-usdt.php';
$checks=0;
function check($ok,$message) { global $checks; $checks++; if (!$ok) { throw new RuntimeException($message); } }
$sections=[['fields'=>[['id'=>'pay_wechat_sdk_options'],['id'=>'pay_stripe_sdk_s']]]];
$injected=HF_Zibll_USDT::settings($sections);
check(count($injected[0]['fields'])===5,'settings injected');
check(HF_Zibll_USDT::settings($injected)===$injected,'injection idempotent');
check(isset(HF_Zibll_USDT::methods([])['usdt']),'ready method available');
foreach (['epusdt','tokenpay'] as $provider) { $options['hf_usdt_provider']=$provider; check(!HF_Zibll_USDT::methods([]),'future adapter hidden'); }
$options['hf_usdt_provider']='epay';
$options['hf_usdt_enabled']=false;
check(!HF_Zibll_USDT::methods([]),'disabled method hidden');
check(isset(HF_Zibll_USDT::initiate([])['error']),'disabled initiation rejected');
$options['hf_usdt_enabled']=true;
check(HF_Zibll_USDT::sdk('old',['payment_method'=>'wechat'])==='old','existing sdk preserved');
$result=HF_Zibll_USDT::initiate(['order_num'=>'123','order_price'=>'25.50','order_name'=>'"<script>bad</script>','return_url'=>'https://evil.example']);
check(strpos($result['form_html'],'&lt;script&gt;bad')!==false,'HTML escaped');
check(strpos($result['form_html'],'name="type" value="usdt"')!==false,'usdt requested');
$p=['pid'=>'12','type'=>'usdt','out_trade_no'=>'123','trade_no'=>'gateway-1','money'=>'25.50','trade_status'=>'TRADE_SUCCESS','sign_type'=>'MD5'];
$p['sign']=HF_Zibll_USDT::sign($p,'test-secret');
check(HF_Zibll_USDT::sign(['b'=>'2','a'=>'1','empty'=>'','sign'=>'ignored','sign_type'=>'MD5'],'key')===md5('a=1&b=2key'),'epay signing vector');
foreach (['money'=>'26.00','pid'=>'13','type'=>'alipay','trade_status'=>'WAIT_BUYER_PAY'] as $field=>$value) {
    $bad=$p; $bad[$field]=$value; $bad['sign']=HF_Zibll_USDT::sign($bad,'test-secret');
    check(!HF_Zibll_USDT::settle($bad),'signed mismatch '.$field);
}
$bad=$p; $bad['money']='1.00'; check(!HF_Zibll_USDT::settle($bad),'unsigned tamper rejected');
$bad=$p; $bad['extra']=[]; check(!HF_Zibll_USDT::settle($bad),'array rejected');
$options['hf_usdt_enabled']=false;
check((bool)HF_Zibll_USDT::settle($p),'pending notify after disable');
check((bool)HF_Zibll_USDT::settle($p) && ZibPay::$calls===1,'duplicate notify idempotent');
$bad=$p; $bad['trade_no']='gateway-2'; $bad['sign']=HF_Zibll_USDT::sign($bad,'test-secret'); check(!HF_Zibll_USDT::settle($bad),'different trade rejected');
check(HF_Zibll_USDT::settle($p)['return_url']===home_url('/'),'external return replaced');
echo "$checks checks passed\n";
