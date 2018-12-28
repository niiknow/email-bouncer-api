<?php

namespace App\Controllers;

class BaseController
{
    public function __construct(\Base $f3, array $params = [])
    {
        $this->f3     = $f3;
        $this->params = $params;
        $this->cache  = \Cache::instance();
    }

    public function getStorageDir()
    {
        return trim($this->getOrDefault('STORAGE', '../storage/'), '/') . '/';
    }

    public function getOrDefault($key, $default = null)
    {
        $rst = $this->f3->get($key);
        if (!isset($rst)) {
            return $default;
        }
        return $rst;
    }

    /**
     * echo json
     * @param object $data
     * @param array  $params
     */
    public function json($data, array $params = [])
    {
        $f3      = $this->f3;
        $body    = json_encode($data, JSON_PRETTY_PRINT);
        $headers = array_key_exists('headers', $params) ? $params['headers'] : [];

        // set ttl
        $ttl = (int) array_key_exists('ttl', $params) ? $params['ttl'] : -1; // cache for $ttl seconds
        if (empty($ttl)) {
            $ttl = -1;
        }

        $headers = array_merge($headers, [
            'content-type'                     => 'application/json; charset=utf-8',
            'Access-Control-Expose-Headers'    =>
            array_key_exists('acl_expose_headers', $params) ? $params['acl_expose_headers'] : null,
            'Access-Control-Allow-Methods'     =>
            array_key_exists('acl_http_methods', $params) ? $params['acl_http_methods'] : null,
            'Access-Control-Allow-Origin'      =>
                array_key_exists('acl_origin', $params) ? $params['acl_origin'] : '*',
            'Access-Control-Allow-Credentials' =>
            array_key_exists('acl_credentials', $params) && !empty($params['acl_credentials']) ? 'true' : 'false',
            'ETag'                             => array_key_exists('etag', $params) ? $params['etag'] : md5($body),
            'Content-Length'                   => \UTF::instance()->strlen($body),
        ]);

        // send the headers + data
        if ($ttl > 0) {
            $f3->expire($ttl);
        }

        // default status is 200 - OK
        $f3->status(array_key_exists('http_status', $params) ? $params['http_status'] : 200);

        header_remove('X-Powered-By');
        ksort($headers);
        foreach ($headers as $header => $value) {
            if (!isset($value)) {
                continue;
            }

            header($header . ': ' . $value);
        }

        echo $body;
    }
}
