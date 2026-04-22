<?php
/**
 * NMEP_Checkout — Production payment flow with Razorpay Route + escrow
 * @version 1.5.5 (adds nmep_checkout_pricing filter for coupons/premium discounts)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NMEP_Checkout {

    public static function init() {
        add_action( 'admin_post_nopriv_nmep_start_checkout', array( __CLASS__, 'handle_checkout_start' ) );
        add_action( 'admin_post_nmep_start_checkout',         array( __CLASS__, 'handle_checkout_start' ) );

        add_action( 'admin_post_nopriv_nmep_verify_payment', array( __CLASS__, 'handle_payment_verification' ) );
        add_action( 'admin_post_nmep_verify_payment',         array( __CLASS__, 'handle_payment_verification' ) );

        add_action( 'wp_ajax_nopriv_nmep_log_payment_failure', array( __CLASS__, 'ajax_log_payment_failure' ) );
        add_action( 'wp_ajax_nmep_log_payment_failure',         array( __CLASS__, 'ajax_log_payment_failure' ) );
    }

    /* ============================================================
       STAGE 1: CHECKOUT START
       ============================================================ */
    public static function handle_checkout_start() {
        if ( ! isset( $_POST['nmep_checkout_nonce'] ) || ! wp_verify_nonce( $_POST['nmep_checkout_nonce'], 'nmep_checkout' ) ) {
            self::redirect_with_error( 'Security check failed. Please try again.', 'checkout' );
            return;
        }

        $service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
        $tier       = isset( $_POST['tier'] ) ? sanitize_key( $_POST['tier'] ) : 'basic';

        if ( ! in_array( $tier, array( 'basic', 'standard', 'premium' ), true ) ) {
            self::redirect_with_error( 'Invalid package tier.', 'checkout' );
            return;
        }

        $service = NMEP_Services::get( $service_id );
        if ( ! $service || $service->status !== NMEP_Services::STATUS_ACTIVE ) {
            self::redirect_with_error( 'This service is not available for purchase.', 'checkout' );
            return;
        }

        $expert = NMEP_Compat::get_expert( $service->expert_id );
        if ( ! $expert ) {
            self::redirect_with_error( 'The expert for this service is no longer available.', 'checkout' );
            return;
        }

        $commission_rate = self::get_commission_rate_for_expert( $expert );

        $gross_amount = NMEP_Services::get_package_price( $service, $tier );
        if ( $gross_amount <= 0 ) {
            self::redirect_with_error( 'Invalid price for this package.', 'checkout' );
            return;
        }

        $min = (float) NMEP_Settings::get( 'min_order_amount', 199 );
        $max = (float) NMEP_Settings::get( 'max_order_amount', 9999 );
        if ( $gross_amount < $min || $gross_amount > $max ) {
            self::redirect_with_error( 'Order amount must be between ' . nmep_format_inr( $min ) . ' and ' . nmep_format_inr( $max ) . '.', 'checkout' );
            return;
        }

        $split = NMEP_Orders::calculate_split( $gross_amount, $commission_rate );

        /* ============================================================
           v1.5.5 — PRICING FILTER HOOK
           ============================================================
           Lets modules (coupons, premium-buyer discount, trusted-buyer
           discount) mutate the pricing BEFORE the order row is inserted
           and BEFORE the Razorpay order is created.

           Filter signature:
             apply_filters( 'nmep_checkout_pricing',
                 $pricing, $_POST, $service, $expert, $commission_rate );

           Contract for $pricing:
             gross_amount        — what the buyer actually pays
             original_amount     — pre-discount price (default = gross)
             discount_amount     — total discount applied (default 0)
             commission_amount   — platform commission (computed on ORIGINAL
                                    for coupons; modules may override)
             expert_amount       — expert payout (gross − commission, min 0)
             coupon_code         — string, empty if no coupon
             coupon_id           — int, 0 if no coupon
             discount_source     — 'coupon' | 'premium_buyer' | 'trusted_buyer' | ''

           A returning module MUST preserve numeric consistency:
             expert_amount = max( 0, gross_amount − commission_amount )
           The block below re-asserts that invariant defensively.
           ============================================================ */
        $pricing_default = array(
            'gross_amount'      => (float) $split['gross'],
            'original_amount'   => (float) $split['gross'],
            'discount_amount'   => 0.0,
            'commission_amount' => (float) $split['commission'],
            'expert_amount'     => (float) $split['expert'],
            'coupon_code'       => '',
            'coupon_id'         => 0,
            'discount_source'   => '',
        );

        $pricing = apply_filters( 'nmep_checkout_pricing', $pricing_default, $_POST, $service, $expert, $commission_rate );

        if ( ! is_array( $pricing ) ) {
            $pricing = $pricing_default;
        }
        $pricing = wp_parse_args( $pricing, $pricing_default );

        // Defensive clamping — never let a buggy filter produce negative or inconsistent money
        $pricing['gross_amount']      = max( 0.0, round( (float) $pricing['gross_amount'], 2 ) );
        $pricing['original_amount']   = max( $pricing['gross_amount'], round( (float) $pricing['original_amount'], 2 ) );
        $pricing['discount_amount']   = max( 0.0, round( (float) $pricing['discount_amount'], 2 ) );
        $pricing['commission_amount'] = max( 0.0, round( (float) $pricing['commission_amount'], 2 ) );
        $pricing['expert_amount']     = max( 0.0, round( (float) $pricing['expert_amount'], 2 ) );

        // Expert amount cannot exceed gross (safety) — re-assert invariant
        if ( $pricing['expert_amount'] > $pricing['gross_amount'] ) {
            $pricing['expert_amount'] = max( 0.0, round( $pricing['gross_amount'] - $pricing['commission_amount'], 2 ) );
        }

        // Re-validate the FINAL gross against min/max after discount
        if ( $pricing['gross_amount'] < $min || $pricing['gross_amount'] > $max ) {
            self::redirect_with_error(
                'Order amount after discount must be between ' . nmep_format_inr( $min ) . ' and ' . nmep_format_inr( $max ) . '.',
                'checkout'
            );
            return;
        }

        // Push filtered values back into $split so existing downstream code keeps working
        $split['gross']      = $pricing['gross_amount'];
        $split['commission'] = $pricing['commission_amount'];
        $split['expert']     = $pricing['expert_amount'];

        $buyer_name   = sanitize_text_field( $_POST['buyer_name'] ?? '' );
        $buyer_email  = sanitize_email( $_POST['buyer_email'] ?? '' );
        $buyer_phone  = sanitize_text_field( $_POST['buyer_phone'] ?? '' );
        $requirements = sanitize_textarea_field( $_POST['requirements'] ?? '' );

        if ( empty( $buyer_name ) || strlen( $buyer_name ) < 2 ) {
            self::redirect_with_error( 'Please enter your full name.', 'checkout' );
            return;
        }
        if ( ! is_email( $buyer_email ) ) {
            self::redirect_with_error( 'Please enter a valid email address.', 'checkout' );
            return;
        }
        if ( strlen( preg_replace( '/\D/', '', $buyer_phone ) ) < 10 ) {
            self::redirect_with_error( 'Please enter a valid phone number.', 'checkout' );
            return;
        }

        $order_data = array(
            'buyer_user_id'      => is_user_logged_in() ? get_current_user_id() : null,
            'buyer_name'         => $buyer_name,
            'buyer_email'        => $buyer_email,
            'buyer_phone'        => $buyer_phone,
            'expert_id'          => (int) $service->expert_id,
            'service_id'         => (int) $service->id,
            'package_tier'       => $tier,
            'service_title'      => $service->title,
            'package_title'      => NMEP_Services::get_package_title( $service, $tier ),
            'delivery_days'      => NMEP_Services::get_delivery_days( $service, $tier ),
            'revisions_allowed'  => NMEP_Services::get_revisions( $service, $tier ),
            'gross_amount'       => $split['gross'],
            'commission_rate'    => $commission_rate,
            'commission_amount'  => $split['commission'],
            'expert_amount'      => $split['expert'],
            'currency'           => 'INR',
            'buyer_requirements' => $requirements,
        );

        // v1.5.5 — persist discount details if a coupon/premium module applied one.
        // Columns are created by NMEP_Coupons::create_tables() migration.
        // Guarded insert so the wpdb call doesn't choke if migration hasn't run yet.
        if ( (float) $pricing['discount_amount'] > 0 ) {
            $order_data['original_amount']  = (float) $pricing['original_amount'];
            $order_data['coupon_code']      = sanitize_text_field( (string) $pricing['coupon_code'] );
            $order_data['coupon_discount']  = (float) $pricing['discount_amount'];
            $order_data['discount_source']  = sanitize_key( (string) $pricing['discount_source'] );

            // If the migration column doesn't exist yet, drop these keys silently.
            // We check ONCE per request and cache the result in a static.
            static $discount_cols_present = null;
            if ( $discount_cols_present === null ) {
                global $wpdb;
                $tbl = NMEP_Database::table( 'orders' );
                $discount_cols_present = (bool) $wpdb->get_var( "SHOW COLUMNS FROM $tbl LIKE 'coupon_code'" );
            }
            if ( ! $discount_cols_present ) {
                unset( $order_data['original_amount'], $order_data['coupon_code'], $order_data['coupon_discount'], $order_data['discount_source'] );
                NMEP_Logger::warning( 'Discount applied but orders table missing coupon columns; discount not persisted', array(
                    'amount' => $pricing['discount_amount'],
                ) );
            }
        }

        $order_id = NMEP_Orders::create( $order_data );
        if ( ! $order_id ) {
            self::redirect_with_error( 'Could not create order. Please try again.', 'checkout' );
            return;
        }

        $order = NMEP_Orders::get( $order_id );

        NMEP_Logger::info( 'Local order created, creating Razorpay order', array(
            'order_id'     => $order_id,
            'order_number' => $order->order_number,
            'gross'        => $gross_amount,
        ) );

        $razorpay_response = self::create_razorpay_order_with_transfer( $order, $expert );

        if ( is_wp_error( $razorpay_response ) ) {
            NMEP_Orders::update( $order_id, array(
                'status'         => NMEP_Orders::STATUS_CANCELLED,
                'payment_status' => NMEP_Orders::PAYMENT_FAILED,
                'api_response'   => wp_json_encode( $razorpay_response->get_error_data() ),
            ) );

            $error_msg = $razorpay_response->get_error_message();
            NMEP_Logger::error( 'Razorpay order creation failed', array(
                'order_id' => $order_id,
                'error'    => $error_msg,
            ) );

            self::redirect_with_error( 'Payment gateway error: ' . $error_msg, 'checkout' );
            return;
        }

        NMEP_Orders::update( $order_id, array(
            'razorpay_order_id' => sanitize_text_field( $razorpay_response['id'] ),
            'api_response'      => wp_json_encode( $razorpay_response ),
        ) );

        NMEP_Logger::info( 'Razorpay order created successfully', array(
            'order_id'          => $order_id,
            'razorpay_order_id' => $razorpay_response['id'],
        ) );

        $payment_url = add_query_arg( array(
            'order_id' => $order_id,
            'token'    => $order->view_token,
            'action'   => 'pay',
        ), nmep_get_page_url( 'checkout' ) );

        wp_safe_redirect( $payment_url );
        exit;
    }

    /**
     * Create Razorpay order with optional Route transfer.
     */
    private static function create_razorpay_order_with_transfer( $order, $expert ) {
        $linked_account = NMEP_Linked_Accounts::get_for_expert( $expert->id );
        $has_linked_account = $linked_account && ! empty( $linked_account->razorpay_account_id );

        $payload = array(
            'amount'   => nmep_to_paise( $order->gross_amount ),
            'currency' => 'INR',
            'receipt'  => $order->order_number,
            'notes'    => array(
                'order_number' => $order->order_number,
                'service_id'   => (string) $order->service_id,
                'expert_id'    => (string) $order->expert_id,
                'tier'         => $order->package_tier,
                'platform'     => 'experts.nagaland.me',
            ),
        );

        if ( $has_linked_account ) {
            $auto_release_days = (int) NMEP_Settings::get( 'auto_release_days', 5 );
            $on_hold_until = strtotime( '+' . $auto_release_days . ' days' );

            $payload['transfers'] = array(
                array(
                    'account'       => $linked_account->razorpay_account_id,
                    'amount'        => nmep_to_paise( $order->expert_amount ),
                    'currency'      => 'INR',
                    'on_hold'       => 1,
                    'on_hold_until' => $on_hold_until,
                    'notes'         => array(
                        'order_number' => $order->order_number,
                        'expert_id'    => (string) $order->expert_id,
                    ),
                ),
            );

            NMEP_Logger::info( 'Creating Razorpay order WITH Route transfer', array(
                'order_id'           => $order->id,
                'linked_account_id'  => $linked_account->razorpay_account_id,
                'expert_amount'      => $order->expert_amount,
                'commission_amount'  => $order->commission_amount,
            ) );
        } else {
            NMEP_Logger::warning( 'Creating Razorpay order WITHOUT Route transfer (no Linked Account)', array(
                'order_id'  => $order->id,
                'expert_id' => $order->expert_id,
            ) );
        }

        return NMEP_Razorpay_Client::post( '/orders', $payload );
    }

    /* ============================================================
       STAGE 2: PAYMENT VERIFICATION
       ============================================================ */
    public static function handle_payment_verification() {
        $razorpay_payment_id = sanitize_text_field( $_POST['razorpay_payment_id'] ?? '' );
        $razorpay_order_id   = sanitize_text_field( $_POST['razorpay_order_id'] ?? '' );
        $razorpay_signature  = sanitize_text_field( $_POST['razorpay_signature'] ?? '' );
        $local_order_id      = isset( $_POST['local_order_id'] ) ? (int) $_POST['local_order_id'] : 0;

        if ( empty( $razorpay_payment_id ) || empty( $razorpay_order_id ) || empty( $razorpay_signature ) || empty( $local_order_id ) ) {
            NMEP_Logger::error( 'Payment verification: missing required fields', array(
                'has_payment_id' => ! empty( $razorpay_payment_id ),
                'has_order_id'   => ! empty( $razorpay_order_id ),
                'has_signature'  => ! empty( $razorpay_signature ),
                'local_order_id' => $local_order_id,
            ) );
            self::redirect_with_error( 'Payment verification failed: missing data.', 'checkout' );
            return;
        }

        $order = NMEP_Orders::get( $local_order_id );
        if ( ! $order ) {
            NMEP_Logger::error( 'Payment verification: order not found', array( 'local_order_id' => $local_order_id ) );
            self::redirect_with_error( 'Order not found.', 'checkout' );
            return;
        }

        if ( $order->razorpay_order_id !== $razorpay_order_id ) {
            NMEP_Logger::critical( 'Payment verification: order ID mismatch (possible tampering)', array(
                'expected'       => $order->razorpay_order_id,
                'received'       => $razorpay_order_id,
                'local_order_id' => $local_order_id,
            ) );
            self::redirect_with_error( 'Order verification failed.', 'checkout' );
            return;
        }

        // Idempotency guard — mirrors NMEP_Webhooks::handle_payment_captured():186-189.
        // If the Razorpay webhook reached us first (common when the buyer's browser
        // is slow or on mobile), this order already has payment_status = captured,
        // orders_count has already been incremented, and nmep_after_payment_captured
        // has already fired once. Running the rest of this method would:
        //   - increment wp_nmep_services.orders_count a second time (the bug that
        //     produced Orders=2 for a single order on the expert dashboard)
        //   - fire duplicate confirmation emails to buyer / expert / admin
        //   - re-emit ORDER_PAID / ORDER_ACCEPTED / ORDER_IN_PROGRESS events
        //   - re-run every nmep_after_payment_captured listener (coupon usage,
        //     escrow sync, metrics, buyer-account auto-creation, etc.)
        // Short-circuit straight to the thank-you page so the buyer's UX is
        // identical whether the webhook or their browser-return won the race.
        if ( $order->payment_status === NMEP_Orders::PAYMENT_CAPTURED ) {
            NMEP_Logger::info( 'Payment verification: order already captured (webhook won the race — idempotent)', array(
                'order_id'   => $local_order_id,
                'payment_id' => $razorpay_payment_id,
            ) );
            $thank_you_url = add_query_arg( array(
                'order_id' => $local_order_id,
                'token'    => $order->view_token,
            ), nmep_get_page_url( 'order-thank-you' ) );
            wp_safe_redirect( $thank_you_url );
            exit;
        }

        $is_valid = NMEP_Razorpay_Client::verify_payment_signature(
            $razorpay_order_id, $razorpay_payment_id, $razorpay_signature
        );

        if ( ! $is_valid ) {
            NMEP_Logger::critical( 'Payment signature verification FAILED', array(
                'order_id'   => $local_order_id,
                'payment_id' => $razorpay_payment_id,
            ) );

            NMEP_Orders::update( $local_order_id, array(
                'status'         => NMEP_Orders::STATUS_CANCELLED,
                'payment_status' => NMEP_Orders::PAYMENT_FAILED,
            ) );

            self::redirect_with_error( 'Payment signature verification failed. Your card was not charged. Please contact support.', 'checkout' );
            return;
        }

        $auto_release_days = (int) NMEP_Settings::get( 'auto_release_days', 5 );
        $now = current_time( 'mysql' );
        $delivery_due = date( 'Y-m-d H:i:s', strtotime( "+{$order->delivery_days} days" ) );
        $auto_release = date( 'Y-m-d H:i:s', strtotime( "+{$auto_release_days} days" ) );

        NMEP_Orders::update( $local_order_id, array(
            'razorpay_payment_id' => $razorpay_payment_id,
            'razorpay_signature'  => $razorpay_signature,
            'status'              => NMEP_Orders::STATUS_PAID,
            'payment_status'      => NMEP_Orders::PAYMENT_CAPTURED,
            'escrow_status'       => NMEP_Orders::ESCROW_HOLDING,
            'paid_at'             => $now,
            'delivery_due_at'     => $delivery_due,
            'auto_release_at'     => $auto_release,
        ) );

        NMEP_Logger::info( 'Payment verified and captured', array(
            'order_id'   => $local_order_id,
            'payment_id' => $razorpay_payment_id,
            'amount'     => $order->gross_amount,
        ) );

        NMEP_Compat::audit_log( 'payment_captured', 'order', $local_order_id, array(
            'amount'     => $order->gross_amount,
            'payment_id' => $razorpay_payment_id,
        ) );

        // Increment service orders count
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . NMEP_Database::table( 'services' ) . " SET orders_count = orders_count + 1 WHERE id = %d",
            (int) $order->service_id
        ) );

        $order = NMEP_Orders::get( $local_order_id );
        do_action( 'nmep_after_payment_captured', $local_order_id, $order );

        if ( class_exists( 'NMEP_Events' ) ) {
            NMEP_Events::emit( NMEP_Events::ORDER_PAID, $local_order_id, $order );
            NMEP_Events::emit( NMEP_Events::ORDER_ACCEPTED, $local_order_id, $order );
            NMEP_Events::emit( NMEP_Events::ORDER_IN_PROGRESS, $local_order_id, $order );
        }

        if ( class_exists( 'NMEP_Emails' ) ) {
            NMEP_Emails::send_order_confirmation( $order );
            NMEP_Emails::send_payment_received_to_expert( $order );
            NMEP_Emails::send_admin_new_order( $order );
        }

        $thank_you_url = add_query_arg( array(
            'order_id' => $local_order_id,
            'token'    => $order->view_token,
        ), nmep_get_page_url( 'order-thank-you' ) );

        wp_safe_redirect( $thank_you_url );
        exit;
    }

    /* ============================================================
       STAGE 3: PAYMENT FAILURE LOG (AJAX)
       ============================================================ */
    public static function ajax_log_payment_failure() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'nmep_ajax' ) ) {
            wp_send_json_error( 'invalid_nonce' );
        }

        $local_order_id = isset( $_POST['local_order_id'] ) ? (int) $_POST['local_order_id'] : 0;
        $error_code     = sanitize_text_field( $_POST['error_code'] ?? '' );
        $error_desc     = sanitize_text_field( $_POST['error_description'] ?? '' );

        if ( $local_order_id ) {
            NMEP_Orders::update( $local_order_id, array( 'payment_status' => NMEP_Orders::PAYMENT_FAILED ) );
            NMEP_Logger::warning( 'Payment failed (logged via AJAX)', array(
                'order_id'   => $local_order_id,
                'error_code' => $error_code,
                'error_desc' => $error_desc,
            ) );

            if ( class_exists( 'NMEP_Events' ) ) {
                NMEP_Events::emit( NMEP_Events::PAYMENT_FAILED, $local_order_id, $error_code, $error_desc );
            }
        }

        wp_send_json_success();
    }

    /* ============================================================
       HELPERS
       ============================================================ */
    private static function get_commission_rate_for_expert( $expert ) {
        $default_rate = (float) NMEP_Settings::get( 'default_commission_rate', 15 );

        if ( ! empty( $expert->is_founding_expert ) ) {
            $founding_months = (int) NMEP_Settings::get( 'founding_expert_months', 3 );
            $founding_rate   = (float) NMEP_Settings::get( 'founding_expert_commission_rate', 0 );

            $approved_at = ! empty( $expert->approved_at ) ? strtotime( $expert->approved_at ) : 0;
            if ( $approved_at > 0 ) {
                $cutoff = strtotime( "+{$founding_months} months", $approved_at );
                if ( time() < $cutoff ) {
                    return $founding_rate;
                }
            }
        }

        return $default_rate;
    }

    private static function redirect_with_error( $message, $page = 'checkout' ) {
        $url = nmep_get_page_url( $page );
        $referer = wp_get_referer();
        if ( $referer ) {
            $url = $referer;
        }
        $url = add_query_arg( array(
            'nmep_status' => 'error',
            'nmep_msg'    => urlencode( $message ),
        ), $url );
        wp_safe_redirect( $url );
        exit;
    }

    public static function get_razorpay_popup_config( $order ) {
        if ( ! $order || empty( $order->razorpay_order_id ) ) return null;

        return array(
            'key'         => NMEP_Settings::get_api_key_id(),
            'amount'      => nmep_to_paise( $order->gross_amount ),
            'currency'    => $order->currency,
            'name'        => NMEP_Settings::get( 'merchant_name', 'Nagaland Me Experts' ),
            'description' => $order->service_title . ' (' . $order->package_title . ')',
            'image'       => NMEP_Settings::get( 'logo_url', '' ),
            'order_id'    => $order->razorpay_order_id,
            'prefill'     => array(
                'name'    => $order->buyer_name,
                'email'   => $order->buyer_email,
                'contact' => $order->buyer_phone,
            ),
            'notes'       => array(
                'order_number' => $order->order_number,
            ),
            'theme'       => array(
                'color' => NMEP_Settings::get( 'theme_color', '#0F2419' ),
            ),
            'local_order_id' => (int) $order->id,
        );
    }
}