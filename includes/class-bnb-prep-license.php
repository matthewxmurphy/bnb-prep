<?php
/**
 * BNB Prep — soft-license client (stub).
 *
 * Licensing law for every product in this fleet:
 *   - An installed copy NEVER stops working over a license. No remote
 *     kill switch, no feature lockout, no "phone home or die".
 *   - A license gates UPGRADES ONLY: whether this install may download
 *     and apply newer versions through the update feed.
 *
 * This stub registers the site with my.net30hosting.com on activation
 * and records the answer. Registration failure (offline, DNS, 4xx/5xx)
 * is stored and otherwise ignored: the plugin keeps working in full.
 * The only consumer of license state is the update checker.
 */

if (!class_exists('BNB_Prep_License')) {
    class BNB_Prep_License
    {
        const PRODUCT = 'bnb-prep';
        const REGISTER_URL = 'https://my.net30hosting.com/api/v1/license/register';
        const OPTION = 'bnb_prep_license';

        /**
         * Stored license state.
         *
         * @return array{status:string,checked:string,detail:string}
         */
        public static function state()
        {
            $default = array('status' => 'unregistered', 'checked' => '', 'detail' => '');
            if (!function_exists('get_option')) {
                return $default;
            }
            $stored = get_option(self::OPTION, array());
            return is_array($stored) ? array_merge($default, $stored) : $default;
        }

        /**
         * Register this install with the license server. Never throws,
         * never blocks activation, and the result only feeds the
         * update gate below.
         */
        public static function register_activation()
        {
            $state = array(
                'status'  => 'unregistered',
                'checked' => gmdate('c'),
                'detail'  => '',
            );
            if (function_exists('wp_remote_post') && function_exists('home_url')) {
                $payload = array(
                    'product' => self::PRODUCT,
                    'version' => defined('BNB_PREP_VERSION') ? BNB_PREP_VERSION : '2026.10.31',
                    'site'    => home_url(),
                );
                $response = wp_remote_post(self::REGISTER_URL, array(
                    'timeout' => 5,
                    'body'    => $payload,
                ));
                if (is_wp_error($response)) {
                    // Offline or unreachable: stay unregistered, stay working.
                    $state['detail'] = 'registration skipped: ' . $response->get_error_message();
                } else {
                    $code = (int) wp_remote_retrieve_response_code($response);
                    $body = json_decode((string) wp_remote_retrieve_body($response), true);
                    if ($code >= 200 && $code < 300 && is_array($body) && !empty($body['license_key'])) {
                        $state['status'] = 'active';
                        $state['detail'] = 'registered';
                        $state['license_key'] = (string) $body['license_key'];
                    } else {
                        $state['detail'] = 'registration answered HTTP ' . $code;
                    }
                }
            }
            if (function_exists('update_option')) {
                update_option(self::OPTION, $state, false);
            }
            return $state;
        }

        /**
         * The ONLY thing a license controls: may this install pull
         * upgrades from the update feed? Everything else in the plugin
         * ignores license state entirely.
         */
        public static function updates_allowed()
        {
            $state = self::state();
            return isset($state['status']) && $state['status'] === 'active';
        }
    }
}
