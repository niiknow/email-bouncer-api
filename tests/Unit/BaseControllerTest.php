<?php

namespace Tests\Unit;

use App\Controllers\BaseController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Net;
use Tests\TestCase;

class BaseControllerTest extends TestCase
{
    private BaseController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new BaseController($this->f3, ['ignored' => true]);
    }

    public function testGetOrDefaultReturnsValueWhenSet(): void
    {
        $this->f3->set('GET.email', 'a@example.com');

        $this->assertSame('a@example.com', $this->controller->getOrDefault('GET.email', 'x'));
    }

    public function testGetOrDefaultReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', $this->controller->getOrDefault('GET.missing', 'fallback'));
        $this->assertNull($this->controller->getOrDefault('GET.missing'));
    }

    public function testGetStorageDirDefault(): void
    {
        $this->assertSame('../storage/', $this->controller->getStorageDir());
    }

    public function testGetStorageDirCustom(): void
    {
        $this->f3->set('STORAGE', '/var/data/');

        $this->assertSame('var/data/', $this->controller->getStorageDir());
    }

    // ---------------------------------------------------------------- isValidEmail

    public static function invalidEmails(): array
    {
        return [
            'null'        => [null],
            'empty'       => [''],
            'no at'       => ['not-an-email'],
            'no domain'   => ['user@'],
            'double at'   => ['a@@example.com'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function testIsValidEmailRejectsMalformed(?string $email): void
    {
        $this->assertFalse($this->controller->isValidEmail($email));
        $this->assertFalse($this->controller->isValidEmail($email, true));
    }

    public function testIsValidEmailWithoutDnsReturnsEmail(): void
    {
        Net::$noMxDomains = ['example.com'];

        // DNS not consulted, so a domain without MX still passes
        $this->assertSame('a@example.com', $this->controller->isValidEmail('a@example.com'));
    }

    public function testIsValidEmailWithDnsChecksMx(): void
    {
        Net::$noMxDomains = ['nomx.test'];

        $this->assertTrue($this->controller->isValidEmail('a@example.com', true));
        $this->assertFalse($this->controller->isValidEmail('a@nomx.test', true));
    }

    // ---------------------------------------------------------------- isValidInbox

    public function testIsValidInboxRejectsInvalidEmail(): void
    {
        $this->assertFalse($this->controller->isValidInbox('nope'));
        $this->assertSame([], Net::$attempts);
    }

    public function testIsValidInboxRejectsDomainWithoutMx(): void
    {
        Net::$noMxDomains = ['nomx.test'];

        $this->assertFalse($this->controller->isValidInbox('a@nomx.test'));
        $this->assertSame([], Net::$attempts);
    }

    public function testIsValidInboxAssumesValidWhenNoServerReachable(): void
    {
        Net::$mx = ['mx2.example.com' => 20, 'mx1.example.com' => 10];

        $this->assertTrue($this->controller->isValidInbox('user@example.com'));

        // MX hosts sorted by weight, domain itself last; ports 25 -> 2525 -> 587
        $hosts = ['mx1.example.com', 'mx2.example.com', 'example.com'];
        $expected = [];
        foreach ([25, 2525, 587] as $port) {
            foreach ($hosts as $host) {
                $expected[] = "$host:$port";
            }
        }
        $this->assertSame($expected, Net::$attempts);
        $this->assertSame([], Net::$written);
    }

    public function testIsValidInboxFallsBackToNextPort(): void
    {
        Net::$mx        = ['mx1.example.com' => 10];
        Net::$listening = ['mx1.example.com:2525'];
        Net::$replies   = ['220 ready', '250 hello', '250 ok', '250 accepted'];

        $this->assertTrue($this->controller->isValidInbox('user@example.com'));
        $this->assertSame(
            ['mx1.example.com:25', 'example.com:25', 'mx1.example.com:2525'],
            Net::$attempts
        );
        $this->assertSame(5, Net::$timeoutSet);
    }

    public function testIsValidInboxReturnsTrueWhenGreetingIsNot220(): void
    {
        Net::$listening = ['example.com:25'];
        Net::$replies   = ['554 go away'];

        $this->assertTrue($this->controller->isValidInbox('user@example.com'));
        $this->assertSame([], Net::$written);
    }

    public function testIsValidInboxHandlesEmptyGreeting(): void
    {
        Net::$listening = ['example.com:25'];
        Net::$replies   = [];

        $this->assertTrue($this->controller->isValidInbox('user@example.com'));
    }

    public static function rcptReplies(): array
    {
        return [
            'accepted'      => ['250 2.1.5 OK', true],
            'greylisted'    => ['451 4.7.1 try later', true],
            'storage full'  => ['452 4.2.2 over quota', true],
            'no such user'  => ['550 5.1.1 user unknown', false],
            'no reply'      => [false, false],
        ];
    }

    #[DataProvider('rcptReplies')]
    public function testIsValidInboxInterpretsRcptReply(string|false $reply, bool $expected): void
    {
        Net::$listening = ['example.com:25'];
        Net::$replies   = ['220 mx ready', '250 hello', '250 sender ok', $reply];

        $this->assertSame($expected, $this->controller->isValidInbox('user@example.com'));
        $this->assertSame([
            "HELO example.com\r\n",
            "MAIL FROM: <user@example.com>\r\n",
            "RCPT TO: <user@example.com>\r\n",
            "quit\r\n",
        ], Net::$written);
        $this->assertTrue(Net::$closed);
    }

    // ---------------------------------------------------------------- json

    public function testJsonDefaults(): void
    {
        $out = $this->capture(fn () => $this->controller->json(['ok' => 'ü']));

        $this->assertSame(json_encode(['ok' => 'ü'], JSON_PRETTY_PRINT), $out);

        $headers = Net::headerMap();
        $this->assertSame('application/json; charset=utf-8', $headers['content-type']);
        $this->assertSame('*', $headers['access-control-allow-origin']);
        $this->assertSame('false', $headers['access-control-allow-credentials']);
        $this->assertSame(md5($out), $headers['etag']);
        // byte length, not character length
        $this->assertSame((string) strlen($out), $headers['content-length']);
        $this->assertArrayNotHasKey('access-control-expose-headers', $headers);
        $this->assertArrayNotHasKey('access-control-allow-methods', $headers);
        $this->assertSame(['X-Powered-By'], Net::$removedHeaders);
    }

    public function testJsonHeadersAreSorted(): void
    {
        $this->capture(fn () => $this->controller->json('OK'));

        $names = array_map(fn ($h) => explode(': ', $h)[0], Net::$headers);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function testJsonWithAllParams(): void
    {
        $this->capture(fn () => $this->controller->json('OK', [
            'headers'            => ['X-Custom' => 'yes'],
            'ttl'                => 60,
            'acl_expose_headers' => 'ETag',
            'acl_http_methods'   => 'GET,POST',
            'acl_origin'         => 'https://example.com',
            'acl_credentials'    => true,
            'etag'               => 'my-etag',
            'http_status'        => 201,
        ]));

        $headers = Net::headerMap();
        $this->assertSame('yes', $headers['x-custom']);
        $this->assertSame('ETag', $headers['access-control-expose-headers']);
        $this->assertSame('GET,POST', $headers['access-control-allow-methods']);
        $this->assertSame('https://example.com', $headers['access-control-allow-origin']);
        $this->assertSame('true', $headers['access-control-allow-credentials']);
        $this->assertSame('my-etag', $headers['etag']);
    }

    public function testJsonEmptyCredentialsIsFalse(): void
    {
        $this->capture(fn () => $this->controller->json('OK', ['acl_credentials' => '']));

        $this->assertSame('false', Net::headerMap()['access-control-allow-credentials']);
    }
}
