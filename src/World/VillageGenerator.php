<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 村庄生成（简化版，建筑种类对齐 1.8 常见件）：
 * 水井 + 小路 + 小屋 / 大屋 / 农田 / 铁匠铺 / 教堂 / 图书馆。
 * 选址仍用原版 MapGenVillage 公式（平原/沙漠/热带草原）。
 */
final class VillageGenerator
{
    private const SPACING = 32;
    private const SEPARATION = 8;
    private const SALT = 10387312;

    /** 平原 / 沙漠 / 热带草原 */
    public const BIOMES = [1, 2, 35];

    public static function isVillageChunk(int $seed, int $chunkX, int $chunkZ): bool
    {
        $i = $chunkX;
        $j = $chunkZ;
        if ($chunkX < 0) {
            $chunkX -= self::SPACING - 1;
        }
        if ($chunkZ < 0) {
            $chunkZ -= self::SPACING - 1;
        }

        $k = intdiv($chunkX, self::SPACING);
        $l = intdiv($chunkZ, self::SPACING);

        $rand = new JavaRandom(0);
        $rand->setSeed(LongMath::add(
            LongMath::add(
                LongMath::mul($k, 341873128712),
                LongMath::mul($l, 132897987541)
            ),
            LongMath::add($seed, self::SALT)
        ));

        $k *= self::SPACING;
        $l *= self::SPACING;
        $k += $rand->nextIntBound(self::SPACING - self::SEPARATION);
        $l += $rand->nextIntBound(self::SPACING - self::SEPARATION);

        return $i === $k && $j === $l;
    }

    /**
     * @param array    $surface 16x16 地表高度
     * @param callable $set function(int $x,int $y,int $z,int $id,int $meta=0)
     * @param callable $get function(int $x,int $y,int $z): int
     */
    public static function build(
        int $cx,
        int $cz,
        int $biome,
        array $surface,
        callable $set,
        callable $get
    ): void {
        $samples = [];
        for ($z = 6; $z <= 9; $z++) {
            for ($x = 6; $x <= 9; $x++) {
                $samples[] = $surface[$z * 16 + $x];
            }
        }
        sort($samples);
        $floorY = max(4, min(120, $samples[intdiv(count($samples), 2)]));

        // 铲平中心区域
        for ($z = 1; $z < 15; $z++) {
            for ($x = 1; $x < 15; $x++) {
                $h = $surface[$z * 16 + $x];
                if ($h > $floorY) {
                    for ($y = $floorY + 1; $y <= $h + 2; $y++) {
                        $set($x, $y, $z, 0, 0);
                    }
                }
                // 填到地坪
                for ($y = $h + 1; $y <= $floorY; $y++) {
                    $set($x, $y, $z, $biome === 2 ? 12 : 3, 0);
                }
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
            }
        }

        // 中央水井
        self::well(7, 7, $floorY, $biome, $set);

        // 十字小路已由整片 path 铺好；四角放建筑
        $rand = new JavaRandom(LongMath::add(
            LongMath::mul($cx, 341873128712),
            LongMath::add($cz, self::SALT)
        ));

        // 四个角：至少保证一间铁匠；其余随机
        $spots = [[1, 1], [9, 1], [1, 9], [9, 9]];
        $kinds = [0, 1, 2, 3, 4, 5]; // house, big, farm, blacksmith, church, library
        // 强制第一角铁匠（若空间够）
        $forcedSmith = true;
        foreach ($spots as $i => [$hx, $hz]) {
            if ($forcedSmith) {
                self::blacksmith($hx, $hz, $floorY, $biome, $set);
                $forcedSmith = false;
                continue;
            }
            if ($rand->nextIntBound(5) === 0) {
                continue; // 空地
            }
            $kind = $kinds[$rand->nextIntBound(count($kinds))];
            match ($kind) {
                0 => self::house($hx, $hz, $floorY, $biome, $set),
                1 => self::bigHouse($hx, $hz, $floorY, $biome, $set),
                2 => self::farm($hx, $hz, $floorY, $biome, $set),
                3 => self::blacksmith($hx, $hz, $floorY, $biome, $set),
                4 => self::church($hx, $hz, $floorY, $biome, $set),
                default => self::library($hx, $hz, $floorY, $biome, $set),
            };
        }
    }

    private static function well(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $cobble = $biome === 2 ? 24 : 4;
        for ($dx = 0; $dx < 3; $dx++) {
            for ($dz = 0; $dz < 3; $dz++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x > 15 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 2 || $dz === 0 || $dz === 2);
                if ($edge) {
                    $set($x, $floorY, $z, $cobble, 0);
                    $set($x, $floorY + 1, $z, 85, 0); // 栅栏沿
                } else {
                    $set($x, $floorY - 1, $z, $cobble, 0);
                    $set($x, $floorY, $z, 9, 0); // 水
                    $set($x, $floorY + 1, $z, 0, 0);
                }
            }
        }
        // 井架
        $set($x0, $floorY + 2, $z0, $cobble, 0);
        $set($x0 + 2, $floorY + 2, $z0, $cobble, 0);
        $set($x0, $floorY + 2, $z0 + 2, $cobble, 0);
        $set($x0 + 2, $floorY + 2, $z0 + 2, $cobble, 0);
        $set($x0 + 1, $floorY + 3, $z0 + 1, $cobble, 0);
    }

    /** 5x5 小屋 */
    private static function house(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $wall = self::wallBlock($biome);
        $roof = self::roofBlock($biome);
        for ($dz = 0; $dz < 5; $dz++) {
            for ($dx = 0; $dx < 5; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 4 || $dz === 0 || $dz === 4);
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
                if (!$edge) {
                    continue;
                }
                for ($dy = 1; $dy <= 3; $dy++) {
                    $win = ($dy === 2) && (
                        (($dx === 0 || $dx === 4) && $dz === 2)
                        || (($dz === 0 || $dz === 4) && $dx === 2)
                    );
                    $set($x, $floorY + $dy, $z, $win ? 20 : $wall, 0);
                }
                $set($x, $floorY + 4, $z, $roof, 0);
            }
        }
        $doorX = $x0 + 2;
        $doorZ = min(15, $z0 + 4);
        if ($doorX >= 0 && $doorX <= 15) {
            $set($doorX, $floorY + 1, $doorZ, 0, 0);
            $set($doorX, $floorY + 2, $doorZ, 0, 0);
        }
    }

    /** 7x5 大屋 */
    private static function bigHouse(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $wall = self::wallBlock($biome);
        $roof = self::roofBlock($biome);
        $w = 6;
        $d = 4;
        for ($dz = 0; $dz <= $d; $dz++) {
            for ($dx = 0; $dx <= $w; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === $w || $dz === 0 || $dz === $d);
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
                if (!$edge) {
                    if ($dx === 3) { // 隔断
                        $set($x, $floorY + 1, $z, $wall, 0);
                        $set($x, $floorY + 2, $z, $wall, 0);
                    }
                    continue;
                }
                for ($dy = 1; $dy <= 3; $dy++) {
                    $win = ($dy === 2) && ($dx === 2 || $dx === 5) && ($dz === 0 || $dz === $d);
                    $set($x, $floorY + $dy, $z, $win ? 20 : $wall, 0);
                }
                $set($x, $floorY + 4, $z, $roof, 0);
            }
        }
        $set($x0 + 2, $floorY + 1, $z0 + $d, 0, 0);
        $set($x0 + 2, $floorY + 2, $z0 + $d, 0, 0);
        $set($x0 + 4, $floorY + 1, $z0 + $d, 0, 0);
        $set($x0 + 4, $floorY + 2, $z0 + $d, 0, 0);
    }

    /**
     * 铁匠铺：圆石主体、熔炉、岩浆池（栅栏围）、箱子。
     * 1.8 村庄铁匠是特色建筑，必须能认出。
     */
    private static function blacksmith(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $wall = 4; // 铁匠一律圆石（沙漠也用圆石，更像原版）
        $w = 6;
        $d = 5;
        for ($dz = 0; $dz <= $d; $dz++) {
            for ($dx = 0; $dx <= $w; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === $w || $dz === 0 || $dz === $d);
                $set($x, $floorY, $z, 4, 0); // 圆石地板
                if ($edge) {
                    for ($dy = 1; $dy <= 3; $dy++) {
                        $set($x, $floorY + $dy, $z, $wall, 0);
                    }
                    $set($x, $floorY + 4, $z, 44, 3); // 圆石台阶顶
                }
            }
        }
        // 门
        $doorX = min(15, $x0 + 3);
        $doorZ = min(15, $z0 + $d);
        $set($doorX, $floorY + 1, $doorZ, 0, 0);
        $set($doorX, $floorY + 2, $doorZ, 0, 0);

        // 内部：熔炉墙边、箱子、岩浆（栅栏护栏）
        $fx = min(15, $x0 + 1);
        $fz = min(15, $z0 + 1);
        $set($fx, $floorY + 1, $fz, 61, 0); // 熔炉

        $cx = min(15, $x0 + 5);
        $cz = min(15, $z0 + 1);
        $set($cx, $floorY + 1, $cz, 54, 0); // 铁匠箱子

        // 岩浆池 2x2 + 铁栏杆
        $lx = min(14, $x0 + 2);
        $lz = min(14, $z0 + 3);
        for ($dz = 0; $dz < 2; $dz++) {
            for ($dx = 0; $dx < 2; $dx++) {
                $set($lx + $dx, $floorY, $lz + $dz, 11, 0); // 岩浆源
            }
        }
        // 围一圈栅栏（铁匠常用铁栏，没有铁栏就用普通栅栏）
        for ($dx = -1; $dx <= 2; $dx++) {
            for ($dz = -1; $dz <= 2; $dz++) {
                if ($dx >= 0 && $dx < 2 && $dz >= 0 && $dz < 2) {
                    continue;
                }
                $bx = $lx + $dx;
                $bz = $lz + $dz;
                if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15) {
                    continue;
                }
                $set($bx, $floorY + 1, $bz, 101, 0); // 铁栏杆
            }
        }

        // 工作台 + 铁砧（1.8 有）
        $set(min(15, $x0 + 1), $floorY + 1, min(15, $z0 + 4), 58, 0);
        $set(min(15, $x0 + 5), $floorY + 1, min(15, $z0 + 4), 145, 0);
    }

    /** 小教堂：更高的塔 */
    private static function church(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $wall = self::wallBlock($biome);
        $w = 5;
        $d = 5;
        for ($dz = 0; $dz <= $d; $dz++) {
            for ($dx = 0; $dx <= $w; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === $w || $dz === 0 || $dz === $d);
                $set($x, $floorY, $z, self::pathBlock($biome), 0);
                if ($edge) {
                    for ($dy = 1; $dy <= 5; $dy++) {
                        $win = ($dy === 3) && ($dx === intdiv($w, 2) || $dz === intdiv($d, 2));
                        $set($x, $floorY + $dy, $z, $win ? 20 : $wall, 0);
                    }
                }
            }
        }
        // 塔顶
        $tx = min(15, $x0 + intdiv($w, 2));
        $tz = min(15, $z0 + intdiv($d, 2));
        for ($dy = 6; $dy <= 8; $dy++) {
            $set($tx, $floorY + $dy, $tz, $wall, 0);
        }
        $set($tx, $floorY + 9, $tz, 50, 0); // 火把当尖顶灯
        // 门
        $set(min(15, $x0 + 2), $floorY + 1, min(15, $z0 + $d), 0, 0);
        $set(min(15, $x0 + 2), $floorY + 2, min(15, $z0 + $d), 0, 0);
    }

    /** 图书馆：书架墙 + 工作台 */
    private static function library(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        $wall = self::wallBlock($biome);
        $roof = self::roofBlock($biome);
        $w = 5;
        $d = 5;
        for ($dz = 0; $dz <= $d; $dz++) {
            for ($dx = 0; $dx <= $w; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === $w || $dz === 0 || $dz === $d);
                $set($x, $floorY, $z, 5, 0); // 木板地板
                if ($edge) {
                    for ($dy = 1; $dy <= 3; $dy++) {
                        // 内墙书架感：靠墙一格放书架
                        $set($x, $floorY + $dy, $z, $wall, 0);
                    }
                    $set($x, $floorY + 4, $z, $roof, 0);
                } elseif ($dx === 1 || $dx === $w - 1) {
                    $set($x, $floorY + 1, $z, 47, 0); // 书架
                    $set($x, $floorY + 2, $z, 47, 0);
                }
            }
        }
        $set(min(15, $x0 + 2), $floorY + 1, min(15, $z0 + $d), 0, 0);
        $set(min(15, $x0 + 2), $floorY + 2, min(15, $z0 + $d), 0, 0);
        $set(min(15, $x0 + 3), $floorY + 1, min(15, $z0 + 2), 58, 0); // 工作台
    }

    private static function farm(int $x0, int $z0, int $floorY, int $biome, callable $set): void
    {
        if ($biome === 2) {
            // 沙漠：变成小沙屋而不是农田
            self::house($x0, $z0, $floorY, $biome, $set);
            return;
        }
        for ($dz = 0; $dz < 5; $dz++) {
            for ($dx = 0; $dx < 5; $dx++) {
                $x = $x0 + $dx;
                $z = $z0 + $dz;
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                $edge = ($dx === 0 || $dx === 4 || $dz === 0 || $dz === 4);
                if ($edge) {
                    if (!($dx === 2 && $dz === 4)) {
                        $set($x, $floorY + 1, $z, 85, 0);
                    }
                    $set($x, $floorY, $z, 13, 0);
                    continue;
                }
                if ($dx === 2 && $dz === 2) {
                    $set($x, $floorY, $z, 9, 0);
                    continue;
                }
                $set($x, $floorY, $z, 60, 0);
                $set($x, $floorY + 1, $z, 59, 7);
            }
        }
    }

    private static function wallBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,
            35 => 5,
            default => 4,
        };
    }

    private static function roofBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,
            default => 5,
        };
    }

    private static function pathBlock(int $biome): int
    {
        return match ($biome) {
            2 => 24,
            default => 13,
        };
    }
}
