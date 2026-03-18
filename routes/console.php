<?php

Schedule::command('laraclaw:memory:consolidate')
    ->dailyAt('03:00')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('04:00')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();
