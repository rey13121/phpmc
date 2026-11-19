<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 简化废弃矿道：区块内走廊 + 木支柱 + 偶尔铁轨/箱子。
 *
 * @param callable $get function(int $x,int $y,int $z): int
 * @param callable $set function(int $x,int $y,int $z,int $id,int $meta=0): void
 */
final class MineshaftGenerator
{
    public static function tryGenerate(
        int $seed,
        int $cx,
        int $cz,
        callable $get,
        callable $set
    ): void {
        $rand = new JavaRandom($seed ^ ($cx * 4987142) ^ ($cz * 5947611) ^ 55121);
        if ($rand->nextIntBound(45) !== 0) {
            return;
        }

        $y = 28 + $rand->nextIntBound(16);
        $mode = $rand->nextIntBound(3);
        if ($mode === 0 || $mode === 2) {
            self::corridorEW($y, $rand, $set);
        }
        if ($mode === 1 || $mode === 2) {
            self::corridorNS($y, $rand, $set);
        }
    }

    private static function corridorEW(int $y, JavaRandom $rand, callable $set): void
    {
        $z = 6 + $rand->nextIntBound(4);
        for ($x = 0; $x < 16; $x++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $zz = $z + $dx;
                if ($zz < 0 || $zz > 15) {
                    continue;
                }
                for ($dy = 0; $dy <= 2; $dy++) {
                    $set($x, $y + $dy, $zz, 0, 0);
                }
            }
            if ($x % 4 === 0) {
                if ($z - 1 >= 0) {
                    $set($x, $y, $z - 1, 85, 0);
                    $set($x, $y + 1, $z - 1, 85, 0);
                    $set($x, $y + 2, $z - 1, 5, 0);
                }
                if ($z + 1 <= 15) {
                    $set($x, $y, $z + 1, 85, 0);
                    $set($x, $y + 1, $z + 1, 85, 0);
                    $set($x, $y + 2, $z + 1, 5, 0);
                }
                $set($x, $y + 2, $z, 5, 0);
            }
            if ($rand->nextIntBound(8) === 0) {
                $set($x, $y, $z, 66, 0);
            }
            if ($rand->nextIntBound(40) === 0 && $z + 1 <= 15) {
                $set($x, $y, $z + 1, 54, 0);
            }
        }
    }

    private static function corridorNS(int $y, JavaRandom $rand, callable $set): void
    {
        $x = 6 + $rand->nextIntBound(4);
        for ($z = 0; $z < 16; $z++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $xx = $x + $dx;
                if ($xx < 0 || $xx > 15) {
                    continue;
                }
                for ($dy = 0; $dy <= 2; $dy++) {
                    $set($xx, $y + $dy, $z, 0, 0);
                }
            }
            if ($z % 4 === 0) {
                if ($x - 1 >= 0) {
                    $set($x - 1, $y, $z, 85, 0);
                    $set($x - 1, $y + 1, $z, 85, 0);
                    $set($x - 1, $y + 2, $z, 5, 0);
                }
                if ($x + 1 <= 15) {
                    $set($x + 1, $y, $z, 85, 0);
                    $set($x + 1, $y + 1, $z, 85, 0);
                    $set($x + 1, $y + 2, $z, 5, 0);
                }
                $set($x, $y + 2, $z, 5, 0);
            }
            if ($rand->nextIntBound(8) === 0) {
                $set($x, $y, $z, 66, 0);
            }
            if ($rand->nextIntBound(40) === 0 && $x + 1 <= 15) {
                $set($x + 1, $y, $z, 54, 0);
            }
        }
    }
}
