<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 简化 1.8 地牢：圆石小房间 + 刷怪笼 + 1~2 箱子。
 * 坐标为区块内局部 0..15。
 *
 * @param callable $get function(int $x,int $y,int $z): int
 * @param callable $set function(int $x,int $y,int $z,int $id,int $meta=0): void
 */
final class DungeonGenerator
{
    public static function tryGenerate(
        int $seed,
        int $cx,
        int $cz,
        callable $get,
        callable $set
    ): void {
        $rand = new JavaRandom($seed ^ ($cx * 341873128712) ^ ($cz * 132897987541) ^ 9871);
        if ($rand->nextIntBound(80) !== 0) {
            return;
        }

        $sizeX = 5 + $rand->nextIntBound(3);
        $sizeZ = 5 + $rand->nextIntBound(3);
        $rx = 1 + $rand->nextIntBound(max(1, 14 - $sizeX));
        $rz = 1 + $rand->nextIntBound(max(1, 14 - $sizeZ));
        $ry = 20 + $rand->nextIntBound(25);

        $stone = 0;
        $air = 0;
        for ($x = $rx; $x < $rx + $sizeX; $x++) {
            for ($z = $rz; $z < $rz + $sizeZ; $z++) {
                for ($y = $ry; $y < $ry + 4; $y++) {
                    $id = $get($x, $y, $z);
                    if ($id === 1 || $id === 3) {
                        $stone++;
                    } elseif ($id === 0) {
                        $air++;
                    }
                }
            }
        }
        if ($stone < ($sizeX * $sizeZ * 2) || $air > ($sizeX * $sizeZ)) {
            return;
        }

        for ($x = $rx - 1; $x <= $rx + $sizeX; $x++) {
            for ($z = $rz - 1; $z <= $rz + $sizeZ; $z++) {
                if ($x < 0 || $x > 15 || $z < 0 || $z > 15) {
                    continue;
                }
                for ($y = $ry - 1; $y <= $ry + 3; $y++) {
                    $edge = ($x === $rx - 1 || $x === $rx + $sizeX
                        || $z === $rz - 1 || $z === $rz + $sizeZ
                        || $y === $ry - 1 || $y === $ry + 3);
                    if ($edge) {
                        $wall = ($rand->nextIntBound(4) === 0) ? 48 : 4;
                        $set($x, $y, $z, $wall, 0);
                    } else {
                        $set($x, $y, $z, 0, 0);
                    }
                }
            }
        }

        $sx = $rx + intdiv($sizeX, 2);
        $sz = $rz + intdiv($sizeZ, 2);
        $set($sx, $ry, $sz, 52, 0);

        $chests = 1 + $rand->nextIntBound(2);
        for ($i = 0; $i < $chests; $i++) {
            $side = $rand->nextIntBound(4);
            $cx2 = $sx;
            $cz2 = $sz;
            if ($side === 0) {
                $cx2 = $rx;
                $cz2 = min(15, max(0, $rz + 1 + $rand->nextIntBound(max(1, $sizeZ - 2))));
            } elseif ($side === 1) {
                $cx2 = $rx + $sizeX - 1;
                $cz2 = min(15, max(0, $rz + 1 + $rand->nextIntBound(max(1, $sizeZ - 2))));
            } elseif ($side === 2) {
                $cz2 = $rz;
                $cx2 = min(15, max(0, $rx + 1 + $rand->nextIntBound(max(1, $sizeX - 2))));
            } else {
                $cz2 = $rz + $sizeZ - 1;
                $cx2 = min(15, max(0, $rx + 1 + $rand->nextIntBound(max(1, $sizeX - 2))));
            }
            if ($cx2 >= 0 && $cx2 <= 15 && $cz2 >= 0 && $cz2 <= 15) {
                $set($cx2, $ry, $cz2, 54, 0);
            }
        }
    }
}
