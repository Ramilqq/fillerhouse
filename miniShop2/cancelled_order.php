<?php


require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/public_html/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

$now = Date('Y-m-d h:i:s', strtotime('-7 day'));

$orders = $modx->getCollection('msOrder', ['status' => '1', 'createdon:<' => $now]);

if (count($orders) > 0){
    $ms = new miniShop2($modx);
    
    foreach ($orders as $order){
        $ms -> changeOrderStatus($order->id, 4);
    }
}