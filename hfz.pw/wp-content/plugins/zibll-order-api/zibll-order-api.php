<?php
/**
 * Plugin Name: Zibll Order API
 * Description: 为子比主题订单系统提供REST API查询接口
 * Version: 1.0.0
 * Author: HF
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 插件激活时创建数据表
 */
register_activation_hook(__FILE__, 'zibll_order_api_activate');

function zibll_order_api_activate() {
    // 不需要创建表，使用user meta存储IP
    error_log('插件激活成功');
}

/**
 * 同步用户网站列表到用户资料
 */
function zibll_sync_user_websites($user_id, $websites) {
    // 确保websites是数组
    if (!is_array($websites)) {
        $websites = array();
    }
    
    // 遍历IP列表，为每个IP创建URL格式
    $urls = array_map(function($ip) {
        return 'http://' . $ip;
    }, $websites);
    
    // 更新用户的额外网站URL
    // 注意：WordPress默认的user_url字段保存主要网站
    // 我们使用自定义字段保存其他网站
    update_user_meta($user_id, 'additional_urls', $urls);
}

/**
 * 在用户资料页面显示额外的网站URL字段
 */
function zibll_show_extra_websites($user) {
    $websites = get_user_meta($user->ID, 'websites', true);
    if (!empty($websites) && is_array($websites)): ?>
    <h3>绑定的服务器IP</h3>
    <table class="form-table">
        <?php foreach ($websites as $index => $ip): ?>
        <tr>
            <th><label>网站 <?php echo $index + 1; ?></label></th>
            <td>
                <input type="text" readonly value="http://<?php echo esc_attr($ip); ?>" class="regular-text" />
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif;
}

// 添加用户资料页面的额外字段显示
add_action('show_user_profile', 'zibll_show_extra_websites');
add_action('edit_user_profile', 'zibll_show_extra_websites');

/**
 * 注册REST API路由
 */
add_action('rest_api_init', function () {
    // 订单查询接口
    register_rest_route('zibll/v1', '/order/(?P<order_no>\d+)', array(
        'methods' => 'GET',
        'callback' => 'zibll_get_order_by_number',
        'permission_callback' => 'zibll_order_api_permission',
    ));

    // 获取用户绑定的服务器IP
    register_rest_route('zibll/v1', '/servers', array(
        'methods' => 'GET',
        'callback' => 'zibll_get_server_ips',
        'permission_callback' => 'zibll_order_api_permission',
    ));

    // 添加新的服务器IP绑定
    register_rest_route('zibll/v1', '/servers', array(
        'methods' => 'POST',
        'callback' => 'zibll_add_server_ip',
        'permission_callback' => 'zibll_order_api_permission',
        'args' => array(
            'server_ip' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'zibll_clean_ip'
            )
        )
    ));

    // 解除服务器IP绑定
    register_rest_route('zibll/v1', '/servers/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'zibll_delete_server_ip',
        'permission_callback' => 'zibll_order_api_permission',
    ));

    // 验证服务器IP
    register_rest_route('zibll/v1', '/verify-server', array(
        'methods' => 'POST',
        'callback' => 'zibll_verify_server_ip',
        'permission_callback' => 'zibll_order_api_permission',
        'args' => array(
            'order_num' => array(
                'required' => true,
                'type' => 'string'
            ),
            'server_ip' => array(
                'required' => true,
                'type' => 'string',
                'validate_callback' => 'zibll_validate_ip'
            )
        )
    ));
});

/**
 * 权限检查 - 需要Basic Auth或Application Password
 */
function zibll_order_api_permission() {
    error_log('开始验证API权限');
    error_log('Authorization: ' . $_SERVER['HTTP_AUTHORIZATION']);
    
    // 检查是否已认证
    if (!is_user_logged_in()) {
        error_log('用户未登录');
        return new WP_Error(
            'rest_forbidden',
            '需要登录',
            array('status' => 401)
        );
    }
    
    $current_user = wp_get_current_user();
    error_log('当前用户: ' . $current_user->user_login . ' (ID: ' . $current_user->ID . ')');
    error_log('用户角色: ' . implode(', ', $current_user->roles));
    
    // 检查是否是授权用户（123@qq.com）
    if ($current_user->user_email !== '123@qq.com') {
        error_log('非授权用户');
        return new WP_Error(
            'rest_forbidden',
            '无权访问',
            array('status' => 403)
        );
    }
    
    error_log('权限验证通过');
    return true;
}

/**
 * 根据订单号查询订单
 */
function zibll_get_order_by_number($request) {
    global $wpdb;
    
    $order_no = $request->get_param('order_no');
    
    // 查询子比主题的订单表
    $table_name = $wpdb->prefix . 'zibpay_order';
    
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE order_num = %s",
        $order_no
    ), ARRAY_A);
    
    if (!$order) {
        return new WP_Error(
            'order_not_found',
            '订单不存在',
            array('status' => 404)
        );
    }
    
    // 解析商品ID（子比主题将实际商品ID存储在post_id字段）
    $product_id = intval($order['post_id']); // 实际商品ID在post_id字段
    
    // 获取商品信息
    $product = get_post($product_id);
    $product_name = $product ? $product->post_title : '';
    
    // 整理返回数据
    $response = array(
        'order_id' => $order['id'],
        'order_num' => $order['order_num'],
        'order_type' => $order['order_type'],
        'product_id' => $product_id,
        'product_name' => $product_name,
        'user_id' => $order['user_id'],
        'order_price' => $order['order_price'],
        'pay_price' => $order['pay_price'],
        'pay_type' => $order['pay_type'],
        'pay_status' => $order['status'],
        'create_time' => $order['create_time'],
        'pay_time' => $order['pay_time'],
        'status' => $order['status']
    );
    
    // 获取用户信息
    if ($order['user_id']) {
        $user = get_userdata($order['user_id']);
        if ($user) {
            $response['user_email'] = $user->user_email;
            $response['user_name'] = $user->display_name;
        }
    }
    
    return rest_ensure_response($response);
}

/**
 * 验证IP地址格式
 */
function zibll_validate_ip($param, $request = null, $key = null) {
    error_log('正在验证主机名/IP: ' . $param);
    // 移除可能的http://或https://前缀
    $host = preg_replace('#^https?://#', '', $param);
    error_log('清理后的主机名/IP: ' . $host);
    // 如果是localhost或127.0.0.1或0.0.0.0，直接返回true
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0') {
        error_log('是本地主机名/IP，验证通过');
        return true;
    }
    $result = filter_var($host, FILTER_VALIDATE_IP);
    error_log('IP验证结果: ' . ($result ? 'true' : 'false'));
    return $result;
}

/**
 * 清理域名/IP地址
 */
function zibll_clean_ip($ip) {
    // 移除协议前缀
    $clean_ip = preg_replace('#^https?://#', '', $ip);
    // 移除可能的结尾斜杠和端口号
    $clean_ip = preg_replace('#(:\d+)?/?$#', '', $clean_ip);
    // 如果是localhost，直接返回
    if ($clean_ip === 'localhost') {
        return $clean_ip;
    }
    return $clean_ip;
}

/**
 * 获取用户绑定的服务器IP列表
 */
function zibll_get_server_ips($request) {
    // 获取admin用户
    $admin_user = get_user_by('login', 'admin');
    if (!$admin_user) {
        error_log('未找到admin用户');
        return new WP_Error('user_not_found', '未找到目标用户', array('status' => 404));
    }
    
    // 获取网站列表
    $websites = get_user_meta($admin_user->ID, 'websites', true);
    if (!is_array($websites)) {
        $websites = array();
    }
    
    // 转换为对象数组以保持API兼容性
    $servers = array_map(function($ip) {
        return array(
            'server_ip' => $ip,
            'status' => 1,
            'created_at' => current_time('mysql')
        );
    }, $websites);
    
    return new WP_REST_Response($servers, 200);
}

/**
 * 添加新的服务器IP绑定
 */
function zibll_add_server_ip($request) {
    error_log('开始添加服务器IP');
    
    // 获取admin用户
    $admin_user = get_user_by('login', 'admin');
    if (!$admin_user) {
        error_log('未找到admin用户');
        return new WP_Error('user_not_found', '未找到目标用户', array('status' => 404));
    }
    
    $user_id = $admin_user->ID;
    $server_ip = zibll_clean_ip($request->get_param('server_ip'));
    
    // 验证清理后的主机名/IP是否有效
    if ($server_ip !== 'localhost' && !filter_var($server_ip, FILTER_VALIDATE_IP)) {
        error_log('无效的主机名/IP地址: ' . $server_ip);
        return new WP_Error('invalid_ip', '无效的主机名/IP地址格式', array('status' => 400));
    }
    
    // 格式化IP为URL格式
    $server_url = 'http://' . $server_ip;
    
    error_log('正在获取用户ID: ' . $user_id);
    
    // 获取现有的网站列表
    $websites = get_user_meta($user_id, 'websites', true);
    error_log('现有网站列表: ' . print_r($websites, true));
    
    if (!is_array($websites)) {
        $websites = array();
        error_log('初始化空数组');
    }
    
    // 同时更新用户的网站URL
    $current_user_url = get_user_meta($user_id, 'user_url', true);
    error_log('当前用户URL: ' . $current_user_url);
    if (empty($current_user_url)) {
        update_user_meta($user_id, 'user_url', $server_url);
        error_log('已更新用户URL为: ' . $server_url);
    }
    
    error_log('现有网站: ' . print_r($websites, true));
    
    // 检查是否已达到最大限制（4个）
    if (count($websites) >= 4) {
        error_log('超过最大绑定数量限制');
        return new WP_Error('too_many_bindings', '已达到最大服务器绑定数量（4个）', array('status' => 400));
    }
    
    // 检查IP是否已存在
    if (in_array($server_ip, $websites)) {
        error_log('IP已被绑定');
        return new WP_Error('ip_already_bound', '该服务器IP已被绑定', array('status' => 400));
    }
    
    // 添加新IP到数组
    $websites[] = $server_ip;
    error_log('准备保存的网站列表: ' . print_r($websites, true));
    
    // 更新用户资料
    delete_user_meta($user_id, 'websites'); // 先删除旧数据
    $result = update_user_meta($user_id, 'websites', $websites);
    
    if ($result === false) {
        error_log('更新用户资料失败');
        return new WP_Error('update_failed', '保存IP失败', array('status' => 500));
    }
    
    error_log('更新成功，验证保存的数据');
    $saved_websites = get_user_meta($user_id, 'websites', true);
    error_log('保存后的网站列表: ' . print_r($saved_websites, true));
    
    // 同步更新用户的额外网站地址
    zibll_sync_user_websites($user_id, $websites);
    
    error_log('IP绑定成功');
    return new WP_REST_Response(array(
        'id' => count($websites),
        'message' => '服务器IP绑定成功'
    ), 201);
    
    error_log('用户ID: ' . $user_id);
    error_log('服务器IP: ' . $server_ip);
    
    // 验证表是否存在
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
    error_log('表是否存在: ' . ($table_exists ? 'yes' : 'no'));
    
    // 检查是否已达到最大绑定数量（4个）
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE user_id = %d AND status = 1",
        $user_id
    ));
    
    error_log('当前绑定数量: ' . $count);
    
    if ($count >= 4) {
        error_log('超过最大绑定数量限制');
        return new WP_Error('too_many_bindings', '已达到最大服务器绑定数量（4个）', array('status' => 400));
    }
    
    // 检查IP是否已被绑定
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_name WHERE server_ip = %s AND status = 1",
        $server_ip
    ));
    
    error_log('IP是否已存在: ' . ($exists ? 'yes' : 'no'));
    
    if ($exists) {
        error_log('IP已被绑定');
        return new WP_Error('ip_already_bound', '该服务器IP已被绑定', array('status' => 400));
    }
    
    // 添加新绑定
    error_log('准备插入新记录');
    $result = $wpdb->insert($table_name, array(
        'user_id' => $user_id,
        'server_ip' => $server_ip,
        'status' => 1
    ));
    
    // 记录插入结果
    error_log('插入结果: ' . ($result ? 'success' : 'failed'));
    if (!$result) {
        error_log('数据库错误: ' . $wpdb->last_error);
    }
    
    if ($result === false) {
        return new WP_Error('db_error', '数据库操作失败', array('status' => 500));
    }
    
    return new WP_REST_Response(array(
        'id' => $wpdb->insert_id,
        'message' => '服务器IP绑定成功'
    ), 201);
}

/**
 * 解除服务器IP绑定
 */
function zibll_delete_server_ip($request) {
    // 获取admin用户
    $admin_user = get_user_by('login', 'admin');
    if (!$admin_user) {
        error_log('未找到admin用户');
        return new WP_Error('user_not_found', '未找到目标用户', array('status' => 404));
    }
    
    // 获取要删除的IP索引
    $id = intval($request->get_param('id'));
    
    // 获取网站列表
    $websites = get_user_meta($admin_user->ID, 'websites', true);
    if (!is_array($websites)) {
        $websites = array();
    }
    
    // 检查索引是否有效
    if ($id < 1 || $id > count($websites)) {
        return new WP_Error('invalid_id', '无效的ID', array('status' => 400));
    }
    
    // 从数组中移除指定索引（注意索引从0开始）
    array_splice($websites, $id - 1, 1);
    
    // 更新用户元数据
    $result = update_user_meta($admin_user->ID, 'websites', $websites);
    
    if ($result === false) {
        return new WP_Error('update_failed', '更新失败', array('status' => 500));
    }
    
    // 同步更新用户的额外网站地址
    zibll_sync_user_websites($admin_user->ID, $websites);
    
    return new WP_REST_Response(array(
        'message' => '服务器IP绑定已解除'
    ), 200);
}

/**
 * 验证服务器IP是否已授权
 */
function zibll_verify_server_ip($request) {
    global $wpdb;
    
    $order_num = $request->get_param('order_num');
    $server_ip = $request->get_param('server_ip');
    
    // 1. 验证订单是否存在且已支付
    $order_table = $wpdb->prefix . 'zibpay_order';
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $order_table WHERE order_num = %s AND status = 1",
        $order_num
    ));
    
    if (!$order) {
        return new WP_Error('invalid_order', '无效的订单或订单未支付', array('status' => 400));
    }
    
    // 2. 使用订单购买者的用户资料来获取绑定的服务器IP（支持 user_url, websites, additional_urls）
    $buyer_id = intval($order->user_id);
    if (!$buyer_id) {
        error_log('订单没有关联用户: ' . $order_num);
        return new WP_Error('invalid_order_user', '订单未关联有效用户', array('status' => 400));
    }

    $buyer = get_userdata($buyer_id);
    if (!$buyer) {
        error_log('未找到购买用户: ' . $buyer_id);
        return new WP_Error('user_not_found', '未找到购买用户', array('status' => 404));
    }

    // 收集可能的绑定来源
    $websites = get_user_meta($buyer_id, 'websites', true);
    if (!is_array($websites)) {
        $websites = array();
    }

    // 主站字段 user_url（可能包含逗号或换行符分隔的多个 URL/IP）
    $user_url = get_user_meta($buyer_id, 'user_url', true);
    if (!empty($user_url)) {
        // 支持逗号或换行符分隔
        $urls = preg_split('/[,\n\r]+/', $user_url);
        foreach ($urls as $u) {
            $u = trim($u);
            if (!empty($u)) {
                $websites[] = $u;
            }
        }
    }

    // 额外同步的地址 (additional_urls) 也包含在检查中
    $additional = get_user_meta($buyer_id, 'additional_urls', true);
    if (is_array($additional)) {
        $websites = array_merge($websites, $additional);
    }

    // 规范化传入的 server_ip
    $server_ip_clean = preg_replace('#^https?://#', '', $server_ip);
    $server_ip_clean = preg_replace('#(:\d+)?/?$#', '', $server_ip_clean);
    // 处理 IPv6 映射到 IPv4 的格式 ::ffff:ip
    if (strpos($server_ip_clean, '::ffff:') === 0) {
        $server_ip_clean = substr($server_ip_clean, 7);
    }
    $server_ip_clean = trim($server_ip_clean);

    error_log('验证订单 ' . $order_num . ' 对应购买用户 ID: ' . $buyer_id . '，传入 server_ip: ' . $server_ip . ' -> 规范化: ' . $server_ip_clean);

    // 规范化用户资料中的网址/IP 列表
    $normalized = array();
    foreach ($websites as $w) {
        if (empty($w)) continue;
        $h = preg_replace('#^https?://#', '', $w);
        $h = preg_replace('#(:\d+)?/?$#', '', $h);
        if (strpos($h, '::ffff:') === 0) {
            $h = substr($h, 7);
        }
        $h = trim($h);
        if ($h !== '') {
            $normalized[] = $h;
        }
    }
    $normalized = array_values(array_unique($normalized));

    error_log('购买用户绑定的地址或IP列表（规范化后）: ' . print_r($normalized, true));

    // 3. 验证服务器IP是否已授权（在购买者的绑定列表中）
    if (!in_array($server_ip_clean, $normalized)) {
        error_log('授权失败: 传入IP不在购买者绑定列表中');
        return new WP_Error('unauthorized_ip', '该服务器IP未授权', array(
            'status' => 403,
            'order_num' => $order_num,
            'server_ip' => $server_ip_clean,
            'allowed' => $normalized
        ));
    }
    
    // 4. 返回验证成功的响应
    return new WP_REST_Response(array(
        'status' => 'success',
        'message' => '服务器IP验证成功',
        'data' => array(
            'order_num' => $order_num,
            'server_ip' => $server_ip,
            'user_id' => $order->user_id,
            'binding_time' => current_time('mysql')
        )
    ), 200);
}
