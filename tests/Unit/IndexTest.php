<?php

namespace Tests\Unit;

use App\Controllers\Index;
use Tests\TestCase;

class IndexTest extends TestCase
{
    public function testHealthCheck(): void
    {
        $this->insertBounce('expired@example.com', 1, self::dateFromNow('-1 day'));

        $out = $this->captureJson(fn () => (new Index($this->f3))->index());

        $this->assertSame('OK', $out);
        // no purge requested, so nothing deleted
        $this->assertSame(1, $this->bounceCount());
    }

    public function testPurgeDeletesOnlyExpired(): void
    {
        $this->insertBounce('expired@example.com', 1, self::dateFromNow('-1 day'));
        $this->insertBounce('active@example.com', 1, self::dateFromNow('+1 day'));
        $this->f3->set('GET.purge', 'true');

        $out = $this->captureJson(fn () => (new Index($this->f3))->index());

        $this->assertSame('OK', $out);
        $this->assertNull($this->findBounce('expired@example.com'));
        $this->assertNotNull($this->findBounce('active@example.com'));
    }
}
