<?php

namespace Tests\Feature;

use Tests\Support\Net;
use Tests\TestCase;

/**
 * Drives requests through the Fat-Free router (Base::mock) using the
 * routes shipped in .env.example.ini, to verify the controllers wire up
 * correctly with the installed bcosca/fatfree-core version.
 */
class RoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // always present under a real web server; F3 uses it when sending the status line
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

        $this->f3->config(APP_PATH . '/.env.example.ini');
        // config() above may reset these; keep the test DB and no-cache settings
        $this->f3->set('DB', $this->db);
        $this->f3->set('CACHE', false);
    }

    protected function tearDown(): void
    {
        // mock() switches CLI off; restore it for other tests
        $this->f3->set('CLI', true);
        parent::tearDown();
    }

    private function request(string $pattern, ?string $body = null): mixed
    {
        $out = $this->capture(fn () => $this->f3->mock($pattern, null, null, $body));

        return $out === '' ? null : json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testFatFreeVersion(): void
    {
        $this->assertTrue(
            // VERSION looks like "3.9.3-Release"
            version_compare(strtok(\Base::VERSION, '-'), '3.9.3', '>='),
            'expected bcosca/fatfree-core >= 3.9.3, got ' . \Base::VERSION
        );
    }

    public function testAllConfiguredRoutesResolveToCallableHandlers(): void
    {
        $routes = $this->f3->get('ROUTES');
        $this->assertNotEmpty($routes);

        foreach ($routes as $pattern => $types) {
            foreach ($types as $verbs) {
                foreach ($verbs as $verb => [$handler]) {
                    [$class, $method] = explode('->', $handler);
                    $this->assertTrue(
                        method_exists($class, $method),
                        "$verb $pattern points to missing handler $handler"
                    );
                }
            }
        }
    }

    public function testHome(): void
    {
        $this->assertSame('OK', $this->request('GET /'));
        $this->assertSame('OK', $this->request('GET /?purge=true'));
    }

    public function testBounceLifecycle(): void
    {
        $this->assertNull($this->request('GET /api/v1/bounces/soft?email=a@example.com'));
        $this->assertNull($this->request('POST /api/v1/bounces/hard?email=b@example.com'));
        $this->assertNull($this->request('GET /api/v1/bounces/complaint?email=c@example.com'));

        $stat = $this->request('GET /api/v1/bounces/stat?email=a@example.com');
        $this->assertFalse($stat['sendable']);

        $stats = $this->request('GET /api/v1/bounces/stats?emails=a@example.com,b@example.com,z@example.com');
        $this->assertSame(['a@example.com', 'b@example.com'], array_keys($stats));

        $removed = $this->request('GET /api/v1/bounces/remove?emails=a@example.com,b@example.com,c@example.com');
        $this->assertTrue($removed['sendable']);
        $this->assertSame(0, $this->bounceCount());

        $stat = $this->request('GET /api/v1/bounces/stat?email=a@example.com');
        $this->assertSame(['throttle' => -1, 'sendable' => true], $stat);
    }

    public function testPostBodyIsStoredAsPayload(): void
    {
        $this->request('POST /api/v1/bounces/soft?email=a@example.com', 'raw-post-body');

        $this->assertSame('raw-post-body', $this->findBounce('a@example.com')['payload']);
    }

    public function testAwsSesNotification(): void
    {
        $body = json_encode([
            'Type'    => 'Notification',
            'Message' => json_encode([
                'notificationType' => 'Bounce',
                'mail'             => ['source' => 'sender@example.com'],
                'bounce'           => [
                    'bounceType'        => 'Permanent',
                    'bounceSubType'     => 'General',
                    'bouncedRecipients' => [
                        ['emailAddress' => 'gone@example.com', 'diagnosticCode' => '550'],
                    ],
                ],
            ]),
        ]);

        $this->assertSame('OK', $this->request('POST /api/v1/bounces/aws-ses', $body));
        $this->assertSame('hard|550|General', $this->findBounce('gone@example.com')['reason']);
    }

    public function testEmailValidationRoutes(): void
    {
        Net::$noMxDomains = ['nomx.test'];

        $this->assertSame(
            ['email' => 'a@example.com', 'valid' => true],
            $this->request('GET /api/v1/email/valid?email=a@example.com')
        );
        $this->assertSame(
            ['email' => 'a@nomx.test', 'valid' => false],
            $this->request('GET /api/v1/email/valid-inbox?email=a@nomx.test')
        );
    }
}
