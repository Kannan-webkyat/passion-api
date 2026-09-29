<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Early checkout inspection (testing only)
    |--------------------------------------------------------------------------
    | When true, reception may request the pre-checkout inspection on any day of
    | a checked-in stay instead of only on the guest's checkout date. Ignored when
    | APP_ENV=production. Mirror with NEXT_PUBLIC_ALLOW_EARLY_CHECKOUT_INSPECTION
    | in the frontend so the room chart button is enabled too.
    */
    'allow_early_checkout_inspection' => (bool) env('ALLOW_EARLY_CHECKOUT_INSPECTION', false),

];
