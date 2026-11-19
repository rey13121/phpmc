<?php
declare(strict_types=1);

namespace PhpMc\World;

/**
 * 1.8 风格基础战利品表（简化权重，不做附魔书/完整 NBT）。
 * 池格式：[[itemId, minCount, maxCount, weight, damage?], ...]
 */
final class LootTables
{
    /** 地牢箱子 */
    private const DUNGEON = [
        [265, 1, 4, 10],   // 铁锭
        [297, 1, 1, 15],   // 面包
        [296, 1, 4, 15],   // 小麦
        [289, 1, 4, 10],   // 火药
        [287, 1, 4, 10],   // 线
        [325, 1, 1, 10],   // 桶
        [331, 1, 4, 10],   // 红石
        [263, 1, 4, 10],   // 煤炭
        [361, 2, 4, 10],   // 南瓜种子
        [362, 2, 4, 10],   // 西瓜种子
        [329, 1, 1, 5],    // 鞍
        [322, 1, 1, 1],    // 金苹果
        [266, 1, 4, 5],    // 金锭
        [264, 1, 1, 3],    // 钻石
        [267, 1, 1, 5],    // 铁剑
        [257, 1, 1, 5],    // 铁镐
        [345, 1, 1, 10],   // 指南针（近似占位）
        [368, 1, 1, 2],    // 末影珍珠（偏少）
    ];

    /** 废弃矿道箱子 */
    private const MINESHAFT = [
        [66, 4, 8, 20],    // 铁轨
        [50, 1, 16, 15],   // 火把
        [263, 3, 8, 15],   // 煤炭
        [265, 1, 5, 10],   // 铁锭
        [266, 1, 3, 5],    // 金锭
        [331, 4, 9, 5],    // 红石
        [351, 4, 9, 5],    // 染料（青金石 meta=4 单独处理）
        [297, 1, 3, 15],   // 面包
        [361, 2, 4, 10],   // 南瓜种子
        [362, 2, 4, 10],   // 西瓜种子
        [322, 1, 1, 3],    // 金苹果
        [257, 1, 1, 5],    // 铁镐
        [256, 1, 1, 5],    // 铁铲
        [267, 1, 1, 3],    // 铁剑
        [264, 1, 1, 3],    // 钻石
        [388, 1, 1, 2],    // 绿宝石
        [329, 1, 1, 3],    // 鞍
        [289, 1, 4, 5],    // 火药
        [287, 1, 4, 5],    // 线
    ];

    /** 村庄房屋箱子（若以后接入） */
    private const VILLAGE = [
        [296, 1, 3, 10],
        [297, 1, 2, 10],
        [260, 1, 3, 5],    // 苹果
        [265, 1, 3, 5],
        [6, 1, 2, 5],      // 树苗
        [281, 1, 1, 2],    // 碗
        [351, 1, 3, 5],
    ];

    /**
     * 生成 27 格箱子内容。
     * @param 'dungeon'|'mineshaft'|'village' $pool
     * @return array<int, array{id:int,count:int,damage:int}|null>
     */
    public static function rollChest(string $pool, ?int $seed = null): array
    {
        $table = match ($pool) {
            'mineshaft' => self::MINESHAFT,
            'village' => self::VILLAGE,
            default => self::DUNGEON,
        };

        $slots = array_fill(0, 27, null);
        $rolls = match ($pool) {
            'mineshaft' => random_int(3, 8),
            'village' => random_int(2, 5),
            default => random_int(3, 7),
        };

        $totalWeight = 0;
        foreach ($table as $row) {
            $totalWeight += $row[3];
        }
        if ($totalWeight <= 0) {
            return $slots;
        }

        for ($r = 0; $r < $rolls; $r++) {
            $pick = random_int(1, $totalWeight);
            $acc = 0;
            $chosen = $table[0];
            foreach ($table as $row) {
                $acc += $row[3];
                if ($pick <= $acc) {
                    $chosen = $row;
                    break;
                }
            }
            [$id, $minC, $maxC, , $dmg] = array_pad($chosen, 5, 0);
            $count = random_int($minC, $maxC);
            // 青金石：id 351 damage 4
            if ($id === 351 && $pool === 'mineshaft' && ($dmg === 0)) {
                $dmg = 4;
            }
            $slot = random_int(0, 26);
            // 找空位
            for ($t = 0; $t < 27; $t++) {
                $s = ($slot + $t) % 27;
                if ($slots[$s] === null) {
                    $slots[$s] = [
                        'id' => (int) $id,
                        'count' => (int) $count,
                        'damage' => (int) $dmg,
                    ];
                    break;
                }
            }
        }

        return $slots;
    }
}
