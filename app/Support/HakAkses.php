<?php

namespace App\Support;

/**
 * Konstanta hak akses: peran super + CENTANG AWAL yang dipakai berkas program (app/Akses/Program/<Kelompok>/*.php).
 *
 * Siapa boleh apa diatur di Sistem › Peran & Hak Akses dengan mencentang permission per role. Nama role di sini
 * HANYA centang awal — dipakai sekali saat permission dibuat oleh `php artisan akses:sinkron` (otomatis sesudah
 * deploy). Sesudahnya yang berlaku centang Role Control, jadi instansi lain bebas memakai nama jabatannya sendiri.
 *
 * Definisi hak (halaman + aksi khusus) ada di berkas program — lihat App\Support\ProgramAkses. Keputusan
 * boleh/tidak: App\Support\AksesHalaman. Pemakaian aksi: blade @can('akun.kelola'), server Gate::authorize().
 */
class HakAkses
{
    /** Role ini selalu boleh semua (Gate::before) — pengaman supaya tidak ada yang mengunci semua admin keluar. */
    public const PERAN_SUPER = 'Admin';

    /** Penanda 'roles' menu yang terbuka untuk SEMUA user login (Panduan) — tak dibuat permission-nya. */
    public const SEMUA_ROLE = '*';

    /** Centang awal (nama role huruf kecil, dicocokkan tanpa beda besar-kecil). */
    public const AWAL_PEMBUKUAN = ['admin', 'manager keuangan', 'staf akuntansi'];
    public const AWAL_LAPORAN = ['admin', 'manager keuangan', 'staf akuntansi', 'direksi'];
    public const AWAL_PENGESAH = ['admin', 'manager keuangan'];
    public const AWAL_SISTEM = ['admin'];

    /** Label hak untuk tampilan (Role Control, Cara Pakai): lihat halaman atau aksi khusus. */
    public static function label(string $permission): string
    {
        $aksi = ProgramAkses::aksiKhusus()[$permission]['label'] ?? null;
        if ($aksi !== null) {
            return $aksi;
        }
        foreach (ProgramAkses::menu() as $menu) {
            if (AksesHalaman::namaPermission($menu['route'], 'lihat') === $permission) {
                return 'Lihat halaman ' . $menu['title'];
            }
        }

        return '';
    }
}
