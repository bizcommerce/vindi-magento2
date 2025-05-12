<?php

namespace Vindi\Payment\Helper;

/**
 * Class BrandNormalizer
 *
 * Normalizes card brand names to standardized two-letter codes (e.g., "VI", "MC").
 *
 * @package Vindi\Payment\Helper
 */
class BrandNormalizer
{
    /**
     * Map of lowercase brand names to two-letter codes.
     *
     * @var array
     */
    private $map = [
        'visa'        => 'VI',
        'mastercard'  => 'MC',
        'elo'         => 'EL',
        'amex'        => 'AM',
        'discover'    => 'DI',
        'hipercard'   => 'HC',
        'diners'      => 'DN',
    ];

    /**
     * Normalize the given brand name.
     *
     * If the brand is not in the map, returns the first two letters uppercased.
     *
     * @param string|null $brand
     * @return string|null
     */
    public function normalize(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }
        $key = strtolower(trim($brand));
        if (isset($this->map[$key])) {
            return $this->map[$key];
        }
        return strtoupper(substr($key, 0, 2));
    }
}
