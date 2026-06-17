<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->formatMoney(...)),
        ];
    }

    /**
     * Converts centimes (int) to formatted euros.
     * Example: 12500 → "125,00 €"
     */
    public function formatMoney(int $centimes): string
    {
        $euros = $centimes / 100;

        return number_format($euros, 2, ',', "\u{202F}") . ' €';
    }
}
