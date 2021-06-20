<?php

namespace Tinycdn;

use Exception;

class Api
{
    /**
     * Send request to API
     * @param  string $query     GraphQL query
     * @param  string $url       API URL
     * @param  string $token     API token
     * @param  array  $variables Array with variables. OPTIONAL
     * @return array response from API
     */
    public static function rawRequest(string $query, string $url, string $token, array $variables = []) : array
    {
        $data = ['query' => $query];
        if (!empty($variables)) {
            $data['variables'] = $variables;
        }
        $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = (function_exists('curl_init'))
            ? self::sendCurlRequest($data, $url, $token)
            : self::sendFgetsRequest($data, $url, $token);

        return self::processResponse($response);
    }

    /**
     * Send request to API via cURL
     * @param  string $data  GraphQL string with request
     * @param  string $url   API URL
     * @param  string $token API token
     * @return string        Raw response
     */
    protected static function sendCurlRequest(string $data, string $url, string $token)
    {
        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: $token",
            ],
        ];
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
    
    /**
     * Send request to API via file_get_contents
     * @param  string $data  GraphQL string with request
     * @param  string $url   API URL
     * @param  string $token API token
     * @return string        Raw response
     */
    protected static function sendFgetsRequest(string $data, string $url, string $token)
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => [
                    'Content-Type: application/json',
                    "Authorization: $token",
                ],
                'content' => $data,
            ],
        ]);

        return file_get_contents($url, false, $context);
    }

    /**
     * Encode args to GraphQL string
     * @param  array  $args assoc array with args
     * @return string Args in GraphQL string
     */
    public static function encodeArguments(array $args) : string
    {
        $res = '';

        foreach ($args as $key => $value) {
            $res .= "$key: " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',';
        }

        return trim($res, ',');
    }

    /**
     * Process response from API
     * @param  string $response Raw response from API
     * @return array
     */
    public static function processResponse($response) : array
    {
        if ($response === false) {
            throw new Exception("Response is false");
        }

        $json = json_decode($response, true);
        if (($json === false) || ($json === null)) {
            throw new Exception("Response is not valid JSON");
        }

        $json = (isset($json['data']['data']) || isset($json['data']['errors'])) ? $json['data'] : $json;

        if (!empty($json['errors'])) {
            
            $code = $json['errors'][0]['code'] ?? 0;
            throw new Exception($json['errors'][0]['message'], $code);
        }

        return $json;
    }
}
