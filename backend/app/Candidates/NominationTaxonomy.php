<?php

namespace App\Candidates;

/**
 * The approved nomination taxonomy: three main domains, each with its
 * subcategories, identified by stable codes (never by their labels).
 *
 * This is the owner-approved structure that supersedes the earlier note that
 * Creativity sub-areas were unresolved: Culture and Arts (culture, arts),
 * Heritage (tangible, intangible) and Creativity (social responsibility,
 * entrepreneurship, quality). The subcategories carry no award slots,
 * rankings, committees or rubrics of their own.
 *
 * `nomination:setup` provisions these codes for an explicitly chosen cycle
 * (award_domains, cycle_domains, cycle_domain_subcategories). The labels here
 * are the names used when provisioning; the stored names are what the
 * candidate sees. The artwork key names the original domain artwork in
 * frontend/src/assets/brand/ (Creativity uses the former Volunteer Work
 * award shape, as approved by the owner).
 */
final class NominationTaxonomy
{
    /**
     * @var array<string, array{name: string, artwork: string, subcategories: array<string, string>}>
     */
    public const DOMAINS = [
        'culture_and_arts' => [
            'name' => 'الثقافة والفنون',
            'artwork' => 'domain-culture-and-arts',
            'subcategories' => [
                'culture' => 'الثقافة',
                'arts' => 'الفنون',
            ],
        ],
        'heritage' => [
            'name' => 'التراث',
            'artwork' => 'domain-tangible-and-intangible-heritage',
            'subcategories' => [
                'tangible_heritage' => 'التراث المادي',
                'intangible_heritage' => 'التراث اللامادي',
            ],
        ],
        'creativity' => [
            'name' => 'الإبداع',
            'artwork' => 'domain-volunteer-work',
            'subcategories' => [
                'social_responsibility' => 'المسؤولية المجتمعية',
                'entrepreneurship' => 'ريادة الأعمال',
                'quality' => 'الجودة',
            ],
        ],
    ];

    public static function artworkFor(string $domainCode): ?string
    {
        return self::DOMAINS[$domainCode]['artwork'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function domainCodes(): array
    {
        return array_keys(self::DOMAINS);
    }
}
