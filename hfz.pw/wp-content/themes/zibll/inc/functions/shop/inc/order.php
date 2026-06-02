<?php
/*
 * @Author       : Qinver
 * @Url          : zibll.com
 * @Date         : 2025-02-24 14:02:36
 * @LastEditTime : 2025-08-06 20:41:33
 * @Project      : Zibll子比主题
 * @Description  : 更优雅的Wordpress主题 | 订单处理
 * Copyright (c) 2025 by Qinver, All Rights Reserved.
 * @Email        : 770349780@qq.com
 * @Read me      : 感谢您使用子比主题，主题源码有详细的注释，支持二次开发
 * @Remind       : 使用盗版主题会存在各种未知风险。支持正版，从我做起！
 */

/**
 * 获取订单确认页面数据
 *
 * @return array 订单确认页面数据
 */
function zib_shop_get_confirm_data($products_items = null)
{

    // 从POST获取选中的商品，格式和购物车一样
    // 数据结构为：$items[$product_id][$options_string] = $count
    // 例如：data['products'][this.product_id][this.options_active_str] = this.selected_count;
    //或者 data['products'][this.product_id][this.options_active_str] = {count: this.selected_count, remark: this.remark};
    $products_items = $products_items ?: $_POST['products'] ?? array();
    $is_cart        = !empty($_POST['is_cart']);

    // 获取购物车选中的商品
    if (empty($products_items)) {
        return array();
    }

    $product_ids = array_keys($products_items);

    $query_args = array(
        'ignore_sticky_posts' => true,
        'post_type'           => 'shop_product',
        'post_status'         => 'publish',
        'orderby'             => 'post__in',
        'post__in'            => $product_ids,
        'show'                => -1,
    );
    $new_query = new WP_Query($query_args);

    //商家，商品，选项
    $shop_author_show     = (bool) _pz('shop_author_show', true);
    $user_data            = zib_shop_get_user_vue_data();
    $item_data            = array();
    $product_data         = array();
    $author_data          = array();
    $error_data           = array();
    $shipping_has_express = false;
    $shipping_has_auto    = false;
    $shipping_has_manual  = false;
    $has_points           = false;
    $has_price            = false;

    //准备计算优惠需要的依赖数据
    $discount_data_dependency = [
        'user_data'    => $user_data,
        'product_data' =>
        [
            1 => [
                'price' => 0,
                'count' => 0,
            ],
        ],
        'author_data'  => [
            1 => [
                'price' => 0,
                'count' => 0,
            ],
        ],
        'total_data'   => [
            'price' => 0,
            'count' => 0,
        ],
    ];

    // 获取商品数据
    if (!is_wp_error($new_query) && !empty($new_query->posts)) {
        foreach ($new_query->posts as $posts_item) {
            $product_data[$posts_item->ID]         = zib_shop_get_product_vue_data($posts_item);
            $author_data[$posts_item->post_author] = zib_shop_get_author_vue_data($posts_item->post_author);

            $product_options = $product_data[$posts_item->ID]['product_options'];
            $start_price     = (float) $product_data[$posts_item->ID]['prices']['start_price'];

            if ($product_data[$posts_item->ID]['shipping_type'] === 'manual') {
                $shipping_has_manual = true;
            } elseif ($product_data[$posts_item->ID]['shipping_type'] === 'auto') {
                $shipping_has_auto = true;
            } else {
                $shipping_has_express = true;
            }

            if ($product_data[$posts_item->ID]['pay_modo'] === 'points') {
                $has_points = true;
            } else {
                $has_price = true;
            }

            foreach ($products_items[$posts_item->ID] as $item_key => $item_val) {
                if (is_array($item_val)) {
                    $item_count = (int) $item_val['count'] ?? 0;
                } else {
                    $item_count = (int) $item_val;
                }

                //判断商品数量,如果数量小于等于0则跳过
                if ($item_count <= 0) {
                    continue;
                }

                //判断商品选项是否存在
                $options_active_name           = '';
                $options_active_name_separator = ' · '; //分隔符

                $options_active = zib_shop_product_options_to_array($item_key);
                $prices         = [
                    'unit_price'  => $start_price, //单价
                    'total_price' => $start_price * $item_count, //总价
                ];

                if (isset($product_options[0])) {
                    //有商品选项
                    if ((!$options_active || count($options_active) !== count($product_options))) {
                        //选项不匹配，跳过
                        $error_data[] = [
                            'product_id'         => $posts_item->ID,
                            'options_active_str' => $item_key,
                            'error_msg'          => '商品[' . $product_data[$posts_item->ID]['title'] . ']选项错误，请重新选择商品选项',
                            'error_type'         => 'options_error',
                        ];
                        continue;
                    }

                    //根据选项匹配价格
                    foreach ($options_active as $option_index => $item_index) {
                        $prices['unit_price'] += (float) $product_options[$option_index]['opts'][$item_index]['price_change']; //单价浮动

                        if (isset($product_options[$option_index]['name']) && isset($product_options[$option_index]['opts'][$item_index]['name'])) {
                            $options_active_name .= $product_options[$option_index]['opts'][$item_index]['name'] . $options_active_name_separator;
                        }
                    }

                    //去除最后一个分隔符
                    $options_active_name = rtrim($options_active_name, $options_active_name_separator);

                    $prices['total_price'] = $prices['unit_price'] * (int) $item_count;
                } else {
                    //没有商品选项
                    $item_key = '0';
                }

                //判断库存
                $item_stock_all = -1;
                if ($product_data[$posts_item->ID]['stock_type'] === 'opts') {
                    $item_stock_all = zib_shop_get_product_opt_stock($posts_item->ID, $options_active);
                }

                if (($item_stock_all === 0 || $item_stock_all < $item_count) && $item_stock_all !== -1) {
                    $error_data[] = [
                        'product_id'         => $posts_item->ID,
                        'options_active_str' => $item_key,
                        'error_msg'          => '商品[' . $product_data[$posts_item->ID]['title'] . '][' . $options_active_name . ']库存不足',
                        'error_type'         => 'opt_stock_error',
                        'stock_all'          => $item_stock_all,
                    ];
                }

                //处理用户必填项
                $user_required       = [];
                $_post_user_required = $item_val['user_required'] ?? [];
                $user_required_key   = rand(100, 999) + rand(100, 999); //创建3位数随机数，用于用户必填项的key
                foreach ($product_data[$posts_item->ID]['user_required'] as $user_required_item) {
                    if ($_post_user_required) {
                        foreach ($_post_user_required as $_post_user_required_item) {
                            if ($_post_user_required_item['name'] === $user_required_item['name'] && $_post_user_required_item['value']) {
                                $user_required_item['value'] = $_post_user_required_item['value'];
                                break;
                            }
                        }
                    }
                    $user_required[] = array_merge($user_required_item, ['key' => $user_required_key]);
                    $user_required_key++;
                }

                //计算优惠依赖数据，用于命中优惠判断
                $discount_data_dependency['product_data'][$posts_item->ID]['price'] = $prices['total_price'] + ($discount_data_dependency['product_data'][$posts_item->ID]['price'] ?? 0);
                $discount_data_dependency['product_data'][$posts_item->ID]['count'] = $item_count + ($discount_data_dependency['product_data'][$posts_item->ID]['count'] ?? 0);

                $discount_data_dependency['author_data'][$posts_item->post_author]['price'] = $prices['total_price'] + ($discount_data_dependency['author_data'][$posts_item->post_author]['price'] ?? 0);
                $discount_data_dependency['author_data'][$posts_item->post_author]['count'] = $item_count + ($discount_data_dependency['author_data'][$posts_item->post_author]['count'] ?? 0);

                $discount_data_dependency['total_data']['price'] = $prices['total_price'] + ($discount_data_dependency['total_data']['price'] ?? 0);
                $discount_data_dependency['total_data']['count'] = $item_count + ($discount_data_dependency['total_data']['count'] ?? 0);

                $author_data[$posts_item->post_author]['price']['total_price'] = $prices['total_price'] + ($author_data[$posts_item->post_author]['price']['total_price'] ?? 0);
                $author_data[$posts_item->post_author]['count']                = $item_count + ($author_data[$posts_item->post_author]['count'] ?? 0);

                $product_data[$posts_item->ID]['prices']['total_price'] = $prices['total_price'] + ($product_data[$posts_item->ID]['prices']['total_price'] ?? 0);
                $product_data[$posts_item->ID]['count']                 = $item_count + ($product_data[$posts_item->ID]['count'] ?? 0);

                //汇总
                $item_data[$posts_item->post_author][$posts_item->ID][$item_key] = [
                    'user_required'       => $user_required,
                    'pay_modo'            => $product_data[$posts_item->ID]['pay_modo'],
                    'count'               => $item_count,
                    'product_title'       => $product_data[$posts_item->ID]['title'],
                    'product_id'          => $posts_item->ID,
                    'options_active_str'  => $item_key,
                    'options_active_name' => $options_active_name,
                    'stock_all'           => $item_stock_all, //库存交给JS处理
                    'prices'              => $prices,
                    'shipping_type'       => $product_data[$posts_item->ID]['shipping_type'],
                ];
                if (isset($item_val['remark'])) {
                    $item_data[$posts_item->post_author][$posts_item->ID][$item_key]['remark'] = sanitize_text_field(strip_tags($item_val['remark']));
                }
            }
        }
    }

    //opt-item的价格都是用的xxx_price，没有用到xxx_points，用pay_modo判断

    global $zib_shop;
    $discount_data = [];
    $total_data    = [
        'points_mark'     => zibpay_get_points_mark(),
        'pay_mark'        => $zib_shop->currency_symbol,
        'shipping_fee'    => 0, //总运费
        'count'           => 0,
        'price_count'     => 0,
        'points_count'    => 0,
        'price'           => 0, //总价
        'discount_price'  => 0, //总优惠价
        'points'          => 0, //总积分
        'discount_points' => 0, //总优惠积分
        'discount_hit'    => [], //命中优惠
    ];

    //以商品为单位计算总运费
    foreach ($product_data as $product_id => $product_data_item) {

        $is_points = $product_data_item['pay_modo'] === 'points'; //积分商品没有运费

        if (!$is_points && $product_data_item['shipping_type'] === 'express') {
            $shipping_fee_opt = $product_data_item['shipping_fee_opt'] ?? [];

            if ($shipping_fee_opt['type'] === 'free') {
                $product_data[$product_id]['prices']['shipping_fee'] = 0;
            }

            if ($shipping_fee_opt['type'] === 'fixed') {
                $product_data[$product_id]['prices']['shipping_fee'] = (float) $shipping_fee_opt['fixed_fee'];
            }

            if ($shipping_fee_opt['type'] === 'amount') {
                $product_total_price = $product_data_item['prices']['total_price']; //在依赖数据里查询商品总价，仅用来判断

                if ($product_total_price >= (float) $shipping_fee_opt['amount_fee']['free_amount']) {
                    $product_data[$product_id]['prices']['shipping_fee'] = 0;
                } else {
                    $product_data[$product_id]['prices']['shipping_fee'] = (float) $shipping_fee_opt['amount_fee']['fee'];
                }
            }
        }
    }

    //计算总数据
    foreach ($item_data as $author_id => $product_data_items) {
        foreach ($product_data_items as $product_id => $opt_items) {
            foreach ($opt_items as $item_key => $opt_item_data) {
                //准备判断优惠价
                $item_product_data = $product_data[$opt_item_data['product_id']];
                $item_discount     = $item_product_data['discount'];
                $is_points         = $item_product_data['pay_modo'] === 'points';

                //初始化优惠数据
                $item_data[$author_id][$product_id][$item_key]['prices']['total_discount_price'] = $item_data[$author_id][$product_id][$item_key]['prices']['total_price'];
                $item_total_discount_price                                                       = $item_data[$author_id][$product_id][$item_key]['prices']['total_price'];

                if ($item_discount) {

                    /**
                     * 执行当前选项的优惠政策计算
                     * 与js函数计算同步
                     * 同步文件：inc/functions/shop/assets/js/main.js:680
                     */

                    //准备依赖数据
                    $item_discount_data_dependency = [
                        'item_data'    => [
                            'price' => $item_total_discount_price / $opt_item_data['count'],
                            'count' => $opt_item_data['count'],
                        ],
                        'product_data' => $discount_data_dependency['product_data'][$opt_item_data['product_id']],
                        'author_data'  => $discount_data_dependency['author_data'][$author_id],
                        'total_data'   => $discount_data_dependency['total_data'],
                        'user_data'    => $discount_data_dependency['user_data'],
                    ];

                    foreach ($item_discount as $discount_item_args) {
                        $discount_item_args['usesed_count']       = 0;
                        $discount_data[$discount_item_args['id']] = $discount_item_args;

                        //开始计算优惠政策及优惠价格
                        //is_valid判断
                        if (!$discount_item_args['is_valid']) {
                            continue;
                        }

                        // 1.金额限制判断
                        if (!zib_shop_discount_price_limit_check($discount_item_args, $item_discount_data_dependency)) {
                            continue;
                        }

                        // 2.用户身份限制
                        if (!zib_shop_discount_user_limit_check($discount_item_args, $discount_data_dependency['user_data'])) {
                            continue;
                        }

                        // 3. 判断结束，命中优惠
                        // 3.1：赠品核算
                        if ($discount_item_args['discount_type'] === 'gift') {
                            //赠品
                            if (empty($item_data[$author_id][$product_id][$item_key]['gift_data'])) {
                                $item_data[$author_id][$product_id][$item_key]['gift_data'] = [];
                            }

                            //添加赠品
                            $item_data[$author_id][$product_id][$item_key]['gift_data'] = array_merge($item_data[$author_id][$product_id][$item_key]['gift_data'], $discount_item_args['gift_config']);

                            $item_data[$author_id][$product_id][$item_key]['discount_hit'][] =
                                [
                                'id'            => $discount_item_args['id'],
                                'discount_type' => $discount_item_args['discount_type'],
                                'gift_data'     => $discount_item_args['gift_config'],
                                'name'          => $discount_item_args['name'],
                            ];
                        } else {
                            // 3.2：优惠计算
                            $discount_price_calculate                  = zib_shop_discount_price_calculate($discount_item_args, $item_discount_data_dependency, $item_total_discount_price, $opt_item_data['count']);
                            $discount_item_args['preferential_amount'] = zib_shop_format_price($discount_price_calculate['preferential_amount'], $is_points, true);
                            $discount_item_args['usesed_count']        = $discount_price_calculate['usesed_count'];
                            $item_total_discount_price -= $discount_item_args['preferential_amount'];

                            //记录命中优惠
                            $item_data[$author_id][$product_id][$item_key]['discount_hit'][] =
                                [
                                'usesed_count'        => $discount_item_args['usesed_count'], //使用次数
                                'id'                  => $discount_item_args['id'],
                                'preferential_amount' => $discount_item_args['preferential_amount'],
                                'discount_type'       => $discount_item_args['discount_type'],
                                'name'                => $discount_item_args['name'],
                            ];

                            if (isset($total_data['discount_hit'][$discount_item_args['id']])) {
                                $total_data['discount_hit'][$discount_item_args['id']]['usesed_count'] += $discount_item_args['usesed_count'];
                                $total_data['discount_hit'][$discount_item_args['id']]['count'] += $opt_item_data['count'];
                                $total_data['discount_hit'][$discount_item_args['id']]['count'] += $opt_item_data['count'];
                                $total_data['discount_hit'][$discount_item_args['id']]['preferential_amount'] += $discount_item_args['preferential_amount'];

                                if (!in_array($opt_item_data['product_id'], $total_data['discount_hit'][$discount_item_args['id']]['product_id'])) {
                                    $total_data['discount_hit'][$discount_item_args['id']]['product_id'][] = $opt_item_data['product_id'];
                                }

                            } else {
                                $total_data['discount_hit'][$discount_item_args['id']] = [
                                    'usesed_count'        => $discount_item_args['usesed_count'], //使用次数
                                    'id'                  => $discount_item_args['id'],
                                    'preferential_amount' => $discount_item_args['preferential_amount'],
                                    'count'               => $opt_item_data['count'],
                                    'product_id'          => [$opt_item_data['product_id']],
                                ];
                            }
                        }
                        //商品选项循环区域：判断优惠结束
                    }
                }
                //商品选项循环区域：优惠判断循环结束

                $item_data[$author_id][$product_id][$item_key]['prices']['total_discount_price'] = $item_total_discount_price;
                $item_data[$author_id][$product_id][$item_key]['prices']['total_discount']       = zib_shop_format_price($item_data[$author_id][$product_id][$item_key]['prices']['total_price'] - $item_total_discount_price, $is_points, true);

                //处理优惠金额，积分取整数，金额保留两位小数
                foreach ($item_data[$author_id][$product_id][$item_key]['prices'] as $_key => $_value) {
                    $item_data[$author_id][$product_id][$item_key]['prices'][$_key] = zib_shop_format_price($_value, $is_points, true);
                }

                //计算运费，商品总运费除以商品数量，再乘以当前商品数量
                $item_data[$author_id][$product_id][$item_key]['prices']['shipping_fee'] = 0;
                if (!empty($item_product_data['prices']['shipping_fee'])) {
                    //运费只能为整数
                    $item_data[$author_id][$product_id][$item_key]['prices']['shipping_fee'] = (int) zib_floatval_round($item_product_data['prices']['shipping_fee'] / $item_product_data['count'] * $opt_item_data['count']);
                }

                //计算支付金额
                $item_data[$author_id][$product_id][$item_key]['prices']['pay_price'] = zib_shop_format_price($item_data[$author_id][$product_id][$item_key]['prices']['total_discount_price'] + $item_data[$author_id][$product_id][$item_key]['prices']['shipping_fee'], $is_points, true);

                //计算总数据
                $total_data['shipping_fee'] += $item_data[$author_id][$product_id][$item_key]['prices']['shipping_fee'];
                $total_data['count'] += $item_data[$author_id][$product_id][$item_key]['count'];

                if ($is_points) {
                    $total_data['points'] += $item_data[$author_id][$product_id][$item_key]['prices']['total_price'];
                    $total_data['discount_points'] += $item_data[$author_id][$product_id][$item_key]['prices']['total_discount_price'];
                    $total_data['points_count'] += $item_data[$author_id][$product_id][$item_key]['count'];
                } else {
                    $total_data['price'] += $item_data[$author_id][$product_id][$item_key]['prices']['total_price'];
                    $total_data['discount_price'] += $item_data[$author_id][$product_id][$item_key]['prices']['total_discount_price'];
                    $total_data['price_count'] += $item_data[$author_id][$product_id][$item_key]['count'];
                }

                //计算库存
                if ($item_product_data['stock_type'] !== 'opts') {
                    $product_stock_all = (int) $item_product_data['stock_all'];
                    if (($product_stock_all === 0 || $product_stock_all < $item_product_data['count']) && $product_stock_all !== -1) {
                        $error_data[] = [
                            'product_id' => $item_product_data['product_id'],
                            'error_msg'  => '商品[' . $item_product_data['title'] . ']库存不足',
                            'error_type' => 'product_stock_error',
                            'stock_all'  => $product_stock_all,
                        ];
                    }
                }

                //计算限购
                if (!empty($item_product_data['limit_buy']['is_limit']) && $item_product_data['limit_buy']['limit'] < $item_product_data['count']) {

                    if ($item_product_data['limit_buy']['limit'] == 0) {
                        $_msg = '限购，无法购买';
                        if (!empty($item_product_data['limit_buy']['limit_all'])) {
                            $_msg = '限购' . $item_product_data['limit_buy']['limit_all'] . '件，您已下单' . $item_product_data['limit_buy']['bought_count'] . '件，无法再购买';
                        }
                    } else {
                        $_msg = '限购' . $item_product_data['limit_buy']['limit'] . '件';
                    }

                    $error_data[] = [
                        'product_id'  => $item_product_data['product_id'],
                        'error_msg'   => '商品[' . $item_product_data['title'] . ']' . $_msg,
                        'error_type'  => 'limit_buy_error',
                        'limit_count' => $item_product_data['limit_buy']['limit'],
                    ];
                }
            }
        }
        //商家循环区域：商品循环结束
    }

    //数据整理
    $total_data['shipping_fee']    = (int) $total_data['shipping_fee']; //运费只能为整数
    $total_data['pay_price']       = $total_data['shipping_fee'] + $total_data['discount_price'];
    $total_data['points']          = (int) $total_data['points'];
    $total_data['discount_points'] = (int) $total_data['discount_points'];
    $total_data['pay_points']      = $total_data['discount_points'];

    $_data = [
        'error_data'           => $error_data,
        'product_data'         => $product_data,
        'author_data'          => $author_data,
        'item_data'            => $item_data,
        'user_data'            => $user_data,
        'discount_data'        => $discount_data,
        //总数据
        'total_data'           => $total_data,
        'shipping_has_express' => $shipping_has_express, //有快递发货商品
        'shipping_has_auto'    => $shipping_has_auto, //有自动发货商品
        'shipping_has_manual'  => $shipping_has_manual, //有手动发货商品
        'has_points'           => $has_points, //有积分支付商品
        'has_price'            => $has_price, //有金额支付商品
        'is_mix'               => false, //是否混合支付
        'pay_modo'             => '', //支付方式
        'is_can_pay'           => false, //可以结算
        'config'               => [
            'author_show' => $shop_author_show,
            'is_cart'     => $is_cart,
        ],
        'pay_data'             => zib_shop_get_payment_data($has_points ? 'points' : 'price'),
    ];

    if ($has_points && $has_price) {
        $_data['is_mix']   = true;
        $_data['pay_modo'] = 'mix';
    } elseif ($has_points) {
        $_data['pay_modo'] = 'points';
    } elseif ($has_price) {
        $_data['pay_modo'] = 'price';
    }

    return $_data;
}

//获取加入购物车链接
function zib_shop_get_order_add_cart_link($order, $class = '', $text = '加入购物车')
{
    $class              = $class ? ' ' . $class : '';
    $options_active_str = zibpay::get_meta($order['id'], 'order_data.options_active_str');

    //判断能否被加入购物车
    $can_add = zib_shop_can_add_cart($order['post_id'], $options_active_str);
    if (!$can_add) {
        return;
    }

    $form_data = array(
        'product_id'     => $order['post_id'],
        'options_active' => $options_active_str,
        'count'          => 1,
        'action'         => 'cart_add',
    );

    return '<a class="wp-ajax-submit ' . $class . '" form-data="' . esc_attr(json_encode($form_data)) . '" href="javascript:;">' . $text . '</a>';
}

//获取查看订单的自动发货内容详情的模态框
function zib_shop_get_order_delivery_content_link(array $order, $class = '', $text = '查看内容')
{
    $class = $class ? ' ' . $class : '';

    if ($order['status'] != '1') {
        return;
    }

    $shipping_type = zibpay::get_meta($order['id'], 'order_data.shipping_type');

    if ($shipping_type !== 'auto') {
        return;
    }

    $order_id = $order['id'];

    $url_var = array(
        'action'   => 'order_delivery_content_modal',
        'order_id' => $order_id,
    );

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'modal-mini full-sm',
        'height'        => 240,
        'new'           => true,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => $url_var,
    );

    //每次都刷新的modal
    return zib_get_refresh_modal_link($args);
}

//获取订单查看物流链接
function zib_shop_get_order_express_link($order, $class = '', $text = '')
{
    $class = $class ? ' ' . $class : '';

    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    if ($order['status'] != '1') {
        return;
    }

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'full-sm',
        'height'        => 240,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => array(
            'action'   => 'order_express_modal',
            'order_id' => $order['id'],
        ),
    );
    return zib_get_refresh_modal_link($args);
}

//获取订单查看优惠链接
function zib_shop_get_order_discount_link($order, $class = '', $text = '')
{
    $class = $class ? ' ' . $class : '';

    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'full-sm modal-mini',
        'height'        => 240,
        'new'           => true,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => array(
            'action'   => 'order_discount_modal',
            'order_id' => $order['id'],
        ),
    );
    return zib_get_refresh_modal_link($args);
}

//获取订单查看赠品链接
function zib_shop_get_order_gift_link($order, $class = '', $text = '')
{
    $class = $class ? ' ' . $class : '';

    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'full-sm modal-mini',
        'height'        => 240,
        'new'           => true,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => array(
            'action'   => 'order_gift_modal',
            'order_id' => $order['id'],
        ),
    );
    return zib_get_refresh_modal_link($args);
}

//获取用户确认收货链接
function zib_shop_get_order_receive_confirm_link($order, $class = '', $text = '')
{
    $class = $class ? ' ' . $class : '';
    //判断订单类型
    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    //必须为已支付
    if ($order['status'] != '1') {
        return;
    }

    //必须为未收货
    if (zib_shop_get_order_shipping_status($order['id']) != '1') {
        return;
    }

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'modal-mini',
        'height'        => 284,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => array(
            'action'   => 'order_receive_confirm_modal',
            'order_id' => $order['id'],
        ),
    );

    return zib_get_refresh_modal_link($args);
}

//获取订单评论链接
function zib_shop_get_order_comment_link($order, $class = '', $text = '')
{
    $class = $class ? ' ' . $class : '';

    //判断订单类型
    if ($order['order_type'] != zib_shop_get_order_type()) {
        return;
    }

    //必须为已支付
    if ($order['status'] != '1') {
        return;
    }

    //必须为已收货
    if (zib_shop_get_order_shipping_status($order['id']) != '2') {
        return;
    }

    //售后中.状态
    if (in_array(zib_shop_get_order_after_sale_status($order['id']), [1, 2])) {
        return;
    }

    //判断是否已评价
    if (zib_shop_get_order_comment_status($order['id']) !== 0) {
        return;
    }

    $args = array(
        'tag'           => 'a',
        'class'         => $class,
        'data_class'    => 'modal-mini full-sm',
        'height'        => 380,
        'mobile_bottom' => true,
        'text'          => $text,
        'query_arg'     => array(
            'action'   => 'order_comment_modal',
            'order_id' => $order['id'],
        ),
    );
    return zib_get_refresh_modal_link($args);
}

//获取订单的收货状态
function zib_shop_get_order_shipping_status($order_id)
{

    $shipping_status = (int) zibpay::get_meta($order_id, 'shipping_status');
    if ($shipping_status === 1) {
        //如果是待收货，则判断待收货时效
        $receipt_over_time = zib_shop_get_order_receipt_over_time($order_id);
        if ($receipt_over_time == 'over') {
            return 2;
        }
    }

    return $shipping_status;
}

//获取订单的收货时效：剩余确认收货时间
function zib_shop_get_order_receipt_over_time($order_id)
{

    //发货时间
    $delivery_time = zibpay::get_meta($order_id, 'order_data.shipping_data.delivery_time');
    if (empty($delivery_time)) {
        return false;
    }

    $current_time = current_time('Y-m-d H:i:s');
    $max_time     = _pz('order_receipt_max_day', 15) ?: 15; //默认15天

    //计算剩余确认收货时间
    $last_time = strtotime('+ ' . $max_time . ' day', strtotime($delivery_time));
    if (strtotime($current_time) > $last_time) {
        //自动确认收货
        zib_shop_order_receive_confirm($order_id, 'auto', '自动确认收货');
        return 'over';
    }

    return $last_time;
}

//更新订单的收货状态
function zib_shop_update_order_shipping_status($order_id, $status)
{
    $shipping_status = zibpay::update_meta($order_id, 'shipping_status', (int) $status);
    return $shipping_status;
}

//获取订单的收货状态名称
function zib_shop_get_order_shipping_status_name($shipping_status)
{
    $shipping_status_name = [
        0 => '待发货',
        1 => '待收货', //待收货，需要用户确认收货
        2 => '已完成', //已完成
    ];

    return $shipping_status_name[$shipping_status] ?? '';
}
