<?php

namespace App\Services;

/**
 * Outcome of WebsiteIdentityVerifier::verify(). `status` is one of the
 * verifier's constants; `reason` explains a rejection (or which evidence
 * verified it) for logs and the field_quarantine record.
 */
final class WebsiteIdentityVerdict
{
    /**
     * @param  list<string>  $evidence
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly array $evidence = [],
    ) {}

    /** Safe to save as a NEW website_url (only a location-verified page). */
    public function acceptsNew(): bool
    {
        return $this->status === WebsiteIdentityVerifier::VERIFIED;
    }

    /** A stored website that must be quarantined. */
    public function isRejected(): bool
    {
        return $this->status === WebsiteIdentityVerifier::REJECTED;
    }

    /** The value to persist in restaurants.website_identity (null = leave as is). */
    public function storedIdentity(): ?string
    {
        return $this->status === WebsiteIdentityVerifier::UNREACHABLE ? null : $this->status;
    }
}
