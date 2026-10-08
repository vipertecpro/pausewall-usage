<?php

namespace Vipertecpro\PausewallUsage\Data;

final readonly class UsageDay
{
    /**
     * @param  string  $date  YYYY-MM-DD in the phone's local time.
     * @param  int  $totalSeconds  Foreground time of every app that day.
     * @param  list<AppUsage>  $apps  Sorted by foreground time, longest first. `today()` lists only the most used.
     */
    public function __construct(
        public string $date,
        public int $totalSeconds = 0,
        public array $apps = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $apps = array_values(array_map(
            fn (array $app) => AppUsage::fromArray($app),
            array_filter((array) ($data['apps'] ?? []), 'is_array'),
        ));

        return new self(
            date: (string) ($data['date'] ?? ''),
            totalSeconds: isset($data['totalSeconds'])
                ? max(0, (int) $data['totalSeconds'])
                : array_sum(array_map(fn (AppUsage $app) => $app->foregroundSeconds, $apps)),
            apps: $apps,
        );
    }

    /** The most used app of the day, or null when nothing was used. */
    public function topApp(): ?AppUsage
    {
        return $this->apps[0] ?? null;
    }

    /**
     * @return array{date: string, totalSeconds: int, apps: list<array{id: string, name: string, foregroundSeconds: int, opens: int}>}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'totalSeconds' => $this->totalSeconds,
            'apps' => array_map(fn (AppUsage $app) => $app->toArray(), $this->apps),
        ];
    }
}
