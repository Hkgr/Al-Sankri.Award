<?php

namespace App\Support;

/**
 * The fourteen governorates of Syria with their ISO 3166-2:SY codes, for
 * implementation locations in cycles whose geography is Syria. The code is
 * what is stored (application_locations.governorate); the Arabic name is
 * shown. No city or village hierarchy is modelled: those are typed by the
 * candidate.
 */
final class SyrianGovernorates
{
    public const COUNTRY = 'SY';

    /**
     * @var array<string, string> ISO 3166-2 code => Arabic name
     */
    public const NAMES = [
        'SY-DI' => 'دمشق',
        'SY-RD' => 'ريف دمشق',
        'SY-HL' => 'حلب',
        'SY-HI' => 'حمص',
        'SY-HM' => 'حماة',
        'SY-LA' => 'اللاذقية',
        'SY-TA' => 'طرطوس',
        'SY-ID' => 'إدلب',
        'SY-HA' => 'الحسكة',
        'SY-DY' => 'دير الزور',
        'SY-RA' => 'الرقة',
        'SY-DR' => 'درعا',
        'SY-SU' => 'السويداء',
        'SY-QU' => 'القنيطرة',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }
}
