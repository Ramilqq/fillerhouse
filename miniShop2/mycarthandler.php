<?php
class myCartHandler extends msCartHandler {
    public function add($id, $count = 1, $options = array())
    {
        if (empty($id) || !is_numeric($id)) {
            return $this->error('ms2_cart_add_err_id');
        }
        $count = intval($count);
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            $options = array();
        }

        $filter = array('id' => $id);
        if (!$this->config['allow_deleted']) {
            $filter['deleted'] = 0;
        }
        if (!$this->config['allow_unpublished']) {
            $filter['published'] = 1;
        }
        /** @var msProduct $product */
        if ($product = $this->modx->getObject('modResource', $filter)) {
            if (!($product instanceof msProduct)) {
                return $this->error('ms2_cart_add_err_product', $this->status());
            }
            if ($count > $this->config['max_count'] || $count <= 0) {
                return $this->error('ms2_cart_add_err_count', $this->status(), array('count' => $count));
            }

            /* You can prevent add of product to cart by adding some text to $modx->event->_output
            <?php
                    if ($modx->event->name = 'msOnBeforeAddToCart') {
                        $modx->event->output('Error');
                    }

            // Also you can modify $count and $options variables by add values to $this->modx->event->returnedValues
                <?php
                    if ($modx->event->name = 'msOnBeforeAddToCart') {
                        $values = & $modx->event->returnedValues;
                        $values['count'] = $count + 10;
                        $values['options'] = array('size' => '99');
                    }
            */
            
            if ($product->get('size') && !$options['size']){
                return $this->error('Select the size option');
            }
            if ($product->get('boxes') && !$options['boxes']){
                return $this->error('Select the boxes option');
            }
            if ($product->get('ms_type') && !$options['ms_type']){
                return $this->error('Select the type option');
            }
            
            if ($product->get('sender_name') && !$options['sender_name']){
                return $this->error('Select the sender name option');
            }
            if ($product->get('receiver_name') && !$options['receiver_name']){
                return $this->error('Select the receiver name option');
            }
            if ($product->get('receiver_mail') && !$options['receiver_mail']){
                return $this->error('Select the receiver mail option');
            }
            if ($product->get('specify_products') && !$options['specify_products']){
                return $this->error('Please specify which products you would like to receive');
            }
            
            $response = $this->ms2->invokeEvent('msOnBeforeAddToCart', array(
                'product' => $product,
                'count' => $count,
                'options' => $options,
                'cart' => $this,
            ));
            if (!($response['success'])) {
                return $this->error($response['message']);
            }
            $price = $product->getPrice();
            $oldPrice = $product->get('old_price');
            $weight = $product->getWeight();
            $count = $response['data']['count'];
            $options = $response['data']['options'];
            $discount_price = $oldPrice > 0 ? $oldPrice - $price : 0;
            $discount_cost = $discount_price * $count;
            $parent = $product->get('parent');
            
            $key = md5($id . $price . $weight . (json_encode($options)));
            if (array_key_exists($key, $this->cart)) {
                return $this->change($key, $this->cart[$key]['count'] + $count);
            } else {
                $ctx_key = 'web';
                if (!$this->modx->getOption('ms2_cart_context', null, '', true)) {
                    $ctx_key = $this->modx->context->get('key');
                }
                $this->cart[$key] = array(
                    'id' => $id,
                    'parent' => $parent,
                    'price' => $price,
                    'old_price' => $oldPrice,
                    'discount_price' => $discount_price,
                    'discount_cost' => $discount_cost,
                    'weight' => $weight,
                    'count' => $count,
                    'options' => $options,
                    'ctx' => $ctx_key,
                );
                $response = $this->ms2->invokeEvent('msOnAddToCart', array('key' => $key, 'cart' => $this));
                if (!$response['success']) {
                    return $this->error($response['message']);
                }

                return $this->success('ms2_cart_add_success', $this->status(array('key' => $key)),
                    array('count' => $count));
            }
        }

        return $this->error('ms2_cart_add_err_nf', $this->status());
    }
    
    public function remove($key)
    {
        if (array_key_exists($key, $this->cart)) {
            $response = $this->ms2->invokeEvent('msOnBeforeRemoveFromCart', array('key' => $key, 'cart' => $this));
            if (!$response['success']) {
                return $this->error($response['message']);
            }
            
            unset($this->cart[$key]);
            
            //удаление айс бокс при удаление токсинов
            $group = [];
            foreach ($this->cart as $k => $v){
                if (in_array($v['parent'], $this->ms2->order->getToxinGroup())) $group[] = $v['parent'];
            }
            if (count($group) == 0){
                foreach ($this->cart as $k => $v){
                    if ($v['id'] == $this->ms2->order->getIseBoxId()) {
                        $_SESSION['minishop2']['order']['propfld_ise_box'] = '';
                        unset($this->cart[$k]);
                    }
                }
            }
            //!удаление айс бокс при удаление токсинов
            
            $response = $this->ms2->invokeEvent('msOnRemoveFromCart', array('key' => $key, 'cart' => $this));
            if (!$response['success']) {
                return $this->error($response['message']);
            }

            return $this->success('ms2_cart_remove_success', $this->status());
        } else {
            return $this->error('ms2_cart_remove_error');
        }
    }
    
    
}
