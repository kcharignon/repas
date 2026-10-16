<?php

namespace Repas\Shared\Domain\Tool;

/**
 * Similarité de Jaro-Winkler entre deux chaînes : 1.0 pour deux chaînes identiques, 0.0 pour deux chaînes sans lettre
 * commune. Elle tolère les fautes de frappe et les lettres permutées, et favorise les débuts de mot communs.
 */
final class JaroWinkler
{
    /** Au-dessous de ce score de Jaro, le début commun n'augmente rien (seuil de Winkler). */
    private const float BOOST_THRESHOLD = 0.7;
    private const float PREFIX_WEIGHT = 0.1;
    private const int MAX_PREFIX = 4;

    public static function similarity(string $first, string $second): float
    {
        $jaro = self::jaro(mb_str_split($first), mb_str_split($second));
        if ($jaro < self::BOOST_THRESHOLD) {
            return $jaro;
        }

        return $jaro + self::commonPrefixLength($first, $second) * self::PREFIX_WEIGHT * (1 - $jaro);
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     */
    private static function jaro(array $first, array $second): float
    {
        $firstLength = count($first);
        $secondLength = count($second);
        if ($firstLength === 0 && $secondLength === 0) {
            return 1.0;
        }
        if ($firstLength === 0 || $secondLength === 0) {
            return 0.0;
        }

        // Deux lettres ne se correspondent que si elles sont assez proches l'une de l'autre
        $window = max(intdiv(max($firstLength, $secondLength), 2) - 1, 0);
        $firstMatched = array_fill(0, $firstLength, false);
        $secondMatched = array_fill(0, $secondLength, false);

        $matches = 0;
        for ($i = 0; $i < $firstLength; $i++) {
            $from = max(0, $i - $window);
            $to = min($secondLength - 1, $i + $window);
            for ($j = $from; $j <= $to; $j++) {
                if (!$secondMatched[$j] && $first[$i] === $second[$j]) {
                    $firstMatched[$i] = true;
                    $secondMatched[$j] = true;
                    $matches++;
                    break;
                }
            }
        }
        if ($matches === 0) {
            return 0.0;
        }

        // Une transposition = deux lettres en commun qui ne sont pas dans le même ordre
        $outOfOrder = 0;
        $j = 0;
        for ($i = 0; $i < $firstLength; $i++) {
            if (!$firstMatched[$i]) {
                continue;
            }
            while (!$secondMatched[$j]) {
                $j++;
            }
            if ($first[$i] !== $second[$j]) {
                $outOfOrder++;
            }
            $j++;
        }
        $transpositions = intdiv($outOfOrder, 2);

        return ($matches / $firstLength + $matches / $secondLength + ($matches - $transpositions) / $matches) / 3;
    }

    private static function commonPrefixLength(string $first, string $second): int
    {
        $length = 0;
        $limit = min(self::MAX_PREFIX, mb_strlen($first), mb_strlen($second));
        while ($length < $limit && mb_substr($first, $length, 1) === mb_substr($second, $length, 1)) {
            $length++;
        }

        return $length;
    }
}
