<?php
declare(strict_types=1);

namespace PhpMc;

use PhpMc\Nbt\Nbt;
use PhpMc\Nbt\Tag;

/**
 * 酿造台（方块 117）。
 *
 * 槽位：0/1/2 = 药水瓶，3 = 材料，4 = 燃料（烈焰粉）。
 * 一次酿造 400 tick（20 秒）。
 *
 * 只做最常用的一条链：水瓶 + 地狱疣 → 粗制的药水，
 * 再往下支持几种常见材料。药水的 damage 编码（1.8）：
 *   damage = 类型 | (等级 << 5) | (延长 << 6) | (喷溅 << 14)
 */
trait BrewingTrait
{
    /** @var array<string,array> "x,y,z" => ['slots'=>[5格], 'brew'=>int, 'fuel'=>int] */
    private array $brews = [];
    private bool $brewsLoaded = false;
    private float $lastBrewTick = 0.0;

    /**
     * 可作酿造材料的物品（用于“有没有配方”判断）。
     * 真正结果由 brewTransform() 按「当前药水 damage + 材料」计算。
     */
    private const BREW_INGREDIENTS = [
        372, // 地狱疣
        353, // 糖
        382, // 闪烁的西瓜
        349, // 鱼（水肺近似）
        378, // 岩浆膏
        375, // 蜘蛛眼
        370, // 恶魂之泪
        377, // 烈焰粉（力量；也作燃料）
        396, // 金胡萝卜
        414, // 兔脚
        376, // 发酵蜘蛛眼
        331, // 红石（延长）
        348, // 荧石粉（增强）
        289, // 火药（喷溅）
    ];

    /**
     * 1.8 常用药水链（damage 简化编码，与旧版兼容）：
     * 0 水瓶 / 16 粗制 / 1 迅捷 / 2 治疗 / 3 水肺 / 4 抗火 / 5 毒 /
     * 6 虚弱 / 7 再生 / 8 力量 / 9 夜视 / 10 跳跃
     * bit6 延长 bit5 增强 bit14 喷溅（近似）
     */
    private function brewTransform(int $ingId, int $dmg): ?int
    {
        $base = $dmg & 0x1F;
        $ext = ($dmg >> 6) & 1;
        $enh = ($dmg >> 5) & 1;
        $splash = ($dmg >> 14) & 1;

        // 火药：变喷溅
        if ($ingId === 289) {
            return $base === 0 ? null : ($dmg | (1 << 14));
        }
        // 红石：延长（清增强）
        if ($ingId === 331) {
            if ($base === 0 || $base === 16) {
                return null;
            }
            return ($base) | (1 << 6) | ($splash << 14);
        }
        // 荧石：增强（清延长）
        if ($ingId === 348) {
            if ($base === 0 || $base === 16) {
                return null;
            }
            return ($base) | (1 << 5) | ($splash << 14);
        }
        // 发酵蜘蛛眼：腐蚀
        if ($ingId === 376) {
            $map = [1 => 5, 5 => 6, 7 => 5, 8 => 6, 2 => 5, 0 => 6, 16 => 6];
            if (!isset($map[$base])) {
                return null;
            }
            return $map[$base] | ($splash << 14);
        }
        // 地狱疣：水瓶 -> 粗制
        if ($ingId === 372) {
            return $base === 0 ? 16 : null;
        }
        // 只能从粗制往下做主效果
        if ($base !== 16 && $base !== 0) {
            // 已有主效果时，其它材料一般无效（红石/荧石/火药/腐蚀已处理）
            return null;
        }
        if ($base === 0 && $ingId !== 372) {
            return null; // 必须先粗制
        }
        $effect = match ($ingId) {
            353 => 1,   // 糖 -> 迅捷
            382 => 2,   // 闪烁的西瓜 -> 治疗
            349 => 3,   // 鱼/河豚近似 -> 水肺
            378 => 4,   // 岩浆膏 -> 抗火
            375 => 5,   // 蜘蛛眼 -> 毒
            370 => 7,   // 恶魂之泪 -> 再生
            377 => 8,   // 烈焰粉 -> 力量
            396 => 9,   // 金胡萝卜 -> 夜视
            414 => 10,  // 兔脚 -> 跳跃
            default => null,
        };
        if ($effect === null) {
            return null;
        }
        return $effect | ($splash << 14);
    }

    private function brewFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'brewing.json';
    }

    private function loadBrews(): void
    {
        if ($this->brewsLoaded) {
            return;
        }
        $this->brewsLoaded = true;
        $f = $this->brewFile();
        if (is_file($f)) {
            $json = json_decode((string) @file_get_contents($f), true);
            if (is_array($json)) {
                $this->brews = $json;
            }
        }
    }

    private function saveBrews(): void
    {
        $dir = dirname($this->brewFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->brewFile(), json_encode($this->brews), LOCK_EX);
    }

    private function ensureBrew(int $x, int $y, int $z): void
    {
        $this->loadBrews();
        $k = $x . ',' . $y . ',' . $z;
        if (!isset($this->brews[$k])) {
            $this->brews[$k] = ['slots' => [null, null, null, null], 'brew' => 0, 'fuel' => 0];
            $this->saveBrews();
        }
    }

    private function openBrewingStand(Connection $c, int $x, int $y, int $z): void
    {
        $this->ensureBrew($x, $y, $z);
        $c->furnace = null;
        $this->closeChest($c);
        $this->closeWorkbench($c);
        $this->returnCraftGrid($c);
        $c->brewing = ['x' => $x, 'y' => $y, 'z' => $z, 'cursor' => $c->cursor];
        $c->cursor = null;

        $c->send(self::C_OPEN_WINDOW, (new Writer())
            ->byte(5)                                  // 酿造台用 windowId=5，避开箱子/熔炉
            ->string('minecraft:brewing_stand')
            ->string(json_encode(['text' => '酿造台'], JSON_UNESCAPED_UNICODE))
            ->byte(40));                               // 1.8 只有 4 格 + 36 背包

        $this->sendBrewWindow($c);
    }

    private function brewSlots(Connection $c): array
    {
        if ($c->brewing === null) {
            return [];
        }
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        $b = $this->brews[$k] ?? ['slots' => array_fill(0, 5, null)];
        $out = [];
        // 必须固定发 5 格：少一格客户端后面的背包就会整体前移，
        // 表现就是「点这里东西跑到别处」。
        for ($i = 0; $i < 4; $i++) {
            $out[] = $b['slots'][$i] ?? null;
        }
        for ($i = 9; $i <= 44; $i++) {
            $out[] = $c->inventory[$i] ?? null;
        }

        return $out;
    }

    private function sendBrewWindow(Connection $c): void
    {
        if ($c->brewing === null) {
            return;
        }
        $slots = $this->brewSlots($c);
        $w = (new Writer())->byte(5)->short(count($slots));
        foreach ($slots as $it) {
            $w->raw($this->slotBytes($it));
        }
        $c->send(self::C_WINDOW_ITEMS, $w);
        $this->sendBrewProgress($c);
    }

    private function sendBrewProgress(Connection $c): void
    {
        if ($c->brewing === null) {
            return;
        }
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        $b = $this->brews[$k] ?? ['brew' => 0, 'fuel' => 0];
        // 0 = 已酿时长，1 = 燃料
        $c->send(self::C_WINDOW_PROPERTY, (new Writer())->byte(5)->short(0)->short(min(400, (int) $b['brew'])));
        $c->send(self::C_WINDOW_PROPERTY, (new Writer())->byte(5)->short(1)->short((int) $b['fuel']));
    }

    private function clickBrewing(Connection $c, int $slot, int $button, int $mode, ?array $cursor): void
    {
        if ($c->brewing === null) {
            return;
        }
        $this->log("  酿造点击 slot={$slot} button={$button} mode={$mode} cursor=" . json_encode($c->cursor));
        $k = $c->brewing['x'] . ',' . $c->brewing['y'] . ',' . $c->brewing['z'];
        if (!isset($this->brews[$k])) {
            $this->ensureBrew($c->brewing['x'], $c->brewing['y'], $c->brewing['z']);
        }
        $get = function (int $s) use ($c, $k) {
            if ($s < 4) {
                return $this->brews[$k]['slots'][$s] ?? null;
            }
            return $c->inventory[9 + ($s - 4)] ?? null;
        };
        $put = function (int $s, ?array $item) use ($c, $k): void {
            if ($s < 4) {
                $this->brews[$k]['slots'][$s] = $item;
                return;
            }
            $inv = 9 + ($s - 4);
            if ($item === null) {
                unset($c->inventory[$inv]);
            } else {
                $c->inventory[$inv] = $item;
            }
        };

        $current = $get($slot);
        if ($mode === 3) {
            if ($slot >= 0 && $slot <= 39 && $current !== null) {
                $c->cursor = $current;
                $this->sendBrewWindow($c);
                $this->sendPlayerInventory($c);
            }
            return;
        }
        if ($mode === 1 && $current !== null) {
            $range = $slot < 4 ? [4, 39] : [0, 3];
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                if ($get($i) !== null) {
                    continue;
                }
                $put($i, $current);
                $put($slot, null);
                $this->saveBrews();
                $this->sendBrewWindow($c);
                $this->sendPlayerInventory($c);
                return;
            }
            $this->sendBrewWindow($c);
            return;
        }
        if ($mode !== 0 || ($button !== 0 && $button !== 1)) {
            return;
        }
        [$next, $newCursor] = self::clickedStacks($current, $c->cursor, $button);
        $c->cursor = $newCursor;
        $put($slot, $next);
        $this->saveBrews();
        $this->sendBrewWindow($c);
        $this->sendPlayerInventory($c);
    }

    /** 每 2 秒推进一次酿造进度 */
    private function tickBrewing(float $now): void
    {
        if ($now - $this->lastBrewTick < 2.0) {
            return;
        }
        $elapsed = $now - ($this->lastBrewTick ?: $now);
        $this->lastBrewTick = $now;
        $this->loadBrews();
        if ($this->brews === []) {
            return;
        }
        $ticks = (int) round($elapsed * 20);

        foreach ($this->brews as $k => &$b) {
            $ing = $b['slots'][3] ?? null;

            // 1.8：烈焰粉不是独立槽位，它放在材料格（槽 3）里当燃料。
            // 材料格是烈焰粉且没燃料时，先充 20 次。
            if ($ing !== null && (int) $ing['id'] === 377 && (int) $b['fuel'] <= 0) {
                $b['fuel'] = 20;
                $b['slots'][3] = ((int) $ing['count'] > 1)
                    ? ['id' => 377, 'count' => (int) $ing['count'] - 1, 'damage' => 0]
                    : null;
                $ing = $b['slots'][3] ?? null;
            }
            // 注意：烈焰粉既可作燃料也可作力量材料；燃料已在上方处理，
            // 这里不再无条件跳过 377，否则永远酿不出力量药水。
            if ($ing === null
                || !in_array((int) $ing['id'], self::BREW_INGREDIENTS, true)
                || (int) $b['fuel'] <= 0) {
                continue;
            }
            // 三个槽里至少要有一个水瓶
            $any = false;
            for ($i = 0; $i < 3; $i++) {
                $it = $b['slots'][$i] ?? null;
                if (is_array($it) && (int) $it['id'] === 373) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            if ($b['fuel'] <= 0) {
                $fuel = $b['slots'][4] ?? null;
                if (is_array($fuel) && (int) $fuel['id'] === 377) {   // 烈焰粉
                    $b['fuel'] = 20;
                    $b['slots'][4] = ((int) $fuel['count'] > 1)
                        ? ['id' => 377, 'count' => (int) $fuel['count'] - 1, 'damage' => 0]
                        : null;
                } else {
                    continue;
                }
            }

            $b['brew'] = (int) $b['brew'] + $ticks;
            if ($b['brew'] >= 400) {
                $b['brew'] = 0;
                $b['fuel'] = max(0, (int) $b['fuel'] - 1);
                for ($i = 0; $i < 3; $i++) {
                    $it = $b['slots'][$i] ?? null;
                    if (is_array($it) && (int) $it['id'] === 373) {
                        $outDamage = $this->brewTransform((int) $ing['id'], (int) ($it['damage'] ?? 0));
                        if ($outDamage === null) {
                            continue;
                        }
                        $b['slots'][$i] = ['id' => 373, 'count' => (int) $it['count'], 'damage' => $outDamage];
                    }
                }
                $b['slots'][3] = ((int) $ing['count'] > 1)
                    ? ['id' => $ing['id'], 'count' => (int) $ing['count'] - 1, 'damage' => (int) ($ing['damage'] ?? 0)]
                    : null;
            }
        }
        unset($b);
        $this->saveBrews();

        foreach ($this->players() as $p) {
            if ($p->brewing !== null) {
                $this->sendBrewWindow($p);
            }
        }
    }
}
