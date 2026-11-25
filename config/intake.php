<?php

declare(strict_types=1);

// Website forms posting leads to POST /intake/{key} (App\Models\IntakeForm).
return [
    'max_bytes' => 16 * 1024,
    // Every POST from one IP (an IPv6 /64), refused ones included.
    'ip_per_minute' => (int) env('INTAKE_IP_PER_MINUTE', 20),
    'ip_per_hour' => (int) env('INTAKE_IP_PER_HOUR', 60),
    // Posts from one IP to one form.
    'per_minute' => (int) env('INTAKE_PER_MINUTE', 5),
    'per_hour' => (int) env('INTAKE_PER_HOUR', 20),
    // A server webhook's posts to one form, whatever its address.
    'webhook_per_minute' => (int) env('INTAKE_WEBHOOK_PER_MINUTE', 60),
    'webhook_per_hour' => (int) env('INTAKE_WEBHOOK_PER_HOUR', 600),
    // Counted only for a new lead, so junk cannot use up a form's day.
    'new_leads_per_day' => (int) env('INTAKE_NEW_LEADS_PER_DAY', 100),
    'turnstile_action' => 'lead-intake',
];
