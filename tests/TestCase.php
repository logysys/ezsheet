<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** EZ-REL-20261016-3: CSRF is enforced on session/owner/upload routes (also in tests),
     *  so every test request carries a valid session token + X-CSRF-TOKEN header unless a
     *  test calls withoutCsrf() to check the 419 on purpose. */
    public const CSRF = 'phpunit-csrf-token-0000000000000000000';
    protected bool $csrfAuto = true;

    protected function withoutCsrf(): static
    {
        $this->csrfAuto = false;
        return $this;
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->csrfAuto) {
            $this->withSession(['_token' => self::CSRF]);
            $server += ['HTTP_X_CSRF_TOKEN' => self::CSRF];
        }
        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
