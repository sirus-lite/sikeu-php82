<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\SaldoAwal;
use App\Support\SnapshotJurnal;

/**
 * TARIK DATA SIRUS — tahun buku, 12 periode, tarik jurnal SIRUS (snapshot), sahkan versi + posting ke buku besar, kunci.
 * Satu-satunya layar penarikan data SIRUS (halaman Snapshot Jurnal lama sudah digabung ke sini).
 * Tahun diketik bebas (mis. 2025) supaya tahun lama bisa diproses mundur. "Tarik & Sahkan Setahun" = antrean: tiap bulan yang
 * belum diposting (sampai bulan berjalan) diambil snapshotnya dari SIRUS lalu disahkan & diposting otomatis; digerakkan
 * wire:poll (pola Snapshot Jurnal) supaya tidak ada request panjang. Logika di App\Support\BukuBesar & SnapshotJurnal.
 * Hak: lihat = buku-besar.periode.lihat (menu), ambil = snapshot.ambil, posting = jurnal.posting, buka tahun & kunci = periode.kunci.
 */
new class extends Component {
    #[Url(as: 'tahun', except: '')]
    public string $tahun = '';

    /** Modal posting */
    public string $periodePosting = '';
    public ?int $snapIdPilih = null;

    /** Modal buka kunci */
    public string $periodeBuka = '';
    public string $alasanBuka = '';

    /** Pengambilan snapshot yang sedang berjalan di halaman ini (wire:poll aktif selama tidak null) */
    public ?int $snapIdProses = null;
    public string $periodeProses = '';

    /** Proses Setahun: periode yang masih menunggu (urut terlama) + ringkasan yang sudah selesai */
    public bool $modeAntrean = false;
    public array $antrean = [];
    public array $hasilAntrean = [];

    public function mount(): void
    {
        if (!$this->tahunSah()) {
            $this->tahun = (string) (DB::table('sikeumst_tahun_bukus')->max('tahun') ?? now()->format('Y'));
        }
        $this->lanjutkanYangTertinggal();
    }

    public function tahunSah(): bool
    {
        return (bool) preg_match('/^\d{4}$/', $this->tahun) && (int) $this->tahun >= 2000 && (int) $this->tahun <= 2100;
    }

    public function updatedTahun(): void
    {
        $this->tahun = trim($this->tahun);
        $this->segarkan();
        $this->lanjutkanYangTertinggal();
    }

    public function geserTahun(int $langkah): void
    {
        if ($this->snapIdProses !== null || !$this->tahunSah()) {
            return;
        }
        $this->tahun = (string) ((int) $this->tahun + ($langkah < 0 ? -1 : 1));
        $this->updatedTahun();
    }

    /** Snapshot tahun ini yang masih berstatus Proses (tab sempat ditutup) → lanjutkan polling. */
    private function lanjutkanYangTertinggal(): void
    {
        if ($this->snapIdProses !== null || !$this->tahunSah() || !auth()->user()?->can('snapshot.ambil')) {
            return;
        }
        $tertinggal = DB::table('sikeutxn_snap_hdrs')->where('periode', 'like', $this->tahun . '%')
            ->where('status', SnapshotJurnal::STATUS_PROSES)->orderBy('periode')->first(['snap_id', 'periode']);
        if ($tertinggal) {
            $this->snapIdProses = (int) $tertinggal->snap_id;
            $this->periodeProses = (string) $tertinggal->periode;
        }
    }

    #[Computed]
    public function tahunAda(): bool
    {
        return $this->tahunSah() && DB::table('sikeumst_tahun_bukus')->where('tahun', (int) $this->tahun)->exists();
    }

    #[Computed]
    public function periodeList(): array
    {
        return $this->tahunSah() ? (new BukuBesar())->periodeList((int) $this->tahun) : [];
    }

    /** Tahun sebelum tahun pertama SIKEU: hanya tarik & bandingkan (tidak dibukukan). */
    #[Computed]
    public function sebelumTahunMulai(): bool
    {
        return $this->tahunSah() && (int) $this->tahun < SaldoAwal::tahunMulai();
    }

    /** Bulan yang akan diproses "Proses Setahun": Buka, belum diposting, tidak di masa depan. */
    #[Computed]
    public function periodeBelumDiposting(): array
    {
        $bulanIni = now()->format('Ym');

        return collect($this->periodeList)
            ->filter(fn (array $row) => $row['status'] === BukuBesar::STATUS_BUKA && !$row['versi_sah'] && $row['periode'] <= $bulanIni)
            ->pluck('periode')->values()->all();
    }

    /** Giliran kunci lintas tahun sejak 01/tahun pertama SIKEU (tahun yang belum dibuka tidak dilewati). */
    #[Computed]
    public function giliranKunci(): array
    {
        return BukuBesar::giliranKunci();
    }

    /** Bulan yang boleh dikunci sekarang = giliran kunci, bila tahunnya sudah dibuka. */
    #[Computed]
    public function periodeBolehKunci(): ?string
    {
        return $this->giliranKunci['ada'] ? $this->giliranKunci['periode'] : null;
    }

    /** Alasan bulan lain belum bisa dikunci (dipakai tombol Kunci nonaktif). */
    #[Computed]
    public function alasanBelumGiliranKunci(): ?string
    {
        if ($this->periodeBolehKunci === null) {
            return 'Kunci berurutan per tahun — tahun ' . substr($this->giliranKunci['periode'], 0, 4) . ' belum dibuka; buka, proses, lalu kunci mulai '
                . SnapshotJurnal::labelPeriode($this->giliranKunci['periode']) . '.';
        }
        $sudahDiposting = DB::table('sikeumst_periodes')->where('periode', $this->periodeBolehKunci)->whereNotNull('snap_id_sah')->exists();
        $label = SnapshotJurnal::labelPeriode($this->periodeBolehKunci);

        return $sudahDiposting
            ? "Kunci berurutan dari bulan terlama — kunci {$label} dulu."
            : "Kunci berurutan dari bulan terlama — {$label} belum diposting.";
    }

    /** Bulan yang boleh dibuka kuncinya: periode Kunci terbaru. */
    #[Computed]
    public function periodeBolehBukaKunci(): ?string
    {
        $periode = DB::table('sikeumst_periodes')->where('status', BukuBesar::STATUS_KUNCI)->max('periode');

        return $periode !== null ? (string) $periode : null;
    }

    #[Computed]
    public function kemajuanProses(): ?array
    {
        return $this->snapIdProses ? (new SnapshotJurnal())->kemajuan($this->snapIdProses) : null;
    }

    private function segarkan(): void
    {
        unset($this->periodeList, $this->tahunAda, $this->periodeBelumDiposting, $this->kemajuanProses, $this->periodeBolehKunci, $this->periodeBolehBukaKunci, $this->alasanBelumGiliranKunci, $this->giliranKunci);
    }

    // ─── Ambil snapshot & Proses Setahun ──────────────────────────────────────
    public function ambilSnapshot(string $periode): void
    {
        abort_unless(auth()->user()?->can('snapshot.ambil'), 403);
        if ($this->snapIdProses !== null) {
            $this->dispatch('toast', type: 'error', message: 'Masih ada penarikan data SIRUS yang berjalan — tunggu selesai.');
            return;
        }
        if (!str_starts_with($periode, $this->tahun) || $periode > now()->format('Ym')) {
            $this->dispatch('toast', type: 'error', message: 'Periode tidak sah / masih di masa depan.');
            return;
        }
        try {
            $this->snapIdProses = (new SnapshotJurnal())->mulai($periode, auth()->id());
            $this->periodeProses = $periode;
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: 'Gagal memulai: ' . $exception->getMessage());
        }
        $this->segarkan();
    }

    public function prosesSetahun(): void
    {
        abort_unless(auth()->user()?->can('snapshot.ambil') && auth()->user()?->can('jurnal.posting'), 403);
        if ($this->snapIdProses !== null) {
            $this->dispatch('toast', type: 'error', message: 'Masih ada penarikan data SIRUS yang berjalan — tunggu selesai.');
            return;
        }
        $this->antrean = $this->periodeBelumDiposting;
        $this->hasilAntrean = [];
        if ($this->antrean === []) {
            $this->dispatch('toast', type: 'info', message: 'Semua bulan sampai bulan berjalan sudah diposting.');
            return;
        }
        $this->modeAntrean = true;
        $this->jalankanAntrean();
    }

    /** Ambil bulan berikutnya dari antrean: pakai versi selesai terakhir bila sudah ada, kalau belum mulai ambil snapshot. */
    private function jalankanAntrean(): void
    {
        while ($this->modeAntrean && $this->antrean !== []) {
            $periode = array_shift($this->antrean);
            $versiList = collect($this->periodeList)->firstWhere('periode', $periode)['versi_list'] ?? [];
            if ($versiList !== []) {
                if (!$this->postingAntrean($periode, (int) end($versiList)['snap_id'])) {
                    return;
                }
                $this->segarkan();
                continue;
            }
            try {
                $this->snapIdProses = (new SnapshotJurnal())->mulai($periode, auth()->id());
                $this->periodeProses = $periode;
            } catch (\Throwable $exception) {
                $this->hentikanAntrean('Gagal memulai ' . SnapshotJurnal::labelPeriode($periode) . ': ' . $exception->getMessage());
            }
            return;
        }
        if ($this->modeAntrean) {
            $this->modeAntrean = false;
            $this->dispatch('toast', type: 'success', message: 'Tarik & sahkan setahun selesai: ' . count($this->hasilAntrean) . ' bulan diposting. Periksa Neraca Saldo sebelum mengunci.');
        }
    }

    private function postingAntrean(string $periode, int $snapId): bool
    {
        try {
            $hasil = (new BukuBesar())->sahkanDanPosting($snapId, auth()->id());
            $this->hasilAntrean[] = ['periode' => $periode, 'jurnal' => $hasil['jurnal'], 'debit' => $hasil['debit']];

            return true;
        } catch (\Throwable $exception) {
            $this->hentikanAntrean('Posting ' . SnapshotJurnal::labelPeriode($periode) . ' gagal: ' . $exception->getMessage());

            return false;
        }
    }

    public function hentikanAntrean(?string $pesan = null): void
    {
        $this->modeAntrean = false;
        $this->antrean = [];
        $this->dispatch('toast', type: $pesan ? 'error' : 'info', message: $pesan ?? 'Antrean dihentikan. Pengambilan yang sedang berjalan tetap diselesaikan, tanpa posting otomatis.');
    }

    public function lanjutkanProses(): void
    {
        if ($this->snapIdProses === null || !auth()->user()?->can('snapshot.ambil')) {
            return;
        }
        $kemajuan = (new SnapshotJurnal())->prosesBerikut($this->snapIdProses, 5);
        $snapId = $this->snapIdProses;
        $periode = $this->periodeProses;
        if ($kemajuan['status'] === SnapshotJurnal::STATUS_SELESAI) {
            $this->snapIdProses = null;
            $this->periodeProses = '';
            $this->segarkan();
            if ($this->modeAntrean) {
                if ($this->postingAntrean($periode, $snapId)) {
                    $this->segarkan();
                    $this->jalankanAntrean();
                }
            } else {
                $terkunci = collect($this->periodeList)->firstWhere('periode', $periode)['status'] ?? BukuBesar::STATUS_BUKA;
                $this->dispatch('toast', type: 'success', message: 'Data SIRUS ' . SnapshotJurnal::labelPeriode($periode) . ' selesai ditarik — '
                    . ($terkunci !== BukuBesar::STATUS_BUKA ? 'periode terkunci, buku tidak berubah; lihat keterangan sama/berbeda di kolom Versi Disahkan.' : 'silakan Sahkan & Posting.'));
            }
        }
        if ($kemajuan['status'] === SnapshotJurnal::STATUS_GAGAL) {
            $this->snapIdProses = null;
            $this->periodeProses = '';
            $pesan = 'Penarikan data SIRUS ' . SnapshotJurnal::labelPeriode($periode) . ' gagal: ' . ($kemajuan['pesan'] ?? 'galat tak dikenal');
            if ($this->modeAntrean) {
                $this->hentikanAntrean($pesan);
            } else {
                $this->dispatch('toast', type: 'error', message: $pesan);
            }
        }
        $this->segarkan();
    }

    public function bukaTahun(): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->bukaTahun((int) $this->tahun, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Tahun buku {$this->tahun} dibuka (12 periode).");
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    // ─── Posting ──────────────────────────────────────────────────────────────
    public function bukaPosting(string $periode): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        $baris = collect($this->periodeList)->firstWhere('periode', $periode);
        if (!$baris || $baris['versi_list'] === []) {
            $this->dispatch('toast', type: 'error', message: 'Data SIRUS bulan ini belum ditarik — klik Tarik dari SIRUS dulu.');
            return;
        }
        $this->periodePosting = $periode;
        $this->snapIdPilih = (int) end($baris['versi_list'])['snap_id'];
        $this->dispatch('open-modal', name: 'posting-periode');
    }

    #[Computed]
    public function akunBelumSiap(): array
    {
        return $this->snapIdPilih ? (new BukuBesar())->akunBelumSiap($this->snapIdPilih) : [];
    }

    /** Akun SIRUS yang diabaikan (Peta Akun SIRUS › Masuk pembukuan = tidak) beserta nilainya di versi terpilih. */
    #[Computed]
    public function akunDiabaikan(): array
    {
        return $this->snapIdPilih ? (new BukuBesar())->akunDiabaikan($this->snapIdPilih) : [];
    }

    public function sahkanPosting(): void
    {
        abort_unless(auth()->user()?->can('jurnal.posting'), 403);
        if (!$this->snapIdPilih) {
            return;
        }
        try {
            $hasil = (new BukuBesar())->sahkanDanPosting($this->snapIdPilih, auth()->id());
            $this->dispatch('toast', type: 'success', message: "Diposting: {$hasil['jurnal']} jurnal, {$hasil['baris']} baris, D = K Rp " . Format::uang($hasil['debit'])
                . ($hasil['dibatalkan'] > 0 ? " ({$hasil['dibatalkan']} jurnal posting lama dibatalkan)" : '')
                . ($hasil['diabaikan']['debit'] > 0 ? '; tidak dibukukan (akun diabaikan) Rp ' . Format::uang($hasil['diabaikan']['debit']) . '.' : '.'));
            $this->tutupPosting();
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function tutupPosting(): void
    {
        $this->reset(['periodePosting', 'snapIdPilih']);
        unset($this->akunBelumSiap, $this->akunDiabaikan);
        $this->dispatch('close-modal', name: 'posting-periode');
    }

    // ─── Kunci / buka kunci ───────────────────────────────────────────────────
    public function kunci(string $periode): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->kunci($periode, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Periode ' . SnapshotJurnal::labelPeriode($periode) . ' dikunci.');
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());
        }
        $this->segarkan();
    }

    public function bukaFormBukaKunci(string $periode): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        $this->periodeBuka = $periode;
        $this->alasanBuka = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'buka-kunci-periode');
    }

    public function bukaKunci(): void
    {
        abort_unless(auth()->user()?->can('periode.kunci'), 403);
        try {
            (new BukuBesar())->bukaKunci($this->periodeBuka, $this->alasanBuka, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'Periode ' . SnapshotJurnal::labelPeriode($this->periodeBuka) . ' dibuka kembali.');
            $this->tutupBukaKunci();
        } catch (\Throwable $exception) {
            $this->addError('alasanBuka', $exception->getMessage());
        }
        $this->segarkan();
    }

    public function tutupBukaKunci(): void
    {
        $this->reset(['periodeBuka', 'alasanBuka']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'buka-kunci-periode');
    }
};
?>

<div>

    <x-page-title
        title="Tarik Data SIRUS"
        subtitle="Tarik jurnal SIRUS per bulan atau setahun sekaligus, sahkan ke buku besar, lalu kunci periode yang sudah diperiksa" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="tahun" value="Tahun Buku" />
                        <div class="flex items-center gap-1 mt-1">
                            <x-secondary-button type="button" wire:click="geserTahun(-1)" :disabled="$snapIdProses !== null" class="px-3" aria-label="Tahun sebelumnya"><x-ikon nama="sebelumnya" /></x-secondary-button>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <x-ikon nama="kalender" class="text-body" />
                                </div>
                                <x-text-input id="tahun" type="text" inputmode="numeric" maxlength="4" wire:model.live.debounce.500ms="tahun"
                                    :disabled="$snapIdProses !== null" placeholder="yyyy" class="block pl-10 w-28" />
                            </div>
                            <x-secondary-button type="button" wire:click="geserTahun(1)" :disabled="$snapIdProses !== null" class="px-3" aria-label="Tahun berikutnya"><x-ikon nama="berikutnya" /></x-secondary-button>
                        </div>
                    </div>
                    @if ($this->tahunAda && !$this->sebelumTahunMulai && $this->periodeBelumDiposting !== [] && !$modeAntrean && $snapIdProses === null)
                        @if (auth()->user()?->can('snapshot.ambil') && auth()->user()?->can('jurnal.posting'))
                            <x-confirm-button variant="primary" action="prosesSetahun()"
                                title="Tarik & Sahkan {{ count($this->periodeBelumDiposting) }} Bulan Tahun {{ $tahun }}"
                                message="Untuk {{ count($this->periodeBelumDiposting) }} bulan yang belum diposting ({{ SnapshotJurnal::labelPeriode($this->periodeBelumDiposting[0]) }} s/d {{ SnapshotJurnal::labelPeriode(collect($this->periodeBelumDiposting)->last()) }}): tarik jurnal dari SIRUS (kira-kira 40-70 detik per bulan; hasil tarikan yang sudah ada dipakai), lalu sahkan & posting otomatis. Biarkan halaman terbuka sampai selesai. Periode TIDAK dikunci otomatis.">
                                <x-ikon nama="proses" /> Tarik & Sahkan Setahun ({{ count($this->periodeBelumDiposting) }} bulan)
                            </x-confirm-button>
                        @endif
                    @endif
                </div>
                <p class="text-xs text-muted dark:text-gray-400" role="status">
                    Tahun pertama SIKEU: <a href="{{ route('buku-besar.pengaturan') }}" wire:navigate class="font-semibold underline text-ink dark:text-gray-200">{{ SaldoAwal::tahunMulai() }}</a>
                    · Kunci berikutnya: <b class="text-ink dark:text-gray-200">{{ SnapshotJurnal::labelPeriode($this->giliranKunci['periode']) }}</b>
                    @if (!$this->giliranKunci['ada'])
                        <span class="text-fg-warning">(tahun {{ substr($this->giliranKunci['periode'], 0, 4) }} belum dibuka)</span>
                    @endif
                </p>
                {{-- Penjelasan alur: buka-tutup (pola Tanya jawab panduan) supaya tidak memenuhi layar --}}
                <details class="px-4 py-2 text-xs rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200 lg:max-w-2xl group">
                    <summary class="flex items-center gap-2 font-medium cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green rounded">
                        <x-ikon nama="bantuan" ukuran="w-3.5 h-3.5" />
                        <span>Cara pakai: <b>Tarik dari SIRUS</b> → <b>Sahkan &amp; Posting</b> → <b>Neraca Saldo</b> → <b>Kunci</b></span>
                        <x-ikon nama="berikutnya" ukuran="w-3.5 h-3.5" class="ml-auto transition-transform group-open:rotate-90 motion-reduce:transition-none" />
                    </summary>
                    <ul class="pl-5 mt-2 space-y-1 list-disc">
                        <li>Ikuti tombol <b>hijau</b> = langkah berikutnya di baris itu. Abu = aksi rutin, garis = hanya melihat, kuning = perlu alasan.</li>
                        <li><b>Kunci berurutan</b> dari bulan terlama (atas ke bawah); <b>Buka Kunci</b> dari bulan terkunci terbaru (bawah ke atas). Tombol yang belum gilirannya tampil pudar dengan alasannya.</li>
                        <li><b>Tarik ulang</b> kapan saja bila data SIRUS berubah — hasil tarikan lama tetap disimpan. Kolom Versi Disahkan menandai tarikan terbaru <i>sama</i> / <i>berbeda</i>.</li>
                        <li>Selama periode Buka, versi lain boleh disahkan (<b>Ganti Versi</b>; posting lama dibatalkan, tetap tersimpan).</li>
                        <li>Bulan terkunci tetap bisa Tarik ulang untuk dicek (buku tidak berubah); bila berbeda, koreksi lewat <b>Jurnal Umum</b> di bulan yang masih Buka.</li>
                        <li>Ketik tahun (mis. 2025) untuk menarik data tahun lama.</li>
                    </ul>
                    <p class="mt-2"><a href="{{ route('panduan.cara-pakai') }}#bulanan" wire:navigate class="font-semibold underline">Panduan lengkap: Cara Pakai SIKEU</a></p>
                </details>
            </div>

            @if (!$this->tahunSah())
                <p role="alert" class="text-sm text-fg-danger-strong">Tahun harus 4 digit antara 2000 dan 2100.</p>
            @endif

            {{-- KEMAJUAN PROSES --}}
            @if ($snapIdProses !== null || $modeAntrean)
                @php
                    $kemajuan = $this->kemajuanProses;
                    $jumlahCabang = max(1, (int) ($kemajuan['jumlah_cabang'] ?? 1));
                    $persen = $kemajuan ? (int) floor((int) $kemajuan['cabang_selesai'] / $jumlahCabang * 100) : 0;
                @endphp
                <div @if ($snapIdProses !== null) wire:poll.2s="lanjutkanProses" @endif
                    class="p-4 border rounded-xl border-blue-200 bg-blue-50 dark:border-blue-800/40 dark:bg-blue-900/20" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-blue-900 dark:text-blue-100">
                                @if ($snapIdProses !== null)
                                    Menarik data SIRUS {{ SnapshotJurnal::labelPeriode($periodeProses) }} — {{ (int) ($kemajuan['cabang_selesai'] ?? 0) }} dari {{ $jumlahCabang }} cabang jurnal ({{ $persen }}%)
                                @else
                                    Memposting...
                                @endif
                            </p>
                            @if ($modeAntrean)
                                <p class="mt-1 text-xs text-blue-800 dark:text-blue-200">
                                    Tarik & sahkan setahun: {{ count($hasilAntrean) }} bulan diposting{{ $antrean !== [] ? ' · menunggu: ' . collect($antrean)->map(fn ($periode) => SnapshotJurnal::labelPeriode($periode))->implode(', ') : '' }}
                                </p>
                            @endif
                        </div>
                        @if ($modeAntrean)
                            <x-danger-button type="button" wire:click="hentikanAntrean" kecil><x-ikon nama="hentikan" ukuran="w-3.5 h-3.5" /> Hentikan antrean</x-danger-button>
                        @endif
                    </div>
                    @if ($snapIdProses !== null)
                        <div class="mt-2"><progress value="{{ $persen }}" max="100" aria-label="Kemajuan penarikan data SIRUS" class="block w-full h-2 overflow-hidden rounded-full appearance-none [&::-webkit-progress-bar]:bg-white [&::-webkit-progress-bar]:rounded-full [&::-webkit-progress-value]:bg-blue-600 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:transition-all motion-reduce:[&::-webkit-progress-value]:transition-none [&::-moz-progress-bar]:bg-blue-600 [&::-moz-progress-bar]:rounded-full bg-white dark:bg-gray-800 dark:[&::-webkit-progress-bar]:bg-gray-800">{{ $persen }}%</progress></div>
                    @endif
                    <p class="mt-2 text-xs text-blue-800 dark:text-blue-200">Biarkan halaman terbuka. Bila tertutup, buka lagi halaman ini untuk melanjutkan penarikan.</p>
                </div>
            @endif

            @if ($hasilAntrean !== [] && !$modeAntrean)
                <div role="status" class="px-4 py-2 text-sm rounded-lg bg-success-soft text-fg-success-strong">
                    Diposting otomatis: {{ collect($hasilAntrean)->map(fn ($hasil) => SnapshotJurnal::labelPeriode($hasil['periode']) . ' (' . $hasil['jurnal'] . ' jurnal)')->implode(', ') }}.
                    <a href="{{ route('buku-besar.neraca-saldo', ['periode' => end($hasilAntrean)['periode']]) }}" wire:navigate class="font-medium underline">Periksa Neraca Saldo</a>
                </div>
            @endif

            @if ($this->tahunSah() && !$this->tahunAda)
                <div class="p-6 text-center border border-dashed rounded-2xl border-hairline bg-canvas dark:bg-gray-800 dark:border-gray-700">
                    <p class="text-sm text-body dark:text-gray-300">Tahun buku {{ $tahun }} belum dibuka.</p>
                    @can('periode.kunci')
                        <x-confirm-button variant="primary" action="bukaTahun()" class="mt-3"
                            title="Buka Tahun Buku {{ $tahun }}"
                            message="Membuat tahun buku {{ $tahun }} dan 12 periode berstatus Buka.">
                            <x-ikon nama="tambah" /> Buka Tahun Buku {{ $tahun }}
                        </x-confirm-button>
                    @else
                        <p class="mt-2 text-xs text-muted">Minta pemegang hak "periode.kunci" membuka tahun buku.</p>
                    @endcan
                </div>
            @elseif ($this->tahunAda)
                @if ($this->sebelumTahunMulai)
                    <div role="status" class="flex gap-2 px-4 py-3 mb-3 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                        <x-ikon nama="bantuan" ukuran="w-4 h-4" class="mt-0.5 shrink-0" />
                        <p>Tahun {{ $tahun }} sebelum tahun pertama SIKEU ({{ SaldoAwal::tahunMulai() }}) — <b>hanya untuk dicek</b>: boleh Tarik dari SIRUS &amp; Bandingkan Versi, tetapi tidak disahkan / dibukukan,
                            karena saldonya sudah tercakup di <a href="{{ route('buku-besar.saldo-awal') }}" wire:navigate class="font-semibold underline">Saldo Awal {{ SaldoAwal::tahunMulai() }}</a>.</p>
                    </div>
                @endif
                <div class="overflow-x-auto bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <table class="ds-table ds-table-entri">
                        <thead>
                            <tr>
                                <th>Periode</th>
                                <th class="ds-c">Status</th>
                                <th>Versi Disahkan</th>
                                <th class="ds-c">Hasil Tarikan</th>
                                <th class="text-right">Total Jurnal (D = K)</th>
                                <th class="ds-c">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->periodeList as $row)
                                <tr wire:key="periode-{{ $row['periode'] }}">
                                    <td class="font-medium text-ink dark:text-gray-100">{{ SnapshotJurnal::labelPeriode($row['periode']) }}</td>
                                    <td class="ds-c">
                                        @switch($row['status'])
                                            @case('B') <x-badge variant="success">Buka</x-badge> @break
                                            @case('K') <x-badge variant="warning">Kunci</x-badge> @break
                                            @case('T') <x-badge variant="gray">Tutup</x-badge> @break
                                        @endswitch
                                        @if ($row['alasan_buka'] && $row['status'] === 'B')
                                            <div class="mt-1 text-xs text-muted dark:text-gray-400" title="{{ $row['alasan_buka'] }}">pernah dibuka ulang</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['versi_sah'])
                                            <span class="font-medium text-ink dark:text-gray-200">Versi {{ $row['versi_sah'] }}</span>
                                            @if ($row['jurnal_sirus'])
                                                <div class="text-xs text-muted dark:text-gray-400">{{ $row['jurnal_sirus']->jumlah }} jurnal · {{ Format::waktu($row['jurnal_sirus']->terakhir) }}</div>
                                            @endif
                                            @if ($row['status_versi_baru'] === 'sama')
                                                <div class="mt-1 text-xs font-medium text-fg-success-strong">Tarikan terbaru (versi {{ $row['versi_terbaru'] }}) sama — SIRUS tidak berubah</div>
                                            @elseif ($row['status_versi_baru'] === 'beda')
                                                <a href="{{ route('cutoff.banding-versi', ['periode' => $row['periode'], 'lama' => $row['snap_id_sah'], 'baru' => $row['snap_id_terbaru']]) }}" wire:navigate
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 mt-1 text-xs font-medium rounded bg-warning-soft text-fg-warning hover:underline">
                                                    <x-ikon-peringatan /> Versi {{ $row['versi_terbaru'] }} berbeda — lihat transaksi yang berubah
                                                </a>
                                                @if ($row['status'] !== 'B')
                                                    <div class="mt-1 text-xs text-muted dark:text-gray-400">
                                                        Periode terkunci: koreksi selisihnya lewat
                                                        <a href="{{ route('buku-besar.jurnal-umum') }}" wire:navigate class="underline">Jurnal Umum</a> di bulan yang masih Buka (disarankan),
                                                        atau Buka Kunci → Ganti Versi → Kunci lagi.
                                                    </div>
                                                @endif
                                            @endif
                                        @else
                                            <span class="italic text-muted-soft">belum diposting</span>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        @if ($periodeProses === $row['periode'])
                                            <span class="text-xs font-medium text-blue-700 dark:text-blue-300">sedang ditarik...</span>
                                        @elseif (count($row['versi_list']) > 0)
                                            <span class="text-xs">{{ count($row['versi_list']) }} versi</span>
                                        @else
                                            <span class="text-xs text-muted">belum ada</span>
                                        @endif
                                    </td>
                                    <td class="text-right tabular-nums">{{ $row['total_debit'] > 0 ? Format::uang($row['total_debit']) : '-' }}</td>
                                    <td class="ds-c">
                                        {{-- Warna = langkah berikutnya: hanya aksi yang memang perlu dikerjakan yang hijau solid --}}
                                        @php
                                            $bebas = $snapIdProses === null && !$modeAntrean;
                                            $bolehKunci = $row['status'] === 'B' && $row['versi_sah'] && $row['periode'] === $this->periodeBolehKunci;
                                            $bolehBukaKunci = $row['status'] === 'K' && $row['periode'] === $this->periodeBolehBukaKunci;
                                        @endphp
                                        <div class="flex flex-wrap justify-end gap-2">
                                            {{-- Menarik versi baru tidak mengubah buku → tetap boleh di bulan terkunci (untuk cek perubahan SIRUS) --}}
                                            @if ($row['periode'] <= now()->format('Ym') && $bebas && ($row['status'] === 'B' || count($row['versi_list']) > 0))
                                                @can('snapshot.ambil')
                                                    <x-secondary-button type="button" kecil wire:click="ambilSnapshot('{{ $row['periode'] }}')">
                                                        <x-ikon nama="muat-ulang" /> {{ count($row['versi_list']) > 0 ? 'Tarik ulang' : 'Tarik dari SIRUS' }}
                                                    </x-secondary-button>
                                                @endcan
                                            @endif
                                            @if ($row['status'] === 'B' && count($row['versi_list']) > 0 && $bebas && !$this->sebelumTahunMulai)
                                                @can('jurnal.posting')
                                                    @if ($row['versi_sah'])
                                                        <x-secondary-button type="button" kecil wire:click="bukaPosting('{{ $row['periode'] }}')">
                                                            <x-ikon nama="setujui" /> Ganti Versi
                                                        </x-secondary-button>
                                                    @else
                                                        <x-primary-button type="button" kecil wire:click="bukaPosting('{{ $row['periode'] }}')">
                                                            <x-ikon nama="setujui" /> Sahkan & Posting
                                                        </x-primary-button>
                                                    @endif
                                                @endcan
                                            @endif
                                            @if ($row['versi_sah'])
                                                <x-outline-button type="button" kecil
                                                    x-on:click="Livewire.navigate('{{ route('buku-besar.neraca-saldo', ['periode' => $row['periode']]) }}')">
                                                    <x-ikon nama="lihat" /> Neraca Saldo
                                                </x-outline-button>
                                            @endif
                                            @if ($row['status'] === 'B' && $row['versi_sah'] && !$bolehKunci)
                                                @can('periode.kunci')
                                                    {{-- Tampil tapi nonaktif + alasan, supaya pemakai tahu kenapa belum bisa dikunci --}}
                                                    <x-secondary-button type="button" kecil disabled
                                                        title="{{ $this->alasanBelumGiliranKunci }}" aria-describedby="alasan-kunci-{{ $row['periode'] }}">
                                                        <x-ikon nama="kunci" /> Kunci
                                                    </x-secondary-button>
                                                @endcan
                                            @endif
                                            @if ($bolehKunci)
                                                @can('periode.kunci')
                                                    <x-confirm-button variant="primary" :action="'kunci(\'' . $row['periode'] . '\')'" kecil
                                                        title="Kunci Periode {{ SnapshotJurnal::labelPeriode($row['periode']) }}"
                                                        message="Sesudah dikunci, periode ini tidak menerima posting / jurnal baru sampai dibuka kembali dengan alasan. Pastikan Neraca Saldo sudah diperiksa.">
                                                        <x-ikon nama="kunci" /> Kunci
                                                    </x-confirm-button>
                                                @endcan
                                            @endif
                                            @if ($bolehBukaKunci)
                                                @can('periode.kunci')
                                                    <x-warning-button type="button" kecil wire:click="bukaFormBukaKunci('{{ $row['periode'] }}')"><x-ikon nama="buka-kunci" /> Buka Kunci</x-warning-button>
                                                @endcan
                                            @elseif ($row['status'] === 'K')
                                                @can('periode.kunci')
                                                    <x-secondary-button type="button" kecil disabled
                                                        title="Buka kunci berurutan dari bulan terbaru — buka {{ SnapshotJurnal::labelPeriode((string) $this->periodeBolehBukaKunci) }} dulu."
                                                        aria-describedby="alasan-kunci-{{ $row['periode'] }}">
                                                        <x-ikon nama="buka-kunci" /> Buka Kunci
                                                    </x-secondary-button>
                                                @endcan
                                            @endif
                                        </div>
                                        @can('periode.kunci')
                                            @if ($row['status'] === 'B' && $row['versi_sah'] && !$bolehKunci)
                                                <p id="alasan-kunci-{{ $row['periode'] }}" class="mt-1 text-xs text-right text-muted dark:text-gray-400">{{ $this->alasanBelumGiliranKunci }}</p>
                                            @elseif ($row['status'] === 'K' && !$bolehBukaKunci)
                                                <p id="alasan-kunci-{{ $row['periode'] }}" class="mt-1 text-xs text-right text-muted dark:text-gray-400">Buka kunci berurutan dari bulan terbaru — buka {{ SnapshotJurnal::labelPeriode((string) $this->periodeBolehBukaKunci) }} dulu.</p>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- MODAL POSTING --}}
    <x-modal name="posting-periode" size="full" height="full">
        <div class="flex flex-col h-full">
            <x-modul-dokumen.header judul="Sahkan & Posting {{ $periodePosting ? SnapshotJurnal::labelPeriode($periodePosting) : '' }}"
                ikon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" tutupAlpine="$wire.tutupPosting()">
                Versi yang disahkan diposting ke buku besar; posting SIRUS sebelumnya untuk periode ini dibatalkan.
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 space-y-4 bg-surface-soft dark:bg-gray-950/20">
                @php $barisPosting = collect($this->periodeList)->firstWhere('periode', $periodePosting); @endphp
                @if ($barisPosting)
                    <x-border-form title="Pilih Hasil Tarikan (versi)">
                        <div class="space-y-2">
                            @php $versiUrut = $barisPosting['versi_list']; @endphp
                            @foreach (array_reverse($versiUrut, true) as $indeksVersi => $versi)
                                @php $versiSebelum = $versiUrut[$indeksVersi - 1] ?? null; @endphp
                                <label wire:key="versi-{{ $versi['snap_id'] }}"
                                    class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer border-hairline dark:border-gray-700 has-[:checked]:border-brand-green has-[:checked]:bg-surface-strong dark:has-[:checked]:border-brand-lime">
                                    <input type="radio" wire:model.live="snapIdPilih" value="{{ $versi['snap_id'] }}" class="text-brand-green focus:ring-brand-green">
                                    <span class="flex-1">
                                        <span class="font-medium text-ink dark:text-gray-100">Versi {{ $versi['versi'] }}</span>
                                        @if ((int) $versi['snap_id'] === (int) $barisPosting['snap_id_sah'])
                                            <x-badge variant="success" class="ml-1">sedang disahkan</x-badge>
                                        @endif
                                        <span class="block text-xs text-muted dark:text-gray-400">
                                            ditarik {{ Format::waktu($versi['selesai_at']) }} · {{ number_format((int) $versi['jumlah_baris'], 0, ',', '.') }} baris · D = K Rp {{ Format::uang($versi['total_debit']) }}
                                        </span>
                                        @if ($versiSebelum)
                                            @if ($versiSebelum['checksum'] === $versi['checksum'])
                                                <span class="block text-xs font-medium text-fg-success-strong">Isi sama persis dengan versi {{ $versiSebelum['versi'] }} — tidak ada transaksi berubah.</span>
                                            @else
                                                <a href="{{ route('cutoff.banding-versi', ['periode' => $periodePosting, 'lama' => $versiSebelum['snap_id'], 'baru' => $versi['snap_id']]) }}" wire:navigate
                                                    class="block text-xs font-medium text-fg-warning hover:underline">Berbeda dengan versi {{ $versiSebelum['versi'] }} — lihat transaksi yang berubah</a>
                                            @endif
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-muted dark:text-gray-400">
                            Ingin tahu beda versinya?
                            <a href="{{ route('cutoff.banding-versi', ['periode' => $periodePosting]) }}" wire:navigate class="font-medium text-brand-green hover:underline dark:text-brand-lime">Bandingkan Versi</a>
                        </p>
                    </x-border-form>

                    <x-border-form title="Pemeriksaan Peta Akun">
                        @if ($this->akunBelumSiap === [])
                            <p class="text-sm text-fg-success-strong" role="status">Semua akun SIRUS di versi ini sudah dipetakan ke akun Detail aktif.</p>
                        @else
                            <p class="text-sm text-fg-danger-strong" role="alert">
                                {{ count($this->akunBelumSiap) }} akun SIRUS belum siap: {{ implode(', ', array_slice($this->akunBelumSiap, 0, 15)) }}{{ count($this->akunBelumSiap) > 15 ? ', ...' : '' }}.
                                Lengkapi di <a href="{{ route('master.peta-akun', ['status' => 'MASALAH']) }}" wire:navigate class="underline">Peta Akun SIRUS</a>.
                            </p>
                        @endif
                        @if ($this->akunDiabaikan !== [])
                            <div class="flex gap-2 px-3 py-2 mt-3 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                                <x-ikon nama="bantuan" ukuran="w-4 h-4" class="mt-0.5 shrink-0" />
                                <div>
                                    <p>{{ count($this->akunDiabaikan) }} akun SIRUS <b>diabaikan</b> — transaksinya (kedua sisi) tidak dibukukan dan harus dicatat sendiri di SIKEU:</p>
                                    <ul class="pl-5 mt-1 list-disc">
                                        @foreach ($this->akunDiabaikan as $akun)
                                            <li wire:key="abaikan-{{ $akun['acc'] }}"><span class="font-mono text-xs">{{ $akun['acc'] }}</span> {{ $akun['nama'] }} — D Rp {{ Format::uang($akun['debit']) }} / K Rp {{ Format::uang($akun['kredit']) }}</li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ route('master.peta-akun', ['status' => 'DIABAIKAN']) }}" wire:navigate class="underline">Atur di Peta Akun SIRUS</a>
                                </div>
                            </div>
                        @endif
                    </x-border-form>
                @endif
            </div>

            <div class="sticky bottom-0 z-10 px-6 py-4 mt-auto bg-surface-soft border-t border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupPosting"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="sahkanPosting" wire:loading.attr="disabled" wire:target="sahkanPosting"
                        :disabled="$this->akunBelumSiap !== [] || !$snapIdPilih || ($barisPosting && (int) $snapIdPilih === (int) $barisPosting['snap_id_sah'])">
                        <span wire:loading.remove wire:target="sahkanPosting" class="inline-flex items-center gap-2"><x-ikon nama="setujui" /> Sahkan & Posting</span>
                        <span wire:loading wire:target="sahkanPosting">Memposting...</span>
                    </x-primary-button>
                </div>
            </div>
        </div>
    </x-modal>

    {{-- MODAL BUKA KUNCI --}}
    <x-modal name="buka-kunci-periode" size="2xl" focusable>
        <div class="p-1">
            <h2 class="text-lg font-semibold text-ink dark:text-gray-100">Buka Kunci {{ $periodeBuka ? SnapshotJurnal::labelPeriode($periodeBuka) : '' }}</h2>
            <p class="mt-1 text-sm text-muted dark:text-gray-400">Periode akan kembali Buka: bisa diposting ulang. Alasan dicatat di log audit.</p>
            <div class="mt-4">
                <x-input-label for="alasanBuka" value="Alasan buka kunci *" />
                <x-textarea id="alasanBuka" wire:model="alasanBuka" rows="3" class="w-full mt-1" :error="$errors->has('alasanBuka')"
                    placeholder="mis. koreksi transaksi kasir tanggal 3 yang terlambat diinput" />
                <x-input-error :messages="$errors->get('alasanBuka')" class="mt-1" />
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <x-secondary-button type="button" wire:click="tutupBukaKunci"><x-ikon nama="batal" /> Batal</x-secondary-button>
                <x-warning-button type="button" wire:click="bukaKunci" wire:loading.attr="disabled" wire:target="bukaKunci"><x-ikon nama="buka-kunci" /> Buka Kunci</x-warning-button>
            </div>
        </div>
    </x-modal>
</div>
