<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Saldo Awal & Jurnal Umum" — kelompok Pembukuan (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Saldo Awal & Jurnal Umum',
    'menu' => [
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 2, 'route' => 'buku-besar.saldo-awal', 'title' => 'Saldo Awal', 'desc' => 'Saldo akun per 1 Januari tahun pertama SIKEU: isi form neraca, seimbangkan, sahkan; tahun berikutnya diteruskan otomatis', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'SA'],
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 5, 'route' => 'buku-besar.jurnal-umum', 'title' => 'Jurnal Umum', 'desc' => 'Jurnal penyesuaian & koreksi: konsep, posting (wajib seimbang), batal / pembalik', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'JU'],
    ],
    'aksiKhusus' => [
        'jurnal.entri' => ['label' => 'Buat / ubah konsep jurnal umum selama periode belum dikunci', 'roles' => HakAkses::AWAL_PEMBUKUAN],
    ],
];
