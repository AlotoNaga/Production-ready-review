<?php
/**
 * NMEP_Buyer_Account_Auto
 *
 * Creates (or links) a WordPress user for a buyer after a successful
 * guest checkout, so the buyer can message experts, manage their
 * Premium Buyer subscription, and view order history.
 *
 * WHY THIS EXISTS
 *   Guest checkout is enabled (enable_guest_checkout = true) and the
 *   order form collects buyer_name, buyer_email, buyer_phone,
 *   requirements — but NMEP_Checkout never creates a wp_user for the
 *   buyer. Result: the buyer pays, but:
 *     - /my-account/ has nothing to show them
 *     - /expert-inbox/ rejects them (requires is_user_logged_in())
 *     - /buyer-premium/ purchase is impossible (requires login)
 *     - /my-orders/ is empty
 *   Every paying buyer should end the flow with a real account they
 *   can log into.
 *
 * HOW IT WORKS
 *   Listens to 'nmep_after_payment_captured' — the action fired by
 *   NMEP_Checkout::handle_payment_verification() ONLY when a payment
 *   is signature-verified and captured. Failed or pending payments
 *   never trigger account creation, so we don't spam abandoned-cart
 *   email addresses.
 *
 *   For each captured order:
 *     1. If buyer_user_id is already set (logged-in checkout), done.
 *     2. If a WP user already exists for buyer_email, stamp that
 *        user's ID back onto the order (retroactive link) — we DO
 *        NOT touch the existing user's role or password.
 *     3. Otherwise, wp_create_user() with role nme_buyer, stamp the
 *        new user_id onto the order, and email a "set your password"
 *        link so the buyer can activate the account.
 *
 * SAFETY / IDEMPOTENCY
 *   - Multiple captures of the same order (webhook retry + sync
 *     verify, or admin re-fire) won't create duplicate accounts — we
 *     short-circuit on existing buyer_user_id and on existing users
 *     by email.
 *   - Race-safe: a short transient lock keyed on email prevents two
 *     concurrent workers from both running wp_create_user.
 *   - Never fails the payment flow: every error path is logged and
 *     swallowed. The order stays valid even if account provisioning
 *     fails — admin can retry from the user admin or via a future
 *     tooling pass.
 *
 * @package NMEPayments
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NMEP_Buyer_Account_Auto {

    const LOCK_PREFIX   = 'nmep_buyer_acct_lock_';
    const LOCK_TTL      = 30; // seconds
    const META_FLAG     = 'nmep_auto_created_from_checkout';
    const META_PHONE    = 'nmep_phone';

    // One-shot backfill for orders captured BEFORE this class was
    // wired into the bootstrap. Flips to 'done' once every eligible
    // order has been processed, so later admin loads are a single
    // get_option() call and nothing else.
    const BACKFILL_OPTION = 'nmep_buyer_account_auto_backfill_v1';
    const BACKFILL_BATCH  = 25;

    public static function init() {
        // Priority 20 — after NMEP_Emails sends order confirmation at
        // default priority 10, so the "set your password" email lands
        // second, not first (less confusing for the buyer).
        add_action( 'nmep_after_payment_captured', array( __CLASS__, 'ensure_account' ), 20, 2 );

        // Retroactively link/create accounts for captured orders that
        // predate this hook being registered. Admin-only so it never
        // runs during a buyer's page render, and self-disables after
        // the final batch.
        add_action( 'admin_init', array( __CLASS__, 'maybe_backfill' ) );
    }

    /**
     * Processes a small batch of captured orders that still have an
     * empty buyer_user_id, reusing ensure_account() so the side effects
     * are identical to the live flow (lock + idempotency + logging +
     * set-password email only for newly created users — linking to an
     * existing WP user by email sends no email).
     *
     * Runs on admin_init. After the final batch, writes 'done' to the
     * option so the query never runs again.
     */
    public static function maybe_backfill() {
        if ( get_option( self::BACKFILL_OPTION ) === 'done' ) {
            return;
        }
        if ( ! class_exists( 'NMEP_Orders' ) || ! class_exists( 'NMEP_Database' ) ) {
            return;
        }

        global $wpdb;
        $tbl = NMEP_Database::table( 'orders' );
        $captured_statuses = array(
            NMEP_Orders::STATUS_PAID,
            NMEP_Orders::STATUS_IN_PROGRESS,
            NMEP_Orders::STATUS_DELIVERED,
            NMEP_Orders::STATUS_REVISION,
            NMEP_Orders::STATUS_COMPLETED,
            NMEP_Orders::STATUS_AUTO_RELEASED,
        );
        $in = "'" . implode( "','", array_map( 'esc_sql', $captured_statuses ) ) . "'";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tbl
             WHERE ( buyer_user_id IS NULL OR buyer_user_id = 0 )
               AND buyer_email <> ''
               AND status IN ($in)
             ORDER BY id ASC
             LIMIT %d",
            (int) self::BACKFILL_BATCH
        ) );

        if ( empty( $rows ) ) {
            update_option( self::BACKFILL_OPTION, 'done', false );
            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::info( 'Buyer account backfill complete (no more eligible orders)' );
            }
            return;
        }

        foreach ( $rows as $order ) {
            self::ensure_account( (int) $order->id, $order );
        }

        if ( class_exists( 'NMEP_Logger' ) ) {
            NMEP_Logger::info( 'Buyer account backfill batch processed', array(
                'processed' => count( $rows ),
            ) );
        }
    }

    /**
     * Hook callback. Create or link a WP user for this order.
     *
     * @param int         $order_id
     * @param object|null $order    Row from NMEP_Orders::get()
     */
    public static function ensure_account( $order_id, $order ) {
        if ( ! is_object( $order ) ) {
            return;
        }

        // Already linked — either a logged-in checkout, or a prior
        // invocation of this handler already ran.
        if ( ! empty( $order->buyer_user_id ) ) {
            return;
        }

        $email = isset( $order->buyer_email ) ? sanitize_email( (string) $order->buyer_email ) : '';
        if ( ! is_email( $email ) ) {
            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::warning( 'Skip buyer account creation: invalid email', array(
                    'order_id' => (int) $order_id,
                    'email'    => $email,
                ) );
            }
            return;
        }

        // Narrow race lock keyed on the email. Prevents webhook +
        // sync-verify from both racing into wp_create_user().
        $lock_key = self::LOCK_PREFIX . md5( strtolower( $email ) );
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, self::LOCK_TTL );

        try {
            $user_id = self::resolve_or_create_user(
                $email,
                (string) ( $order->buyer_name  ?? '' ),
                (string) ( $order->buyer_phone ?? '' )
            );
            if ( ! $user_id ) {
                return;
            }

            // Stamp the user_id onto the order so inbox, my-orders,
            // premium, and future refund/dispute flows can find the
            // buyer by user_id rather than by fragile email lookup.
            NMEP_Orders::update( (int) $order_id, array( 'buyer_user_id' => (int) $user_id ) );

            // Store phone on the user so premium checkout, SMS, and
            // future order forms can prefill without re-asking.
            if ( ! empty( $order->buyer_phone ) ) {
                update_user_meta( $user_id, self::META_PHONE, sanitize_text_field( (string) $order->buyer_phone ) );
            }

            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::info( 'Buyer account auto-linked to order', array(
                    'order_id' => (int) $order_id,
                    'user_id'  => (int) $user_id,
                    'email'    => $email,
                ) );
            }
        } catch ( Exception $e ) {
            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::error( 'Buyer account creation threw', array(
                    'order_id' => (int) $order_id,
                    'error'    => $e->getMessage(),
                ) );
            }
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Return an existing user_id for this email, or create a new
     * nme_buyer user and return its ID. Returns 0 on unrecoverable
     * failure (never throws out of this call).
     */
    private static function resolve_or_create_user( $email, $name, $phone ) {
        $existing = get_user_by( 'email', $email );
        if ( $existing instanceof WP_User ) {
            // Existing user — do NOT change password, role, or
            // anything else. Just return the ID so the order links
            // to their existing account.
            return (int) $existing->ID;
        }

        // Build a username from the local-part of the email, dedupe
        // on collision. sanitize_user strips everything WordPress
        // considers unsafe for a login.
        $local = (string) substr( $email, 0, (int) strpos( $email, '@' ) );
        $base_login = sanitize_user( $local, true );
        if ( $base_login === '' ) {
            $base_login = 'buyer';
        }
        $login = $base_login;
        $i     = 1;
        while ( username_exists( $login ) ) {
            $i++;
            $login = $base_login . $i;
            if ( $i > 99 ) {
                // Pathological collision — fall back to random suffix
                // so we never infinite-loop.
                $login = $base_login . '_' . wp_generate_password( 6, false );
                break;
            }
        }

        $password = wp_generate_password( 20, true, true );
        $user_id  = wp_create_user( $login, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::error( 'wp_create_user failed for buyer', array(
                    'email' => $email,
                    'error' => $user_id->get_error_message(),
                ) );
            }
            return 0;
        }

        // Assign the nme_buyer role (replaces default "subscriber"
        // so the user gets nme_place_orders / nme_leave_reviews caps).
        $u = new WP_User( $user_id );
        if ( class_exists( 'NME_Roles' ) ) {
            $u->set_role( NME_Roles::ROLE_BUYER );
        } else {
            $u->set_role( 'nme_buyer' );
        }

        // Fill first/last/display name from buyer_name if present.
        $name = trim( $name );
        if ( $name !== '' ) {
            $parts      = preg_split( '/\s+/', $name, 2 );
            $first_name = isset( $parts[0] ) ? $parts[0] : '';
            $last_name  = isset( $parts[1] ) ? $parts[1] : '';
            wp_update_user( array(
                'ID'           => $user_id,
                'first_name'   => sanitize_text_field( $first_name ),
                'last_name'    => sanitize_text_field( $last_name ),
                'display_name' => sanitize_text_field( $name ),
            ) );
        }

        // Mark the user as auto-provisioned from checkout. Useful for
        // (a) analytics, (b) future CTAs on /my-account/ that nudge
        // the buyer to set their password if they haven't yet.
        update_user_meta( $user_id, self::META_FLAG, time() );

        // Email the buyer a "set your password" link. Uses the WP
        // core password-reset key machinery so the link expires and
        // honours the site's auth keys/salts.
        self::send_set_password_email( $user_id, $email, $name );

        return (int) $user_id;
    }

    /**
     * Send a welcome / set-your-password email to the new buyer.
     *
     * We use get_password_reset_key() + a hand-written message rather
     * than wp_new_user_notification() because the latter also emails
     * the admin for every signup (noisy on a marketplace) and its
     * buyer-facing copy is geared toward site-invitation flows, not
     * post-purchase onboarding.
     */
    private static function send_set_password_email( $user_id, $email, $name ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            if ( class_exists( 'NMEP_Logger' ) ) {
                NMEP_Logger::warning( 'Failed to generate password reset key for new buyer', array(
                    'user_id' => (int) $user_id,
                    'error'   => $key->get_error_message(),
                ) );
            }
            return;
        }

        $reset_url = network_site_url(
            'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ),
            'login'
        );

        $first     = $name !== '' ? strtok( $name, ' ' ) : '';
        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

        $subject = sprintf( 'Welcome to %s — set your password', $site_name );

        $body  = ( $first !== '' ? "Hi $first,\n\n" : "Hi,\n\n" );
        $body .= "Thanks for your order on $site_name.\n\n";
        $body .= "We've created an account for you so you can:\n";
        $body .= "  - Message the expert about your order\n";
        $body .= "  - Track your order status and delivery\n";
        $body .= "  - Unlock Premium Buyer benefits (discounts + priority support)\n\n";
        $body .= "Set your password here to log in:\n";
        $body .= $reset_url . "\n\n";
        $body .= "Your login email is: " . $email . "\n\n";
        $body .= "— The $site_name team";

        wp_mail( $email, $subject, $body );
    }
}