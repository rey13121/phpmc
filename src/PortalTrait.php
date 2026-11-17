<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 地狱门。
 *
 * 做法：
 *   1. 打火石点黑曜石 → 向四周探查黑曜石框，内部填传送门方块(90)
 *   2. 站在传送门里 2 秒 → 传送到地狱（主世界地图上 (200000,200000) 那块坐标带）
 *   3. 落点周围清一个 7×7×5 的房间，北墙嵌回程门
 *   4. 在地狱那边站门里 → 传回原来的位置
 *
 * 地狱是真地形，不是现搭的平台：
 *   AnvilWorld 在 (12500,12500) 半径 512 区块内改用 NetherGenerator，
 *   有岩浆海、分层洞穴、萤石、灵魂沙、石英矿。
 *   没建 DIM-1 目录 —— 维度靠 0x07 Respawn 包切（客户端按 dimension -1 渲染），
 *   方块数据还是同一个 AnvilWorld，只是那块坐标带是地狱地形。
 *
 * 完整流程：
 *   1) 打火石点到黑曜石框内部空气 → tryIgnitePortal 探框并填 90
 *   2) 站在 90 上满 2 秒 → tickPortals → teleportThroughPortal
 *   3) 主世界→地狱：记 portalReturn，ensureNetherEntry 凿房间，发 0x07 dim=-1
 *   4) 地狱→主世界：用 portalReturn 落点，发 0x07 dim=0
 *   5) 每次切维都清空 sentChunks / chunkQueue，否则客户端虚空
 */
trait PortalTrait
{
    private const PORTAL_BLOCK = 90;
    private const OBSIDIAN = 49;
    private const NETHERRACK = 87;
    private const GLOWSTONE = 89;

    /** 地狱平台原点（远到不会碰到正常地形） */
    private const NETHER_X = 200000;
    private const NETHER_Y = 64;
    private const NETHER_Z = 200000;

    private bool $netherBuilt = false;
    /** 维度自动同步的节流时间 */
    private float $lastDimSync = 0.0;
    /** 地狱入口房间的地面高度（第一次传送时定下来） */
    private int $netherY = self::NETHER_Y;
    private float $lastPortalTick = 0.0;

    /**
     * 从「火所在的那一格」向四周探，找出黑曜石框并填传送门。
     *
     * 原版允许 2×3（最小）到 21×21 的内部尺寸，所以不能写死尺寸，
     * 要顺着一个方向走到黑曜石边界，再验证四条边。
     */
    private function tryIgnitePortal(Connection $c, int $x, int $y, int $z): bool
    {
        foreach (['x', 'z'] as $axis) {
            $r = $this->probeFrame($x, $y, $z, $axis);
            if ($r === null) {
                continue;
            }
            [$x0, $y0, $z0, $w, $h] = $r;
            $meta = $axis === 'x' ? 1 : 2;
            for ($i = 0; $i < $w; $i++) {
                for ($j = 0; $j < $h; $j++) {
                    $this->changeBlock(
                        $axis === 'x' ? $x0 + $i : $x0,
                        $y0 + $j,
                        $axis === 'z' ? $z0 + $i : $z0,
                        self::PORTAL_BLOCK,
                        $meta
                    );
                }
            }
            $this->log("  {$c->username} 点燃地狱门 @ {$x0},{$y0},{$z0} 轴={$axis} 内部={$w}x{$h}");
            $this->sendChat($c, ['text' => '地狱门被点燃了。', 'color' => 'light_purple'], 1);

            return true;
        }

        return false;
    }

    /**
     * 以 (x,y,z) 为内部一格，沿 axis 方向探边界。
     * 返回 [x0,y0,z0,内宽,内高]，不合法返回 null。
     */
    private function probeFrame(int $x, int $y, int $z, string $axis): ?array
    {
        $at = function (int $i, int $j) use ($x, $y, $z, $axis): int {
            return $this->blockIdAt(
                $axis === 'x' ? $x + $i : $x,
                $y + $j,
                $axis === 'z' ? $z + $i : $z
            );
        };

        // ① 左右（沿水平轴）找黑曜石边界
        $left = null;
        for ($i = -1; $i >= -21; $i--) {
            $id = $at($i, 0);
            if ($id === self::OBSIDIAN) {
                $left = $i;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                $this->log("  [门] 轴={$axis} 左侧第 {$i} 格是 {$id}，不是空气也不是黑曜石");
                return null;
            }
        }
        $right = null;
        for ($i = 1; $i <= 21; $i++) {
            $id = $at($i, 0);
            if ($id === self::OBSIDIAN) {
                $right = $i;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        if ($left === null || $right === null) {
            $this->log("  [门] 轴={$axis} 找不到左右边框（left=" . ($left ?? 'null') . " right=" . ($right ?? 'null') . '）');
            return null;
        }
        $w = $right - $left - 1;
        if ($w < 2) {
            $this->log("  [门] 轴={$axis} 内部只有 {$w} 格宽（最小 2）");
            return null;
        }

        // ② 上下找黑曜石边界
        $down = null;
        for ($j = -1; $j >= -21; $j--) {
            $id = $at(0, $j);
            if ($id === self::OBSIDIAN) {
                $down = $j;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        $up = null;
        for ($j = 1; $j <= 21; $j++) {
            $id = $at(0, $j);
            if ($id === self::OBSIDIAN) {
                $up = $j;
                break;
            }
            if ($id !== 0 && $id !== self::PORTAL_BLOCK) {
                return null;
            }
        }
        if ($down === null || $up === null) {
            $this->log("  [门] 轴={$axis} 找不到上下边框（down=" . ($down ?? 'null') . " up=" . ($up ?? 'null') . '）');
            return null;
        }
        $h = $up - $down - 1;
        if ($h < 3) {
            $this->log("  [门] 轴={$axis} 内部只有 {$h} 格高（最小 3）");
            return null;
        }

        // ③ 验证四条边全是黑曜石
        for ($i = $left + 1; $i < $right; $i++) {
            if ($at($i, $down) !== self::OBSIDIAN || $at($i, $up) !== self::OBSIDIAN) {
                $this->log("  [门] 轴={$axis} 上下边第 {$i} 格不是黑曜石（下=" . $at($i, $down) . ' 上=' . $at($i, $up) . '）');
                return null;
            }
        }
        for ($j = $down + 1; $j < $up; $j++) {
            if ($at($left, $j) !== self::OBSIDIAN || $at($right, $j) !== self::OBSIDIAN) {
                $this->log("  [门] 轴={$axis} 左右边第 {$j} 格不是黑曜石（左=" . $at($left, $j) . ' 右=' . $at($right, $j) . '）');
                return null;
            }
        }

        // 原点 = 内部左下角（相对坐标）
        return [
            $axis === 'x' ? $x + $left + 1 : $x,
            $y + $down + 1,
            $axis === 'z' ? $z + $left + 1 : $z,
            $w,
            $h,
        ];
    }

    /** 每 0.5 秒：站在门里满 2 秒就传送（传送后 3 秒冷却，避免落地立刻弹回） */
    private function tickPortals(float $now): void
    {
        if ($now - $this->lastPortalTick < 0.5) {
            return;
        }
        $this->lastPortalTick = $now;

        foreach ($this->players() as $p) {
            // portalSince 设在未来 = 冷却中，先跳过
            if ($p->portalSince > $now) {
                continue;
            }

            $in = $this->blockIdAt((int) floor($p->x), (int) floor($p->y), (int) floor($p->z)) === self::PORTAL_BLOCK
                || $this->blockIdAt((int) floor($p->x), (int) floor($p->y) + 1, (int) floor($p->z)) === self::PORTAL_BLOCK;

            if (!$in) {
                $p->portalSince = 0.0;
                continue;
            }
            if ($p->portalSince <= 0.0) {
                $p->portalSince = $now;
                continue;
            }
            if ($now - $p->portalSince < 2.0) {
                continue;
            }

            $p->portalSince = 0.0;
            $this->teleportThroughPortal($p);
        }
    }

    private function teleportThroughPortal(Connection $c): void
    {
        // 用区块带判断，不能用「离落点 64 格」—— 玩家在地狱走远了就判错，
        // 会以为自己在主世界，一站门又给送回地狱。
        $atNether = $this->world->isNetherAt((int) floor($c->x), (int) floor($c->z));

        if ($atNether) {
            // 回主世界：回到进门前的位置（没有记录则用世界出生点）
            if ($c->portalReturn !== null) {
                [$tx, $ty, $tz] = $c->portalReturn;
            } else {
                [$sx, $sy, $sz] = $this->world->spawnPoint();
                $tx = (float) $sx + 0.5;
                $ty = (float) $sy;
                $tz = (float) $sz + 0.5;
            }
        } else {
            $c->portalReturn = [$c->x, $c->y, $c->z];
            // 落点高度由 ensureNetherEntry 根据地形决定
            $standY = $this->ensureNetherEntry();
            $tx = self::NETHER_X + 0.5;
            $ty = (float) $standY;
            $tz = self::NETHER_Z + 0.5;
        }

        $c->x = (float) $tx;
        $c->y = (float) $ty;
        $c->z = (float) $tz;

        // 发 Respawn (0x07) 让客户端切维度：
        // 我们不建真 DIM-1 目录，只让客户端按 dim=-1 渲染（暗红天空、雾、无日夜）。
        // 回去时再发 dim=0。
        $c->portalDim = $atNether ? 0 : -1;
        // 不再补发 0x07：补发会让客户端再丢一次世界，容易闪虚空。
        $c->portalResyncAt = 0.0;
        // 传送后 3 秒内不再触发门（避免刚落地又站在回程门里被弹回来）
        $c->portalSince = microtime(true) + 3.0;

        $c->send(self::C_RESPAWN, (new Writer())
            ->int($atNether ? 0 : -1)      // 目标维度
            ->byte(1)                       // 难度 normal
            ->byte($c->gamemode)
            ->string('default'));

        $c->send(self::C_POS_LOOK, (new Writer())
            ->double($c->x)->double($c->y)->double($c->z)
            ->float($c->yaw)->float($c->pitch)->byte(0));

        // 【必须清缓存】客户端收到 0x07 会把整个世界丢掉重建；
        // 服务端若还记着「这些区块发过了」，新维度就是虚空。
        $c->sentChunks = [];
        $c->chunkQueue = [];
        $c->resendInvAt = microtime(true) + 0.3;
        $this->queueChunks($c);
        // 切维后背包也要重发，否则窗口可能全空
        if (method_exists($this, 'sendPlayerInventory')) {
            $this->sendPlayerInventory($c);
        }
        if (method_exists($this, 'sendCursor')) {
            $this->sendCursor($c);
        }

        $this->log(sprintf('  %s 传送 -> %.1f,%.1f,%.1f  dim=%d',
            $c->username, $c->x, $c->y, $c->z, $c->portalDim));
        $this->sendChat($c, [
            'text' => $atNether ? '回到主世界。' : '你来到了地狱。走进北墙的门可返回。',
            'color' => 'light_purple',
        ], 1);
    }

    /**
     * 维度自动同步：玩家坐标落在地狱带里就得是 dimension -1，否则是 0。
     *
     * 之前只有「站传送门」才会发 0x07，用 /tp 直接跳过去的玩家客户端还停在
     * 主世界维度 —— 表现就是天空是蓝的、光照按主世界算、F3 里 Light 全是 0，
     * 但 Biome 显示 Hell，看着特别怪。这个函数每 0.5 秒对一次。
     */
    private function tickDimSync(float $now): void
    {
        if ($now - $this->lastDimSync < 0.5) {
            return;
        }
        $this->lastDimSync = $now;

        foreach ($this->players() as $p) {
            $fx = (int) floor($p->x);
            $fz = (int) floor($p->z);
            if ($this->world->isEndAt($fx, $fz)) {
                $want = 1;          // 末地
            } elseif ($this->world->isNetherAt($fx, $fz)) {
                $want = -1;         // 地狱
            } else {
                $want = 0;          // 主世界
            }
            if ($want === $p->portalDim) {
                continue;
            }

            $p->portalDim = $want;
            $p->portalDimAt = microtime(true);
            // 客户端收到 0x07 会把世界整个丢掉重建，区块缓存必须跟着清
            $p->sentChunks = [];
            $p->chunkQueue = [];

            // /tp 到 y=44 那种位置会卡在石头里，屏幕全黑。
            // 切换维度时顺手把玩家顶到最近的空位。
            $bx = (int) floor($p->x);
            $by = (int) floor($p->y);
            $bz = (int) floor($p->z);
            if ($this->blockIdAt($bx, $by, $bz) !== 0
                || $this->blockIdAt($bx, $by + 1, $bz) !== 0) {
                for ($k = 0; $k < 64; $k++) {
                    if ($by + $k >= 127) {
                        break;
                    }
                    if ($this->blockIdAt($bx, $by + $k, $bz) === 0
                        && $this->blockIdAt($bx, $by + $k + 1, $bz) === 0) {
                        $p->y = (float) ($by + $k);
                        $this->log("  {$p->username} 卡在方块里，顶到 y={$p->y}");
                        break;
                    }
                }
            }

            $p->send(self::C_RESPAWN, (new Writer())
                ->int($want)
                ->byte(1)
                ->byte($p->gamemode)
                ->string('default'));
            $p->send(self::C_POS_LOOK, (new Writer())
                ->double($p->x)->double($p->y)->double($p->z)
                ->float($p->yaw)->float($p->pitch)->byte(0));
            $this->queueChunks($p);

            // 进末地：把水晶实体发给他
            if ($want === 1) {
                $this->spawnEndCrystals();
                $this->sendEndCrystalsTo($p);
                $this->spawnDragon();
                $this->sendDragonSpawn($p);
            }

            $this->log("  维度自动切到 {$want} -> {$p->username} @ {$p->x},{$p->z}");
        }
    }

    /** 传送后 1 秒补发一次维度包（客户端偶尔把紧跟后面的包吞掉） */
    private function tickPortalDimResync(float $now): void
    {
        foreach ($this->players() as $p) {
            if ($p->portalResyncAt <= 0 || $now < $p->portalResyncAt) {
                continue;
            }
            $p->portalResyncAt = 0.0;
            // 补发 0x07 会让客户端再丢一次世界，所以区块缓存必须跟着清，
            // 否则补发完就是一片虚空。
            $p->sentChunks = [];
            $p->chunkQueue = [];
            $p->send(self::C_RESPAWN, (new Writer())
                ->int($p->portalDim)
                ->byte(1)
                ->byte($p->gamemode)
                ->string('default'));
            $p->send(self::C_POS_LOOK, (new Writer())
                ->double($p->x)->double($p->y)->double($p->z)
                ->float($p->yaw)->float($p->pitch)->byte(0));
            $this->queueChunks($p);
            $this->log('  补发维度包 dim=' . $p->portalDim);
        }
    }

    /**
     * 在地狱地形里凿出入口房间 + 嵌回程门，返回玩家该站的 y。
     *
     * 地形是 NetherGenerator 生成的，空腔形状不可靠（可能只有 3 格高、地板是岩浆），
     * 所以统一凿一个 7×7×5 的安全房间：地面强制地狱岩，头顶清空，北墙嵌回程门。
     * 只做一次，高度记在 $netherY。重启后 netherBuilt 会重置，会再凿一次（幂等）。
     */
    private function ensureNetherEntry(): int
    {
        $ox = self::NETHER_X;
        $oz = self::NETHER_Z;

        // 先把落点周围 5×5 区块生成出来（房间 + 门框需要空间）
        for ($dz = -2; $dz <= 2; $dz++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->world->chunk(($ox >> 4) + $dx, ($oz >> 4) + $dz);
            }
        }

        if ($this->netherBuilt) {
            return $this->netherY;
        }
        $this->netherBuilt = true;

        // 找能站的地面：下方是实心（非空气/岩浆/火），上方至少 4 格空气。
        // 不找「纯空气柱」—— 地狱大空腔会让人掉半空。
        $standY = -1;
        for ($y = 100; $y >= 33; $y--) {
            $below = $this->blockIdAt($ox, $y, $oz);
            if ($below === 0 || $below === 10 || $below === 11 || $below === 51) {
                continue;
            }
            $ok = true;
            for ($k = 1; $k <= 4; $k++) {
                if ($this->blockIdAt($ox, $y + $k, $oz) !== 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $standY = $y + 1;
                break;
            }
        }
        if ($standY < 0) {
            $standY = 58; // 整列实心，硬凿
        }

        $set = function (int $x, int $y, int $z, int $id, int $meta = 0): void {
            $this->world->chunk($x >> 4, $z >> 4)->setBlock($x & 15, $y, $z & 15, $id, $meta);
        };

        $half = 3;
        $top = $standY + 4;
        for ($dx = -$half; $dx <= $half; $dx++) {
            for ($dz = -$half; $dz <= $half; $dz++) {
                $wx = $ox + $dx;
                $wz = $oz + $dz;
                // 地面：强制铺地狱岩（覆盖岩浆/空洞）
                $set($wx, $standY - 1, $wz, self::NETHERRACK);
                // 房间内部清空
                for ($y = $standY; $y <= $top; $y++) {
                    $set($wx, $y, $wz, 0);
                }
            }
        }

        // 顶灯：天然空腔可能全黑
        $set($ox + 2, $top, $oz, self::GLOWSTONE);
        $set($ox - 2, $top, $oz, self::GLOWSTONE);

        // 回程门嵌在北墙（z = oz - 3）：4 宽 × 5 高。
        // 门内部底部与地面齐平，玩家走进北墙即可触发回传。
        $px = $ox - 1;
        $pz = $oz - 3;
        $base = $standY - 1;
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 5; $j++) {
                $edge = $i === 0 || $i === 3 || $j === 0 || $j === 4;
                if ($edge) {
                    $set($px + $i, $base + $j, $pz, self::OBSIDIAN);
                } elseif ($j >= 1 && $j <= 3) {
                    $set($px + $i, $base + $j, $pz, self::PORTAL_BLOCK, 2);
                }
            }
        }
        // 门前一格地面也铺实，避免踩空
        for ($i = 0; $i < 4; $i++) {
            $set($px + $i, $standY - 1, $pz + 1, self::NETHERRACK);
        }

        $this->netherY = $standY;
        $this->log(sprintf('  地狱入口已就绪 @ %d,%d,%d', $ox, $standY, $oz));

        return $standY;
    }
}
