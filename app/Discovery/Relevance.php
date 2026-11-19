<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

/** Is this a barbershop / men's haircut place (good lead) or e.g. a women-only beauty salon? */
final class Relevance
{
    private const MEN = '/barber|бръснар|барбер|men\'?s|мъжк|gentlem|джентълм|fade/iu';
    private const NOT_HAIR = '/nail|маникюр|педикюр|lash|мигли|козмет|cosmet|spa\b|масаж|massage|tattoo|татту|tatoo|epil|епил|солар|solar/iu';
    private const WOMEN = '/дамск|ladies|women|жен(ск|и)/iu';

    /** @return array{0:string,1:string,2:string} [relevance high|medium|low, category, reason] */
    public static function classify(Candidate $c): array
    {
        $text = $c->name . ' ' . ($c->description ?? '');
        $types = implode(' ', $c->types);

        if (preg_match(self::MEN, $text)) {
            return ['high', 'barbershop', 'Името/описанието сочи бръснарница или мъжки фризьор'];
        }
        if (preg_match('/shop=barber|hairdresser=(barber|men|male)|male=yes|barber_shop/', $types)) {
            return ['high', 'barbershop', 'Източникът го води като бръснарница / мъжки фризьор'];
        }
        if (preg_match('/hairdresser=(female|women)|female=yes/', $types) || (preg_match(self::WOMEN, $text) && !preg_match('/unisex=yes/', $types))) {
            return ['low', 'womens_salon', 'Изглежда като дамски салон'];
        }
        if (!preg_match('/hairdresser|hair_salon|hair_care|barber/', $types) && preg_match(self::NOT_HAIR, $text . ' ' . $types)) {
            return ['low', 'beauty', 'Изглежда като козметичен/маникюрен салон, не фризьор'];
        }
        if (preg_match('/hairdresser|hair_salon|hair_care|фризьор|frizyor|hair/iu', $types . ' ' . $text)) {
            return ['medium', 'hairdresser', 'Фризьорски салон: не е ясно дали прави мъжки прически'];
        }
        return ['low', 'other', 'Няма данни, че е фризьор или бръснар'];
    }
}
