<?php

namespace Tests\Unit;

use App\Controllers\BouncesController;
use Tests\Support\Net;
use Tests\TestCase;

class BouncesControllerTest extends TestCase
{
    private const DAY = 86400;

    private BouncesController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new BouncesController($this->f3);
    }

    private function secondsUntil(string $datetime): int
    {
        return \DateTime::createFromFormat('Y-m-d H:i:s', $datetime)->getTimestamp() - time();
    }

    private function assertExpiresInDays(int $days, array $row): void
    {
        $this->assertEqualsWithDelta($days * self::DAY, $this->secondsUntil($row['expired_at']), 5);
    }

    // ---------------------------------------------------------------- hard / soft / complaint

    public function testHardBounce(): void
    {
        $this->f3->set('GET.email', 'hard@example.com');

        $out = $this->capture(fn () => $this->controller->hard());

        $this->assertSame('', $out);
        $row = $this->findBounce('hard@example.com');
        $this->assertSame(365, (int) $row['count']);
        $this->assertSame('hard|5xx', $row['reason']);
        $this->assertNull($row['from']);
        $this->assertSame('', $row['payload']);
        $this->assertExpiresInDays(365, $row);
    }

    public function testHardBounceIsCappedAt365Days(): void
    {
        $this->f3->set('GET.email', 'hard@example.com');

        $this->controller->hard();
        $this->controller->hard();

        $row = $this->findBounce('hard@example.com');
        $this->assertSame(365, (int) $row['count']);
        $this->assertSame(1, $this->bounceCount());
    }

    public function testSoftBounceIncrementsWhileActive(): void
    {
        $this->f3->set('GET.email', 'soft@example.com');

        $this->controller->soft();
        $row = $this->findBounce('soft@example.com');
        $this->assertSame(1, (int) $row['count']);
        $this->assertSame('soft|4xx', $row['reason']);
        $this->assertExpiresInDays(1, $row);

        $this->controller->soft();
        $row = $this->findBounce('soft@example.com');
        $this->assertSame(2, (int) $row['count']);
        $this->assertExpiresInDays(2, $row);
    }

    public function testSoftBounceResetsAfterExpiry(): void
    {
        $this->insertBounce('soft@example.com', 10, self::dateFromNow('-1 hour'));
        $this->f3->set('GET.email', 'soft@example.com');

        $this->controller->soft();

        $row = $this->findBounce('soft@example.com');
        $this->assertSame(1, (int) $row['count']);
        $this->assertExpiresInDays(1, $row);
    }

    public function testComplaint(): void
    {
        $this->f3->set('GET.email', 'c@example.com');

        $this->controller->complaint();

        $row = $this->findBounce('c@example.com');
        $this->assertSame('complaint', $row['reason']);
        $this->assertSame(1, (int) $row['count']);
    }

    public function testBounceStoresFromAndPayload(): void
    {
        $this->f3->set('GET.email', 'soft@example.com');
        $this->f3->set('GET.from', 'sender@example.com');
        $this->f3->set('BODY', 'raw body');

        $this->controller->soft();

        $row = $this->findBounce('soft@example.com');
        $this->assertSame('sender@example.com', $row['from']);
        $this->assertSame('raw body', $row['payload']);
    }

    public function testBounceIgnoresInvalidEmail(): void
    {
        $this->f3->set('GET.email', 'not-an-email');
        $this->controller->hard();
        $this->controller->soft();
        $this->controller->complaint();

        $this->f3->clear('GET.email');
        $this->controller->hard();

        $this->assertSame(0, $this->bounceCount());
    }

    // ---------------------------------------------------------------- remove

    public function testRemove(): void
    {
        $this->insertBounce('a@example.com', 1, self::dateFromNow('+1 day'));
        $this->insertBounce('b@example.com', 1, self::dateFromNow('+1 day'));
        $this->insertBounce('keep@example.com', 1, self::dateFromNow('+1 day'));
        $this->f3->set('GET.emails', 'a@example.com, b@example.com');

        $out = $this->captureJson(fn () => $this->controller->remove());

        $this->assertSame(['emails' => ['a@example.com', 'b@example.com'], 'sendable' => true], $out);
        $this->assertSame(1, $this->bounceCount());
        $this->assertNotNull($this->findBounce('keep@example.com'));
    }

    // ---------------------------------------------------------------- stat

    public function testStatInvalidEmail(): void
    {
        $this->f3->set('GET.email', 'bad');

        $out = $this->captureJson(fn () => $this->controller->stat());

        $this->assertSame(['throttle' => PHP_INT_MAX, 'error' => 'Invalid email bad', 'sendable' => false], $out);
    }

    public function testStatDomainWithoutMx(): void
    {
        Net::$noMxDomains = ['nomx.test'];
        $this->f3->set('GET.email', 'a@nomx.test');

        $out = $this->captureJson(fn () => $this->controller->stat());

        $this->assertFalse($out['sendable']);
    }

    public function testStatUnknownEmailIsSendable(): void
    {
        $this->f3->set('GET.email', 'new@example.com');

        $out = $this->captureJson(fn () => $this->controller->stat());

        $this->assertSame(['throttle' => -1, 'sendable' => true], $out);
    }

    public function testStatActiveBounceIsNotSendable(): void
    {
        $this->insertBounce('b@example.com', 1, self::dateFromNow('+1 day'));
        $this->f3->set('GET.email', 'b@example.com');

        $out = $this->captureJson(fn () => $this->controller->stat());

        $this->assertFalse($out['sendable']);
        $this->assertEqualsWithDelta(self::DAY, $out['throttle'], 5);
    }

    public function testStatExpiredBounceIsSendable(): void
    {
        $this->insertBounce('b@example.com', 1, self::dateFromNow('-1 hour'));
        $this->f3->set('GET.email', 'b@example.com');

        $out = $this->captureJson(fn () => $this->controller->stat());

        $this->assertTrue($out['sendable']);
        $this->assertLessThan(0, $out['throttle']);
    }

    // ---------------------------------------------------------------- stats

    public function testStats(): void
    {
        Net::$noMxDomains = ['nomx.test'];
        $this->insertBounce('active@example.com', 1, self::dateFromNow('+1 day'));
        $this->insertBounce('expired@example.com', 1, self::dateFromNow('-1 hour'));
        $this->f3->set('GET.emails', 'active@example.com, expired@example.com,unknown@example.com,bad,x@nomx.test,');

        $out = $this->captureJson(fn () => $this->controller->stats());

        $this->assertSame(
            ['bad', 'x@nomx.test', 'active@example.com', 'expired@example.com'],
            array_keys($out)
        );
        $this->assertSame(PHP_INT_MAX, $out['bad']);
        $this->assertSame(PHP_INT_MAX, $out['x@nomx.test']);
        $this->assertEqualsWithDelta(self::DAY, $out['active@example.com'], 5);
        $this->assertLessThan(0, $out['expired@example.com']);
    }

    public function testStatsWithNoEmails(): void
    {
        $out = $this->capture(fn () => $this->controller->stats());

        $this->assertSame('[]', $out);
    }

    // ---------------------------------------------------------------- validEmail / validInbox

    public function testValidEmail(): void
    {
        Net::$noMxDomains = ['nomx.test'];

        $this->f3->set('GET.email', 'a@example.com');
        $this->assertSame(
            ['email' => 'a@example.com', 'valid' => true],
            $this->captureJson(fn () => $this->controller->validEmail())
        );

        $this->f3->set('GET.email', 'a@nomx.test');
        $this->assertSame(
            ['email' => 'a@nomx.test', 'valid' => false],
            $this->captureJson(fn () => $this->controller->validEmail())
        );
    }

    public function testValidInbox(): void
    {
        Net::$listening = ['example.com:25'];
        Net::$replies   = ['220 ready', '250 hi', '250 ok', '550 no such user'];
        $this->f3->set('GET.email', 'ghost@example.com');

        $this->assertSame(
            ['email' => 'ghost@example.com', 'valid' => false],
            $this->captureJson(fn () => $this->controller->validInbox())
        );

        Net::$replies = ['220 ready', '250 hi', '250 ok', '250 ok'];
        $this->assertSame(
            ['email' => 'ghost@example.com', 'valid' => true],
            $this->captureJson(fn () => $this->controller->validInbox())
        );
    }

    // ---------------------------------------------------------------- awsSes

    private function sns(string $type, array $message = [], array $extra = []): void
    {
        $payload = array_merge(['Type' => $type, 'Message' => json_encode($message)], $extra);
        $this->f3->set('BODY', json_encode($payload));
    }

    private function bounceMessage(string $type, string $subType, array $recipients): array
    {
        return [
            'notificationType' => 'Bounce',
            'mail'             => ['source' => 'sender@example.com'],
            'bounce'           => [
                'bounceType'        => $type,
                'bounceSubType'     => $subType,
                'bouncedRecipients' => $recipients,
            ],
        ];
    }

    public function testAwsSesMissingType(): void
    {
        $this->f3->set('BODY', '{}');

        $out = $this->captureJson(fn () => $this->controller->awsSes());

        $this->assertSame("Key 'Type' not found in payload ", $out);
    }

    public function testAwsSesEmptyBody(): void
    {
        $this->f3->clear('BODY');

        $out = $this->captureJson(fn () => $this->controller->awsSes());

        $this->assertSame("Key 'Type' not found in payload ", $out);

    }

    public function testAwsSesSubscriptionConfirmation(): void
    {
        $this->sns('SubscriptionConfirmation', [], [
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
        ]);

        $out = $this->captureJson(fn () => $this->controller->awsSes());

        $this->assertSame('OK', $out);
        $this->assertSame(['https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription'], Net::$fetched);
    }

    public function testAwsSesUnknownTypeIsIgnored(): void
    {
        $this->sns('UnsubscribeConfirmation');

        $this->assertSame('OK', $this->captureJson(fn () => $this->controller->awsSes()));
        $this->assertSame([], Net::$fetched);
        $this->assertSame(0, $this->bounceCount());
    }

    public function testAwsSesPermanentBounce(): void
    {
        $this->sns('Notification', $this->bounceMessage('Permanent', 'General', [
            ['emailAddress' => 'gone@example.com', 'status' => '5.1.1', 'diagnosticCode' => 'smtp; 550 user unknown'],
        ]));

        $this->assertSame('OK', $this->captureJson(fn () => $this->controller->awsSes()));

        $row = $this->findBounce('gone@example.com');
        $this->assertSame('hard|smtp; 550 user unknown|General', $row['reason']);
        $this->assertSame(365, (int) $row['count']);
        $this->assertSame('sender@example.com', $row['from']);
        $this->assertSame($this->f3->get('BODY'), $row['payload']);
    }

    public function testAwsSesTransientBounce(): void
    {
        $this->sns('Notification', $this->bounceMessage('Transient', 'MailboxFull', [
            ['emailAddress' => 'full@example.com', 'status' => '4.2.2', 'diagnosticCode' => '452 full'],
            ['emailAddress' => 'nostatus@example.com', 'diagnosticCode' => 'n/a'],
        ]));

        $this->captureJson(fn () => $this->controller->awsSes());

        $row = $this->findBounce('full@example.com');
        $this->assertSame('soft|452 full|MailboxFull', $row['reason']);
        $this->assertSame(1, (int) $row['count']);

        $row = $this->findBounce('nostatus@example.com');
        $this->assertSame('soft|n/a|MailboxFull', $row['reason']);
    }

    public function testAwsSesTransientBounceWith5xxStatusIsHard(): void
    {
        $this->sns('Notification', $this->bounceMessage('Transient', 'General', [
            ['emailAddress' => 'loop@example.com', 'status' => '5.4.14', 'diagnosticCode' => '554 hop count exceeded'],
        ]));

        $this->captureJson(fn () => $this->controller->awsSes());

        $row = $this->findBounce('loop@example.com');
        $this->assertSame('hard|554 hop count exceeded|General', $row['reason']);
        $this->assertSame(1, (int) $row['count']);
    }

    public function testAwsSesComplaintWithFeedbackType(): void
    {
        $this->sns('Notification', [
            'notificationType' => 'Complaint',
            'mail'             => ['source' => 'sender@example.com'],
            'complaint'        => [
                'complaintFeedbackType' => 'abuse',
                'complainedRecipients'  => [['emailAddress' => 'angry@example.com']],
            ],
        ]);

        $this->captureJson(fn () => $this->controller->awsSes());

        $this->assertSame('complaint|abuse', $this->findBounce('angry@example.com')['reason']);
    }

    public function testAwsSesComplaintWithoutFeedbackType(): void
    {
        $this->sns('Notification', [
            'notificationType' => 'Complaint',
            'mail'             => ['source' => 'sender@example.com'],
            'complaint'        => [
                'complainedRecipients' => [['emailAddress' => 'angry@example.com']],
            ],
        ]);

        $this->captureJson(fn () => $this->controller->awsSes());

        $this->assertSame('complaint', $this->findBounce('angry@example.com')['reason']);
    }

    public function testAwsSesDeliveryIsIgnored(): void
    {
        $this->sns('Notification', [
            'notificationType' => 'Delivery',
            'mail'             => ['source' => 'sender@example.com'],
        ]);

        $this->assertSame('OK', $this->captureJson(fn () => $this->controller->awsSes()));
        $this->assertSame(0, $this->bounceCount());
    }
}
