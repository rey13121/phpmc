<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 红石：邻居更新 + 红石粉(55) 的通电/断电。
 *
 * 现在支持的电源：
 *   152 红石块      —— 恒定 15
 *    76 红石火把(亮) —— 恒定 15
 *    69 拉杆        —— meta & 0x8 表示打开
 *    77 按钮        —— meta & 0x8 表示按下
 *
 * 规则（原版的大方向）：
 *   - 红石粉旁边有电源 → 15
 *   - 否则取相邻红石粉的最大值 - 1，最低 0
 *   - 迭代到稳定（最多 16 轮，红石最多传 15 格）
 *
 * 还没做的：火把的反相、中继器的延迟和方向、活塞、充能方块的强弱充能。
 */
trait RedstoneTrait
{
    /** 防止 changeBlock 和红石更新互相递归 */
    private bool $redstoneBusy = false;

    private function isRedstoneWire(int $id): bool
    {
        return $id === 55;
    }

    /** 这个方块作为「电源」能给导线多少电 */
    private function redstoneSourcePower(int $x, int $y, int $z): int
    {
        $state = $this->world->chunk($x >> 4, $z >> 4)->blockState($x & 15, $y, $z & 15);
        $id = $state >> 4;
        $meta = $state & 0x0F;

        if ($id === 152) {          // 红石块
            return 15;
        }
        if ($id === 76) {           // 红石火把（亮）
            return 15;
        }
        if ($id === 69 || $id === 77) {   // 拉杆 / 按钮
            return ($meta & 0x8) !== 0 ? 15 : 0;
        }
        // 中继器(93 未锁定 / 94 锁定)：简化——有输入侧电就输出 15
        if ($id === 93 || $id === 94) {
            return $this->repeaterOutputPower($x, $y, $z, $meta);
        }
        // 比较器(149/150)：有侧向或后方输入则输出 15（简化，不做容器读数）
        if ($id === 149 || $id === 150) {
            return $this->maxNeighborWirePower($x, $y, $z) > 0
                || $this->redstoneSourcePowerAdjacent($x, $y, $z) > 0 ? 15 : 0;
        }

        return 0;
    }

    /** 中继器朝向 meta&0x3：0 北 1 东 2 南 3 西；输入来自朝向的反方向 */
    private function repeaterOutputPower(int $x, int $y, int $z, int $meta): int
    {
        $dir = $meta & 3;
        // 输出朝向 dir，输入在对面
        $in = match ($dir) {
            0 => [0, 1],   // 朝北，输入南
            1 => [-1, 0],  // 朝东，输入西
            2 => [0, -1],  // 朝南，输入北
            default => [1, 0], // 朝西，输入东
        };
        $ix = $x + $in[0];
        $iz = $z + $in[1];
        // 输入侧导线或电源
        $st = $this->world->chunk($ix >> 4, $iz >> 4)->blockState($ix & 15, $y, $iz & 15);
        $iid = $st >> 4;
        if ($this->isRedstoneWire($iid) && ($st & 0x0F) > 0) {
            return 15;
        }
        if ($this->redstoneSourcePower($ix, $y, $iz) > 0) {
            return 15;
        }
        // 强充能：输入侧固体背后有电源（简化：检查再外侧）
        $ix2 = $ix + $in[0];
        $iz2 = $iz + $in[1];
        if ($this->redstoneSourcePower($ix2, $y, $iz2) > 0) {
            return 15;
        }
        return 0;
    }

    private function redstoneSourcePowerAdjacent(int $x, int $y, int $z): int
    {
        $best = 0;
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            $best = max($best, $this->redstoneSourcePower($x + $dx, $y + $dy, $z + $dz));
        }
        return $best;
    }

    /** 某个红石粉格应该在的强度 */
    private function wirePowerAt(int $x, int $y, int $z): int
    {
        // 自己贴着电源就是 15
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            if ($this->redstoneSourcePower($x + $dx, $y + $dy, $z + $dz) > 0) {
                return 15;
            }
        }

        // 否则取相邻导线最大值 - 1，或朝向本格的中继器输出
        $best = 0;
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            $nz = $z + $dz;
            if ($ny < 0 || $ny > 255) {
                continue;
            }
            $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
            $nid = $state >> 4;
            if ($this->isRedstoneWire($nid)) {
                $best = max($best, ($state & 0x0F) - 1);
                continue;
            }
            if ($nid === 93 || $nid === 94) {
                $pow = $this->repeaterOutputPower($nx, $ny, $nz, $state & 0x0F);
                if ($pow > 0) {
                    // 中继器输出方向应对准本格
                    $dir = ($state & 3);
                    $out = match ($dir) {
                        0 => [0, -1], 1 => [1, 0], 2 => [0, 1], default => [-1, 0],
                    };
                    if ($nx + $out[0] === $x && $nz + $out[1] === $z) {
                        $best = max($best, 15);
                    }
                }
            }
        }

        return max(0, $best);
    }

    /**
     * 某个方块变了之后调用：把它周围连成一片的导线全部重算一遍。
     * 不是逐格触发，而是先 BFS 收集连通集合、再迭代到稳定 —— 这样
     * 断线、拐弯、多条路径汇合都能算对。
     */
    private function updateRedstoneAround(int $x, int $y, int $z): void
    {
        if ($this->redstoneBusy) {
            return;
        }
        $this->redstoneBusy = true;

        // ① 收集连通的红石粉
        $wires = [];
        $queue = [[$x, $y, $z]];
        $visited = [$x . ',' . $y . ',' . $z => true];

        while ($queue !== []) {
            [$cx, $cy, $cz] = array_shift($queue);
            foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;
                $nz = $cz + $dz;
                if ($ny < 0 || $ny > 255) {
                    continue;
                }
                $key = $nx . ',' . $ny . ',' . $nz;
                if (isset($visited[$key])) {
                    continue;
                }
                $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
                if (!$this->isRedstoneWire($state >> 4)) {
                    continue;
                }
                $visited[$key] = true;
                $wires[$key] = [$nx, $ny, $nz];
                $queue[] = [$nx, $ny, $nz];
            }
        }

        if ($wires === []) {
            $this->redstoneBusy = false;

            return;
        }

        // ② 迭代到稳定
        for ($round = 0; $round < 16; $round++) {
            $changed = false;
            foreach ($wires as [$wx, $wy, $wz]) {
                $cur = $this->world->chunk($wx >> 4, $wz >> 4)->blockState($wx & 15, $wy, $wz & 15) & 0x0F;
                $want = $this->wirePowerAt($wx, $wy, $wz);
                if ($want !== $cur) {
                    $chunk = $this->world->chunk($wx >> 4, $wz >> 4);
                    $chunk->setBlock($wx & 15, $wy, $wz & 15, 55, $want);
                    $chunk->dirty = true;
                    $pos = $this->packPositionBytes($wx, $wy, $wz);
                    $state = (55 << 4) | $want;
                    foreach ($this->players() as $p) {
                        $p->send(self::C_BLOCK_CHANGE, (new Writer())->raw($pos)->varInt($state));
                    }
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        // ③ 导线稳定之后，处理「受电控制」的方块：火把反相、木门开合
        $this->applyRedstoneConsumers($wires);

        $this->redstoneBusy = false;
    }

    /** 某格周围（六向）最大的红石粉强度 */
    private function maxNeighborWirePower(int $x, int $y, int $z): int
    {
        $best = 0;
        foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny > 255) {
                continue;
            }
            $nx = $x + $dx;
            $nz = $z + $dz;
            $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
            if (!$this->isRedstoneWire($state >> 4)) {
                continue;
            }
            $best = max($best, $state & 0x0F);
        }

        return $best;
    }

    /**
     * 受电方块：
     *   红石火把(75 灭 / 76 亮) —— 反相：旁边有电就灭
     *   木门(64) / 铁门(71)     —— 有电就开（meta 的 0x4 位），上下两格一起
     */
    private function applyRedstoneConsumers(array $wires): void
    {
        $done = [];
        foreach ($wires as [$wx, $wy, $wz]) {
            foreach ([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]) {
                $nx = $wx + $dx;
                $ny = $wy + $dy;
                $nz = $wz + $dz;
                if ($ny < 0 || $ny > 255) {
                    continue;
                }
                $key = $nx . ',' . $ny . ',' . $nz;
                if (isset($done[$key])) {
                    continue;
                }
                $done[$key] = true;

                $state = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $ny, $nz & 15);
                $id = $state >> 4;
                $meta = $state & 0x0F;

                // 火把：旁边有电就灭
                if ($id === 75 || $id === 76) {
                    $powered = $this->maxNeighborWirePower($nx, $ny, $nz) > 0;
                    $wantId = $powered ? 75 : 76;
                    if ($wantId !== $id) {
                        $this->changeBlock($nx, $ny, $nz, $wantId, $meta);
                    }
                    continue;
                }

                // 门：有电就开（0x4），上下两格同步
                if ($id === 64 || $id === 71) {
                    $powered = $this->maxNeighborWirePower($nx, $ny, $nz) > 0;
                    $lower = ($meta & 0x8) !== 0 ? $ny - 1 : $ny;
                    if ($lower < 0) {
                        continue;
                    }
                    $st = $this->world->chunk($nx >> 4, $nz >> 4)->blockState($nx & 15, $lower, $nz & 15);
                    $lowMeta = $st & 0x0F;
                    $wantOpen = $powered;
                    $isOpen = ($lowMeta & 0x4) !== 0;
                    if ($wantOpen === $isOpen) {
                        continue;
                    }
                    $newLow = $powered ? ($lowMeta | 0x4) : ($lowMeta & ~0x4);
                    $this->changeBlock($nx, $lower, $nz, $id, $newLow);
                    if ($lower < 255) {
                        $this->changeBlock($nx, $lower + 1, $nz, $id, ($newLow & 0x7) | 0x8);
                    }
                }
            }
        }
    }
}
