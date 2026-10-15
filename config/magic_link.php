<?php

return [
    // Minutes a sign-in link / backup code stays valid.
    'ttl' => 15,

    /*
     | TEMPORARY, OFF BY DEFAULT. Comma list of emails whose /magic/send response may include the
     | sign-in link directly (for Roy's testing while mail delivery is being checked). Leave
     | MAGIC_LINK_TEST_EMAILS unset in production once email works. Anyone who can type one of these
     | addresses can sign in as it, so only list throwaway test accounts.
     */
    'test_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('MAGIC_LINK_TEST_EMAILS', ''))
    ))),
];
