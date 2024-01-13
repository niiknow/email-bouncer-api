<?php

namespace App\Controllers;

class Index extends BaseController
{
    /**
    * health check
    */
    public function index()
    {
        if ($this->getOrDefault('GET.purge', null)) {
            $db  = $this->getOrDefault('DB', null);
            $rst = $db->exec(
                'DELETE FROM bounces WHERE expired_at < CURRENT_TIMESTAMP'
            );

            return $this->json('OK');
        }

        $this->json('OK');
    }
}
