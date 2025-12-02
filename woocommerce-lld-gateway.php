<?php
/**
 * Plugin Name: LLD WooCommerce Payment Gateway
 * Description: Liberland LLD payment gateway for WooCommerce with robust middleware verification.
 * Version: 2.0.25
 * Author: Danish Raaz
 * Requires at least: 6.0
 * Tested up to: 6.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// HPOS COMPATIBILITY
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p><strong>LLD Payment Gateway:</strong> WooCommerce not active.</p></div>';
        } );
        return;
    }

    class WC_LLD_Gateway extends WC_Payment_Gateway {

        public $merchant_address;
        public $webhook_url;
        public $network;
        public $public_key;
        public $lld_rate;
        public $gateway_base;
        public $api_base;
        public $explorer_base;
        public $enabled;
        public $title;
        public $description;
        public $debug_mode;

        private $webhook_url_default;

        public function __construct() {
            $this->id                 = 'lld_gateway';
            $this->method_title       = __( 'LLD Payment Gateway', 'woocommerce' );
            $this->method_description = __( 'Accept Liberland Dollar (LLD) payments via Liberland gateway (Testnet/Mainnet).', 'woocommerce' );
            $this->has_fields         = false;
            $this->supports           = [ 'products' ];

            $this->webhook_url_default = rest_url( 'lld-gateway/v1/webhook' );

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled          = $this->get_option( 'enabled', 'no' );
            $this->title            = $this->get_option( 'title', $this->method_title );
            $this->description      = $this->get_option( 'description', '' );
            $this->merchant_address = $this->get_option( 'merchant_address', '' );
            $this->network          = $this->get_option( 'network', 'mainnet' );
            $this->public_key       = $this->get_option( 'public_key', $this->default_public_key() );
            $this->lld_rate         = $this->get_option( 'lld_rate', '' );
            $this->webhook_url      = $this->get_option( 'webhook_url', $this->webhook_url_default );
            $this->debug_mode       = $this->get_option( 'debug_mode', 'no' ) === 'yes';

            $is_test = $this->network === 'testnet';
            $this->gateway_base     = $is_test ? 'https://testnet.liberland.org/home/wallet/gateway/' : 'https://blockchain.liberland.org/home/wallet/gateway/';
            $this->api_base         = $is_test ? 'https://staging.api.blockchain.liberland.org' : 'https://api.blockchain.liberland.org';
            $this->explorer_base    = $is_test ? 'https://testnet.liberland.org' : 'https://blockchain.liberland.org';

            $this->method_title = 'LLD Payment Gateway' . ( $is_test ? ' — Testnet' : ' — Mainnet' );
            $this->title = $this->title . ( $is_test ? ' (Testnet)' : ' (Mainnet)' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
            add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
            add_action( 'woocommerce_api_lld_webhook', [ $this, 'handle_legacy_wc_api_webhook' ] );
            add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'force_processing_status' ], 10, 3 );
            add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'add_verify_button' ] );

            add_action( 'woocommerce_order_status_processing', [ $this, 'send_payment_confirmation_emails' ], 10, 1 );
            add_action( 'woocommerce_order_status_completed', [ $this, 'send_payment_confirmation_emails' ], 10, 1 );

            wc_get_logger()->info( 'LLD Gateway v2.0.25 initialized; network=' . $this->network, [ 'source' => 'lld-gateway' ] );
        }

        public function send_payment_confirmation_emails( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->get_payment_method() !== $this->id ) return;
            if ( $order->get_meta( '_lld_emails_sent', true ) ) return;

            $lld_amount = $order->get_meta( '_lld_amount', true );
            $tx_hash = $order->get_meta( '_lld_tx_hash', true );
            $network_name = $this->network === 'testnet' ? 'Testnet' : 'Mainnet';
            $explorer_url = $this->explorer_base . '/extrinsic/' . $tx_hash;

            $admin_subject = sprintf( '[%s] LLD Payment Confirmed - Order #%d', get_bloginfo( 'name' ), $order_id );
            $admin_message = sprintf(
                "LLD payment confirmed on %s.\n\nOrder: #%d\nCustomer: %s\nAmount: %s LLD\nTx Hash: %s\nView Tx: %s\nView Order: %s\n",
                $network_name, $order_id, $order->get_formatted_billing_full_name(),
                $lld_amount, $tx_hash, $explorer_url,
                admin_url( 'post.php?post=' . $order_id . '&action=edit' )
            );
            wp_mail( get_option( 'admin_email' ), $admin_subject, $admin_message, [ 'Content-Type: text/plain; charset=UTF-8' ] );

            $customer_subject = sprintf( 'Your LLD Payment for Order #%d is Confirmed!', $order_id );
            ob_start();
            ?>
            <p>Hi <?php echo esc_html( $order->get_billing_first_name() ); ?>,</p>
            <p>Great news! Your payment of <strong><?php echo esc_html( $lld_amount ); ?> LLD</strong> has been confirmed on the <strong>Liberland <?php echo esc_html( $network_name ); ?></strong> blockchain.</p>
            <p>Your order is now being processed.</p>
            <h3>Transaction Details</h3>
            <ul>
                <li><strong>Tx Hash:</strong> <a href="<?php echo esc_url( $explorer_url ); ?>"><?php echo esc_html( $tx_hash ); ?></a></li>
                <li><strong>Amount:</strong> <?php echo esc_html( $lld_amount ); ?> LLD</li>
            </ul>
            <p><a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" style="background:#004aad;color:#fff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block;">View Your Order</a></p>
            <p>Thank you for shopping with us!</p>
            <?php
            $customer_html = ob_get_clean();

            $mailer = WC()->mailer();
            $email = new WC_Email();
            $email->set_heading( 'Payment Confirmed!' );
            $email->set_content( $customer_html );
            $email->send( $order->get_billing_email(), $customer_subject, $email->get_content(), $email->get_headers(), [] );

            $order->update_meta_data( '_lld_emails_sent', 'yes' );
            $order->save();
        }

        public function force_processing_status( $status, $order_id, $order = null ) {
            if ( ! $order ) $order = wc_get_order( $order_id );
            if ( $order && $order->get_payment_method() === $this->id && $order->has_status( 'on-hold' ) ) {
                wc_get_logger()->info( "LLD: Forcing status to processing for order $order_id", [ 'source' => 'lld-gateway' ] );
                return $order->needs_processing() ? 'processing' : 'completed';
            }
            return $status;
        }

        public function add_verify_button( $order ) {
            if ( ! $order || $order->get_payment_method() !== $this->id ) return;
            if ( $order->is_paid() ) {
                echo '<p><strong>Payment already confirmed.</strong></p>';
                return;
            }

            $order_id = $order->get_id();
            $paid_plancks = get_post_meta( $order_id, '_lld_paid_amount', true );
            ?>
            <div style="margin:15px 0;padding:10px;background:#f9f9f9;border:1px solid #ddd;">
                <button type="button" class="button button-primary" id="lld-manual-verify" data-order-id="<?php echo esc_attr( $order_id ); ?>">
                    Verify LLD Payment Now
                </button>
                <p><small>Verifies the transaction on Blcokchain).</small></p>
                <div id="lld-verify-result"></div>
            </div>
            <script>
            document.getElementById('lld-manual-verify').addEventListener('click', function() {
                const btn = this;
                const result = document.getElementById('lld-verify-result');
                btn.disabled = true;
                btn.textContent = 'Checking blockchain...';
                result.innerHTML = '<p style="color:#666;">Verifying the transaction...</p>';

                fetch('<?php echo esc_url_raw( rest_url( "lld-gateway/v1/trigger-verify/" . $order_id ) ); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify({})
                })
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (data.verified) {
                        result.innerHTML = '<p style="color:green;">Payment confirmed! Reloading...</p>';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        result.innerHTML = '<p style="color:red;">Not verified: ' + (data.message || 'No transaction found') + '</p>';
                        btn.disabled = false;
                        btn.textContent = 'Verify LLD Payment Now';
                    }
                })
                .catch(error => {
                    result.innerHTML = '<p style="color:red;">Error: ' + error.message + '</p>';
                    btn.disabled = false;
                    btn.textContent = 'Verify LLD Payment Now';
                });
            });
            </script>
            <?php
        }

        private function default_public_key() {
            return "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsnqntFgG8ZS01FJ4eoxc\n5yAaIKz+O/p9e2+r8INQV71fbtscBG0+cv+pexhYtD10tshvoqS5O3W0s7Sea1b2\nhlc1ePo0VCIRQew7csMIikALY/AIi/6swfzyxRulCPuBiNtA0tAQBplQJxRYkps6\nWf4KIAypJk/mr7JPO76VcCAtqg/chApWNBlg7qpFqfjO/N3EnHxYRmZ8/bCLml7h\nYnmjK0fH6ceQcxy1WMN0wB06tFvEzDpAJrex/ilWJvhemVH2IpT4IYjpYAPj//Cj\n8/cdjED9mx2RuP5xsp96Qr1Vk4GXhM89emp35h936fBq4i4b0nvRQ+vIUIPA5pPa\nqwIDAQAB\n-----END PUBLIC KEY-----";
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
                'network' => [
                    'title'       => __( 'Network', 'woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Choose Mainnet or Testnet. All URLs update automatically.', 'woocommerce' ),
                    'default'     => 'mainnet',
                    'options'     => [
                        'mainnet' => __( 'Mainnet', 'woocommerce' ),
                        'testnet' => __( 'Testnet', 'woocommerce' ),
                    ],
                ],
                'lld_rate' => [
                    'title' => __( 'Manual LLD Rate (optional)', 'woocommerce' ),
                    'type' => 'number',
                    'description' => __( 'USD per 1 LLD. Leave empty to auto-fetch from CoinGecko.', 'woocommerce' ),
                    'default' => '',
                    'custom_attributes' => [ 'step' => '0.00000001' ],
                ],
                'public_key' => [
                    'title'       => __( 'Webhook Public Key (PEM)', 'woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'Public key used to verify webhook signatures.', 'woocommerce' ),
                    'default'     => $this->default_public_key(),
                ],
                'webhook_url' => [
                    'title'       => __( 'Webhook URL', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Auto-filled. Only change if you use a custom endpoint.', 'woocommerce' ),
                    'default'     => $this->webhook_url_default,
                ],
                'debug_mode' => [
                    'title'       => __( 'Debug Mode', 'woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable verbose logging (for testing only)', 'woocommerce' ),
                    'default'     => 'no',
                ],
            ];
        }

        public function payment_scripts() {
            if ( is_checkout() || is_order_received_page() ) {
                wp_enqueue_script( 'qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true );
            }
        }

        // FIXED: Return LLD with decimals for wallet display
        protected function convert_to_lld_display( $amount ) {
            $usd_per_lld = $this->lld_rate && is_numeric( $this->lld_rate ) && floatval( $this->lld_rate ) > 0
                ? floatval( $this->lld_rate )
                : $this->get_coingecko_price();

            $lld = $amount / $usd_per_lld;
            return number_format( $lld, 12, '.', '' ); // e.g. 0.900900900901
        }

        private function get_coingecko_price() {
            $resp = wp_remote_get( 'https://api.coingecko.com/api/v3/simple/price?ids=liberland-lld&vs_currencies=usd', [ 'timeout' => 10 ] );
            if ( ! is_wp_error( $resp ) ) {
                $data = json_decode( wp_remote_retrieve_body( $resp ), true );
                if ( isset( $data['liberland-lld']['usd'] ) && $data['liberland-lld']['usd'] > 0 ) {
                    return floatval( $data['liberland-lld']['usd'] );
                }
            }
            return 1.0;
        }

        // FIXED: Use LLD (not plancks) in the gateway link
        protected function build_gateway_link( $order_id, $price_in_lld ) {
            $base = $this->gateway_base;
            $order = wc_get_order( $order_id );
            $callback = rawurlencode( $this->get_return_url( $order ) );
            $failure  = rawurlencode( wc_get_checkout_url() );
            $remark   = rawurlencode( 'Order #' . $order_id );
            $hook     = rawurlencode( $this->webhook_url );

            $query = http_build_query( [
                'price'    => $price_in_lld,           // LLD with decimals
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
            if ( ! $order ) {
                wc_get_logger()->error( 'LLD: process_payment order not found: ' . $order_id, [ 'source' => 'lld-gateway' ] );
                return [ 'result' => 'failure' ];
            }

            $fiat_amount = $order->get_total();

            // For wallet display
            $price_in_lld = $this->convert_to_lld_display( $fiat_amount );

            // For exact verification (plancks)
            $price_in_plancks = (string) round( $fiat_amount / $this->get_coingecko_price() * 1000000000000, 0 );

            $gateway_link = $this->build_gateway_link( $order_id, $price_in_lld );

            update_post_meta( $order_id, '_lld_payment_link', $gateway_link );
            update_post_meta( $order_id, '_lld_amount', $price_in_lld );
            update_post_meta( $order_id, '_lld_exact_amount', $price_in_plancks );
            update_post_meta( $order_id, '_lld_paid_amount', $price_in_plancks );

            $order->add_order_note( sprintf( __( 'LLD payment link created: %s LLD (%s plancks)', 'woocommerce' ), $price_in_lld, $price_in_plancks ) );
            $order->update_status( 'on-hold', __( 'Waiting for LLD payment', 'woocommerce' ) );

            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();

            wc_get_logger()->info( 'LLD process_payment generated link for order ' . $order_id . ' (LLD: ' . $price_in_lld . ', plancks: ' . $price_in_plancks . ')', [ 'source' => 'lld-gateway' ] );

            return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
        }

        public function thankyou_page( $order_id ) {
            $gateway_link = get_post_meta( $order_id, '_lld_payment_link', true );
            $amount       = get_post_meta( $order_id, '_lld_amount', true );

            if ( empty( $gateway_link ) ) return;

            echo '<div class="woocommerce-message" style="background:#fff;border:1px solid #e6e6e6;border-radius:8px;padding:20px;margin:20px 0;max-width:560px;margin-left:auto;margin-right:auto;text-align:center;">';
            echo '<h3 style="margin-top:0;color:#222;">' . esc_html__( 'Pay with Liberland Dollar (LLD)', 'woocommerce' ) . '</h3>';
            echo '<p style="color:#666;margin-bottom:12px;">' . esc_html__( 'Scan the QR code or click the button below to complete your payment.', 'woocommerce' ) . '</p>';
            echo '<div id="lld-qrcode" style="margin:0 auto 14px;width:220px;height:220px;"></div>';
            echo '<p><a class="button alt" style="background:#004aad;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;" target="_blank" href="' . esc_url( $gateway_link ) . '">' . esc_html__( 'Open Payment Page', 'woocommerce' ) . '</a></p>';
            echo '<p style="margin-top:12px;"><strong>' . esc_html__( 'Amount:', 'woocommerce' ) . '</strong> ' . esc_html( $amount ) . ' LLD</p>';
            echo '<div id="lld-status" style="margin-top:14px;font-weight:600;color:#333;">' . esc_html__( 'If you have completed the payment, then please wait your transaction is being verified on the network. A notification will be sent to your email once the confirmation is complete.', 'woocommerce' ) . '</div>';
            echo '<div id="lld-timer" style="margin-top:6px;color:#666;font-weight:normal;"></div>';
            echo '</div>';

            $check_url = esc_url_raw( rest_url( '/lld-gateway/v1/check-order/' . intval( $order_id ) ) );
            $nonce = wp_create_nonce( 'wp_rest' );
            $gateway_json = wp_json_encode( $gateway_link );
            echo "<script>
                (function(){
                    var link = {$gateway_json};
                    function renderQR() {
                        if ( typeof QRCode !== 'undefined' ) {
                            try { new QRCode(document.getElementById('lld-qrcode'), link); } catch(e) { console.error('QR render', e); }
                        } else {
                            var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
                            s.onload = function(){ try { new QRCode(document.getElementById('lld-qrcode'), link); } catch(e) { console.error('QR render', e); } };
                            document.body.appendChild(s);
                        }
                    }
                    renderQR();

                    var checkUrl = " . wp_json_encode( $check_url ) . ";
                    var nonce = " . wp_json_encode( $nonce ) . ";
                    var interval = 10000;
                    var maxSeconds = 600;
                    var elapsed = 0;
                    var timerEl = document.getElementById('lld-timer');
                    var statusEl = document.getElementById('lld-status');

                    function updateTimerDisplay() {
                        var remaining = Math.max(0, maxSeconds - elapsed);
                        var m = Math.floor(remaining / 60);
                        var s = remaining % 60;
                        if (timerEl) timerEl.textContent = 'Auto-checking for confirmation. Time left: ' + m + 'm ' + s + 's';
                    }

                    function checkOnce() {
                        var xhr = new XMLHttpRequest();
                        var url = checkUrl + '?_wpnonce=' + encodeURIComponent(nonce);
                        xhr.open('GET', url, true);
                        xhr.setRequestHeader('Accept', 'application/json');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState !== 4) return;
                            if (xhr.status === 200) {
                                try {
                                    var res = JSON.parse(xhr.responseText);
                                    if (res && res.verified) {
                                        if (statusEl) statusEl.textContent = 'Payment confirmed on Liberland Blockchain.';
                                        if (timerEl) timerEl.textContent = '';
                                        clearInterval(window.lldPollInterval);
                                        return;
                                    }
                                } catch(e) {
                                    console.error('LLD parse response', e);
                                }
                            }
                        };
                        xhr.send();
                    }

                    updateTimerDisplay();
                    checkOnce();
                    window.lldPollInterval = setInterval(function(){
                        elapsed += interval/1000;
                        updateTimerDisplay();
                        if (elapsed >= maxSeconds) {
                            clearInterval(window.lldPollInterval);
                            if (statusEl) statusEl.textContent = 'Still waiting for blockchain confirmation. We will complete your order automatically once it is verified.';
                            return;
                        }
                        checkOnce();
                    }, interval);
                })();
            </script>";
        }

        public static function register_rest_routes() {
            register_rest_route( 'lld-gateway/v1', '/webhook', [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'handle_rest_webhook' ],
                'permission_callback' => '__return_true',
            ] );

            register_rest_route( 'lld-gateway/v1', '/check-order/(?P<order_id>\d+)', [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'handle_check_order' ],
                'permission_callback' => '__return_true',
            ] );

            register_rest_route( 'lld-gateway/v1', '/trigger-verify/(?P<order_id>\d+)', [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'handle_trigger_verify' ],
                'permission_callback' => function() { return current_user_can( 'manage_woocommerce' ); },
            ] );
        }

        private function middleware_verify_exact( $order_id, $to_id ) {
            $base_endpoint = rtrim( $this->api_base, '/' ) . '/v1/verify-purchase';
            $remarks = [
                "Order #$order_id",
                (string)$order_id,
                "order #$order_id",
                "ORDER #$order_id",
                "Order $order_id",
                "Order#{$order_id}",
            ];

            $price_plancks = get_post_meta( $order_id, '_lld_paid_amount', true );
            if ( ! $price_plancks ) {
                $price_plancks = get_post_meta( $order_id, '_lld_exact_amount', true );
            }
            if ( ! $price_plancks ) {
                $price_plancks = $this->convert_to_lld_display( wc_get_order( $order_id )->get_total() );
                $price_plancks = (string) round( floatval( $price_plancks ) * 1000000000000, 0 );
            }

            foreach ( $remarks as $remark ) {
                $url = add_query_arg( [
                    'orderId' => $remark,
                    'price'   => $price_plancks,
                    'toId'    => $to_id,
                ], $base_endpoint );

                wc_get_logger()->info( "LLD: Trying remark '$remark' → $url", [ 'source' => 'lld-gateway' ] );

                $resp = wp_remote_get( $url, [ 'timeout' => 12 ] );
                if ( is_wp_error( $resp ) ) continue;

                $code = wp_remote_retrieve_response_code( $resp );
                $body = wp_remote_retrieve_body( $resp );
                if ( $code !== 200 ) continue;

                $data = json_decode( $body, true );
                if ( ! empty( $data['paid'] ) || ! empty( $data['verified'] ) ) {
                    $tx_hash = $data['txHash'] ?? $data['extrinsicHash'] ?? 'unknown';
                    update_post_meta( $order_id, '_lld_tx_hash', $tx_hash );
                    wc_get_logger()->info( "LLD: VERIFIED with remark '$remark' – Tx: $tx_hash", [ 'source' => 'lld-gateway' ] );
                    return true;
                }
            }

            wc_get_logger()->warning( "LLD: No matching transaction found for order $order_id", [ 'source' => 'lld-gateway' ] );
            return false;
        }

        public static function handle_check_order( WP_REST_Request $request ) {
            $gateway = WC()->payment_gateways->payment_gateways()['lld_gateway'] ?? null;
            if ( ! $gateway ) return [ 'verified' => false ];

            $order_id = intval( $request->get_param( 'order_id' ) );
            $order = wc_get_order( $order_id );
            if ( ! $order || $order->is_paid() ) return [ 'verified' => $order && $order->is_paid() ];

            $verified = $gateway->middleware_verify_exact( $order_id, $gateway->merchant_address );

            if ( $verified ) {
                $order->payment_complete();
                $order->add_order_note( 'LLD confirmed via polling (exact paid plancks + remark match).' );
            }

            return [ 'verified' => $verified ];
        }

        public static function handle_rest_webhook( WP_REST_Request $request ) {
            $gateway = WC()->payment_gateways->payment_gateways()['lld_gateway'] ?? null;
            if ( ! $gateway ) return new WP_REST_Response( [ 'error' => 'gateway_not_loaded' ], 500 );

            $body = $request->get_body();
            $data = json_decode( $body, true );
            $sig  = $request->get_header( 'signature' ) ?: $request->get_header( 'x-signature' );

            if ( ! $gateway->debug_mode && $sig ) {
                $key = openssl_pkey_get_public( $gateway->public_key ?: $gateway->default_public_key() );
                if ( ! $key || openssl_verify( $body, base64_decode( $sig ), $key, OPENSSL_ALGO_SHA256 ) !== 1 ) {
                    return new WP_REST_Response( [ 'error' => 'bad_sig' ], 401 );
                }
            }

            $order_id = $data['orderId'] ?? 0;
            $order = wc_get_order( $order_id );
            if ( ! $order ) return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );

            $verified = $gateway->middleware_verify_exact( $order_id, $gateway->merchant_address );

            if ( $verified ) {
                $order->payment_complete();
                return new WP_REST_Response( [ 'success' => true ], 200 );
            }

            return new WP_REST_Response( [ 'error' => 'not_verified' ], 400 );
        }

        public static function handle_trigger_verify( WP_REST_Request $request ) {
            $gateway = WC()->payment_gateways->payment_gateways()['lld_gateway'] ?? null;
            if ( ! $gateway ) return new WP_REST_Response( [ 'error' => 'gateway_not_loaded' ], 500 );

            $order_id = intval( $request->get_param( 'order_id' ) );
            $order = wc_get_order( $order_id );
            if ( ! $order || ! current_user_can( 'manage_woocommerce' ) ) {
                return new WP_REST_Response( [ 'error' => 'forbidden' ], 403 );
            }

            $verified = $gateway->middleware_verify_exact( $order_id, $gateway->merchant_address );

            if ( $verified && ! $order->is_paid() ) {
                $order->payment_complete();
                $order->add_order_note( 'LLD confirmed manually (exact paid plancks + remark match).' );
            }

            return [ 'verified' => $verified, 'message' => $verified ? 'Found (remark match)' : 'No transaction found' ];
        }

        public function handle_legacy_wc_api_webhook() {
            $payload = file_get_contents( 'php://input' );
            $req = new WP_REST_Request();
            $req->set_body( $payload );
            $req->set_header( 'signature', $_SERVER['HTTP_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '' );
            $resp = self::handle_rest_webhook( $req );
            if ( $resp instanceof WP_REST_Response ) {
                status_header( $resp->get_status() );
                echo wp_json_encode( $resp->get_data() );
                exit;
            }
            status_header( 500 );
            echo 'Error';
            exit;
        }
    }

    add_action( 'rest_api_init', [ 'WC_LLD_Gateway', 'register_rest_routes' ] );

    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_LLD_Gateway';
        return $methods;
    } );
} );