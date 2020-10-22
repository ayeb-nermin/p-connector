<?php

namespace MedianetDev\PConnector;

use InvalidArgumentException;

class AuthManager
{
    /**
     * @var \MedianetDev\PConnector\Contracts\Http
     *
     * The http client
     */
    private $httpClient;

    public function __construct()
    {
        $httpClient = config('p-connector.http_client');
        $this->httpClient = new $httpClient(false);
    }

    public static function deleteTokenFor($profile)
    {
        if ('basic' !== config('p-connector.profiles.'.$profile.'.auth.auth_method', config('p-connector.auth.auth_method', 'basic'))) {
            app('db')->table(config('p-connector.table', 'p_connector'))->updateOrInsert(
                ['gateway_profile' => $profile],
                ['token' => null, 'updated_at' => date('Y-m-d H:i:s')]
            );
        }
    }

    public function getAuthenticationHeader($profile)
    {
        $token = $this->getToken($profile);
        $authMethod = config('p-connector.profiles.'.$profile.'.auth.auth_method', config('p-connector.auth.auth_method', 'basic'));
        switch ($authMethod) {
            case 'api_key':
                return [config('p-connector.profiles.'.$profile.'.auth.api_key', config(
                    'p-connector.auth.api_key',
                    'X-AUTH-TOKEN'
                )) => $token];
            case 'basic':
                return ['Authorization' => 'Basic '.$token];
            case 'bearer':
                return ['Authorization' => 'Bearer '.$token];

            default:
                throw new InvalidArgumentException(
                    'Invalid method "'.$authMethod.'".'
                );
                break;
        }
    }

    /**
     * get access token from database.
     *
     * @return string
     */
    private function getToken($profile)
    {
        if ('basic' === config('p-connector.profiles.'.$profile.'.auth.auth_method', config('p-connector.auth.auth_method', 'basic'))) {
            $auth = config('p-connector.profiles.'.$profile.'.auth.credentials', []);
            if (! array_key_exists('username', $auth) || ! array_key_exists('password', $auth)) {
                throw new InvalidArgumentException(
                    "config('p-connector.profiles.$profile.auth.credentials') array must have a username and password keys."
                );
            }

            return base64_encode($auth['username'].':'.$auth['password']);
        }

        $token = app('db')->table(config('p-connector.table', 'p_connector'))->where('gateway_profile', $profile)->first();
        if ($token && ! empty($token->token)) {
            return $token->token;
        }

        return $this->loginToGateway($profile);
    }

    /**
     * Send request to login to the gateway, save the received token and return it.
     *
     * @return string
     */
    private function loginToGateway($profile)
    {
        $result = $this->httpClient->send(
            build_url(config('p-connector.profiles.'.$profile.'.auth.login_path', config('p-connector.auth.login_path', 'login')), $profile),
            config('p-connector.profiles.'.$profile.'.auth.credentials'),
            strtoupper(config('p-connector.profiles.'.$profile.'.auth.login_http_method', config('p-connector.auth.login_http_method', 'POST'))),
            $profile,
            false
        );

        if ($result['status'] && in_array($result['response']['status_code'], config('p-connector.profiles.'.$profile.'.auth.success_login_code', config('p-connector.auth.success_login_code', [])))) {
            $token = _get(json_decode($result['response']['body']), explode('.', config('p-connector.profiles.'.$profile.'.auth.token_path', config('p-connector.auth.token_path'))), '');
            if ('string' !== gettype($token)) {
                throw new InvalidArgumentException('The returned token is not of type string (type: "'.gettype($token).'").');
            }
            app('db')->table(config('p-connector.table', 'p_connector'))->updateOrInsert(
                ['gateway_profile' => $profile],
                ['token' => $token, 'updated_at' => date('Y-m-d H:i:s')]
            );

            return $token;
        }

        return null;
    }
}
