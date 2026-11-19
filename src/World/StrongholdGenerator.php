<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 简化要塞：门厅 + 走廊 + 末地门房间（12 框架正确朝向）+ 银鱼刷怪笼。
 */
final class StrongholdGenerator
{
    public static function tryGenerate(int $seed, int $cx, int $cz, callable $get, callable $set): void
    {
        $rand = new JavaRandom($seed ^ ($cx * 341873128712) ^ ($cz * 132897987541) ^ 10387313);
        if ((($cx % 48 + 48) % 48) !== $rand->nextIntBound(48)) {
            return;
        }
        if ((($cz % 48 + 48) % 48) !== $rand->nextIntBound(48)) {
            return;
        }
        if ($rand->nextIntBound(4) !== 0) {
            return;
        }

        $y = 22 + $rand->nextIntBound(18);

        // 主厅 11×11
        self::box($set, 0, $y, 0, 10, 6, 10, 98, 0);
        self::hollow($set, 1, $y + 1, 1, 9, 5, 9);
        // 门洞朝 -Z
        $set(5, $y + 1, 0, 0, 0);
        $set(5, $y + 2, 0, 0, 0);

        // 走廊向 +X
        self::box($set, 10, $y, 3, 15, 5, 7, 98, 0);
        self::hollow($set, 10, $y + 1, 4, 15, 4, 6);

        // 末地门房（走廊尽头，仍在本区块内用 10-15）
        // 门框架相对坐标：中心约 (12, 5)
        $fy = $y + 1;
        // 12 框、无四角；meta: 方向 | 0（未嵌眼）
        // 北 z=3 朝南(0)；南 z=7 朝北(2)；西 x=10 朝东(3)；东 x=14 朝西(1)
        foreach ([11, 12, 13] as $x) {
            $set($x, $fy, 3, 120, 0);
            $set($x, $fy, 7, 120, 2);
        }
        foreach ([4, 5, 6] as $z) {
            $set(10, $fy, $z, 120, 3);
            $set(14, $fy, $z, 120, 1);
        }
        // 门内空气
        for ($x = 11; $x <= 13; $x++) {
            for ($z = 4; $z <= 6; $z++) {
                $set($x, $fy, $z, 0, 0);
            }
        }

        // 银鱼刷怪笼
        $set(5, $y + 1, 5, 52, 0);

        // 箱子 ×2
        $set(2, $y + 1, 2, 54, 0);
        $set(8, $y + 1, 8, 54, 0);

        // 书架墙
        for ($dy = 1; $dy <= 3; $dy++) {
            $set(1, $y + $dy, 8, 47, 0);
            $set(1, $y + $dy, 9, 47, 0);
        }

        // 石砖楼梯装饰
        $set(5, $y + 1, 9, 109, 3);
    }

    private static function box(callable $set, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, int $id, int $meta): void
    {
        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                for ($z = $z0; $z <= $z1; $z++) {
                    if ($x > 15 || $z > 15 || $y < 0 || $y > 255) {
                        continue;
                    }
                    $edge = ($x === $x0 || $x === $x1 || $y === $y0 || $y === $y1 || $z === $z0 || $z === $z1);
                    if ($edge) {
                        $set($x, $y, $z, $id, $meta);
                    }
                }
            }
        }
    }

    private static function hollow(callable $set, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1): void
    {
        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                for ($z = $z0; $z <= $z1; $z++) {
                    if ($x > 15 || $z > 15) {
                        continue;
                    }
                    $set($x, $y, $z, 0, 0);
                }
            }
        }
    }
}
