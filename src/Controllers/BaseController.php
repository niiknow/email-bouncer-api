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

    /**
     * Validate if exist is valid.
     *
     * @param  String  $email    the email to validate
     * @param  boolean $checkDns true to check for MX record
     * @return boolean           false if email is not valid
     */
    public function isValidEmail($email, $checkDns = false)
    {
        $isValid = filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($isValid && $checkDns) {
            // check valid MX record
            list($name, $domain) = explode('@', $email);
            return checkdnsrr($domain, 'MX'))
        }

        return $isValid;
    }

    /**
     * Valid if email actually exists at destination email server.
     *
     * @param  String  $email this is the email
     * @return boolean        false means is invalid or does not exists
     */
    public function isValidInbox($email)
    {
        $result = $this->isValidEmail($email, true);

        if (!$result) {
            return $result;
        }

        list($name, $domain) = explode('@', $email);
        // check SMTP query
        $max_conn_time = 30;
        $sock          = '';
        $port          = 25;
        $max_read_time = 5;
        $users         = $name;
        $hosts         = array();
        $mxweights     = array();

        getmxrr($domain, $hosts, $mxweights);
        $mxs = array_combine($hosts, $mxweights);
        asort($mxs, SORT_NUMERIC);
        $mxs[$domain] = 100;
        $timeout      = $max_conn_time / count($mxs);

        // try to check each host
        while (list($host) = each($mxs)) {
            // connect to SMTP server
            if ($sock = fsockopen($host, $port, $errno, $errstr, (float) $timeout)) {
                stream_set_timeout($sock, $max_read_time);
                break;
            }
        }

        // get TCP socket
        if ($sock) {
            $reply = fread($sock, 2082);
            preg_match('/^([0-9]{3}) /ims', $reply, $matches);
            $code = isset($matches[1]) ? $matches[1] : '';

            if ($code != '220') {
                return $result;
            }

            // initial SMTP connection
            $msg = "HELO ".$domain;
            fwrite($sock, $msg."\r\n");
            $reply = fread($sock, 2082);

            // sender call
            $msg = "MAIL FROM: <".$name.'@'.$domain.">";
            fwrite($sock, $msg."\r\n");
            $reply = fread($sock, 2082);

            // ask to receiver
            $msg = "RCPT TO: <".$name.'@'.$domain.">";
            fwrite($sock, $msg."\r\n");
            $reply = fread($sock, 2082);

            // get response
            preg_match('/^([0-9]{3}) /ims', $reply, $matches);
            $code = isset($matches[1]) ? $matches[1] : '';

            if ($code == '250') {
                // email address accepted : 250
                $result = true;
            } elseif($code == '451' || $code == '452') {
                //email address greylisted : 451
                $result = true;
            } else {
                $result = false;
            }

            //quit SMTP connection
            $msg = "quit";
            fwrite($sock, $msg."\r\n");
            //close socket
            fclose($sock);
        }

        return $result;
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
