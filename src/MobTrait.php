<?php
declare(strict_types=1);

namespace PhpMc;

/**
 * 生物 AI（1.8 简化版，可玩优先）：
 * - 敌对：视野内追击、1 格上台阶、侧向绕障、近战冷却
 * - 苦力怕：靠近后点燃引线并爆炸
 * - 骷髅：保持距离射箭
 * - 蜘蛛：短距离扑击
 * - 被动：闲逛；被打后逃离攻击者
 * - 狼：被打后进入敌对
 * - 末影人：受伤有概率瞬移
 *
 * 不做完整 A* 寻路；移动仍是「试探前/侧/上台阶」。
 */
trait MobTrait
{
    /**
     * 类型 => [名字, 默认敌对, 血量, 掉落表, 移速(格/拍≈0.1s), 近战伤害, 攻击距离, 攻击冷却秒]
     * 掉落表：[[id, min, max], ...]
     */
    private const MOB_TYPES = [
        50  => ['creeper',   true,  20, [[289, 0, 2]],                 0.18, 0,  3.0, 0.0],
        51  => ['skeleton',  true,  20, [[352, 0, 2], [262, 0, 2]],    0.20, 0, 16.0, 2.0],
        52  => ['spider',    true,  16, [[287, 0, 2], [375, 0, 1]],    0.24, 2,  2.5, 1.2],
        54  => ['zombie',    true,  20, [[367, 0, 2]],                 0.19, 3,  2.2, 1.5],
        57  => ['pigman',    true,  20, [[371, 0, 1], [370, 0, 1]],    0.22, 5,  2.2, 1.2],
        58  => ['enderman',  true,  40, [[368, 0, 1]],                 0.26, 7,  2.5, 1.0],
        65  => ['bat',       false,  6, [],                            0.25, 0,  0.0, 0.0],
        90  => ['pig',       false, 10, [[319, 1, 3]],                 0.16, 0,  0.0, 0.0],
        91  => ['sheep',     false,  8, [[423, 1, 2], [35, 1, 1]],     0.15, 0,  0.0, 0.0],
        92  => ['cow',       false, 10, [[363, 1, 3], [334, 0, 2]],    0.15, 0,  0.0, 0.0],
        93  => ['chicken',   false,  4, [[365, 1, 1], [288, 0, 2]],    0.18, 0,  0.0, 0.0],
        95  => ['wolf',      false,  8, [],                            0.24, 4,  2.0, 1.0],
        96  => ['mooshroom', false, 10, [[363, 1, 3], [334, 0, 2]],    0.15, 0,  0.0, 0.0],
        98  => ['ocelot',    false, 10, [],                            0.22, 0,  0.0, 0.0],
        100 => ['horse',     false, 20, [[334, 0, 2]],                 0.20, 0,  0.0, 0.0],
        101 => ['rabbit',    false, 10, [[411, 0, 1], [415, 0, 1], [414, 0, 1]], 0.26, 0, 0.0, 0.0],
    ];

    private const BIOME_PASSIVE = [
        1 => [90, 91, 92, 93, 100], 129 => [90, 91, 92, 93, 100],
        4 => [95, 91, 92, 93], 27 => [95, 91, 92, 93], 28 => [95, 91, 92, 93],
        3 => [95, 91, 92], 5 => [95, 91, 101], 19 => [95, 91, 101],
        29 => [95, 91, 101], 30 => [95, 91, 101],
        21 => [98, 93], 23 => [98, 93], 22 => [98, 93],
        32 => [100, 92], 33 => [100, 92], 14 => [96], 2 => [101], 17 => [101],
    ];

    private const MOB_HOSTILE = [54, 51, 50, 52];

    /** @var array<int, array<string, mixed>> */
    private array $mobs = [];

    private float $lastMobSpawn = 0.0;
    private float $lastMobTick = 0.0;
    private float $lastTimeSync = 0.0;

    private function spawnMob(int $type, float $x, float $y, float $z): int
    {
        if (!isset(self::MOB_TYPES[$type])) {
            return 0;
        }
        $eid = $this->nextEntityId++;
        $hostile = self::MOB_TYPES[$type][1];
        $this->mobs[$eid] = [
            'type' => $type,
            'x' => $x, 'y' => $y, 'z' => $z,
            'hp' => self::MOB_TYPES[$type][2],
            'life' => 0.0,
            'lastHit' => 0.0,
            'lastAttack' => 0.0,
            'target' => 0,
            'angryUntil' => 0.0,
            'hostile' => $hostile,
            'burnAt' => 0.0,
            'onFireUntil' => 0.0,
            'dirX' => 0.0, 'dirZ' => 0.0, 'dirUntil' => 0.0,
            'fuse' => 0.0,
            'leapUntil' => 0.0,
            'yaw' => 0.0,
        ];
        foreach ($this->players() as $p) {
            $this->sendMobSpawn($p, $eid, $type, $x, $y, $z);
        }

        return $eid;
    }

    private function sendMobSpawn(Connection $p, int $eid, int $type, float $x, float $y, float $z): void
    {
        $p->send(self::C_SPAWN_MOB, (new Writer())
            ->varInt($eid)->byte($type)
            ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
            ->byte(0)->byte(0)->byte(0)
            ->short(0)->short(0)->short(0)
            ->unsignedByte(0x00)->unsignedByte(0x00)
            ->unsignedByte(0x7F));
    }

    private function removeMob(int $eid): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        unset($this->mobs[$eid]);
        foreach ($this->players() as $p) {
            $p->send(self::C_DESTROY_ENTITIES, (new Writer())->varInt(1)->varInt($eid));
        }
    }

    private function damageMob(int $eid, int $dmg, Connection $attacker): bool
    {
        if (!isset($this->mobs[$eid])) {
            return false;
        }
        $this->mobs[$eid]['hp'] -= $dmg;
        $this->mobs[$eid]['lastHit'] = microtime(true);
        $this->mobs[$eid]['target'] = $attacker->entityId;
        $this->mobs[$eid]['angryUntil'] = microtime(true) + 20.0;

        $type = (int) $this->mobs[$eid]['type'];
        if ($type === 95) {
            $this->mobs[$eid]['hostile'] = true;
        }
        if ($type === 58 && random_int(0, 1) === 1) {
            $this->mobBlink($eid, 8.0);
        }

        foreach ($this->players() as $pp) {
            $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(2));
        }

        $m = $this->mobs[$eid];
        $wpn = $attacker->inventory[36 + $attacker->heldSlot] ?? null;
        $kb = 0.6 + 0.5 * $this->itemEnchLevel($wpn, 19);
        $fireAspect = $this->itemEnchLevel($wpn, 20);
        if ($fireAspect > 0) {
            $this->mobs[$eid]['onFireUntil'] = microtime(true) + 4.0 * $fireAspect;
            $this->mobs[$eid]['burnAt'] = microtime(true);
        }

        $dx = $m['x'] - $attacker->x;
        $dz = $m['z'] - $attacker->z;
        $len = sqrt($dx * $dx + $dz * $dz);
        if ($len > 0.01) {
            $nx = $m['x'] + ($dx / $len) * $kb;
            $nz = $m['z'] + ($dz / $len) * $kb;
            $ny = $this->groundY((int) floor($nx), (int) floor($nz), (int) $m['y'] + 2);
            if ($ny > 0) {
                $this->mobs[$eid]['x'] = $nx;
                $this->mobs[$eid]['y'] = (float) $ny;
                $this->mobs[$eid]['z'] = $nz;
                $this->broadcastMobTeleport($eid);
            }
        }

        if ($this->mobs[$eid]['hp'] <= 0) {
            $this->killMob($eid, $attacker);
        }

        return true;
    }

    private function killMob(int $eid, ?Connection $killer): void
    {
        if (!isset($this->mobs[$eid]) || !empty($this->mobs[$eid]['dead'])) {
            return;
        }
        $m = $this->mobs[$eid];
        $type = (int) $m['type'];
        [, , , $drops] = self::MOB_TYPES[$type];
        foreach ($this->players() as $pp) {
            $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(3));
        }
        $this->mobs[$eid]['dead'] = true;
        $this->mobs[$eid]['deadAt'] = microtime(true) + 1.0;
        $owner = $killer;
        foreach ($drops as [$dId, $dMin, $dMax]) {
            $cnt = random_int($dMin, $dMax);
            for ($q = 0; $q < $cnt; $q++) {
                $this->spawnDrop(
                    (int) floor($m['x']), (int) floor($m['y']), (int) floor($m['z']),
                    $dId, 1, 0, $owner
                );
            }
        }
        if ($killer !== null) {
            $xp = self::MOB_TYPES[$type][1] ? 5 : random_int(1, 3);
            $this->giveExp($killer, $xp);
        }
    }

    private function mobBlink(int $eid, float $radius): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        $m = $this->mobs[$eid];
        for ($i = 0; $i < 8; $i++) {
            $ang = deg2rad(random_int(0, 359));
            $dist = $radius * (0.4 + mt_rand(0, 60) / 100);
            $tx = $m['x'] + cos($ang) * $dist;
            $tz = $m['z'] + sin($ang) * $dist;
            $ty = $this->groundY((int) floor($tx), (int) floor($tz), (int) $m['y'] + 4);
            if ($ty <= 0) {
                continue;
            }
            [$ok] = $this->canStand($tx, $tz, $ty);
            if (!$ok) {
                continue;
            }
            $this->mobs[$eid]['x'] = $tx;
            $this->mobs[$eid]['y'] = (float) $ty;
            $this->mobs[$eid]['z'] = $tz;
            $this->broadcastMobTeleport($eid);
            return;
        }
    }

    private function broadcastMobTeleport(int $eid): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        $m = $this->mobs[$eid];
        $yawByte = self::angle($m['yaw'] ?? 0.0);
        foreach ($this->players() as $p) {
            $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                ->varInt($eid)
                ->int((int) ($m['x'] * 32))->int((int) ($m['y'] * 32))->int((int) ($m['z'] * 32))
                ->byte($yawByte)->byte(0)->bool(true));
        }
    }

    private function tickMobs(float $now): void
    {
        if ($now - $this->lastMobTick < 0.1) {
            return;
        }
        $this->lastMobTick = $now;
        $dt = 0.1;

        foreach ($this->mobs as $eid => $m) {
            if (!empty($m['dead'])) {
                if ($now >= ($m['deadAt'] ?? 0)) {
                    $this->removeMob($eid);
                }
                continue;
            }

            $type = (int) $m['type'];
            $def = self::MOB_TYPES[$type];
            $speed = (float) $def[4];
            $meleeDmg = (int) $def[5];
            $atkRange = (float) $def[6];
            $atkCd = (float) $def[7];
            $isHostile = !empty($m['hostile']);

            $near = null;
            $bestD = PHP_FLOAT_MAX;
            if (($m['target'] ?? 0) > 0 && $now < ($m['angryUntil'] ?? 0)) {
                foreach ($this->players() as $pp) {
                    if ($pp->entityId !== $m['target'] || $pp->gamemode === 1 || $pp->gamemode === 3) {
                        continue;
                    }
                    $d = $this->mobDistTo($m, $pp);
                    if ($d < 40.0) {
                        $near = $pp;
                        $bestD = $d;
                    }
                    break;
                }
            }
            if ($near === null && $isHostile) {
                foreach ($this->players() as $pp) {
                    if ($pp->gamemode === 1 || $pp->gamemode === 3) {
                        continue;
                    }
                    $d = $this->mobDistTo($m, $pp);
                    if ($d < $bestD && $d < 24.0) {
                        $bestD = $d;
                        $near = $pp;
                    }
                }
            } elseif ($near === null) {
                foreach ($this->players() as $pp) {
                    $d = $this->mobDistTo($m, $pp);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $near = $pp;
                    }
                }
            }

            if ($near === null || $bestD > 64.0) {
                $this->removeMob($eid);
                continue;
            }

            if (($m['onFireUntil'] ?? 0) > $now && $now - ($m['burnAt'] ?? 0) >= 1.0) {
                $this->mobs[$eid]['burnAt'] = $now;
                $this->mobs[$eid]['hp']--;
                foreach ($this->players() as $pp) {
                    $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(2));
                }
                if ($this->mobs[$eid]['hp'] <= 0) {
                    $this->killMob($eid, $near);
                    continue;
                }
            }

            if (($type === 54 || $type === 51) && !$this->isNight()
                && $now - ($m['burnAt'] ?? 0) >= 1.0) {
                $bx = (int) floor($m['x']);
                $bz = (int) floor($m['z']);
                $by = (int) floor($m['y']) + 1;
                $sky = $this->world->chunk($bx >> 4, $bz >> 4)->skyLight($bx & 15, $by, $bz & 15);
                if ($sky >= 8) {
                    $this->mobs[$eid]['burnAt'] = $now;
                    $this->mobs[$eid]['onFireUntil'] = $now + 8.0;
                    $this->mobs[$eid]['hp']--;
                    foreach ($this->players() as $pp) {
                        $pp->send(self::C_ENTITY_STATUS, (new Writer())->int($eid)->byte(2));
                    }
                    if ($this->mobs[$eid]['hp'] <= 0) {
                        $this->killMob($eid, $near);
                        continue;
                    }
                }
            }

            $nx = $m['x'];
            $nz = $m['z'];
            $moved = false;

            if ($isHostile && $near !== null) {
                if ($type === 50) {
                    if ($bestD < 3.0) {
                        if (($m['fuse'] ?? 0.0) <= 0.0) {
                            $this->mobs[$eid]['fuse'] = $now + 1.5;
                        }
                        if ($bestD > 1.0) {
                            [$nx, $nz, $moved] = $this->mobStepToward($m, $near->x, $near->z, $speed * 0.6);
                        }
                        if ($now >= ($this->mobs[$eid]['fuse'] ?? 0)) {
                            $this->mobCreeperExplode($eid);
                            continue;
                        }
                    } else {
                        $this->mobs[$eid]['fuse'] = 0.0;
                        if ($bestD < 16.0) {
                            [$nx, $nz, $moved] = $this->mobStepToward($m, $near->x, $near->z, $speed);
                        } else {
                            [$nx, $nz, $moved] = $this->mobWander($eid, $m, $now, $speed * 0.7);
                        }
                    }
                } elseif ($type === 51) {
                    if ($bestD < 5.0) {
                        $dx = $m['x'] - $near->x;
                        $dz = $m['z'] - $near->z;
                        $len = sqrt($dx * $dx + $dz * $dz) ?: 1.0;
                        [$nx, $nz, $moved] = $this->mobStepToward(
                            $m,
                            $m['x'] + ($dx / $len) * 3,
                            $m['z'] + ($dz / $len) * 3,
                            $speed
                        );
                    } elseif ($bestD > 12.0 && $bestD < 20.0) {
                        [$nx, $nz, $moved] = $this->mobStepToward($m, $near->x, $near->z, $speed);
                    }
                    if ($bestD >= 4.0 && $bestD <= 16.0
                        && $now - ($m['lastAttack'] ?? 0) >= $atkCd) {
                        $this->mobShootArrow($eid, $near);
                        $this->mobs[$eid]['lastAttack'] = $now;
                    }
                } elseif ($type === 52) {
                    if ($bestD > 2.0 && $bestD < 18.0) {
                        $leap = ($bestD > 3.0 && $bestD < 8.0
                            && $now >= ($m['leapUntil'] ?? 0)
                            && random_int(0, 8) === 0);
                        $spd = $leap ? $speed * 2.2 : $speed;
                        [$nx, $nz, $moved] = $this->mobStepToward($m, $near->x, $near->z, $spd);
                        if ($leap) {
                            $this->mobs[$eid]['leapUntil'] = $now + 3.0;
                        }
                    }
                    if ($bestD <= $atkRange && $now - ($m['lastAttack'] ?? 0) >= $atkCd) {
                        $this->damage($near, max(1, $meleeDmg));
                        $this->mobs[$eid]['lastAttack'] = $now;
                    }
                } else {
                    if ($bestD > $atkRange && $bestD < 20.0) {
                        [$nx, $nz, $moved] = $this->mobStepToward($m, $near->x, $near->z, $speed);
                    }
                    if ($bestD <= $atkRange && $now - ($m['lastAttack'] ?? 0) >= $atkCd) {
                        $this->damage($near, max(1, $meleeDmg));
                        $this->mobs[$eid]['lastAttack'] = $now;
                    }
                }
                $this->mobs[$eid]['yaw'] = rad2deg(atan2(-($near->x - $m['x']), $near->z - $m['z']));
            } else {
                $fleeing = ($m['target'] ?? 0) > 0 && $now < ($m['angryUntil'] ?? 0);
                if ($fleeing && $near !== null && $near->entityId === ($m['target'] ?? 0)) {
                    $dx = $m['x'] - $near->x;
                    $dz = $m['z'] - $near->z;
                    $len = sqrt($dx * $dx + $dz * $dz) ?: 1.0;
                    [$nx, $nz, $moved] = $this->mobStepToward(
                        $m,
                        $m['x'] + ($dx / $len) * 6,
                        $m['z'] + ($dz / $len) * 6,
                        $speed * 1.35
                    );
                    $this->mobs[$eid]['yaw'] = rad2deg(atan2(-($nx - $m['x']), $nz - $m['z']));
                } else {
                    [$nx, $nz, $moved] = $this->mobWander($eid, $m, $now, $speed);
                }
            }

            if ($moved && (abs($nx - $m['x']) > 0.001 || abs($nz - $m['z']) > 0.001)) {
                $stepY = $this->mobResolveHeight($m, $nx, $nz);
                if ($stepY !== null) {
                    $ny = (float) $stepY;
                    $ddx = (int) round(($nx - $m['x']) * 32);
                    $ddy = (int) round(($ny - $m['y']) * 32);
                    $ddz = (int) round(($nz - $m['z']) * 32);
                    $this->mobs[$eid]['x'] = $nx;
                    $this->mobs[$eid]['y'] = $ny;
                    $this->mobs[$eid]['z'] = $nz;
                    $yawByte = self::angle($this->mobs[$eid]['yaw'] ?? 0.0);
                    $small = abs($ddx) < 128 && abs($ddy) < 128 && abs($ddz) < 128;
                    foreach ($this->players() as $p) {
                        if ($small) {
                            $p->send(self::C_ENTITY_MOVE, (new Writer())
                                ->varInt($eid)->byte($ddx)->byte($ddy)->byte($ddz)->bool(true));
                        } else {
                            $p->send(self::C_ENTITY_TELEPORT, (new Writer())
                                ->varInt($eid)
                                ->int((int) ($nx * 32))->int((int) ($ny * 32))->int((int) ($nz * 32))
                                ->byte($yawByte)->byte(0)->bool(true));
                        }
                    }
                }
            }

            $this->mobs[$eid]['life'] += $dt;
            $maxLife = $isHostile ? 600.0 : 300.0;
            if ($this->mobs[$eid]['life'] > $maxLife && (!$isHostile || $bestD > 32.0)) {
                $this->removeMob($eid);
            }
        }

        if ($now - $this->lastMobSpawn >= 5.0) {
            $this->lastMobSpawn = $now;
            foreach ($this->players() as $p) {
                if (count($this->mobs) >= 60) {
                    break;
                }
                $this->tryNaturalSpawn($p);
            }
        }
    }

    private function mobDistTo(array $m, Connection $p): float
    {
        return sqrt(
            ($p->x - $m['x']) ** 2
            + ($p->y - $m['y']) ** 2
            + ($p->z - $m['z']) ** 2
        );
    }

    /** @return array{0:float,1:float,2:bool} */
    private function mobStepToward(array $m, float $tx, float $tz, float $speed): array
    {
        $dx = $tx - $m['x'];
        $dz = $tz - $m['z'];
        $len = sqrt($dx * $dx + $dz * $dz);
        if ($len < 0.05) {
            return [$m['x'], $m['z'], false];
        }
        $dx /= $len;
        $dz /= $len;
        $candidates = [
            [$dx, $dz],
            [$dx * 0.7 - $dz * 0.7, $dz * 0.7 + $dx * 0.7],
            [$dx * 0.7 + $dz * 0.7, $dz * 0.7 - $dx * 0.7],
            [-$dz, $dx],
            [$dz, -$dx],
        ];
        foreach ($candidates as [$cx, $cz]) {
            $nx = $m['x'] + $cx * $speed;
            $nz = $m['z'] + $cz * $speed;
            if ($this->mobResolveHeight($m, $nx, $nz) !== null) {
                return [$nx, $nz, true];
            }
        }

        return [$m['x'], $m['z'], false];
    }

    private function mobWander(int $eid, array $m, float $now, float $speed): array
    {
        if ($now >= ($m['dirUntil'] ?? 0)) {
            $ang = deg2rad(random_int(0, 359));
            $this->mobs[$eid]['dirX'] = cos($ang);
            $this->mobs[$eid]['dirZ'] = sin($ang);
            $this->mobs[$eid]['dirUntil'] = $now + random_int(20, 40) / 10;
            if (random_int(0, 3) === 0) {
                $this->mobs[$eid]['dirX'] = 0.0;
                $this->mobs[$eid]['dirZ'] = 0.0;
                $this->mobs[$eid]['dirUntil'] = $now + random_int(10, 25) / 10;
            }
        }
        $dxs = $this->mobs[$eid]['dirX'];
        $dzs = $this->mobs[$eid]['dirZ'];
        if ($dxs === 0.0 && $dzs === 0.0) {
            return [$m['x'], $m['z'], false];
        }
        $nx = $m['x'] + $dxs * $speed;
        $nz = $m['z'] + $dzs * $speed;
        if ($this->mobResolveHeight($m, $nx, $nz) === null) {
            $this->mobs[$eid]['dirUntil'] = 0.0;
            return [$m['x'], $m['z'], false];
        }
        $this->mobs[$eid]['yaw'] = rad2deg(atan2(-$dxs, $dzs));

        return [$nx, $nz, true];
    }

    private function mobResolveHeight(array $m, float $nx, float $nz): ?float
    {
        $fromY = (int) floor($m['y']);
        [$ok, $gy] = $this->canStand($nx, $nz, $fromY);
        if ($ok && abs($gy - $fromY) <= 1) {
            return (float) $gy;
        }
        [$ok2, $gy2] = $this->canStand($nx, $nz, $fromY + 1);
        if ($ok2 && $gy2 === $fromY + 1) {
            return (float) $gy2;
        }

        return null;
    }

    private function mobShootArrow(int $mobEid, Connection $target): void
    {
        if (!isset($this->mobs[$mobEid])) {
            return;
        }
        $m = $this->mobs[$mobEid];
        $dx = $target->x - $m['x'];
        $dy = ($target->y + 1.4) - ($m['y'] + 1.5);
        $dz = $target->z - $m['z'];
        $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz) ?: 1.0;
        $speed = 1.4;
        $vx = ($dx / $dist) * $speed;
        $vy = ($dy / $dist) * $speed + 0.12;
        $vz = ($dz / $dist) * $speed;
        $x = $m['x'];
        $y = $m['y'] + 1.5;
        $z = $m['z'];
        $eid = $this->nextEntityId++;
        $this->arrows[$eid] = [
            $x, $y, $z,
            $vx, $vy, $vz,
            0,
            0, 0,
            4,
            0, 0,
        ];
        $yaw = rad2deg(atan2(-$dx, $dz));
        $pitch = rad2deg(-asin(max(-1.0, min(1.0, $dy / $dist))));
        foreach ($this->players() as $p) {
            $p->send(self::C_SPAWN_OBJECT, (new Writer())
                ->varInt($eid)
                ->byte(60)
                ->int((int) ($x * 32))->int((int) ($y * 32))->int((int) ($z * 32))
                ->byte(self::angle($yaw))
                ->byte(self::angle($pitch))
                ->int($mobEid)
                ->short((int) ($vx * 8000))
                ->short((int) ($vy * 8000))
                ->short((int) ($vz * 8000)));
        }
    }

    private function mobCreeperExplode(int $eid): void
    {
        if (!isset($this->mobs[$eid])) {
            return;
        }
        $m = $this->mobs[$eid];
        $cx = $m['x'];
        $cy = $m['y'];
        $cz = $m['z'];
        $power = 3.0;

        foreach ($this->players() as $p) {
            $p->send(self::C_EXPLOSION, (new Writer())
                ->float((float) $cx)->float((float) $cy)->float((float) $cz)
                ->float($power)
                ->int(0)
                ->float(0.0)->float(0.0)->float(0.0));
        }

        foreach ($this->players() as $p) {
            if ($p->gamemode === 1 || $p->gamemode === 3) {
                continue;
            }
            $d = sqrt(($p->x - $cx) ** 2 + ($p->y - $cy) ** 2 + ($p->z - $cz) ** 2);
            if ($d < $power + 2) {
                $dmg = (int) max(1, round((1.0 - $d / ($power + 2)) * 18));
                $this->damage($p, $dmg, 'explosion');
            }
        }

        $r = 2;
        $ix = (int) floor($cx);
        $iy = (int) floor($cy);
        $iz = (int) floor($cz);
        $soft = [1, 2, 3, 12, 13, 18, 31, 32, 37, 38, 39, 40, 59, 83, 86, 91, 106, 161, 175];
        for ($x = $ix - $r; $x <= $ix + $r; $x++) {
            for ($y = max(1, $iy - $r); $y <= min(255, $iy + $r); $y++) {
                for ($z = $iz - $r; $z <= $iz + $r; $z++) {
                    if (($x - $ix) ** 2 + ($y - $iy) ** 2 + ($z - $iz) ** 2 > $r * $r + 1) {
                        continue;
                    }
                    $chunk = $this->world->chunk($x >> 4, $z >> 4);
                    $id = $chunk->blockState($x & 15, $y, $z & 15) >> 4;
                    if ($id === 0 || !in_array($id, $soft, true)) {
                        continue;
                    }
                    if (random_int(0, 100) < 55) {
                        $chunk->setBlock($x & 15, $y, $z & 15, 0, 0);
                        $this->broadcastBlockChange($x, $y, $z, 0);
                    }
                }
            }
        }

        $this->removeMob($eid);
    }

    private function tryNaturalSpawn(Connection $p): void
    {
        $players = max(1, count($this->players()));
        if (count($this->mobs) >= min(40, 10 * $players)) {
            return;
        }

        $angle = deg2rad(random_int(0, 359));
        $dist = random_int(12, 28);
        $x = (int) floor($p->x + cos($angle) * $dist);
        $z = (int) floor($p->z + sin($angle) * $dist);
        $y = $this->groundY($x, $z, (int) $p->y + 8);
        if ($y <= 0 || $y >= 250) {
            return;
        }
        [$ok] = $this->canStand($x + 0.5, $z + 0.5, $y);
        if (!$ok) {
            return;
        }

        $light = $this->lightAt($x, $y, $z);
        $biome = $this->biomeAt($x, $z);
        $hostile = $this->isNight() || $light <= 7;

        if ($hostile) {
            $type = self::MOB_HOSTILE[array_rand(self::MOB_HOSTILE)];
            if ($light > 7) {
                return;
            }
        } else {
            $pool = self::BIOME_PASSIVE[$biome] ?? [90, 91, 92, 93];
            $type = $pool[array_rand($pool)];
            if ($light < 9) {
                return;
            }
        }

        $this->spawnMob($type, $x + 0.5, (float) $y, $z + 0.5);
    }

    private function lightAt(int $x, int $y, int $z): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $block = $chunk->blockLight($x & 15, $y, $z & 15);
        $sky = $chunk->skyLight($x & 15, $y, $z & 15);
        if ($sky > 0 && $this->isNight()) {
            $sky = max(0, $sky - 11);
        }

        return max($block, $sky);
    }

    private function canStand(float $x, float $z, int $fromY): array
    {
        $bx = (int) floor($x);
        $bz = (int) floor($z);
        $chunk = $this->world->chunk($bx >> 4, $bz >> 4);
        $solid = static fn (int $id): bool => $id !== 0 && !in_array($id, [
            6, 8, 9, 10, 11, 31, 32, 37, 38, 39, 40, 50, 51, 59, 78, 83, 106, 111, 175,
        ], true);
        for ($gy = $fromY + 1; $gy >= max(1, $fromY - 3); $gy--) {
            $here = $chunk->blockState($bx & 15, $gy, $bz & 15) >> 4;
            if (!$solid($here)) {
                continue;
            }
            $up1 = $chunk->blockState($bx & 15, $gy + 1, $bz & 15) >> 4;
            $up2 = $chunk->blockState($bx & 15, $gy + 2, $bz & 15) >> 4;
            if (!$solid($up1) && !$solid($up2)) {
                return [true, $gy + 1];
            }

            return [false, $gy + 1];
        }

        return [false, $fromY];
    }

    private function groundY(int $x, int $z, int $startY): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        for ($y = min(255, $startY); $y > 1; $y--) {
            $id = $chunk->blockState($x & 15, $y, $z & 15) >> 4;
            if ($id !== 0 && !in_array($id, [8, 9, 31, 32, 37, 38, 175], true)) {
                return $y + 1;
            }
        }

        return 0;
    }

    private function biomeAt(int $x, int $z): int
    {
        $chunk = $this->world->chunk($x >> 4, $z >> 4);
        $biomes = $chunk->biomes();
        if ($biomes === null || strlen($biomes) !== 256) {
            return 1;
        }

        return ord($biomes[($z & 15) * 16 + ($x & 15)]);
    }

    private function isNight(): bool
    {
        $time = $this->world->timeOfDay();

        return $time >= 13000 && $time <= 23000;
    }
}
