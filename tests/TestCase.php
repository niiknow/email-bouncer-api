<?php

namespace Tests;

use Tests\Support\Net;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected \Base $f3;

    protected \DB\SQL $db;

    protected function setUp(): void
    {
        parent::setUp();

        Net::reset();

        $this->f3 = \Base::instance();
        $this->f3->set('GET', []);
        $this->f3->set('POST', []);
        $this->f3->set('BODY', '');
        $this->f3->set('CLI', true);
        $this->f3->clear('STORAGE');

        $this->db = new \DB\SQL('sqlite::memory:');
        // same schema as the sqlite branch of public/index.php initdb()
        $this->db->exec("CREATE TABLE IF NOT EXISTS `bounces` (
  `id` INTEGER PRIMARY KEY autoincrement,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `from` VARCHAR(191) NULL,
  `count` INT unsigned NOT NULL,
  `reason` VARCHAR(191) NOT NULL,
  `payload` TEXT NOT NULL,
  `expired_at` TIMESTAMP NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);");
        $this->f3->set('DB', $this->db);
    }

    /**
     * Run a callable and return everything it echoed.
     */
    protected function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $out = ob_get_clean();
        }

        return $out;
    }

    /**
     * Run a callable and decode its JSON output.
     */
    protected function captureJson(callable $fn): mixed
    {
        return json_decode($this->capture($fn), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function insertBounce(string $email, int $count, string $expiredAt, string $reason = 'soft|4xx'): void
    {
        $this->db->exec(
            'INSERT INTO bounces (email, `from`, count, reason, payload, expired_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$email, null, $count, $reason, '', $expiredAt]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function findBounce(string $email): ?array
    {
        $rows = $this->db->exec('SELECT * FROM bounces WHERE email = ?', [$email]);

        return $rows[0] ?? null;
    }

    protected function bounceCount(): int
    {
        return (int) $this->db->exec('SELECT COUNT(*) AS c FROM bounces')[0]['c'];
    }

    protected static function dateFromNow(string $modifier): string
    {
        return (new \DateTime())->modify($modifier)->format('Y-m-d H:i:s');
    }
}
