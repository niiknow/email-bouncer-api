<?php

namespace App\Controllers;

class BouncesController extends BaseController
{
    protected function handleBounce($from, $email, $reason, $increment = 1)
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
        $item->from    = $this->getOrDefault('GET.from', $from);
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
        $this->handleBounce(null, $email, 'hard|5xx', 8);
    }

    /**
     * handle soft bounce
     */
    public function soft()
    {
        $email = $this->getOrDefault('GET.email', null);
        $this->handleBounce(null, $email, 'soft|4xx');
    }

    /**
     * handle complaint
     */
    public function complaint()
    {
        $email = $this->getOrDefault('GET.email', null);
        $this->handleBounce(null, $email, 'complaint', 3);
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

    public function awsSes()
    {
        // handle SES bounces
        $payload = json_decode($this->getOrDefault('BODY', '{}'), true);

        if (!isset($payload['Type'])) {
            return $this->json("Key 'Type' not found in payload ", ['http_status' => 400]);
        }

        if ($payload['Type'] == 'SubscriptionConfirmation') {
            $result = file_get_contents($payload['SubscribeURL']);
        } elseif ($payload['Type'] == 'Notification') {
            $message = json_decode($payload['Message'], true);
            $type    = mb_strtolower($message['notificationType']);
            $source  = $sesMessage['mail']['source'];

            // only handle bounce and compalint, not delivery
            if ($type == 'bounce') {
                $bt = 'soft|';
                $bc = 1;

                if ($message['bounce']['bounceType'] == 'Permanent') {
                    $bt = 'hard|';
                    $bc = 8;
                }

                // handle recipients
                $bouncedRecipients = $message['bounce']['bouncedRecipients'];
                foreach ($bouncedRecipients as $item) {
                    $this->handleBounce(
                        $source,
                        $item['emailAddress'],
                        $bt . $item['diagnosticCode'] . '|' . $message['bounce']['bounceSubType'],
                        $bc
                    );
                }
            } elseif ($type == 'complaint') {
                // semi-hardbounce customer that complain about spam at their mail provider
                foreach ($message['complaint']['complainedRecipients'] as $item) {
                    if (isset($message['complaint']['complaintFeedbackType'])) {
                        // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                        $this->handleBounce(
                            $source,
                            $item['emailAddress'],
                            'complaint|' . $message['complaint']['complaintFeedbackType'],
                            3
                        );
                    } else {
                        $this->handleBounce($source, $item['emailAddress'], 'complaint', 3);
                    }
                }
            }
        }

        return $this->json('OK');
    }
}
