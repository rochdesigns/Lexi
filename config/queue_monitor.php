<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stall Threshold
    |--------------------------------------------------------------------------
    |
    | How long a job may sit on the queue before `queue:verify-draining` treats
    | the queue as unattended. With a worker running, jobs are taken within
    | milliseconds, so this only needs to be long enough to survive a worker
    | restart mid-deploy.
    |
    */

    'max_wait' => (int) env('QUEUE_MAX_WAIT', 300),

    /*
    |--------------------------------------------------------------------------
    | Where to Shout
    |--------------------------------------------------------------------------
    |
    | Address the stall alert is mailed to. Left empty, the check still logs and
    | still exits non-zero; the mail is what makes it reach a person.
    |
    | The alert is sent in the request rather than queued, because the queue is
    | the thing that is broken when it fires.
    |
    */

    'alert_email' => env('QUEUE_ALERT_EMAIL'),

];
