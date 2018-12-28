<?php

namespace App\Controllers;

class BouncesController extends BaseController
{
    protected function handleBounce($email, $reason, $increment = 1)
    {
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
        $email = $this->getOrDefault('GET.email', null);
        $this->handleBounce($email, 'hard;5xx', 8);
    }

    /**
     * handle soft bounce
     */
    public function soft()
    {
        $email = $this->getOrDefault('GET.email', null);
        $this->handleBounce($email, 'soft;4xx');
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

        // set expire to 20 minutes to for CDN usage
        $http_expire = 20 * 60;
        $this->json(['throttle' => $throttle], ['ttl' => $http_expire]);
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

    public function aws()
    {
        // handle SES bounces
        $payload = json_decode($this->getOrDefault('BODY', '{}'), true);

        if (!isset($payload['Type'])) {
            throw new HttpException(400, "Key 'Type' not found in payload ");
        }

        if ($payload['Type'] == 'SubscriptionConfirmation') {
            $result = file_get_contents($payload['SubscribeURL']);
        } elseif ($payload['Type'] == 'Notification') {
            $message = json_decode($payload['Message'], true);
            $type    = mb_strtolower($message['notificationType']);

            // only handle bounce and compalint, not delivery
            if ($type == 'bounce') {
                $bounce = 'soft;';
                $bcount = 1;

                if ($message['bounce']['bounceType'] == 'Permanent') {
                    $bounce = 'hard';
                    $bcount = 8;
                }

                // handle softbounce
                $bouncedRecipients = $message['bounce']['bouncedRecipients'];
                foreach ($bouncedRecipients as $bouncedRecipient) {
                    $this->handleBounce($bouncedRecipient['emailAddress'], $bounce . $bouncedRecipient['diagnosticCode'], $bcount);
                }
            } elseif ($type == 'complaint') {
                // semi-hardbounce customer that complain about spam at their mail provider
                foreach ($message['complaint']['complainedRecipients'] as $complainedRecipient) {
                    if (isset($message['complaint']['complaintFeedbackType'])) {
                        // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                        $this->handleBounce($bouncedRecipient['emailAddress'], 'complaint;' . $message['complaint']['complaintFeedbackType'], 3);
                    }
                }
            }
        }

        return $rows;
    }
}
