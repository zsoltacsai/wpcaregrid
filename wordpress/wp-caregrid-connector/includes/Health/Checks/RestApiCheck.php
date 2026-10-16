<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

/** The REST API index responds (loopback request). */
final class RestApiCheck implements Check
{
    public function run(): Finding
    {
        $response = wp_remote_get(rest_url(), ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 1048576, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);

        if (is_wp_error($response)) {
            return new Finding('rest_api', Finding::UNKNOWN, 'REST API', 'Loopback request not possible: '.$response->get_error_code());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return match (true) {
            $code >= 200 && $code < 300 => new Finding('rest_api', Finding::PASS, 'REST API', "HTTP {$code}"),
            in_array($code, [401, 403], true) => new Finding('rest_api', Finding::WARNING, 'REST API', "Restricted (HTTP {$code})"),
            default => new Finding('rest_api', Finding::FAIL, 'REST API', "HTTP {$code}"),
        };
    }
}
