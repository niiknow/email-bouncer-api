<?php

namespace Tests\Support;

/**
 * State for the namespaced function overrides in functions.php.
 * Lets tests control DNS / SMTP / HTTP behaviour without touching the network,
 * and records what the controllers sent.
 */
final class Net
{
    /** @var string[] domains for which checkdnsrr() returns false */
    public static array $noMxDomains = [];

    /** @var array<string,int> MX host => weight returned by getmxrr() */
    public static array $mx = [];

    /** @var string[] "host:port" entries that fsockopen() can connect to */
    public static array $listening = [];

    /** @var string[] "host:port" connection attempts, in order */
    public static array $attempts = [];

    /** @var array<int,string|false> queued responses returned by fread() */
    public static array $replies = [];

    /** @var string[] data written with fwrite() */
    public static array $written = [];

    public static int $timeoutSet = 0;

    public static bool $closed = false;

    /** @var string[] URLs fetched with file_get_contents() */
    public static array $fetched = [];

    /** @var string[] headers sent with header() */
    public static array $headers = [];

    /** @var array<int,string|null> header_remove() calls */
    public static array $removedHeaders = [];

    public static function reset(): void
    {
        self::$noMxDomains    = [];
        self::$mx             = [];
        self::$listening      = [];
        self::$attempts       = [];
        self::$replies        = [];
        self::$written        = [];
        self::$timeoutSet     = 0;
        self::$closed         = false;
        self::$fetched        = [];
        self::$headers        = [];
        self::$removedHeaders = [];
    }

    /**
     * @return array<string,string> header name => value
     */
    public static function headerMap(): array
    {
        $map = [];
        foreach (self::$headers as $line) {
            [$name, $value]     = explode(': ', $line, 2);
            $map[strtolower($name)] = $value;
        }

        return $map;
    }
}
