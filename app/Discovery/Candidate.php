<?php

declare(strict_types=1);

namespace BarberLead\Discovery;

/** One business as reported by one source, already normalised. Unknown values stay null. */
final class Candidate
{
    /** @param list<string> $types source categories, e.g. OSM shop/hairdresser tags or Google types */
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $name,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $email = null,
        public ?string $instagram = null,
        public ?string $facebook = null,
        public ?string $openingHours = null,
        public ?float $rating = null,
        public ?int $reviewsCount = null,
        public ?string $url = null,
        public array $types = [],
        public ?string $description = null,
        public bool $closed = false,
    ) {
    }

    /** @return array<string,mixed> what gets stored in business_sources.data */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name, 'lat' => $this->lat, 'lng' => $this->lng, 'address' => $this->address,
            'phone' => $this->phone, 'website' => $this->website, 'email' => $this->email,
            'instagram' => $this->instagram, 'facebook' => $this->facebook, 'opening_hours' => $this->openingHours,
            'rating' => $this->rating, 'reviews_count' => $this->reviewsCount, 'types' => $this->types ?: null,
            'description' => $this->description, 'closed' => $this->closed ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
