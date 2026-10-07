<?php

namespace Fullworks_Free_Plugin_Lib\Classes;

class Security {
    /**
     * Verify nonce with specific context
     *
     * @param string $nonce_name
     * @param string $action
     * @return bool
     */
    public static function verify_nonce($nonce_name, $action) {
        if (!isset($_REQUEST[$nonce_name])) {
            return false;
        }
        $nonce = sanitize_text_field(wp_unslash($_REQUEST[$nonce_name]));
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Check rate limiting
     *
     * @param string $key_prefix
     * @param int $max_attempts
     * @param int $timeframe
     * @return bool
     */
    public static function check_rate_limit($key_prefix, $max_attempts = 5, $timeframe = 3600) {
        $ip = self::get_client_ip();
        $rate_key = "ffpl_rate_limit_{$key_prefix}_{$ip}";
        $attempts = get_transient($rate_key) ?: 0;

        if ($attempts >= $max_attempts) {
            return false;
        }

        set_transient($rate_key, $attempts + 1, $timeframe);
        return true;
    }

    /**
     * Get client IP with proxy support
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip = '127.0.0.1';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Add security headers
     */
    public static function add_security_headers() {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';");
        }
    }
}
