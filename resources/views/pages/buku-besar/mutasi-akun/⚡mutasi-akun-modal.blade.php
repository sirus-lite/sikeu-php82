<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * MODAL MUTASI AKUN — buku besar satu akun tanpa meninggalkan halaman laporan (Neraca Saldo, Laba Rugi, Neraca).
 * Dibuka lewat event `buku-besar.buka-mutasi` {akunId, dari, sampai}; rincian transaksi SIRUS dibuka di baris
 * itu sendiri (bukan modal bertumpuk). Data dari App\Support\BukuBesar::mutasiAkun / rincianSirus — sama dengan
 * halaman Buku Besar. Hasil query tidak disimpan di properti publik (#[Computed]).
 */
new class extends Component {
    public string $akunId = '';
    public string $periodeDari = '';
    public string $periodeSampai = '';

    /** Indeks baris yang rinciannya sedang dibuka (null = tertutup). */
    public ?int $rincianIndeks = null;

    private const BATAS_BARIS = 3000;

    #[On('buku-besar.buka-mutasi')]
    public function buka(string $akunId, string $dari, string $sampai): void
    {
        if (!SnapshotJurnal::periodeValid($dari) || !SnapshotJurnal::periodeValid($sampai)) {
            return;
        }
        $this->akunId = $akunId;
        $this->periodeDari = min($dari, $sampai);
        $this->periodeSampai = max($dari, $sampai);
        $this->rincianIndeks = null;
        unset($this->akun, $this->mutasi, $this->rincian);
        $this->dispatch('open-modal', name: 'mutasi-akun');
    }

    public function updatedPeriodeDari(): void
    {
        $this->periodeSampai = max($this->periodeDari, $this->periodeSampai);
        $this->rincianIndeks = null;
    }

    public function updatedPeriodeSampai(): void
    {
        $this->periodeDari = min($this->periodeDari, $this->periodeSampai);
        $this->rincianIndeks = null;
    }

    public function tutup(): void
    {
        $this->reset(['akunId', 'rincianIndeks']);
        $this->dispatch('close-modal', name: 'mutasi-akun');
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->pluck('periode')->map(fn ($periode) => (string) $periode)->all();
    }

    #[Computed]
    public function akun(): ?object
    {
        return $this->akunId === '' ? null : DB::table('sikeumst_akuns')->where('akun_id', $this->akunId)->first();
    }

    #[Computed]
    public function mutasi(): array
    {
        if (!$this->akun || $this->akun->tipe !== 'D') {
            return ['saldo_awal' => 0.0, 'baris' => [], 'saldo_akhir' => 0.0, 'debit' => 0.0, 'kredit' => 0.0, 'terpotong' => false];
        }
        $hasil = (new BukuBesar())->mutasiAkun($this->akunId, $this->periodeDari, $this->periodeSampai);
        $saldo = $hasil['saldo_awal'];
        foreach ($hasil['baris'] as $indeks => $baris) {
            $saldo = round($saldo + (float) $baris['debit'] - (float) $baris['kredit'], 2);
            $hasil['baris'][$indeks]['saldo'] = $saldo;
        }

        return $hasil + [
            'saldo_akhir' => $saldo,
            'debit' => round(collect($hasil['baris'])->sum('debit'), 2),
            'kredit' => round(collect($hasil['baris'])->sum('kredit'), 2),
            'terpotong' => count($hasil['baris']) > self::BATAS_BARIS,
        ];
    }

    public function toggleRincian(int $indeks): void
    {
        $this->rincianIndeks = $this->rincianIndeks === $indeks ? null : $indeks;
        unset($this->rincian);
    }

    #[Computed]
    public function rincian(): array
    {
        $baris = $this->rincianIndeks !== null ? ($this->mutasi['baris'][$this->rincianIndeks] ?? null) : null;
        if (!$baris || $baris['sumber'] !== BukuBesar::SUMBER_SIRUS) {
            return [];
        }

        return (new BukuBesar())->rincianSirus((int) $baris['jurnal_id'], $this->akunId, (string) $baris['akun_lawan_id'], (string) $baris['cabang_kunci']);
    }

    /**
     * Saldo bertanda (+ debit / − kredit) → nilai MENURUT SALDO NORMAL akun: positif = di sisi normalnya,
     * negatif = berlawanan. Dipakai supaya tampilan tidak memakai akhiran D/K yang rawan salah baca.
     */
    public function saldoMenurutNormal(float $saldo): float
    {
        return ($this->akun->saldo_normal ?? 'D') === 'K' ? -$saldo : $saldo;
    }

    /** Teks saldo menurut saldo normal: 1,234.00 — berlawanan ditulis dalam kurung (1,234.00). */
    public function teksSaldo(float $saldo): string
    {
        $nilai = round($this->saldoMenurutNormal($saldo), 2);

        return $nilai < 0 ? '(' . Format::uang(-$nilai) . ')' : Format::uang($nilai);
    }

    public function saldoBerlawanan(float $saldo): bool
    {
        return round($this->saldoMenurutNormal($saldo), 2) < 0;
    }

    public function keteranganBaris(array $baris): string
    {
        return (string) ($baris['keterangan'] ?: BukuBesar::labelCabangBaca($baris['label_cabang'] ?? null) ?: ($baris['keterangan_jurnal'] ?? '') ?: $baris['sumber']);
    }
};
?>

<div>
    <x-modal name="mutasi-akun" size="full" height="full">
        <div class="flex flex-col h-full">
            @php $akun = $this->akun; @endphp
            <x-modul-dokumen.header :judul="$akun ? 'Buku Besar ' . $akun->akun_id . ' ' . $akun->nama : 'Buku Besar'"
                ikon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
                tutupAlpine="$wire.tutup()">
                Mutasi & saldo berjalan {{ SnapshotJurnal::labelPeriode($periodeDari ?: now()->format('Ym')) }} s/d {{ SnapshotJurnal::labelPeriode($periodeSampai ?: now()->format('Ym')) }}
                — baris SIRUS bisa dirinci sampai transaksi aslinya.
                <x-slot:badge>
                    @if ($akun)
                        <x-badge variant="alternative" class="shrink-0 whitespace-nowrap">Saldo normal {{ $akun->saldo_normal === 'D' ? 'Debit' : 'Kredit' }}</x-badge>
                    @endif
                </x-slot:badge>
            </x-modul-dokumen.header>

            <div class="flex-1 px-4 py-4 space-y-4 overflow-auto bg-surface-soft dark:bg-gray-950/20">
                @if ($akun)
                    {{-- RENTANG + RINGKASAN --}}
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div class="flex items-end gap-2">
                            <div class="w-36">
                                <x-input-label for="mutasiDari" value="Dari" />
                                <x-select-input id="mutasiDari" wire:model.live="periodeDari" class="mt-1">
                                    @foreach ($this->periodeList as $pilihanPeriode)
                                        <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode($pilihanPeriode) }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                            <span class="pb-2 text-sm text-muted">s/d</span>
                            <div class="w-36">
                                <x-input-label for="mutasiSampai" value="Sampai" />
                                <x-select-input id="mutasiSampai" wire:model.live="periodeSampai" class="mt-1">
                                    @foreach ($this->periodeList as $pilihanPeriode)
                                        <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode($pilihanPeriode) }}</option>
                                    @endforeach
                                </x-select-input>
                            </div>
                        </div>
                        @php $mutasi = $this->mutasi; @endphp
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach (['Saldo awal' => [$this->teksSaldo($mutasi['saldo_awal']), $this->saldoBerlawanan($mutasi['saldo_awal'])], 'Debit' => [Format::uang($mutasi['debit']), false], 'Kredit' => [Format::uang($mutasi['kredit']), false], 'Saldo akhir' => [$this->teksSaldo($mutasi['saldo_akhir']), $this->saldoBerlawanan($mutasi['saldo_akhir'])]] as $judul => [$nilai, $berlawanan])
                                <div class="px-3 py-2 border rounded-lg {{ $berlawanan ? 'bg-danger-soft border-fg-danger-strong' : 'bg-canvas border-hairline dark:bg-gray-900 dark:border-gray-700' }}">
                                    <p class="text-xs {{ $berlawanan ? 'text-fg-danger-strong' : 'text-muted dark:text-gray-400' }}">{{ $judul }}</p>
                                    <p class="text-sm font-semibold tabular-nums whitespace-nowrap {{ $berlawanan ? 'text-fg-danger-strong' : 'text-ink dark:text-gray-100' }}">@if ($berlawanan)<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $nilai }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- TABEL MUTASI --}}
                    <div class="overflow-x-auto bg-canvas border border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                        <table class="ds-table ds-table-entri">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>No. Jurnal</th>
                                    <th>Keterangan</th>
                                    <th>Akun Lawan</th>
                                    <th class="text-right w-36">Debit</th>
                                    <th class="text-right w-36">Kredit</th>
                                    <th class="text-right w-40">Saldo<span class="block text-xs font-normal tracking-normal normal-case">sisi {{ ($akun->saldo_normal ?? 'D') === 'D' ? 'Debit' : 'Kredit' }} (normal)</span></th>
                                    <th class="ds-c w-24">Rincian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="bg-surface-card dark:bg-gray-800">
                                    <td colspan="6" class="font-medium text-ink dark:text-gray-100">Saldo awal {{ SnapshotJurnal::labelPeriode($periodeDari) }}</td>
                                    <td class="text-right font-medium tabular-nums whitespace-nowrap {{ $this->saldoBerlawanan($mutasi['saldo_awal']) ? 'bg-danger-soft text-fg-danger-strong' : '' }}">@if ($this->saldoBerlawanan($mutasi['saldo_awal']))<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $this->teksSaldo($mutasi['saldo_awal']) }}</td>
                                    <td></td>
                                </tr>
                                @forelse (array_slice($mutasi['baris'], 0, 3000) as $indeks => $baris)
                                    <tr wire:key="mm-{{ $indeks }}-{{ $baris['jurnal_id'] }}-{{ $baris['cabang_kunci'] }}-{{ $baris['akun_lawan_id'] }}"
                                        class="{{ $rincianIndeks === $indeks ? 'bg-surface-strong dark:bg-gray-800' : '' }}">
                                        <td class="whitespace-nowrap">{{ Format::tanggal($baris['tanggal']) }}</td>
                                        <td class="ds-td-meta">{{ $baris['no_jurnal'] }}</td>
                                        <td class="text-body dark:text-gray-300">{{ $this->keteranganBaris($baris) }}</td>
                                        <td><span class="font-mono">{{ $baris['akun_lawan_id'] }}</span> {{ $baris['nama_lawan'] }}</td>
                                        <td class="text-right tabular-nums">{{ (float) $baris['debit'] != 0 ? Format::uang($baris['debit']) : '' }}</td>
                                        <td class="text-right tabular-nums">{{ (float) $baris['kredit'] != 0 ? Format::uang($baris['kredit']) : '' }}</td>
                                        <td class="text-right tabular-nums whitespace-nowrap {{ $this->saldoBerlawanan($baris['saldo']) ? 'bg-danger-soft text-fg-danger-strong' : '' }}">@if ($this->saldoBerlawanan($baris['saldo']))<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $this->teksSaldo($baris['saldo']) }}</td>
                                        <td class="ds-c">
                                            @if ($baris['sumber'] === 'SIRUS')
                                                {{-- ikon saja (tanpa teks): ikon lihat = buka transaksi asli SIRUS; hijau saat rinciannya terbuka --}}
                                                <x-icon-button :color="$rincianIndeks === $indeks ? 'green' : 'gray'" type="button" wire:click="toggleRincian({{ $indeks }})"
                                                    aria-expanded="{{ $rincianIndeks === $indeks ? 'true' : 'false' }}" title="{{ $rincianIndeks === $indeks ? 'Tutup' : 'Lihat' }} transaksi asli SIRUS">
                                                    <span class="sr-only">Rincian transaksi SIRUS {{ $baris['no_jurnal'] }}</span><x-ikon nama="lihat" />
                                                </x-icon-button>
                                            @else
                                                <span class="text-xs text-muted">{{ $baris['sumber'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($rincianIndeks === $indeks)
                                        <tr wire:key="mm-rincian-{{ $indeks }}">
                                            <td colspan="8" class="!p-0">
                                                <div class="px-6 py-3 border-y border-hairline bg-surface-soft dark:bg-gray-950/40 dark:border-gray-700">
                                                    <p class="mb-2 text-xs font-semibold text-ink dark:text-gray-100">{{ count($this->rincian) }} transaksi SIRUS{{ count($this->rincian) >= 500 ? ' (500 pertama)' : '' }}</p>
                                                    <table class="w-full text-xs">
                                                        <thead>
                                                            <tr class="text-left text-muted dark:text-gray-400">
                                                                <th class="py-1 pr-3 font-medium">Waktu</th>
                                                                <th class="py-1 pr-3 font-medium">Transaksi</th>
                                                                <th class="py-1 pr-3 font-medium">Akun / Lawan SIRUS</th>
                                                                <th class="py-1 pr-3 font-medium">Shift</th>
                                                                <th class="py-1 pr-3 font-medium text-right">Debit</th>
                                                                <th class="py-1 font-medium text-right">Kredit</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($this->rincian as $nomorRincian => $transaksi)
                                                                <tr wire:key="mm-trx-{{ $indeks }}-{{ $nomorRincian }}" class="border-t border-hairline/60 dark:border-gray-800">
                                                                    <td class="py-1 pr-3 whitespace-nowrap">{{ Format::waktu($transaksi['txn_date']) }}</td>
                                                                    <td class="py-1 pr-3 text-body dark:text-gray-300">{{ $transaksi['txn_name'] }}</td>
                                                                    <td class="py-1 pr-3 font-mono whitespace-nowrap">{{ $transaksi['txn_acc'] }} / {{ $transaksi['txn_acc_k'] }}</td>
                                                                    <td class="py-1 pr-3">{{ $transaksi['shift'] }}</td>
                                                                    <td class="py-1 pr-3 text-right tabular-nums">{{ (float) $transaksi['txn_d'] != 0 ? Format::uang($transaksi['txn_d']) : '' }}</td>
                                                                    <td class="py-1 text-right tabular-nums">{{ (float) $transaksi['txn_k'] != 0 ? Format::uang($transaksi['txn_k']) : '' }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr><td colspan="8" class="px-6 py-8 text-center text-muted dark:text-gray-400">Tidak ada mutasi akun ini di rentang yang sudah diposting.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        @if ($mutasi['terpotong'])
                            <p class="px-4 py-2 text-xs text-fg-warning">Tampil 3.000 baris pertama — persempit rentang. Saldo akhir tetap dihitung dari semua baris.</p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="px-6 py-4 border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex items-center justify-between gap-2">
                    @if ($akun)
                        <x-tautan-halaman route="buku-besar.buku-besar" :params="['akun' => $akunId, 'dari' => $periodeDari, 'sampai' => $periodeSampai]"
                            class="text-sm font-medium text-brand-green hover:underline dark:text-brand-lime">Buka halaman Buku Besar →</x-tautan-halaman>
                    @else
                        <span></span>
                    @endif
                    <x-secondary-button type="button" wire:click="tutup"><x-ikon nama="batal" /> Tutup</x-secondary-button>
                </div>
            </div>
        </div>
    </x-modal>
</div>
