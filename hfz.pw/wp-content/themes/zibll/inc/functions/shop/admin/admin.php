<?php
/*
* @Author : Qinver
* @Url : zibll.com
* @Date : 2025-04-07 17:39:04
* @LastEditTime : 2025-04-07 17:39:05
* @Project : Zibll子比主题
* @Description : 更优雅的Wordpress主题
* Copyright (c) 2025 by Qinver, All Rights Reserved.
* @Email : 770349780@qq.com
* @Read me : 感谢您使用子比主题，主题源码有详细的注释，支持二次开发
* @Remind : 使用盗版主题会存在各种未知风险。支持正版，从我做起！
*/

global $zib_shop;

zib_require(array(
    'option-module',
    'admin-option',
), false, ZIB_SHOP_REQUIRE_URI . 'admin/options/');

//引入商城资源文件
if ($zib_shop->s) {
    zib_require(array(
        'meta-option',
        'term-option',
    ), false, ZIB_SHOP_REQUIRE_URI . 'admin/options/');
    zib_require(array(
        'ajax',
    ), false, ZIB_SHOP_REQUIRE_URI . 'admin/actions/');
}