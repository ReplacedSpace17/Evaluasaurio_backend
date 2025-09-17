<?php
namespace App\Models;

class AnalyticsEvent
{
    public int $id;
    public string $event_type;
    public ?string $page;
    public ?string $ip_address;
    public ?string $user_agent;
    public ?string $referer;
    public ?int $duration_seconds;
    public string $created_at;
}
