<?php

return [
    /*
     | EZ-LOCK-20261017 P2: email-code / password unlock for private slugs. While false, a private
     | slug is owner-only (404 for everyone else) even if the owner configured unlock methods.
     */
    'unlock_enabled' => (bool) env('SLUG_UNLOCK_V1', false),

    // How long an unlock lasts in the visitor's session (minutes). Default 7 days.
    'grant_minutes' => (int) env('SLUG_UNLOCK_GRANT_MINUTES', 10080),

    // Email code / magic link lifetime (minutes).
    'code_ttl' => (int) env('SLUG_UNLOCK_CODE_TTL', 10),
];
