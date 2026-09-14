<?php

return [
    'dispatch_batch_size' => max(1, (int) env('PLAYLIST_SYNC_DISPATCH_BATCH_SIZE', 50)),
    'maximum_check_interval_minutes' => min(240, max(1, (int) env('PLAYLIST_SYNC_MAXIMUM_CHECK_INTERVAL_MINUTES', 240))),
    'login_check_threshold_minutes' => max(15, (int) env('PLAYLIST_SYNC_LOGIN_CHECK_THRESHOLD_MINUTES', 15)),
    'run_retention_days' => max(1, (int) env('PLAYLIST_SYNC_RUN_RETENTION_DAYS', 14)),
    'prune_batch_size' => max(1, (int) env('PLAYLIST_SYNC_PRUNE_BATCH_SIZE', 500)),
    'job_tries' => max(1, (int) env('PLAYLIST_SYNC_JOB_TRIES', 3)),
    'job_timeout_seconds' => min(450, max(1, (int) env('PLAYLIST_SYNC_JOB_TIMEOUT_SECONDS', 450))),
    'job_backoff' => [60, 300],
    'overlap_release_seconds' => 60,
];
