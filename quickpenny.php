<?php
/*
 * Plugin Name: Quickpenny
 * Plugin URI: https://quickpenny.com
 * Description: pay easily with quickpenny, earn store credit without borrowing money by paying with your bank account
 * Author: Quickpenny
 * Author URI: https://quickpenny.com
 * Version: 1.1.1
 */

require_once realpath(__DIR__ . "/vendor/autoload.php");

// Looing for .env at the root directory
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */

add_filter("woocommerce_payment_gateways", "qpenny_add_gateway_class");
function qpenny_add_gateway_class($gateways)
{
    $gateways[] = "WC_Quickpenny_Gateway"; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action("plugins_loaded", "qpenny_init_gateway_class");

function qpenny_init_gateway_class()
{
    class WC_Quickpenny_Gateway extends WC_Payment_Gateway
    {
        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {
            $this->id = "quickpenny"; // payment gateway plugin ID
            $this->icon = plugins_url(
                "assets/images/pay-with-logo.png",
                __FILE__
            ); // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = "Quickpenny Gateway";
            $this->method_description =
                "earn store credit without borrowing money by paying with your bank account, remember you must enable the payment method, and have your client id and client secret credentials filled out. "; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = ["products"];

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->enabled = $this->get_option("enabled");
            $this->testmode = "yes" === $this->get_option("testmode");
            $this->client_id = $this->testmode
                ? $this->get_option("test_client_id")
                : $this->get_option("publishable_client_id");
            $this->client_secret = $this->testmode
                ? $this->get_option("test_client_secret")
                : $this->get_option("publishable_client_secret");

            // This action hook saves the settings
            add_action(
                "woocommerce_update_options_payment_gateways_" . $this->id,
                [$this, "process_admin_options"]
            );

            // We need custom JavaScript to obtain a token
            add_action("wp_enqueue_scripts", [$this, "payment_scripts"]);

            // You can also register a webhook here
            add_action("woocommerce_api_quickpenny_payment_complete", [
                $this,
                "webhook",
            ]);
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                "enabled" => [
                    "title" => "Enable/Disable",
                    "label" => "Enable QuickPenny Gateway",
                    "type" => "checkbox",
                    "description" => "",
                    "default" => "no",
                ],
                "title" => [
                    "title" => "Title",
                    "type" => "text",
                    "description" =>
                    "This controls the title which the user sees during checkout.",
                    "default" => "Pay with QuickPenny",
                    "desc_tip" => true,
                ],
                "description" => [
                    "title" => "Description",
                    "type" => "textarea",
                    "description" =>
                    "This controls the description which the user sees during checkout.",
                    "default" =>
                    "earn store credit without borrowing money by paying with your bank account",
                ],
                "testmode" => [
                    "title" => "Test mode",
                    "label" => "Enable Test Mode",
                    "type" => "checkbox",
                    "description" =>
                    "Place the payment gateway in test mode using test API keys.",
                    "default" => "yes",
                    "desc_tip" => true,
                ],
                "test_client_id" => [
                    "title" => "Client Id Sandbox",
                    "type" => "text",
                ],
                "test_client_secret" => [
                    "title" => "Client Secret Sandbox",
                    "type" => "password",
                ],
                "publishable_client_id" => [
                    "title" => "Production Client ID",
                    "type" => "text",
                ],
                "publishable_client_secret" => [
                    "title" => "Production Client Secret",
                    "type" => "password",
                ],
            ];
        }

        // 	/**
        // 	 * You will need it if you want your custom credit card form, Step 4 is about it
        // 	 */
        // 	public function payment_fields() {

        //
        // 	}

        // 	/*
        // 	 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
        // 	 */
        public function payment_scripts()
        {
            // we need JavaScript to process a token only on cart/checkout pages, right?
            if (
                !is_cart() &&
                !is_checkout() &&
                !isset($_GET["pay_for_order"])
            ) {
                return;
            }

            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ("no" === $this->enabled) {
                return;
            }

            // no reason to enqueue JavaScript if API keys are not set
            // if (empty($this->client_id) || empty($this->client_secret)) {
            //   return;
            // }

            //Customize the checkout button when the payment method is QP, we can also cancel a payment by "express checkout".
            wp_register_script(
                "quickpenny_js",
                plugins_url("assets/js/quickpenny.js", __FILE__),
                ["jquery"]
            );
            wp_localize_script("quickpenny_js", "quickpennyCheckout", [
                "urlCheckout" => wc_get_checkout_url(),
                "locale" => get_bloginfo('language'),
            ]);
            wp_enqueue_script("quickpenny_js");

            //It takes care of the opening of the pop-up window to process the "express checkout" and all its flow to complete the payment.
            wp_register_script(
                "quickpenny_express_js",
                plugins_url("assets/js/quickpennyExpress.js", __FILE__),
                ["jquery"]
            );
            wp_localize_script("quickpenny_express_js", "ajax_var", [
                "url" => "?wc-ajax=checkout",
                "nonce" => wp_create_nonce("woocommerce-process_checkout"),
                "createorder" => "?wc-ajax=qpenny_create_order",
                "urlCheckout" => wc_get_checkout_url(),
                "hashkey" => $_ENV["QPNNY_HASHKEY"],
                "siteurl" => get_option("siteurl"),
                "express_checkout_url" => $_ENV["QPNNY_EXCH_TRANSACTION"],
                "locale" => get_bloginfo('language'),
                "client_id" => $this->client_id,
                "client_secret" => $this->client_secret,
            ]);
            wp_enqueue_script("quickpenny_express_js");

            wp_register_style(
                "quickpenny_css",
                plugins_url("assets/css/quickpenny.css", __FILE__)
            );
            wp_enqueue_style("quickpenny_css");
        }

        /*
         * Fields validation, more in Step 5
         */
        public function validate_fields()
        {
            // if( empty( $_POST[ 'billing_first_name' ]) ) {
            //     wc_add_notice(  'First name is required!', 'error' );
            //     return false;
            //   }
            return true;
        }

        /*
         * We're processing the payments here, everything about it is in Step 5
         */

        public function process_payment($order_id)
        {
            global $woocommerce;

            // we need it to get any order detailes
            $order = wc_get_order($order_id);
            // $user = $order->get_user();

            if (empty($this->client_id) || empty($this->client_secret)) {
                wc_add_notice(
                    "Client id and Client secret are empty or invalid",
                    "error"
                );
                return;
            }

            $qp_payment_method = "regular";

            if (
                isset($_POST["payment_method"]) &&
                isset($_POST["__payment_method__"]) &&
                $_POST["payment_method"] == "quickpenny" &&
                $_POST["__payment_method__"] == "quickpennyexpress"
            ) {
                $qp_payment_method = "quickpennyexpress";
            }


            if ($qp_payment_method == 'quickpennyexpress') {
                //PAGO CON EXPRESS CHECKOUT, YA HAY UNA TRANSACCIÓN CREADA, SE TIENE QUE COMPLETAR EL ID DE LA ORDEN ACTUAL PARA ESO ES NECESARIO CONSUMIR EL ENDPOINT
                if (isset($_COOKIE["__ts_id_quickpenny__"])) {

                    /*ENDPOINT */
                    $urlUpdate =
                        (!$this->testmode
                            ? $_ENV["QPNNY_UPDATE_ORDER_TRANS_PROD"]
                            : $_ENV["QPNNY_UPDATE_ORDER_TRANS_DEV"]);

                    $argsUpdate = [
                        "method" => "POST",
                        "sslverify" => false,
                        "headers" => [
                            "Authorization" =>
                            "Bearer " .
                                $_COOKIE["__user_token_quickpenny__"],
                            "client_id" => $this->client_id,
                            "client_secret" => $this->client_secret,
                            "Content-Type" => "application/json",
                        ],
                        //{  "order_id": 0,  "transaction_id": "string"}
                        'body'        => [
                            'order_id' => $order_id,
                            'transaction_id' => $_COOKIE["__ts_id_quickpenny__"],
                        ]
                    ];

                    $responseUpdate = wp_remote_post(
                        $urlUpdate,
                        $argsUpdate
                    );

                    if (is_wp_error($responseUpdate)) {
                        // $error_message = $responseExpress->get_error_message();
                        wc_add_notice(
                            $responseUpdate["message"],
                            "error"
                        );
                    } else {
                        //SE ACTUALIZO EL ID DE LA ORDEN OK, AHORA TOCA COMPLETAR LA TRANSACCIÓN


                        if (!isset($_COOKIE["__user_token_quickpenny__"])) {
                            //ERROR COOKIE
                            wc_add_notice(
                                "The user has not been successfully authenticated, please try again",
                                "error"
                            );
                        } else {
                            $urlExpress =
                                (!$this->testmode
                                    ? $_ENV["QPNNY_EXPRESS_API_PROD"]
                                    : $_ENV["QPNNY_EXPRESS_API_DEV"]) .
                                "/" .
                                $_COOKIE["__ts_id_quickpenny__"];

                            $argsExpress = [
                                "method" => "POST",
                                "sslverify" => false,
                                "headers" => [
                                    "Authorization" =>
                                    "Bearer " .
                                        $_COOKIE["__user_token_quickpenny__"],
                                    "client_id" => $this->client_id,
                                    "client_secret" => $this->client_secret,
                                    "Content-Type" => "application/json",
                                ],
                            ];

                            $responseExpress = wp_remote_post(
                                $urlExpress,
                                $argsExpress
                            );

                            // error check
                            error_log(print_r($responseExpress, true));
                            if (is_wp_error($responseExpress)) {
                                // $error_message = $responseExpress->get_error_message();
                                error_log(print_r($responseExpress, true));
                                wc_add_notice(
                                    $responseExpress["message"],
                                    "error"
                                );
                            } else {
                                //COMPLETE ORDER
                                $this->successCheckout();

                                return [
                                    "result" => "success",
                                    "redirect" => $this->get_return_url($order),
                                ];
                                exit();
                            }

                            return;
                            exit();
                        }
                    }


                    /*ENDPOINT */
                } else {
                    wc_add_notice(
                        "The transaction has been lost, please try again",
                        "error"
                    );
                }
            } else {
                //PAGO NORMAL, SE TIENE QUE CONSULTAR EL ENDPOINT PARA CREAR LA TRANSACCIÓN

                /*
             * Array with parameters for API interaction
             */
                $body = [
                    "amount" =>
                    $order->get_subtotal() - $order->get_discount_total(),
                    "shipping" => floatval($order->get_shipping_total()),
                    "taxes" => floatval($order->get_total_tax()),
                    "order_id" => $order_id,
                    "qp_payment_method" => $qp_payment_method,
                    // 'currency' => get_woocommerce_currency(),
                    "address" => [
                        "additional_info" => $order->get_customer_note()
                            ? $order->get_customer_note()
                            : "",
                        "address_value" => $order->get_billing_address_1()
                            ? $order->get_billing_address_1()
                            : $order->get_shipping_address_1(),
                        "city" => $order->get_billing_city()
                            ? $order->get_billing_city()
                            : $order->get_shipping_city(),
                        "country" => $order->get_billing_country()
                            ? $order->get_billing_country()
                            : $order->get_shipping_country(),
                        "name" => "buy",
                        "state" => $order->get_billing_state()
                            ? $order->get_billing_state()
                            : $order->get_shipping_state(),
                        "zip_code" => $order->get_billing_postcode()
                            ? $order->get_billing_postcode()
                            : $order->get_shipping_postcode(),
                    ],
                    // 'url' => get_site_url(),
                ];

                $args = [
                    "method" => "POST",
                    "headers" => [
                        "client_id" => $this->client_id,
                        "client_secret" => $this->client_secret,
                        "Content-Type" => "application/json",
                    ],
                    "body" => json_encode($body),
                ];

                foreach ($order->get_items() as $item_id => $item) {
                    $product = [
                        "name" => $item->get_name(),
                        "quantity" => $item->get_quantity(),
                    ];
                    array_push($args, $product);
                }

                /*
             * Your API interaction could be built with wp_remote_post()
             */
                $url = $_ENV["QPNNY_API_DEV"]; //sandbox
                if (!$this->testmode) {
                    $url = $_ENV["QPNNY_API_PROD"];
                }
                $response = wp_remote_post($url, $args);

                if (is_array($response) || is_object($response)) {
                    error_log(print_r($response, true));
                } else {
                    error_log($response);
                }

                if (!is_wp_error($response)) {
                    $body = json_decode($response["body"], true);

                    // it could be different depending on your payment processor
                    if (wp_remote_retrieve_response_code($response) == 200) {
                        //  // we received the payment
                        //  $order->payment_complete();
                        //  $order->reduce_order_stock();

                        //  // some notes to customer (replace true with false to make it private)
                        //  $order->add_order_note( 'Hey, your order is paid! Thank you!', true );

                        //  // Empty cart
                        //  $woocommerce->cart->empty_cart();

                        //  // Redirect to the thank you page
                        //  return array(
                        //    'result' => 'success',
                        //    'redirect' => $this->get_return_url( $order )
                        //  );
                        if (isset($body) && isset($body["id"])) {
                            /** EXPRESS CHECKOUT **/


                            //COMPLETE ORDER
                            $this->successCheckout();

                            $woocommerce->cart->empty_cart();


                            return [
                                "result" => "success",
                                "redirect" => $_ENV["QPNNY_SANDBOX_REDIR"] . "/" . $body["id"],
                            ];
                            exit();
                        } else {
                            if ($body["code"] === "G_E-101") {
                                wc_add_notice(
                                    "Please try again, An error has occurred",
                                    "error"
                                );
                                return;
                            } else {
                                wc_add_notice($body["message"], "error");
                                if (isset($body["details"])) {
                                    foreach ($body["details"] as $key => $error) {
                                        if (isset($error[0])) {
                                            wc_add_notice(
                                                "• " . $error[0],
                                                "error"
                                            );
                                        }
                                    }
                                }
                                return;
                            }
                        }
                    }

                    if (wp_remote_retrieve_response_code($response) == 403) {
                        wc_add_notice(
                            "Not authorized to access please contact admin site",
                            "error"
                        );

                        return;
                    } else {
                        wc_add_notice("Please try again.", "error");
                        return;
                    }
                } else {
                    wc_add_notice("Connection error.", "error");
                    return;
                }
            }
        }

        public function successCheckout()
        {
            /** EXPRESS CHECKOUT **/
            setcookie("__user_token_quickpenny__", "", time() - 1, "/");
            setcookie("__payment_method__", "", time() - 1, "/");
            setcookie("__ts_id_quickpenny__", "", time() - 1, "/");
        }

        /*
         * In case you need a webhook, like PayPal IPN etc
         */
        public function webhook()
        {
            $order_id = intval($_GET["id"]);
            $order = wc_get_order($order_id);
            $status = sanitize_text_field($_GET["status"]);
            $comment = isset($_GET["comment"]) ? sanitize_text_field($_GET["comment"]) : null;


            $order->update_status($status, $comment);

            if (isset($status) && $status == "completed") {
                $order->payment_complete();
                wc_reduce_stock_levels($order->get_id());
            }

            update_option("webhook_debug", $_GET);
        }
    }
}

// add_filter('woocommerce_checkout_fields', 'readonly_billing_account_fields', 25, 1);
// function readonly_billing_account_fields($billing_fields)
// {

//     // Only my account billing address for logged in users
//     if ((is_user_logged_in() && is_account_page()) || is_checkout()) {

//         $attr = ['readonly' => 'readonly', 'disabled' => 'disabled'];

//         $billing_fields['billing']['billing_state']['custom_attributes'] = $attr;
//     }
//     error_log(json_encode($billing_fields));
//     return $billing_fields;
// }

add_filter("woocommerce_available_payment_gateways", "qpenny_gateway_by_country");

function qpenny_gateway_by_country($gateways)
{
    // error_log(json_encode(array_keys($gateways)));

    if (is_admin()) {
        return $gateways;
    }

    if (is_wc_endpoint_url("order-pay")) {
        // Pay for order page

        $order = wc_get_order(wc_get_order_id_by_order_key($_GET["key"]));
        $country = $order->get_billing_country();
    } else {
        // Cart page
        $country = WC()->customer->get_billing_country();
    }

    if ("US" !== $country && "UM" !== $country) {
        if (isset($gateways["quickpenny"])) {
            unset($gateways["quickpenny"]);
        }
    } else {
        if (is_checkout()) {
            if (
                isset($_COOKIE["__payment_method__"]) &&
                $_COOKIE["__payment_method__"] == "quickpennyexpress"
            ) {
                $gateways = ["quickpenny" => $gateways["quickpenny"]];
            }
        }
    }

    return $gateways;
}

add_action("woocommerce_after_cart_totals", "qpenny_button_express_checkout");
function qpenny_button_express_checkout()
{


    $locale_validate = strpos(get_bloginfo('language'), 'es');
    if ($locale_validate === false) {
        //English
        $message_checkout_1 = "Don't see Quickpenny's secure browser? We will open the window again so you can complete your purchase.";
        $message_checkout_2 = "Continue";
    } else {
        $message_checkout_1 = "¿No ve el navegador seguro de Quickpenny? Abriremos la ventana nuevamente para que pueda completar su compra.";
        $message_checkout_2 = "Continuar";
    }


?>
    <div>
        <form action="" method="POST">
            <button style="width: 100%; min-width: 100%;" type="button" class="checkout-button button alt wc-forward qp-button" id="button-quick-penny-express"></button>
        </form>
    </div>

    <div id="overlay-qp-load" style="display:none" class="qp-overlay-context-popup qp-checkout-overlay">
        <a href="#" class="qp-checkout-close" aria-label="close" role="button"></a>
        <div class="qp-checkout-modal">
            <div class="qp-checkout-logo">
                <img src="<?php echo plugin_dir_url(__FILE__); ?>assets/images/loading.gif">
            </div>
            <div class="qp-checkout-message">
                <?php echo esc_html($message_checkout_1); ?>
            </div>
            <div class="qp-checkout-continue">
                <a href="#"><?php echo esc_html($message_checkout_2); ?></a>
            </div>
            <div class="qp-checkout-loader">
                <div class="qp-spinner"></div>
            </div>
        </div>
        <div class="qp-checkout-iframe-container"></div>
    </div>
<?php
}

add_action("wc_ajax_qpenny_create_order", "qpenny_create_order");

function qpenny_create_order()
{
    global $woocommerce;

    $item_product = [];

    foreach ($woocommerce->cart->get_cart() as $key => $product) {
        $_product = wc_get_product($product["product_id"]);

        $item_product[] = [
            "product_id" => $product["product_id"],
            "quantity" => $product["quantity"],
            "name" => $_product->get_name(),
            "sku" => $_product->get_sku(),
            "description" => $_product->get_description(),
            "unit_amount" => [
                "currency_code" => get_woocommerce_currency(),
                "value" => $_product->get_regular_price(),
            ],
        ];
    }

    wp_send_json([
        "success" => true,
        "data" => [
            "info_merchant" => qpenny_info_merchant(),
            "include_tax" => wc_prices_include_tax(),
            "purchase_units" => [
                "amount" => [
                    "breakdown" => [
                        "sub_total" => [
                            "currency_code" => get_woocommerce_currency(),
                            "value" =>
                            WC()->cart->get_subtotal() -
                                WC()->cart->get_discount_total(),
                        ],
                        "shipping" => [
                            "currency_code" => get_woocommerce_currency(),
                            "value" => WC()->cart->get_shipping_total(),
                        ],
                        "tax_total" => [
                            "currency_code" => get_woocommerce_currency(),
                            "value" => WC()->cart->get_total_tax(),
                        ],
                    ],
                    "currency_code" => get_woocommerce_currency(),
                    "value" => $woocommerce->cart->total,
                ],
                "shipping" => [
                    "address" => [
                        "address_line_1" => $woocommerce->customer->get_shipping_address_1(),
                        "admin_area_1" => $woocommerce->customer->get_shipping_state(),
                        "admin_area_2" => $woocommerce->customer->get_shipping_city(),
                        "country_code" => $woocommerce->customer->get_shipping_country(),
                        "postal_code" => $woocommerce->customer->get_shipping_postcode(),
                    ],
                    "name" => [
                        "full_name" =>
                        $woocommerce->customer->get_shipping_first_name() .
                            " " .
                            $woocommerce->customer->get_shipping_last_name(),
                    ],
                ],

                "items" => $item_product,
            ],
        ],
    ]);

    die();
}

function qpenny_info_merchant()
{
    $qpenny = new WC_Quickpenny_Gateway();


    $url = $_ENV["QPNNY_INFO_MERCHANT_DEV"];
    if (!$qpenny->testmode) {
        $url = $_ENV["QPNNY_INFO_MERCHANT_PROD"];
    }

    $body = null;

    $response = wp_remote_get($url . '/' . $qpenny->client_id);
    if (is_array($response) || is_object($response)) {
        error_log(print_r($response, true));
    } else {
        error_log($response);
    }

    if (!is_wp_error($response)) {

        $body = json_decode($response['body'], true);
    }


    return  $body;
}

add_action("wc_ajax_qpenny_update_order_review", "qpenny_update_order_review");
add_action(
    "wp_ajax_nopriv_qpenny_update_order_review",
    "qpenny_update_order_review"
);

function qpenny_update_order_review()
{
    global $woocommerce;
    //  error_log($_POST['country']);


    // $country = $_POST['country'] ? sanitize_text_field($_POST['country']) : null;
    $country = 'US';
    $state = isset($_POST['address']['state']) ? sanitize_text_field($_POST['address']['state']) : null;
    $postalCode = isset($_POST['address']['zip_code']) ? sanitize_text_field($_POST['address']['zip_code']) : null;
    $city = isset($_POST['address']['city']) ? sanitize_text_field($_POST['address']['city']) : null;
    $address1 = isset($_POST['address']['address']) ? sanitize_text_field($_POST['address']['address']) : null;
    // $address2 = $_POST['address2'] ? sanitize_text_field($_POST['address2']) : null;
    // $addressFirstName = $_POST['addressFirstName'] ? sanitize_text_field($_POST['addressFirstName']) : null;
    // $addressLastName = $_POST['addressLastName'] ? sanitize_text_field($_POST['addressLastName']) : null;



    WC()->customer->set_props([
        "billing_country" => wc_clean(wp_unslash($country)),
        "billing_state" => wc_clean(wp_unslash($state)),
        "billing_postcode" => wc_clean(wp_unslash($postalCode)),
        "billing_city" => wc_clean(wp_unslash($city)),
        "billing_address_1" => wc_clean(wp_unslash($address1)),
        // "billing_address_2" => wc_clean(wp_unslash($address2)),
        // "billing_first_name" => wc_clean(wp_unslash($addressFirstName)),
        // "billing_last_name" => wc_clean(wp_unslash($addressLastName))
    ]);

    if (wc_ship_to_billing_address_only()) {
        WC()->customer->set_props([
            "shipping_country" => wc_clean(wp_unslash($country)),
            "shipping_state" => wc_clean(wp_unslash($state)),
            "shipping_postcode" => wc_clean(wp_unslash($postalCode)),
            "shipping_city" => wc_clean(wp_unslash($city)),
            "shipping_address_1" => wc_clean(wp_unslash($address1)),
            // "shipping_address_2" => wc_clean(wp_unslash($address2)),
            // "shipping_first_name" => wc_clean(wp_unslash($addressFirstName)),
            // "shipping_last_name" => wc_clean(wp_unslash($addressLastName))
        ]);
    } else {
        WC()->customer->set_props([
            "shipping_country" => wc_clean(wp_unslash($country)),
            "shipping_state" => wc_clean(wp_unslash($state)),
            "shipping_postcode" => wc_clean(wp_unslash($postalCode)),
            "shipping_city" => wc_clean(wp_unslash($city)),
            "shipping_address_1" => wc_clean(wp_unslash($address1)),
            // "shipping_address_2" => wc_clean(wp_unslash($address2)),
            // "shipping_first_name" => wc_clean(wp_unslash($addressFirstName)),
            // "shipping_last_name" => wc_clean(wp_unslash($addressLastName))
        ]);
    }

    $chosen_shipping_methods = WC()->session->get("chosen_shipping_methods");

    WC()->session->set("chosen_shipping_methods", $chosen_shipping_methods);
    WC()->session->set("chosen_payment_method", "quickpenny");

    WC()->customer->save();

    // Calculate shipping before totals. This will ensure any shipping methods that affect things like taxes are chosen prior to final totals being calculated. Ref: #22708.
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_totals();

    // Get order review fragment.
    ob_start();
    woocommerce_order_review();
    $woocommerce_order_review = ob_get_clean();

    // Get cart fragment. CUSTOM
    ob_start();
    // woocommerce_order_cart();
    $woocommerce_order_cart = ob_get_clean();

    // Get checkout payment fragment.
    ob_start();
    woocommerce_checkout_payment();
    $woocommerce_checkout_payment = ob_get_clean();

    // Get messages if reload checkout is not true.
    $reload_checkout = isset(WC()->session->reload_checkout);
    if (!$reload_checkout) {
        $messages = wc_print_notices(true);
    } else {
        $messages = "";
    }

    unset(WC()->session->refresh_totals, WC()->session->reload_checkout);

    $available_payment_methods = WC()
        ->payment_gateways()
        ->get_available_payment_gateways();

    wp_send_json([
        "result" => empty($messages) ? "success" : "failure",
        "messages" => $messages,
        "reload" => $reload_checkout,
        "fragments" => apply_filters(
            "woocommerce_update_order_review_fragments",
            [
                ".woocommerce-cart-form__contents" => $woocommerce_order_cart /* CUSTOM */,
                ".woocommerce-checkout-review-order-table" => $woocommerce_order_review,
                ".woocommerce-checkout-payment" => $woocommerce_checkout_payment,
            ]
        ),
    ]);

    die();
}
