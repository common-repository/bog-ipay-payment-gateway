<?php
/*
 * Plugin Name: Bank of Georgia iPay Payment Gateway
 * Plugin URI: http://ipay.ge
 * Description: Bank Of Georgia iPay payment on your store.
 * Author: JSC Bank of Georgia
 * Author URI: https://bankofgeorgia.ge
 * Version: 0.0.2
 */
add_filter("woocommerce_payment_gateways", "ipay_add_gateway_class");

function ipay_add_gateway_class($gateways)
{
    $gateways[] = "WC_IPay_Gateway";

    return $gateways;
}


function plugin_name_load_plugin_textdomain()
{
    load_plugin_textdomain('bog-ipay-payment-gateway', false, basename(dirname(__FILE__)) . '/languages/');
}

add_action('init', 'plugin_name_load_plugin_textdomain');
add_action("plugins_loaded", "ipay_init_gateway_class");

function ipay_init_gateway_class()
{
    class WC_IPay_Gateway extends WC_Payment_Gateway
    {
        private $url;
        private $demo;
        private $auth_data;
        private $intent;
        private $urls = array("dev" => "http://dev.ipay.ge", "prod" => "https://ipay.ge");

        public function __construct()
        {
            global $woocommerce;
            $this->id = "ipay";
            $this->icon = plugin_dir_url(__FILE__) . "assets/img/logo.png";
            $this->has_fields = true;
            $this->method_title = "Bank Of Georgia iPay Payment Gateway";
            $this->method_description = __("Payment can be fulfilled with BOG personal Internet banking as well as VISA, MasterCard and American Express cards.", "bog-ipay-payment-gateway");


            $this->supports = array(
                "products",
                'refunds'
            );

            $this->init_form_fields();

            $this->init_settings();
            $this->title = "iPay";
            $this->description = __("Payment can be fulfilled with BOG personal Internet banking as well as VISA, MasterCard and American Express cards.", "bog-ipay-payment-gateway");
            $this->enabled = $this->get_option("enabled");
            $this->demo = "yes" === $this->get_option("demo");
            $this->intent = $this->get_option("intent");
            $this->auth_data = ($this->demo == "yes" ? base64_encode($this->get_option("demo_merchant_id") . ":" . $this->get_option("demo_merchant_password")) : base64_encode($this->get_option("merchant_id") . ":" . $this->get_option("merchant_password")));
            $this->url = $this->demo == "yes" ? $this->urls['dev'] : $this->urls['prod'];


            add_action("woocommerce_update_options_payment_gateways_" . $this->id, array(
                $this,
                "process_admin_options"
            ));
            add_action("woocommerce_api_ipay-payment-callback", array($this, "ipay_payment_callback"));
            add_action("woocommerce_api_ipay-payment-reversal", array($this, "ipay_payment_reversal"));

            add_action('admin_init', array($this, 'setup_sections'));

            add_action('init', function () {
                load_plugin_textdomain('bog-ipay-payment-gateway', false, basename(dirname(__FILE__)) . '/languages/');
            });
        }

        /**
         *
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                "enabled" => array(
                    "title" => __("Enable/Disable", "bog-ipay-payment-gateway"),
                    "label" => __("Enable IPay Gateway", "bog-ipay-payment-gateway"),
                    "type" => "checkbox",

                    "default" => "no"
                ),
                "demo" => array(
                    "title" => __("Demo mode", "bog-ipay-payment-gateway"),
                    "label" => __("Enable Demo Mode", "bog-ipay-payment-gateway"),
                    "type" => "checkbox",
                    "description" => __("Place the payment gateway in test mode using test demo merchant id and password.", "bog-ipay-payment-gateway"),
                    "default" => "yes",
                    "desc_tip" => true,
                ),
                "intent" => array(
                    "title" => __("INTENT", "bog-ipay-payment-gateway"),
                    "label" => __("Seelct intent", "bog-ipay-payment-gateway"),
                    "type" => "select",
                    "options" => array(
                        "CAPTURE" => "CAPTURE",
                        "AUTHORIZE" => "AUTHORIZE",
                    )
                ),
                "demo_merchant_id" => array(
                    "title" => __("Demo Merchant ID", "bog-ipay-payment-gateway"),
                    "type" => "text"
                ),
                "demo_merchant_password" => array(
                    "title" => __("Demo Merchant Password", "bog-ipay-payment-gateway"),
                    "type" => "text",
                ),
                "merchant_id" => array(
                    "title" => __("Real Merchant ID", "bog-ipay-payment-gateway"),
                    "type" => "text"
                ),
                "merchant_password" => array(
                    "title" => __("Real Merchant Password", "bog-ipay-payment-gateway"),
                    "type" => "text"
                ),
                "payment_callback" => array(
                    "title" => __("Payment callback", "bog-ipay-payment-gateway"),
                    "type" => "text",
                    "default" => get_site_url() . "/wc-api/ipay-payment-callback",
                    'custom_attributes' => array('readonly' => 'readonly')
                ),
                "refund_callback" => array(
                    "title" => __("Refund callback", "bog-ipay-payment-gateway"),
                    "type" => "text",
                    "default" => get_site_url() . "/wc-api/ipay-payment-reversal",
                    'custom_attributes' => array('readonly' => 'readonly')
                )

            );
        }

        /**
         * @return array|mixed|object
         */
        private function ipay_authorization()
        {
            return (json_decode(wp_remote_retrieve_body(wp_remote_post(
                $this->url . "/opay/api/v1/oauth2/token",
                array(
                    "method" => "POST",
                    "body" => array("grant_type" => "client_credentials"),
                    "headers" => array(
                        "Authorization" => "Basic " . $this->auth_data,
                        "Content-type" => "application/x-www-form-urlencoded"
                    ),
                )
            )), true));
        }

        /**
         * @param $token
         * @param $checkout_products_list
         *
         * @return array|mixed|object
         */
        private function ipay_checkout($token, $checkout_products_list)
        {
            return json_decode(wp_remote_retrieve_body(wp_remote_post(
                $this->url . "/opay/api/v1/checkout/orders",
                array(
                    "method" => "POST",
                    "body" => json_encode($checkout_products_list),
                    "headers" => array(
                        "Authorization" => "Bearer " . $token,
                        "Content-type" => "application/json"
                    )
                ))), true);
        }

        /**
         * @param $items
         * @param $total
         * @param $redirect_url
         * @param $shop_order_id
         *
         * @return array
         */
        private function get_checkout_params($items, $total, $redirect_url, $shop_order_id)
        {
            $checkout_products_list = [
                "intent" => $this->intent,
                "redirect_url" => $redirect_url,
                "shop_order_id" => $shop_order_id,
                "card_transaction_id" => "",
                "locale" => str_replace("_", "-", get_locale()),
                "purchase_units" => [
                    [
                        "amount" => [
                            "currency_code" => "GEL",
                            "value" => $total
                        ],
                        "industry_type" => "ECOMMERCE"
                    ]
                ],
                "items" => []
            ];

            foreach ($items as $item_id => $item_data) {

                $product = $item_data->get_product();

                $product_name = $product->get_name();

                $sku = $product->get_sku();

                $item_quantity = $item_data->get_quantity();


                $price = $product->get_price();
                array_push($checkout_products_list["items"], [
                    "amount" => $price,
                    "description" => $product_name,
                    "product_id" => $sku,
                    "quantity" => $item_quantity
                ]);
            }

            return $checkout_products_list;
        }

        public function setup_sections()
        {
            add_settings_section('our_first_section', 'My First Section Title', false, 'smashing_fields');
        }

        /**
         * @param $links
         */
        private function get_checkout_url($links)
        {
            foreach ($links as $link) {
                if ($link["rel"] == "approve") {
                    return $link["href"];
                }
            }
            wc_add_notice(__("Something went wrong, URL not found.", "bog-ipay-payment-gateway"), "error");

            return;
        }

        /**
         * @param int $order_id
         *
         * @return array|void
         */

        public function process_payment($order_id)
        {

            global $woocommerce;
            $order = new WC_Order($order_id);

            if ($order->get_currency() != "GEL") {
                wc_add_notice(__("Unsupported currency. iPay only supports GEL.", "bog-ipay-payment-gateway"), "error");

                return;
            }
            $ipay_order_id = $order->get_meta("ipay_order_id");

            $response = $this->ipay_authorization();
            if (empty($response)) {
                wc_add_notice(__("iPay not responding, please try again later", "bog-ipay-payment-gateway"), "error");

                return;
            }
            if (!isset($response["access_token"]) || empty($response["access_token"])) {
                wc_add_notice(__("Something went wrong, please try later.", "bog-ipay-payment-gateway"), "error");

                return;
            }
            if (isset($ipay_order_id) && !empty($ipay_order_id)) {
                return [
                    "result" => "success",
                    "redirect" => $this->url . "/?paymentId=" . $ipay_order_id
                ];
            } else {
                $params = $this->get_checkout_params($order->get_items(), $order->get_total(), get_permalink(woocommerce_get_page_id('shop')), $order_id);
                $checkout_response = $this->ipay_checkout($response["access_token"], $params);

                if (!empty($checkout_response) && isset($checkout_response["links"])) {
                    $redirect = $this->get_checkout_url($checkout_response["links"]);
                    $order->add_meta_data("payment_hash", $checkout_response["payment_hash"]);
                    $order->add_meta_data("ipay_order_id", $checkout_response["order_id"]);
                    $order->update_status("pending");
                    $order->add_order_note(__("Hey, your order is received. Thank you!", "bog-ipay-payment-gateway"), true);
                    $woocommerce->cart->empty_cart();

                    return [
                        "result" => "success",
                        "redirect" => $redirect
                    ];
                } else {
                    wc_add_notice(__("Something went wrong, please try later.", "bog-ipay-payment-gateway"), "error");

                    return;
                }
            }
        }

        /**
         * @param $payment_hash
         * @param $ipay_order_id
         * @param $status
         */
        protected function get_order_with_metadata($payment_hash, $status)
        {

            $args = array('payment_method' => 'ipay', 'status' => 'pending');
            $orders = wc_get_orders($args);
            foreach ($orders as $ord) {
                $order_id = $ord->id;
                if (get_post_meta($order_id, "payment_hash", true) == $payment_hash && get_post_meta($order_id, "ipay_order_id", true) == $ipay_order_id) {
                    $order = wc_get_order($order_id);
                    if ($status == 'success') {
                        $order->payment_complete();
                    } else {
                        $order->cancel_order();
                    }
                    http_response_code(200);

                    return;
                }
            }
            http_response_code(404);

            return;
        }

        /**
         * @param $payment_hash
         * @param $ipay_order_id
         */
        protected function revers_order_with_metadata($payment_hash, $ipay_order_id)
        {
            $args = array('payment_method' => 'ipay', 'status' => array('processing', 'completed'));
            $orders = wc_get_orders($args);
            foreach ($orders as $ord) {
                $order_id = $ord->id;
                if (get_post_meta($order_id, "payment_hash", true) == $payment_hash && get_post_meta($order_id, "ipay_order_id", true) == $ipay_order_id) {
                    $order = wc_get_order($order_id);

                    if ('refunded' == $order->get_status()) {
                        http_response_code(409);

                        return;
                    }
                    if ($order->update_status('refunded', __('Payment refunded', 'bog-ipay-payment-gateway'))) {
                        http_response_code(200);

                        return;
                    }
                    http_response_code(201);

                    return;
                }
            }
            http_response_code(404);

            return;
        }


        /**
         * http://your-site-url/wc-api/IPAY-PAYMENT-CALLBACK
         */
        public function ipay_payment_callback()
        {
            global $woocommerce;
            $payment_hash = (isset($_REQUEST["payment_hash"]) ? sanitize_text_field($_REQUEST["payment_hash"]) : null);
            $ipay_order_id = (isset($_REQUEST["order_id"]) ? sanitize_text_field($_REQUEST["order_id"]) : null);
            $status = (isset($_REQUEST["status"]) ? sanitize_text_field($_REQUEST["status"]) : null);
            $shop_order_id = (isset($_REQUEST["shop_order_id"]) ? sanitize_text_field($_REQUEST["shop_order_id"]) : null);
            $status_description = (isset($_REQUEST["status_description"]) ? sanitize_text_field($_REQUEST["status_description"]) : null);
            $payment_method = (isset($_REQUEST["payment_method"]) ? sanitize_text_field($_REQUEST["payment_method"]) : null);
            $card_type = (isset($_REQUEST["card_type"]) ? sanitize_text_field($_REQUEST["card_type"]) : null);
            $transaction_id = (isset($_REQUEST["transaction_id"]) ? sanitize_text_field($_REQUEST["transaction_id"]) : null);
            $pan = (isset($_REQUEST["pan"]) ? sanitize_text_field($_REQUEST["pan"]) : null);


            if (!is_null($shop_order_id)) {

                $order = new WC_Order($shop_order_id);


                if ($order->get_meta('payment_hash') != $payment_hash && $order->get_meta('ipay_order_id') != $ipay_order_id) {
                    http_response_code(404);

                    return;
                }

                $note = __('Order ID: ' . $ipay_order_id . ' Payment hash: ' . $payment_hash . ' Status: ' . $status . ' Shop Order ID:' . $shop_order_id . ' Status description: ' . $status_description . ' Payment method: ' . $payment_method . ' ');
                $note .= (!is_null($card_type) ? ($card_type != 'UNKNOWN' ? ' Card type: ' . $card_type : '') : '');
                $note .= (!is_null($transaction_id) ? ($transaction_id != 'UNKNOWN' ? ' Transaction ID: ' . $transaction_id : '') : '');
                $note .= (!is_null($pan) ? ($pan != 'UNKNOWN' ? ' PAN: ' . $pan : '') : '');

                if ($order->get_status() == "pending") {
                    if ($status == 'success') {
                        $order->payment_complete();
                    } else {
                        $order->cancel_order();
                    }
                    http_response_code(200);
                    $order->add_order_note($note);
                    return;
                }
                http_response_code(404);

                return;
            } else {
                $this->get_order_with_metadata($payment_hash, $status);
            }
        }

        /**
         *
         * http://your-site-url/wc-api/ipay-payment-reversal/
         */
        public function ipay_payment_reversal()
        {
            global $woocommerce;
            $payment_hash = (isset($_REQUEST["payment_hash"]) ? sanitize_text_field($_REQUEST["payment_hash"]) : null);
            $ipay_order_id = (isset($_REQUEST["order_id"]) ? sanitize_text_field($_REQUEST["order_id"]) : null);
            $shop_order_id = (isset($_REQUEST["shop_order_id"]) ? sanitize_text_field($_REQUEST["shop_order_id"]) : null);
            if (!is_null($shop_order_id)) {
                $order = new WC_Order($shop_order_id);

                if ($order->get_meta('payment_hash') != $payment_hash && $order->get_meta('ipay_order_id') != $ipay_order_id) {
                    http_response_code(404);

                    return;
                }

                if ('refunded' == $order->get_status()) {
                    http_response_code(409);

                    return;
                }

                if ($order->update_status('refunded', __('Payment refunded', 'bog-ipay-payment-gateway'))) {
                    http_response_code(200);

                    return;
                }

            } else {
                $this->revers_order_with_metadata($payment_hash, $ipay_order_id);
            }
        }
    }
}

