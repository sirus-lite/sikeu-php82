<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Keputusan hak akses — dipakai menu (AppMenu::forUser), penjaga URL + aksi Livewire (CekAksesMenu),
 * Gate aksi khusus (AppServiceProvider) dan tautan antarhalaman (x-tautan-halaman). Pola sama dengan SIRUS.
 *
 * Permission halaman: `{route}.{aksi}` — SIKEU hanya memakai 'lihat' (aksi lain di dalam halaman memakai
 * aksi khusus berkas program, mis. akun.kelola). Kunci 'aksi' entri menu menyatakan aksi yang berlaku.
 *
 * Urutan keputusan:
 *  1. route tak terdaftar di berkas program → tidak boleh (deny-by-default untuk grup route 'menu');
 *  2. menu ber-roles HakAkses::SEMUA_ROLE → semua user login boleh;
 *  3. peran super (Admin) → boleh;
 *  4. permission sudah dibuat → centang Role Control yang berlaku;
 *  5. belum dibuat (sebelum akses:sinkron pertama) → cadangan: centang awal 'roles' definisi.
 */
class AksesHalaman
{
    /** Aksi halaman yang dikenal; yang dibuat permission-nya hanya yang ada di 'aksi' entri menu. */
    public const AKSI = ['lihat', 'tambah', 'ubah', 'hapus'];

    /** Aksi yang berlaku bila entri menu tak menyebut 'aksi'. */
    private const AKSI_BAWAAN = ['lihat'];

    /** @var array<string, array<string, mixed>>|null route => entri menu (entri pertama bila route dobel) */
    private static ?array $menuPerRoute = null;

    /** @var array<string, true>|null nama permission yang sudah ada di DB */
    private static ?array $permissionAda = null;

    public static function boleh(?object $user, ?string $route, string $aksi = 'lihat'): bool
    {
        if (!$user || !$route) {
            return false;
        }

        $menu = self::menuPerRoute()[$route] ?? null;
        if ($menu === null) {
            return false;
        }
        if (in_array(HakAkses::SEMUA_ROLE, $menu['roles'], true)) {
            return true;
        }
        if (!in_array($aksi, self::aksiBerlaku($route), true)) {
            return false;
        }

        return self::bolehHak($user, self::namaPermission($route, $aksi), self::rolesAwal($route, $aksi));
    }

    /** Gate aksi khusus berkas program (mis. 'akun.kelola'). */
    public static function bolehAksi(?object $user, string $nama): bool
    {
        $aksi = ProgramAkses::aksiKhusus()[$nama] ?? null;
        if (!$user || $aksi === null) {
            return false;
        }

        return self::bolehHak($user, $nama, $aksi['roles']);
    }

    /** Route terdaftar di berkas program (menu)? */
    public static function dikenal(string $route): bool
    {
        return isset(self::menuPerRoute()[$route]);
    }

    public static function namaPermission(string $route, string $aksi): string
    {
        return $route . '.' . $aksi;
    }

    /** @return array<int, string> */
    public static function aksiBerlaku(string $route): array
    {
        return self::menuPerRoute()[$route]['aksi'] ?? self::AKSI_BAWAAN;
    }

    /** @return array<int, string> centang awal (huruf kecil) */
    public static function rolesAwal(string $route, string $aksi): array
    {
        $menu = self::menuPerRoute()[$route] ?? [];

        return array_map(fn ($r) => trim(strtolower($r)), $menu['rolesAksi'][$aksi] ?? $menu['roles'] ?? []);
    }

    /** @return array<string, array<string, mixed>> */
    public static function menuPerRoute(): array
    {
        if (self::$menuPerRoute !== null) {
            return self::$menuPerRoute;
        }

        $hasil = [];
        foreach (ProgramAkses::menu() as $menu) {
            $hasil[$menu['route']] ??= $menu;
        }

        return self::$menuPerRoute = $hasil;
    }

    public static function lupakanCache(): void
    {
        self::$menuPerRoute = null;
        self::$permissionAda = null;
    }

    /** @param array<int, string> $rolesAwal */
    private static function bolehHak(object $user, string $permission, array $rolesAwal): bool
    {
        if ($user->hasRole(HakAkses::PERAN_SUPER)) {
            return true;
        }
        if (self::permissionAda($permission)) {
            return $user->checkPermissionTo($permission);
        }

        $rolesUser = $user->getRoleNames()->map(fn ($r) => trim(strtolower($r)))->all();

        return array_intersect(array_map(fn ($r) => trim(strtolower($r)), $rolesAwal), $rolesUser) !== [];
    }

    private static function permissionAda(string $permission): bool
    {
        self::$permissionAda ??= DB::table('permissions')->where('guard_name', 'web')->pluck('name')->flip()->map(fn () => true)->all();

        return isset(self::$permissionAda[$permission]);
    }
}
