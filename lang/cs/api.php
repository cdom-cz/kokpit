<?php

declare(strict_types=1);

/*
 * Strings of the REST API layer (/api/v1). Domain strings stay in kokpit.php.
 */

return [

    'errors' => [
        'not_found' => 'Záznam neexistuje.',
        'duration_too_long' => 'Záznam je příliš dlouhý.',
        'running_entry_end' => 'Běžící záznam se ukončuje časovačem v aplikaci.',
        'running_entry_delete' => 'Běžící záznam nelze smazat. Nejdřív ho v aplikaci zastavte.',
        'idempotency_key_invalid' => 'Hlavička Idempotency-Key musí mít 1 až 255 viditelných znaků bez mezer.',
        'idempotency_key_reused' => 'Tento klíč Idempotency-Key už byl použit pro jiný požadavek.',
        'idempotency_in_progress' => 'Požadavek s tímto klíčem Idempotency-Key se právě zpracovává. Zkuste to za chvíli znovu.',
    ],

];
