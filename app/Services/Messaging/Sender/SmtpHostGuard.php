<?php

declare(strict_types=1);

namespace App\Services\Messaging\Sender;

use App\Exceptions\SenderUnavailable;
use Symfony\Component\HttpFoundation\IpUtils;

/** Every address a sender host resolves to must be public, and the send connects to one of those checked addresses. */
final class SmtpHostGuard
{
    /** Multicast, IPv6 site-local and NAT64 local use, which the global-range filter lets through. */
    private const array ALSO_REFUSED = ['224.0.0.0/4', 'ff00::/8', 'fec0::/10', '64:ff9b:1::/48'];

    public function __construct(private readonly HostResolver $resolver) {}

    /** Local development and the tests may send to a relay on a private address (Mailpit). */
    public static function allowsLocalRelay(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /**
     * @throws SenderUnavailable
     */
    public function check(string $host): CheckedHost
    {
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw SenderUnavailable::unresolved(__('The sender host :host does not resolve.', ['host' => $host]));
        }

        $public = array_filter($addresses, $this->isPublic(...)) === $addresses;
        if (! $public && ! self::allowsLocalRelay()) {
            throw SenderUnavailable::host(__('The sender host :host resolves to an address that is not public.', ['host' => $host]));
        }

        $ipv4 = array_values(array_filter($addresses, fn (string $address): bool => ! str_contains($address, ':')));

        return new CheckedHost($host, $ipv4[0] ?? $addresses[0], $public);
    }

    public function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // IpUtils also refuses every IPv4-mapped address, CGNAT and the documentation ranges.
        if (IpUtils::isPrivateIp($address)) {
            return false;
        }

        return ! IpUtils::checkIp($address, [...self::ALSO_REFUSED, ...$this->stackSubnets()]);
    }

    /** @return list<string> */
    private function stackSubnets(): array
    {
        return array_values(array_filter(array_map(trim(...), (array) config('mail.account_sender.blocked_subnets', [])), fn (string $subnet): bool => $subnet !== ''));
    }
}
