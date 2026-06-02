<?php
//echo 'ok';
header('Content-type:text/html; Charset=utf-8');

ob_start();
require dirname(__FILE__) . '/../../../../../../wp-load.php';
ob_end_clean();

$config = zibpay_get_payconfig('epusdt');
if (!$config['apiurl'] || !$config['key'] ) {
    //判断参数是否为空
    exit('fail');
}

if (_pz('pay_usdt_sdk_options') != 'epusdt') {
    //判断是否开启此支付接口
    //  exit('fail');
}
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);
ksort($data);
$sign = '';
foreach ($data as $k => $v) {
    if ($v == '') continue;
        if ($k != 'signature') {
            if ($sign != '') {
                $sign .= "&";
            }
            $sign .= "$k=$v";
        }
}
$signature = md5($sign.$config['key']);
if (!$data['order_id'] || $data['signature'] != $signature) { //不合法的数据
    exit('fail'); //返回失败 继续补单
} else {
    //成功
    $pay = array(
        'order_num' => $data['order_id'],
        'pay_type'  => 'epusdt',
        'pay_price' => $data['amount'],
        'pay_num'   => $data['trade_id'],
        'other'     => '',
    );
    // 更新订单状态
    $order = ZibPay::payment_order($pay);

    echo 'ok';
    exit();
}
exit();

