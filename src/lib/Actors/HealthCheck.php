<?php

namespace Dapr\Actors;

/**
 * Class HealthCheck
 * @package Dapr\Actors
 */
class HealthCheck
{
    /**
     * @return bool Whether the app is healthy or not
     */
    public function do_health_check(): bool
    {
        return true;
    }
}
