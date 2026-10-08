<?php

namespace Vipertecpro\PausewallUsage\Data;

final readonly class AppUsage
{
    /**
     * @param  string  $id  The package name on Android, for example com.android.chrome.
     * @param  string  $name  The app's label on the phone.
     * @param  int  $foregroundSeconds  Time the app was on screen.
     * @param  int  $opens  Times the app was switched to from another app or from screen off.
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $foregroundSeconds = 0,
        public int $opens = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            foregroundSeconds: max(0, (int) ($data['foregroundSeconds'] ?? 0)),
            opens: max(0, (int) ($data['opens'] ?? 0)),
        );
    }

    /**
     * @return array{id: string, name: string, foregroundSeconds: int, opens: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'foregroundSeconds' => $this->foregroundSeconds,
            'opens' => $this->opens,
        ];
    }
}
