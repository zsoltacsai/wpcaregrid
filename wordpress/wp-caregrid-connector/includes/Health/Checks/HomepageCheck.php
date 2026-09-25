<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

/** The home page answers without a server error (loopback request). */
final class HomepageCheck implements Check
{
    public function run(): Finding
    {
        $response = wp_remote_get(home_url('/'), ['timeout' => 10, 'redirection' => 3, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);

        if (is_wp_error($response)) {
            // Many hosts block loopback requests; that is not a site failure.
            return new Finding('homepage', Finding::UNKNOWN, 'Home page', 'Loopback request not possible: '.$response->get_error_code(), true);
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code >= 500
            ? new Finding('homepage', Finding::FAIL, 'Home page', "HTTP {$code}", true)
            : new Finding('homepage', Finding::PASS, 'Home page', "HTTP {$code}", true);
    }
}
