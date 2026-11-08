<?php

namespace Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Boots the real front controller (public/index.php) with PHP's built-in
 * web server and talks to it over HTTP. Only endpoints that need no DNS or
 * SMTP lookups are exercised, so this runs offline.
 */
class ServerTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static string $baseUrl;

    private static string $configFile;

    private static string $dbFile;

    private static string $logFile;

    public static function setUpBeforeClass(): void
    {
        $id               = getmypid();
        self::$dbFile     = '/storage/data/e2e-' . $id . '.sqlite';
        self::$configFile = sys_get_temp_dir() . '/bouncer-e2e-' . $id . '.ini';
        self::$logFile    = sys_get_temp_dir() . '/bouncer-e2e-' . $id . '.log';

        // shipped example config, pointed at a throwaway sqlite file with caching off
        $config = file_get_contents(APP_PATH . '/.env.example.ini');
        $config = preg_replace('/^db_file=.*$/m', 'db_file=' . self::$dbFile, $config);
        $config = preg_replace('/^cache=.*$/m', 'cache=false', $config);
        file_put_contents(self::$configFile, $config);

        $port          = self::freePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $env = array_merge(getenv(), ['APP_CONFIG' => self::$configFile]);
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', 'public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$logFile, 'a'], 2 => ['file', self::$logFile, 'a']],
            $pipes,
            APP_PATH,
            $env
        );

        // wait for the server to accept connections
        for ($i = 0; $i < 100; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);

                return;
            }
            usleep(50_000);
        }

        self::fail('built-in server did not start: ' . @file_get_contents(self::$logFile));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        @unlink(APP_PATH . self::$dbFile);
        @unlink(self::$configFile);
        @unlink(self::$logFile);
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name   = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr(strrchr($name, ':'), 1);
    }

    /**
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function http(string $method, string $path, ?string $body = null): array
    {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'content'       => $body ?? '',
            'header'        => "Content-Type: application/json\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]]);

        $content = file_get_contents(self::$baseUrl . $path, false, $context);
        $this->assertNotFalse($content, "request failed: $method $path");

        $responseHeaders = http_get_last_response_headers();
        preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0], $m);

        $headers = [];
        foreach (array_slice($responseHeaders, 1) as $line) {
            [$name, $value]                    = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($name)] = $value;
        }

        return ['status' => (int) $m[1], 'headers' => $headers, 'body' => $content];
    }

    private function json(string $method, string $path, ?string $body = null, int $status = 200): mixed
    {
        $res = $this->http($method, $path, $body);
        $this->assertSame($status, $res['status'], "$method $path: " . $res['body']);
        $this->assertStringStartsWith('application/json', $res['headers']['content-type']);

        return json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testHealthCheck(): void
    {
        $res = $this->http('GET', '/');

        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertSame('"OK"', $res['body']);
        $this->assertSame((string) strlen($res['body']), $res['headers']['content-length']);
        $this->assertSame('*', $res['headers']['access-control-allow-origin']);
        $this->assertArrayNotHasKey('x-powered-by', $res['headers']);
    }

    public function testPurge(): void
    {
        $this->assertSame('OK', $this->json('GET', '/?purge=true'));
    }

    public function testBounceAndRemove(): void
    {
        $this->assertSame(200, $this->http('GET', '/api/v1/bounces/hard?email=e2e-hard@example.com')['status']);
        $this->assertSame(200, $this->http('POST', '/api/v1/bounces/soft?email=e2e-soft@example.com', 'x')['status']);
        $this->assertSame(200, $this->http('GET', '/api/v1/bounces/complaint?email=e2e-c@example.com')['status']);

        $out = $this->json('GET', '/api/v1/bounces/remove?emails=e2e-hard@example.com,e2e-soft@example.com,e2e-c@example.com');
        $this->assertSame(
            ['emails' => ['e2e-hard@example.com', 'e2e-soft@example.com', 'e2e-c@example.com'], 'sendable' => true],
            $out
        );
    }

    public function testStatRejectsInvalidEmailWith422(): void
    {
        $out = $this->json('GET', '/api/v1/bounces/stat?email=not-an-email', null, 422);

        $this->assertFalse($out['sendable']);
    }

    public function testAwsSesRequiresTypeWith400(): void
    {
        $out = $this->json('POST', '/api/v1/bounces/aws-ses', '{}', 400);

        $this->assertSame("Key 'Type' not found in payload ", $out);
    }

    public function testAwsSesNotification(): void
    {
        $body = json_encode([
            'Type'    => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Complaint',
                'mail'             => ['source' => 'sender@example.com'],
                'complaint'        => [
                    'complaintFeedbackType' => 'abuse',
                    'complainedRecipients'  => [['emailAddress' => 'e2e-ses@example.com']],
                ],
            ]),
        ]);

        $this->assertSame('OK', $this->json('POST', '/api/v1/bounces/aws-ses', $body));
    }

    public function testUnknownRouteIs404(): void
    {
        $this->assertSame(404, $this->http('GET', '/nope')['status']);
    }
}
