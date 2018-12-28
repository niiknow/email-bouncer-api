<?php

namespace App\Controllers;

class Index extends BaseController
{
    /**
    * health check
    */
    public function index()
    {
        $db  = $this->getOrDefault('DB', null);
        $rst = $db->exec('DELETE FROM bounces WHERE expired_at < CURRENT_TIMESTAMP');

        $this->json('OK');
    }
}
