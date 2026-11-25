<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

final class DnsHostResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (is_string($record[$field] ?? null)) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
