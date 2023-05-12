<?php
class myOrderHandler extends msOrderHandler {
    public function validate($key, $value) {
        
        if ($key == 'propfld_ise_box' and $value == 'yes'){
            $ise_box = false;
            $cart = $this->ms2->cart->get();
            
            foreach ($cart as $v){
                if ($v['id'] == 787){
                     $ise_box = true; 
                     break;
                }
            }
            if ($ise_box === false) $this->ms2->cart->add(787);
        }
        
        if ($key == 'propfld_ise_box' and $value == 'not'){
            $cart = $this->ms2->cart->get();
            foreach ($cart as $k => $v){
                if ($v['id'] == 787){
                     $this->ms2->cart->remove($k);
                     break;
                }
            }
        }
        
        switch ($key) {
            case 'email':
                // меняем filter_var() на простую регулярку
                $value = filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : @$this->order[$key];
            break;
            case 'country':
                // меняем filter_var() на простую регулярку
                $value = $this->countryArray($value) ? $value : @$this->order[$key];
            break;
            default:
                return parent::validate($key, $value);
        }
        if ($value === false) {
            $value = '';
        }

        return $value;
    }
    
    
    public function submit($data = array())
    {
        $response = $this->ms2->invokeEvent('msOnSubmitOrder', array(
            'data' => $data,
            'order' => $this,
        ));
        
        
        $properties = [];
        foreach ($data as $key => $value){
            if (strpos($key,'propfld_') !== false){
                $properties[$key] = htmlentities($value,ENT_COMPAT | ENT_HTML401,'UTF-8');
            }
        }
        
        if (!array_key_exists('propfld_signature', $properties)) return $this->error('Select a signature option');
        
        if (count($properties) > 0){
            $this->order['properties'] = json_encode($properties);
        }

        
        if (!$response['success']) {
            return $this->error($response['message']);
        }
        if (!empty($response['data']['data'])) {
            $this->set($response['data']['data']);
        }
        
        if ($this->order['payment'] == 24){
            return $this->error('Select a Payment Method');
        }
        
        $response = $this->getDeliveryRequiresFields();
        if ($this->ms2->config['json_response']) {
            $response = json_decode($response, true);
        }
        if (!$response['success']) {
            return $this->error($response['message']);
        }
        $requires = $response['data']['requires'];

        $errors = array();
        foreach ($requires as $v) {
            if (!empty($v) && empty($this->order[$v])) {
                $errors[] = $v;
            }
        }
        if (!empty($errors)) {
            return $this->error('ms2_order_err_requires', $errors);
        }

        $user_id = $this->ms2->getCustomerId();
        if (empty($user_id) || !is_int($user_id)) {
            return $this->error(is_string($user_id) ? $user_id : 'ms2_err_user_nf');
        }

        $cart_status = $this->ms2->cart->status();
        if (empty($cart_status['total_count'])) {
            return $this->error('ms2_order_err_empty');
        }

        $delivery_cost = $this->getCost(false, true);
        $cart_cost = $this->getCost(true, true) - $delivery_cost;
        $createdon = date('Y-m-d H:i:s');
        /** @var msOrder $order */
        $order = $this->modx->newObject('msOrder');
        $order->fromArray(array(
            'user_id' => $user_id,
            'createdon' => $createdon,
            'num' => $this->getNum(),
            'delivery' => $this->order['delivery'],
            'payment' => $this->order['payment'],
            'cart_cost' => $cart_cost,
            'weight' => $cart_status['total_weight'],
            'delivery_cost' => $delivery_cost,
            'cost' => $cart_cost + $delivery_cost,
            'status' => 0,
            'context' => $this->ms2->config['ctx'],
        ));

        // Adding address
        /** @var msOrderAddress $address */
        $address = $this->modx->newObject('msOrderAddress');
        $address->fromArray(array_merge($this->order, array(
            'user_id' => $user_id,
            'createdon' => $createdon,
        )));
        $order->addOne($address);

        // Adding products
        $cart = $this->ms2->cart->get();
        $products = array();
        
        foreach ($cart as $v) {
            if ($tmp = $this->modx->getObject('msProduct', array('id' => $v['id']))) {
                $name = $tmp->get('pagetitle');
                $parent = $tmp->get('parent');
                if ($tmp->get('in_remains')){
                    return $this->error('Product '.$name.' is out of stock!');
                }
                
                //для бесплатных товаров если сумма корзины меньше 100$
                if ($parent == 587 and $cart_cost < 100){
                    return $this->error('Free of charge order requires more than $100');
                }
            }
        }
        
        foreach ($cart as $v) {
            if ($tmp = $this->modx->getObject('msProduct', array('id' => $v['id']))) {
                $name = $tmp->get('pagetitle');
            } else {
                $name = '';
            }
            /** @var msOrderProduct $product */
            $product = $this->modx->newObject('msOrderProduct');
            $product->fromArray(array_merge($v, array(
                'product_id' => $v['id'],
                'name' => $name,
                'cost' => $v['price'] * $v['count'],
            )));
            $products[] = $product;
        }
        $order->addMany($products);

        $response = $this->ms2->invokeEvent('msOnBeforeCreateOrder', array(
            'msOrder' => $order,
            'order' => $this,
        ));
        if (!$response['success']) {
            return $this->error($response['message']);
        }

        if ($order->save()) {
            $response = $this->ms2->invokeEvent('msOnCreateOrder', array(
                'msOrder' => $order,
                'order' => $this,
            ));
            if (!$response['success']) {
                return $this->error($response['message']);
            }

            //$this->ms2->cart->clean();
            $this->clean();
            if (empty($_SESSION['minishop2']['orders'])) {
                $_SESSION['minishop2']['orders'] = array();
            }
            $_SESSION['minishop2']['orders'][] = $order->get('id');

            // Trying to set status "new"
            if ($order->get('cost') == 0)
            {
            $response = $this->ms2->changeOrderStatus($order->get('id'), 26);
            }
            else
            {
                $response = $this->ms2->changeOrderStatus($order->get('id'), 1);
            }
            
            if ($response !== true) {
                return $this->error($response, array('msorder' => $order->get('id')));
            }

            // Reload order object after changes in changeOrderStatus method
            $order = $this->modx->getObject('msOrder', array('id' => $order->get('id')));

            /** @var msPayment $payment */
            if (
                $payment = $this->modx->getObject(
                    'msPayment',
                    array('id' => $order->get('payment'), 'active' => 1)
                )
            ) {
                $response = $payment->send($order);
                if ($this->config['json_response']) {
                    @session_write_close();
                    exit(is_array($response) ? json_encode($response) : $response);
                } else {
                    if (!empty($response['data']['redirect'])) {
                        $this->modx->sendRedirect($response['data']['redirect']);
                    } elseif (!empty($response['data']['msorder'])) {
                        $this->modx->sendRedirect(
                            $this->modx->context->makeUrl(
                                $this->modx->resource->id,
                                array('msorder' => $response['data']['msorder'])
                            )
                        );
                    } else {
                        $this->modx->sendRedirect($this->modx->context->makeUrl($this->modx->resource->id));
                    }

                    return $this->success();
                }
            } else {
                if ($this->ms2->config['json_response']) {
                    return $this->success('', array('msorder' => $order->get('id')));
                } else {
                    $this->modx->sendRedirect(
                        $this->modx->context->makeUrl(
                            $this->modx->resource->id,
                            array('msorder' => $response['data']['msorder'])
                        )
                    );

                    return $this->success();
                }
            }
        }

        return $this->error();
    }
    
    
    public function countryArray($value) {
        $country = array("Afghanistan","Albania","Algeria","American Samoa","Andorra","Angola","Anguilla","Antarctica","Antigua and Barbuda","Argentina","Armenia","Aruba","Australia","Austria","Azerbaijan","Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bermuda","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Bouvet Island","Brazil","British Indian Ocean Territory","British Virgin Islands","Brunei","Bulgaria","Burkina Faso","Burundi","Cambodia","Cameroon","Canada","Cape Verde","Cayman Islands","Central African Republic","Chad","Chile","China","Christmas Island","Cocos Keeling Islands","Colombia","Comoros","Congo - Brazzaville","Congo - Kinshasa","Cook Islands","Costa Rica","Croatia","Cuba","Cyprus","Czech Republic","Côte d’Ivoire","Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Ethiopia","Falkland Islands","Faroe Islands","Fiji","Finland","France","French Guiana","French Polynesia","French Southern Territories","Gabon","Gambia","Georgia","Germany","Ghana","Gibraltar","Greece","Greenland","Grenada","Guadeloupe","Guam","Guatemala","Guernsey","Guinea","Guinea-Bissau","Guyana","Haiti","Heard Island and McDonald Islands","Honduras","Hong Kong SAR China","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Isle of Man","Israel"/*,"Italy"*/,"Jamaica","Japan","Jersey","Jordan","Kazakhstan","Kenya","Kiribati","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg","Macau SAR China","Macedonia","Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Martinique","Mauritania","Mauritius","Mayotte","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Montserrat","Morocco","Mozambique","Myanmar Burma","Namibia","Nauru","Nepal","Netherlands","Netherlands Antilles","New Caledonia","New Zealand","Nicaragua","Niger","Nigeria","Niue","Norfolk Island","North Korea","Northern Mariana Islands","Norway","Oman","Pakistan","Palau","Palestinian Territories","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Pitcairn Islands","Poland","Portugal","Puerto Rico","Qatar","Romania","Russia","Rwanda","Réunion","Saint Barthélemy","Saint Helena","Saint Kitts and Nevis","Saint Lucia","Saint Martin","Saint Pierre and Miquelon","Saint Vincent and the Grenadines","Samoa","San Marino","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Georgia and the South Sandwich Islands","South Korea","Spain Espana","Sri Lanka","Sudan","Suriname","Svalbard and Jan Mayen","Swaziland","Sweden","Switzerland","Syria","São Tomé and Príncipe","Taiwan","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tokelau","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Turks and Caicos Islands","Tuvalu","U.S. Minor Outlying Islands","U.S. Virgin Islands","Uganda","Ukraine","United Arab Emirates","United Kingdom","United States","Uruguay","Uzbekistan","Vanuatu","Vatican City","Venezuela","Vietnam","Wallis and Futuna","Western Sahara","Yemen","Zambia","Zimbabwe","Åland Islands");
        
        $value = in_array($value, $country);
        return $value;
    }
}