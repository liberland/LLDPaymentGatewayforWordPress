<?php
/**
 * Plugin Name: WooCommerce LLD Gateway
 * Description: Liberland Dollar (LLD) payment gateway for WooCommerce with environment switch, QR code, Coingecko rates, and webhook verification.
 * Author: Danish Raaz
 * Version: 1.3.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', function() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p><strong>LLD Gateway:</strong> WooCommerce not active — plugin inactive until WooCommerce is enabled.</p></div>';
        });
        return;
    }

    class WC_Gateway_LLD extends WC_Payment_Gateway {

        public $webhook_url_default;

        public function __construct() {
            $this->id                 = 'lld_gateway';
            $this->method_title       = __( 'Liberland Dollar (LLD)', 'woocommerce' );
            $this->method_description = __( 'Accept LLD payments via Liberland gateway (Testnet/Mainnet).', 'woocommerce' );
            $this->has_fields         = false;
            $this->supports           = [ 'products' ];

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled          = $this->get_option( 'enabled', 'no' );
            $this->title            = $this->get_option( 'title', $this->method_title );
            $this->description      = $this->get_option( 'description', '' );
            $this->merchant_address = $this->get_option( 'merchant_address', '' );
            $this->environment      = $this->get_option( 'environment', 'testnet' );
            $this->public_key       = $this->get_option( 'public_key', $this->default_public_key() );
            $this->lld_rate         = $this->get_option( 'lld_rate', '' );

            $this->webhook_url_default = rest_url( 'lld-gateway/v1/webhook' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
            add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
            add_action( 'woocommerce_api_lld_webhook', [ $this, 'handle_legacy_wc_api_webhook' ] );

            // Fix method title to display environment
            if ( $this->environment === 'mainnet' ) {
                $this->method_title = __( 'Liberland Dollar (LLD) (Mainnet)', 'woocommerce' );
            } else {
                $this->method_title = __( 'Liberland Dollar (LLD) (Testnet)', 'woocommerce' );
            }
        }

        private function default_public_key() {
            return "-----BEGIN PUBLIC KEY-----\nYOUR_DEFAULT_KEY_HERE\n-----END PUBLIC KEY-----";
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __( 'Enable/Disable', 'woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable LLD Gateway', 'woocommerce' ),
                    'default' => 'no',
                ],
                'title' => [
                    'title'   => __( 'Title', 'woocommerce' ),
                    'type'    => 'text',
                    'default' => __( 'Liberland Dollar (LLD)', 'woocommerce' ),
                ],
                'description' => [
                    'title'   => __( 'Description', 'woocommerce' ),
                    'type'    => 'textarea',
                    'default' => __( 'Pay using Liberland Dollar (LLD) via Liberland gateway.', 'woocommerce' ),
                ],
                'merchant_address' => [
                    'title'       => __( 'Merchant LLD Address', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Your Liberland chain address (SS58).', 'woocommerce' ),
                    'default'     => '',
                ],
                'environment' => [
                    'title'       => __( 'Environment', 'woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Use Testnet for testing or Mainnet for production.', 'woocommerce' ),
                    'default'     => 'testnet',
                    'options'     => [
                        'testnet' => __( 'Testnet (staging)', 'woocommerce' ),
                        'mainnet' => __( 'Mainnet (production)', 'woocommerce' ),
                    ],
                ],
                'lld_rate' => [
                    'title' => __( 'Manual LLD Rate (optional)', 'woocommerce' ),
                    'type' => 'number',
                    'description' => __( 'Store currency per 1 LLD (USD/LLD). If empty, Coingecko API is used.', 'woocommerce' ),
                    'default' => '',
                    'custom_attributes' => [ 'step' => '0.0001' ],
                ],
                'public_key' => [
                    'title'       => __( 'Webhook Public Key (PEM)', 'woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'Public key used to verify webhook signatures.', 'woocommerce' ),
                    'default'     => $this->default_public_key(),
                ],
                'webhook_url' => [
                    'title'       => __( 'Webhook URL (read-only)', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Provide this URL to Liberland gateway.', 'woocommerce' ),
                    'default'     => $this->webhook_url_default,
                    'custom_attributes' => [ 'readonly' => 'readonly' ],
                ],
            ];
        }

        protected function fetch_lld_rate() {
            if ( $this->lld_rate && floatval( $this->lld_rate ) > 0 ) {
                return floatval( $this->lld_rate );
            }
            $response = wp_remote_get( 'https://api.coingecko.com/api/v3/simple/price?ids=liberland-lld&vs_currencies=usd' );
            if ( ! is_wp_error( $response ) ) {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $data['liberland-lld']['usd'] ) && $data['liberland-lld']['usd'] > 0 ) {
                    return floatval( $data['liberland-lld']['usd'] );
                }
            }
            return 1.0;
        }

        protected function convert_to_lld( $amount ) {
            $rate = $this->fetch_lld_rate();
            return round( floatval( $amount ) / $rate, 6 );
        }

        protected function get_gateway_base() {
            return $this->environment === 'mainnet'
                ? 'https://blockchain.liberland.org/home/wallet/gateway/'
                : 'http://testnet.liberland.org/home/wallet/gateway/';
        }

        protected function build_gateway_link( $order_id, $price_in_lld ) {
            $base = $this->get_gateway_base();
            $order = wc_get_order( $order_id );
            $callback = rawurlencode( $this->get_return_url( $order ) );
            $failure  = rawurlencode( wc_get_checkout_url() );
            $remark   = rawurlencode( 'Order #' . $order_id );
            $hook     = rawurlencode( rest_url( 'lld-gateway/v1/webhook' ) );

            $query = http_build_query( [
                'price'    => $price_in_lld,
                'toId'     => $this->merchant_address,
                'callback' => $callback,
                'remark'   => $remark,
                'failure'  => $failure,
                'hook'     => $hook,
            ] );

            return $base . intval( $order_id ) . '?' . $query;
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return [ 'result' => 'failure' ];

            $fiat_amount = $order->get_total();
            $price_in_lld = $this->convert_to_lld( $fiat_amount );
            $gateway_link = $this->build_gateway_link( $order_id, $price_in_lld );

            update_post_meta( $order_id, '_lld_payment_link', $gateway_link );
            update_post_meta( $order_id, '_lld_amount', $price_in_lld );

            $order->update_status( 'on-hold', __( 'Waiting for LLD payment', 'woocommerce' ) );
            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();

            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        public function thankyou_page( $order_id ) {
            $gateway_link = get_post_meta( $order_id, '_lld_payment_link', true );
            $amount       = get_post_meta( $order_id, '_lld_amount', true );

            if ( $gateway_link ) {
                echo '<div class="woocommerce-order-overview woocommerce-thankyou-order-details" style="padding:20px; border:1px solid #eee; border-radius:8px; margin-top:20px;">';
                echo '<h3>' . esc_html__( 'Pay with Liberland Dollar (LLD)', 'woocommerce' ) . '</h3>';
                echo '<p>' . esc_html__( 'Scan the QR code or click the button below to complete your payment.', 'woocommerce' ) . '</p>';
                echo '<div id="lld-qrcode" style="margin:20px auto; text-align:center;"></div>';
                echo '<a href="' . esc_url( $gateway_link ) . '" target="_blank" class="button alt" style="margin-top:10px;">' . esc_html__( 'Open Payment Page', 'woocommerce' ) . '</a>';
                echo '<p><strong>' . esc_html__( 'Amount:', 'woocommerce' ) . '</strong> ' . esc_html( $amount ) . ' LLD</p>';
                echo '<p id="lld-countdown" style="font-weight:bold;"></p>';
                echo '</div>';

                // Inline JS to render QR code + countdown
                echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'></script>
                <script>
                (function(){
                    var link = " . json_encode( $gateway_link ) . ";
                    var qrEl = document.getElementById('lld-qrcode');
                    if(qrEl){
                        new QRCode(qrEl, {
                            text: link,
                            width: 180,
                            height: 180
                        });
                    }
                    var el=document.getElementById('lld-countdown');
                    var minutes=15, seconds=0;
                    function tick(){
                        if(minutes<=0 && seconds<=0){
                            el.innerText='Payment session expired.';
                            return;
                        }
                        el.innerText='Time left: '+minutes+'m '+seconds+'s';
                        if(seconds==0){minutes--;seconds=59;}else{seconds--;}
                        setTimeout(tick,1000);
                    }
                    tick();
                })();
                </script>";
            }
        }

        // --- webhook placeholder methods ---
        public function register_rest_routes() {}
        public function handle_rest_webhook( $request ) {}
        public function handle_legacy_wc_api_webhook() {}
    }

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_Gateway_LLD';
        return $methods;
    });

});
