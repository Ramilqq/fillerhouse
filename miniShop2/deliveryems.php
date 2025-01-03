<?php
if(!class_exists('msDeliveryInterface')) {
    require_once dirname(dirname(__FILE__)) . '/minishop2/model/minishop2/msdeliveryhandler.class.php';
}

class deliveryEms extends msDeliveryHandler implements msDeliveryInterface{

    public function getCost(msOrderInterface $order, msDelivery $delivery, $cost = 0) {

        $freedeliverysumm = 300;
        $cart = $order->ms2->cart->status();
        $cart_get = $order->ms2->cart->get();
        $cart_cost = $cart['total_cost'];
        
        $card_deliv = 0;
        $card_add = false;
        
        $adr = $order->get();
        $country = $adr['country'];
        //$this->modx->log(modX::LOG_LEVEL_ERROR, print_r($order->get(), 1));
        
        /*
        была доствка за заказ гифт карты, убрал и добавил ниже
        foreach($cart_get as $cart_prod){
            $parents = $this->modx->getParentIds($cart_prod['id'], 10, array('context' => 'web'));
            if (!in_array(344, $parents)) {
                $card_deliv = $card_deliv + ($cart_prod['price'] * $cart_prod['count']);
            }else{
                $card_add = true;
            }
        }*/
        
        foreach($cart_get as $cart_prod){
            $parents = $this->modx->getParentIds($cart_prod['id'], 10, array('context' => 'web'));
            if (!in_array(666, $parents)) {
                $card_deliv = $card_deliv + ($cart_prod['price'] * $cart_prod['count']);
            }else{
                $card_add = true;
            }
        }
        
        
        /*
        была доствка за заказ гифт карты, убрал и добавил ниже
        if ($card_deliv < $freedeliverysumm and $card_add) {
            $cost = $cost + $delivery->get('price');
            return $cost;
        }else{
            $delivery_cost = parent::getCost($order, $delivery, $cost);
            return $delivery_cost;
        }
        */
        if ($card_deliv == 0 and $card_add) {
            $cost = $cost;
            return $cost;
        }elseif ($card_deliv < $freedeliverysumm and $card_add) {
            $cost = $cost + $delivery->get('price');
            return $cost;
        }else{
            $delivery_cost = parent::getCost($order, $delivery, $cost);
            return $delivery_cost;
        }
        
    }
}
