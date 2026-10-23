<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function london(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('Europe/London'));
}
