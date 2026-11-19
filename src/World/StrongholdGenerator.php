<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 简化要塞：地下石砖房间 + 末地门框架圈（未激活）。
 * 不是原版多房间迷宫，仅提供可探索的末地门结构雏形。
 */
final class StrongholdGenerator
{
    /** 每 48 区块网格尝试一次 */
    public static function tryGenerate(int $seed, int $cx, int $cz, callable $get, callable $set): void
    {
        $rand = new JavaRandom($seed ^ ($cx * 341873128712) ^ ($cz * 132897987541) ^ 10387313);
        // 稀疏
        if ((($cx % 48 + 48) % 48) !== $rand->nextIntBound(48)) {
            return;
        }
        if ((($cz % 48 + 48) % 48) !== $rand->nextIntBound(48)) {
            return;
        }
        if ($rand->nextIntBound(5) !== 0) {
            return;
        }

        $y = 25 + $rand->nextIntBound(15);
        // 外框房间 11x11
        for ($dx = 0; $dx < 11; $dx++) {
            for ($dz = 0; $dz < 11; $dz++) {
                for ($dy = 0; $dy < 7; $dy++) {
                    $x = $dx;
                    $z = $dz;
                    if ($x > 15 || $z > 15) {
                        continue;
                    }
                    $edge = ($dx === 0 || $dx === 10 || $dz === 0 || $dz === 10 || $dy === 0 || $dy === 6);
                    if ($edge) {
                        $set($x, $y + $dy, $z, 98, 0); // 石砖
                    } else {
                        $set($x, $y + $dy, $z, 0, 0);
                    }
                }
            }
        }
        // 门洞
        $set(5, $y + 1, 0, 0, 0);
        $set(5, $y + 2, 0, 0, 0);

        // 末地门框架 5x5 开口向上（方块 120）
        $fx = 3;
        $fz = 3;
        $fy = $y + 1;
        for ($i = 0; $i < 5; $i++) {
            $set($fx + $i, $fy, $fz, 120, 0);
            $set($fx + $i, $fy, $fz + 4, 120, 0);
            $set($fx, $fy, $fz + $i, 120, 0);
            $set($fx + 4, $fy, $fz + $i, 120, 0);
        }
        // 中央一格箱子
        $set(5, $y + 1, 5, 54, 0);
        // 书架装饰
        $set(1, $y + 1, 1, 47, 0);
        $set(1, $y + 2, 1, 47, 0);
        $set(9, $y + 1, 9, 47, 0);
        $set(9, $y + 2, 9, 47, 0);
    }
}
