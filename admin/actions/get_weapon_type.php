<?php
function getWeaponType(string $code): string {
    if (str_starts_with($code, 'IS-')) {
        $n = (int)substr($code, 3);
        if ($n >= 1 && $n <= 14) return 'air_pistol';
        if ($n >= 15 && $n <= 43) return 'pistol';
        if ($n >= 51 && $n <= 88) return 'rifle';
        return 'rifle';
    }
    if (preg_match('/^A-\d+$/', $code)) return 'air_pistol';
    if (preg_match('/^S-(\d+)$/', $code, $m)) {
        $n = (int)$m[1];
        if ($n === 64) return 'pistol';
        if ($n >= 1 && $n <= 29) return 'rifle';
        if ($n >= 30 && $n <= 50) return 'pistol';
        if ($n >= 51 && $n <= 68) return 'air_pistol';
        if ($n >= 65 && $n <= 66) return 'rifle';
        if ($n >= 75 && $n <= 80) return 'rifle';
        if ($n >= 81 && $n <= 96) return 'pistol';
        if ($n >= 101 && $n <= 106) return 'rifle';
        if ($n >= 130 && $n <= 133) return 'rifle';
        return 'rifle';
    }
    if (str_starts_with($code, 'DS')) {
        $n = (int)substr($code, 2);
        return ($n >= 1 && $n <= 6) ? 'rifle' : 'pistol';
    }
    if (str_starts_with($code, 'R')) {
        $n = (int)substr($code, 1);
        if ($n >= 1 && $n <= 20) return 'rifle';
        if ($n >= 21 && $n <= 24) return 'air_pistol';
        if ($n >= 25 && $n <= 30) return 'pistol';
        if ($n >= 31 && $n <= 32) return 'air_pistol';
        return 'rifle';
    }
    return 'rifle';
}
