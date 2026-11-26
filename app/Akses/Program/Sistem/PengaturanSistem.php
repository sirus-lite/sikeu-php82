<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Pengaturan Sistem" — kelompok Sistem (urutan & judul: _kelompok.php).
 * Membuka halaman = boleh mengubah isinya (tak ada hak ubah terpisah). Role Admin selalu boleh semua,
 * jadi tak mungkin semua admin terkunci keluar dari Peran & Hak Akses.
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Pengaturan Sistem',
    'menu' => [
        ['group' => 'Sistem', 'groupOrder' => 5, 'order' => 1, 'route' => 'sistem.identitas', 'title' => 'Identitas Instansi', 'desc' => 'Logo, nama & profil instansi (kop cetakan, halaman depan)', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'ID'],
        ['group' => 'Sistem', 'groupOrder' => 5, 'order' => 2, 'route' => 'sistem.user-control', 'title' => 'Pengguna', 'desc' => 'Kelola akun login pengguna, peran & tanda tangan', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'PGN'],
        ['group' => 'Sistem', 'groupOrder' => 5, 'order' => 3, 'route' => 'sistem.role-control', 'title' => 'Peran & Hak Akses', 'desc' => 'Kelola peran (role) & hak akses yang dicentang per peran', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'HAK'],
        ['group' => 'Sistem', 'groupOrder' => 5, 'order' => 4, 'route' => 'sistem.sambungan-sirus', 'title' => 'Sambungan SIRUS', 'desc' => 'Uji sambungan API SIRUS: login token, katalog cabang jurnal & bagan akun', 'roles' => HakAkses::AWAL_SISTEM, 'badge' => 'API'],
    ],
];
