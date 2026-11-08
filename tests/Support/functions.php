<?php

/*
 * Unqualified function calls inside App\Controllers resolve to these
 * namespaced versions first, which keeps the tests off the network.
 */

namespace App\Controllers;

use Tests\Support\Net;

function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return !in_array($hostname, Net::$noMxDomains, true);
}

function getmxrr(string $hostname, &$hosts, &$weights = null): bool
{
    $hosts   = array_keys(Net::$mx);
    $weights = array_values(Net::$mx);

    return count($hosts) > 0;
}

function fsockopen(
    string $hostname,
    int $port = -1,
    &$error_code = null,
    &$error_message = null,
    ?float $timeout = null
) {
    $target           = $hostname . ':' . $port;
    Net::$attempts[]  = $target;

    if (!in_array($target, Net::$listening, true)) {
        $error_code    = 61;
        $error_message = 'Connection refused';

        return false;
    }

    return \fopen('php://memory', 'r+');
}

function stream_set_timeout($stream, int $seconds, int $microseconds = 0): bool
{
    Net::$timeoutSet = $seconds;

    return true;
}

function fread($stream, int $length): string|false
{
    return count(Net::$replies) ? array_shift(Net::$replies) : false;
}

function fwrite($stream, string $data, ?int $length = null): int|false
{
    Net::$written[] = $data;

    return strlen($data);
}

function fclose($stream): bool
{
    Net::$closed = true;

    return \fclose($stream);
}

function file_get_contents(string $filename, ...$rest): string|false
{
    Net::$fetched[] = $filename;

    return '<ConfirmSubscriptionResponse/>';
}

function header(string $header, bool $replace = true, int $response_code = 0): void
{
    Net::$headers[] = $header;
}

function header_remove(?string $name = null): void
{
    Net::$removedHeaders[] = $name;
}
