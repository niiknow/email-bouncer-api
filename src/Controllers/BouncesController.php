<?php

namespace App\Controllers;

class BouncesController extends BaseController
{
    protected function handleBounce($reason, $increment = 1)
    {
        $email = $this->getOrDefault('GET.email', null);
        $today = new \DateTime();
        $db    = $this->getOrDefault('DB', null);
        $item  = new \DB\SQL\Mapper($db, 'bounces');
        $item->load(array('email=?', $email));

        if ($increment > 8) {
            $$increment = 8;
        }

        if ($item->dry()) {
            // insert new
            $item        = new \DB\SQL\Mapper($db, 'bounces');
            $item->count = 0;
        } else {
            $expired_at = \DateTime::createFromFormat("Y-m-d H:i:s", $item->expired_at);
            $throttle   = $expired_at->getTimestamp() - time();

            if ($throttle < 0) {
                $item->count = 0;
            }
        }

        $item->reason  = $reason;
        $item->email   = $email;
        $item->payload = $this->getOrDefault('BODY', '');
        $item->count  += $increment;

        $exp_min = pow(8, $item->count);

        if (is_infinite($exp_min)) {
            $exp_min = PHP_INT_MAX;
        }

        $exp_at = (new \DateTime())->add(\DateInterval::createFromDateString($exp_min . ' minutes'));

        // echo $exp_at->format('Y-m-d H:i:s');
        $item->expired_at = $exp_at->format('Y-m-d H:i:s');
        $item->updated_at = $today->format('Y-m-d H:i:s');
        $item->save();
    }

    /**
     * bulk remove email from bounces
     */
    protected function unBounce()
    {
        $values  = explode(',', $this->getOrDefault('GET.emails', ''));
        $count   = count($values);
        $db      = $this->getOrDefault('DB', null);
        $results = $db->exec(
            "DELETE FROM bounces WHERE email in ( ?".str_repeat(", ?", $count-1).")",
            $values
        );
    }

    /**
     * handle hard bounce
     */
    public function hard()
    {
        $this->handleBounce('hard', 8);
    }

    /**
     * handle soft bounce
     */
    public function soft()
    {
        $this->handleBounce('soft');
    }

    /**
     * check an email for sends throttle
     */
    public function stat()
    {
        $email = $this->getOrDefault('GET.email', null);
        $db    = $this->getOrDefault('DB', null);
        $item  = new \DB\SQL\Mapper($db, 'bounces');
        $item->load(array('email=?', $email));

        if ($item->dry()) {
            return $this->json(['throttle' => -1]);
        }

        // calculate throttle
        $today      = new \DateTime();
        $expired_at = \DateTime::createFromFormat("Y-m-d H:i:s", $item->expired_at);
        $throttle   = $expired_at->getTimestamp() - time();

        //echo $expired_at->format("Y-m-d H:i:s");
        $this->json(['throttle' => $throttle], ['ttl' => $throttle]);
    }

    /**
     * bulk check of emails to determine sends throttle
     */
    public function stats()
    {
        $values  = explode(',', $this->getOrDefault('GET.emails', ''));
        $count   = count($values);
        $db      = $this->getOrDefault('DB', null);
        $results = $db->exec(
            "SELECT email, expired_at FROM bounces WHERE email in ( ?".str_repeat(", ?", $count-1).")",
            $values
        );

        $today = time();
        foreach ($results as $item) {
            $expired_at = \DateTime::createFromFormat("Y-m-d H:i:s", $item->expired_at);

            $rst[$item->email] = $expired_at->getTimestamp() - $today;
        }

        return $this->json($results);
    }
}
