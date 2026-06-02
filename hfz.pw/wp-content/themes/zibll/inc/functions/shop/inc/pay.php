<?php
/*
* @Author : Qinver
* @Url : zibll.com
* @Date : 2025-03-04 11:54:28
 * @LastEditTime : 2025-08-15 14:28:35
* @Project : Zibll子比主题
* @Description : 更优雅的Wordpress主题
* Copyright (c) 2025 by Qinver, All Rights Reserved.
* @Email : 770349780@qq.com
* @Read me : 感谢您使用子比主题，主题源码有详细的注释，支持二次开发
* @Remind : 使用盗版主题会存在各种未知风险。支持正版，从我做起！
*/

function zib_shop_ajax_submit_order()
{
    $user_id = get_current_user_id();
    if (!$user_id) {
        zib_send_json_error(['code' => 'login_error', 'msg' => '请先登录']);
    }

    $products = $_POST['products'] ?? array();
    if (empty($products)) {
        zib_send_json_error(['code' => 'products_error', 'msg' => '未选择商品']);
    }

    $confirm_data = zib_shop_get_confirm_data($products);
    if (!$confirm_data) {
        zib_send_json_error(['code' => 'products_error', 'msg' => '未选择商品，或商品不存在']);
    }

    if ($confirm_data['is_mix']) {
        zib_send_json_error(['code' => 'mix_error', 'msg' => '积分商品和现金商品不能同时支付，请返回购物车重新选择']);
    }

    //判断用户地址
    if ($confirm_data['shipping_has_express']) {
        if (empty($_POST['address_data']['name']) || empty($_POST['address_data']['phone']) || empty($_POST['address_data']['address'])) {
            zib_send_json_error(['code' => 'address_error', 'msg' => '请先设置收货地址']);
        }
    }

    //判断邮箱
    if ($confirm_data['shipping_has_auto']) {
        if (empty($_POST['user_email'])) {
            zib_send_json_error(['code' => 'email_error', 'msg' => '请输入邮箱']);
        }

        //邮箱格式判断
        if (!filter_var($_POST['user_email'], FILTER_VALIDATE_EMAIL)) {
            zib_send_json_error(['code' => 'email_error', 'msg' => '邮箱格式错误']);
        }
    }

    //商品参数基本判断：库存，限购
    if (!empty($confirm_data['error_data'])) {
        foreach ($confirm_data['error_data'] as $error_data) {
            $error_data['msg']  = $error_data['error_msg'] ?? '商品参数错误';
            $error_data['code'] = $error_data['error_type'] ?? 'product_error';
            zib_send_json_error($error_data);
        }
    }

    //判断积分
    $is_points      = $confirm_data['pay_modo'] === 'points';
    $payment_method = $is_points ? 'points' : $_POST['payment_method'] ?? '';
    $payment_price  = $is_points ? (int) $confirm_data['total_data']['pay_points'] : zib_floatval_round($confirm_data['total_data']['pay_price']);
    $_post_price    = $is_points ? $_POST['points'] ?? 0 : $_POST['price'] ?? 0;
    $order_type     = zib_shop_get_order_type();

    //订单金额判断
    if (round((float) $_post_price, 2) !== round((float) $payment_price, 2)) {
        zib_send_json_error(['code' => 'price_error', 'msg' => '订单金额发生变化，请重新提交']);
    }

    if (!$is_points && (float) $payment_price > 0) {
        //支付方式选择
        if (!$payment_method) {
            zib_send_json_error(['code' => 'payment_method_error', 'msg' => '请选择支付方式']);
        }

        //支付方式合法判断
        $pay_methods = zib_shop_get_payment_methods();
        if (!isset($pay_methods[$payment_method])) {
            zib_send_json_error(['code' => 'payment_method_error', 'msg' => '支付方式错误']);
        }
    }

    //准备下单数据
    if ((float) $payment_price <= 0) {
        //金额为0，则使用余额或积分支付
        $payment_method = $is_points ? 'points' : 'balance';
        $payment_price  = 0;
    }

    //准备数据，并下单
    $order_data     = [];
    $zibpay_payment = zibpay::add_payment([
        'method' => $payment_method,
        'price'  => $payment_price,
    ]); //创建一个新的支付数据

    if (!$zibpay_payment) {
        zib_send_json_error(['code' => 'add_payment_error', 'msg' => '支付数据创建失败']);
    }

    $zibpay_payment_id = $zibpay_payment['id']; //支付数据ID
    foreach ($confirm_data['item_data'] as $author_id => $product_data_item) {
        foreach ($product_data_item as $product_id => $opt_items) {
            foreach ($opt_items as $opt_key => $item_data_item) {
                //判断必填项目
                if ($item_data_item['user_required']) {
                    $user_required_error = [];
                    foreach ($item_data_item['user_required'] as $key => $user_required_item) {
                        if (!$user_required_item['value']) {
                            $user_required_error[] = $user_required_item['name'];
                        }
                        unset($item_data_item['user_required'][$key]['key']);
                        unset($item_data_item['user_required'][$key]['desc']);
                    }

                    if ($user_required_error) {
                        zib_send_json_error(['code' => 'user_required_error', 'msg' => '请填写' . implode(',', $user_required_error)]);
                    }
                }
                //必填项目结束
                $__order_price = zib_shop_format_price($item_data_item['prices']['total_price'], $is_points);
                $__pay_price   = zib_shop_format_price($item_data_item['prices']['pay_price'], $is_points);

                $__pay_detail                   = [];
                $__pay_detail['payment_method'] = $payment_method;
                $__pay_detail[$payment_method]  = $__pay_price;

                if (!empty($item_data_item['prices']['total_discount'])) {
                    $__pay_detail['discount'] = (string) zib_shop_format_price($item_data_item['prices']['total_discount'], $is_points);
                }
                if ($is_points) {
                    $__pay_detail['points'] = $__pay_price;
                }
                $__mate_order_data = $item_data_item;
                //移出不需要的数据
                foreach (['stock_all', 'limit_buy'] as $key) {
                    if (isset($__mate_order_data[$key])) {
                        unset($__mate_order_data[$key]);
                    }
                }

                //发货信息
                if ($item_data_item['shipping_type'] === 'auto') {
                    $__mate_order_data['consignee'] = [ //收货人
                        'email' => $_POST['user_email'],
                    ];
                } elseif ($item_data_item['shipping_type'] === 'express') {
                    $__mate_order_data['consignee'] = [ //收货地址
                        'address_data' => $_POST['address_data'],
                    ];
                }

                $__meta = [
                    'order_data' => $__mate_order_data, //订单数据
                    'pay_modo'   => $is_points ? 'points' : 'price',
                ];

                $__order_data = [
                    'count'       => $__mate_order_data['count'] ?? 1,
                    'post_id'     => $item_data_item['product_id'],
                    'post_author' => $author_id,
                    'user_id'     => $user_id,
                    'product_id'  => $item_data_item['options_active_str'],
                    'order_type'  => $order_type,
                    'order_price' => $__order_price, //原价
                    'pay_price'   => $__pay_price, //支付金额，优惠价
                    'payment_id'  => $zibpay_payment_id, //支付ID
                    'pay_detail'  => $__pay_detail,
                    'meta'        => $__meta,
                ];

                //下单
                $add_order_data = zibpay::add_order($__order_data);
                if ($add_order_data) {
                    $order_data[] = [
                        'order_id'   => $add_order_data['id'],
                        'payment_id' => $zibpay_payment_id,
                        'order_num'  => $add_order_data['order_num'],
                        'pay_price'  => $add_order_data['pay_price'],
                        'product_id' => $item_data_item['product_id'], //移出购物车依赖数据
                        'opt_key'    => $item_data_item['options_active_str'], //移出购物车依赖数据
                    ];
                } else {
                    zib_send_json_error(['code' => 'add_order_error', 'msg' => '订单创建失败']);
                }
            }
        }
    }

    //订单创建完成
    if ($confirm_data['config']['is_cart']) {
        //如果是从购物车过来的，则移出购物车
        zib_shop_cart_remove_multi($order_data, $user_id);
    }

    $send_data = [
        'order_data'   => $order_data,
        'payment_data' => $zibpay_payment,
    ];

    zib_send_json_success($send_data);
}
add_action('wp_ajax_shop_submit_order', 'zib_shop_ajax_submit_order');
add_action('wp_ajax_nopriv_shop_submit_order', 'zib_shop_ajax_submit_order');

//获取支付方式
function zib_shop_get_payment_methods()
{
    $pay_methods = zibpay_get_payment_methods(zib_shop_get_order_type());
    return $pay_methods;
}

//允许余额支付
function zib_shop_is_allow_balance_pay_filter($is, $pay_type)
{
    if ($pay_type == zib_shop_get_order_type()) {
        return true;
    }
    return $is;
}
add_filter('zibpay_is_allow_balance_pay', 'zib_shop_is_allow_balance_pay_filter', 10, 2); //允许余额支付

//不允许卡密支付
function zib_shop_is_allow_card_pass_pay_filter($is, $pay_type)
{
    if ($pay_type == zib_shop_get_order_type()) {
        return false;
    }
    return $is;
}
add_filter('zibpay_is_allow_card_pass_pay', 'zib_shop_is_allow_card_pass_pay_filter', 10, 2); //不允许卡密支付

function zib_shop_get_payment_data($pay_type = 'price')
{

    $data = [
        'pay_methods'         => [],
        'pay_methods_active'  => '',
        'user_balance'        => '',
        'user_points'         => '',
        'balance_charge_link' => '',
        'points_pay_link'     => '',
        'user_balance_url'    => zib_get_user_center_url('balance'),
        'pay_type'            => zib_shop_get_order_type(), //固定值，表示商品购买
        'return_url'          => zib_get_user_center_url('order'), //返回地址
    ];

    $user_id = get_current_user_id();
    if ($pay_type === 'points') {

        $data['pay_methods'] = [
            'points' => array(
                'name' => '积分支付',
                'img'  => '<svg aria-hidden="true"><use xlink:href="#icon-points-color"></use></svg>',
            ),
        ];

        $data['pay_methods_active'] = 'points';
        $data['user_points']        = zibpay_get_user_points($user_id);
        $data['user_points_url']    = zib_get_user_center_url('balance');
        $data['points_pay_link']    = zibpay_get_points_pay_link('but c-yellow', '购买积分');
        return $data;
    }

    //获取支付方式
    $pay_methods                = zib_shop_get_payment_methods();
    $pay_methods_keys           = array_keys($pay_methods);
    $pay_methods_active         = $pay_methods_keys[0] ?? '';
    $data['pay_methods']        = $pay_methods;
    $data['pay_methods_active'] = $pay_methods_active;

    if ($user_id && in_array('balance', $pay_methods_keys)) {
        //存在余额支付，则显示用户余额
        $user_balance = zibpay_get_user_balance($user_id);
        //余额充值的链接
        $balance_charge_link = zibpay_get_balance_charge_link('but c-yellow', '充值');

        $data['user_balance']        = $user_balance;
        $data['balance_charge_link'] = $balance_charge_link;
    }

    if (!$data['pay_methods']) {
        $data['error_msg'] = is_super_admin() ? '<a href="' . zib_get_admin_csf_url('支付付费/收款接口') . '" class="but c-red btn-block">请先在主题设置中配置收款方式及收款接口</a>' : '<span class="badg px12 c-yellow-2">暂时无法购买，请与客服联系</span>';
    }

    return $data;
}

//挂钩下单成功后
add_action('order_created', 'zib_shop_order_created', 10, 1);
function zib_shop_order_created($order)
{
    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    //扣减库存
    $product_id      = $order['post_id'];
    $product_opt_str = $order['meta']['order_data']['options_active_str'];
    zib_shop_product_deduct_stock($product_id, $product_opt_str, $order['count']);
}

//挂钩关闭订单，添加库存恢复
add_action('order_closed', 'zib_shop_order_closed', 10, 1); //订单关闭
add_action('order_refunded', 'zib_shop_order_closed', 10, 1); //订单退单
function zib_shop_order_closed($order_id)
{
    $order = zibpay::get_order($order_id, 'all');
    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    $product_id      = $order['post_id'];
    $product_opt_str = $order['meta']['order_data']['options_active_str'];
    zib_shop_product_add_stock($product_id, $product_opt_str, $order['count']);
}

//挂钩订单付款成功后
add_action('payment_order_success', 'zib_shop_order_payment_success', 10, 2);
function zib_shop_order_payment_success($order)
{
    $order = zibpay::order_data_map($order);
    if ($order['order_type'] != '10') {
        return;
    }

    //更新发货状态为待发货
    zib_shop_update_order_shipping_status($order['id'], 0);

    //准备发货
    //1. 判断是否需要发货，还是自动发货
    $shipping_type = zib_shop_get_product_config($order['post_id'], 'shipping_type');
    if ($shipping_type === 'auto') {
        //自动发货
        zib_shop_auto_shipping($order);
    } else {
        //通知商家发货
        zib_shop_notify_shipping($order);
    }

    //更新商品销量
    zib_shop_update_product_sales_volume($order['post_id'], $order['count']);
}

function zib_shop_update_order_shipping_time($order_id)
{
    //更新物流时间
    zibpay::update_meta($order_id, 'shipping_time', current_time('Y-m-d H:i:s'));
}
