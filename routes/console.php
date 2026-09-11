<?php

use App\Jobs\RefreshProviderTokenJob;
use App\Jobs\SyncStorageQuotaJob;
use Illuminate\Support\Facades\Schedule;

// FR-09: tokens expire hourly at most providers; refresh them ahead of time.
Schedule::job(new RefreshProviderTokenJob)->hourly();

// FR-21: keep the dashboard quota numbers current.
Schedule::job(new SyncStorageQuotaJob)->everySixHours();
