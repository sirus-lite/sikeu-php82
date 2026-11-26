<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Tarik Data & Pengesahan" — kelompok Pembukuan (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Tarik Data & Pengesahan',
    'menu' => [
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 1, 'route' => 'buku-besar.pengaturan', 'title' => 'Pengaturan Pembukuan', 'desc' => 'Langkah pertama: tahun pertama SIKEU (set ulang), buka tahun buku, akun saldo laba', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'PP'],
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 3, 'route' => 'buku-besar.periode', 'title' => 'Tarik Data SIRUS', 'desc' => 'Tarik jurnal SIRUS per bulan / setahun, sahkan ke buku besar, kunci periode; buka tahun buku', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'TDS'],
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 4, 'route' => 'cutoff.banding-versi', 'title' => 'Bandingkan Versi', 'desc' => 'Cari transaksi SIRUS yang baru, hilang, atau berubah antara dua hasil tarikan', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'BV'],
    ],
    'aksiKhusus' => [
        'snapshot.ambil' => ['label' => 'Tarik data jurnal dari SIRUS (hasil tarikan versi baru)', 'roles' => HakAkses::AWAL_PEMBUKUAN],
        'jurnal.posting' => ['label' => 'Sahkan hasil tarikan SIRUS, saldo awal & posting jurnal ke buku besar', 'roles' => HakAkses::AWAL_PENGESAH],
        'periode.kunci' => ['label' => 'Buka tahun buku, kunci / buka kunci periode bulanan', 'roles' => HakAkses::AWAL_PENGESAH],
        'tutupBuku.jalankan' => ['label' => 'Jalankan / batalkan tutup buku tahunan', 'roles' => HakAkses::AWAL_PENGESAH],
    ],
];
