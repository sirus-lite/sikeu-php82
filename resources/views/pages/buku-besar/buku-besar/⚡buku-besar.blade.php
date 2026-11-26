<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\BaganAkun;
use App\Support\BukuBesar;
use App\Support\Format;
use App\Support\SnapshotJurnal;

/**
 * BUKU BESAR — mutasi satu akun dalam rentang periode dengan saldo berjalan; baris jurnal SIRUS bisa dirinci sampai
 * transaksi aslinya di snapshot yang diposting. Hitungan di App\Support\BukuBesar::mutasiAkun / rincianSirus.
 */
new class extends Component {
    #[Url(as: 'akun', except: '')]
    public string $akunId = '';

    #[Url(as: 'dari', except: '')]
    public string $periodeDari = '';

    #[Url(as: 'sampai', except: '')]
    public string $periodeSampai = '';

    /** Rincian SIRUS yang sedang dibuka (modal) — hasil query tidak disimpan di properti publik. */
    public array $rincianKunci = [];

    private const BATAS_BARIS = 3000;

    public function mount(): void
    {
        $periodeTerakhir = (string) (DB::table('sikeumst_periodes')->whereNotNull('snap_id_sah')->max('periode') ?? now()->format('Ym'));
        if (!SnapshotJurnal::periodeValid($this->periodeDari)) {
            $this->periodeDari = $periodeTerakhir;
        }
        if (!SnapshotJurnal::periodeValid($this->periodeSampai) || $this->periodeSampai < $this->periodeDari) {
            $this->periodeSampai = $this->periodeDari;
        }
    }

    public function updatedPeriodeDari(): void
    {
        if ($this->periodeSampai < $this->periodeDari) {
            $this->periodeSampai = $this->periodeDari;
        }
    }

    public function updatedPeriodeSampai(): void
    {
        if ($this->periodeSampai < $this->periodeDari) {
            $this->periodeDari = $this->periodeSampai;
        }
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->pluck('periode')->map(fn ($periode) => (string) $periode)->all();
    }

    /** Ada periode terposting di rentang? — untuk membedakan "belum diposting" dari "memang tak ada mutasi". */
    #[Computed]
    public function adaPosting(): bool
    {
        return DB::table('sikeumst_periodes')->whereNotNull('snap_id_sah')
            ->where('periode', '>=', $this->periodeDari)->where('periode', '<=', $this->periodeSampai)->exists();
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
            return ['saldo_awal' => 0.0, 'baris' => []];
        }
        $hasil = (new BukuBesar())->mutasiAkun($this->akunId, $this->periodeDari, $this->periodeSampai);
        $saldo = $hasil['saldo_awal'];
        foreach ($hasil['baris'] as $indeks => $baris) {
            $saldo = round($saldo + (float) $baris['debit'] - (float) $baris['kredit'], 2);
            $hasil['baris'][$indeks]['saldo'] = $saldo;
        }
        $hasil['saldo_akhir'] = $saldo;
        $hasil['terpotong'] = count($hasil['baris']) > self::BATAS_BARIS;
        $hasil['baris'] = array_slice($hasil['baris'], 0, self::BATAS_BARIS);

        return $hasil;
    }

    public function bukaRincian(int $indeks): void
    {
        $baris = $this->mutasi['baris'][$indeks] ?? null;
        if (!$baris || $baris['sumber'] !== BukuBesar::SUMBER_SIRUS) {
            return;
        }
        $this->rincianKunci = [
            'jurnal_id' => (int) $baris['jurnal_id'], 'akun_lawan_id' => (string) $baris['akun_lawan_id'],
            'cabang_kunci' => (string) $baris['cabang_kunci'], 'judul' => $this->keteranganBaris($baris), 'no_jurnal' => $baris['no_jurnal'],
        ];
        $this->dispatch('open-modal', name: 'rincian-sirus');
    }

    #[Computed]
    public function rincian(): array
    {
        if ($this->rincianKunci === []) {
            return [];
        }

        return (new BukuBesar())->rincianSirus($this->rincianKunci['jurnal_id'], $this->akunId, $this->rincianKunci['akun_lawan_id'], $this->rincianKunci['cabang_kunci']);
    }

    public function tutupRincian(): void
    {
        $this->rincianKunci = [];
        $this->dispatch('close-modal', name: 'rincian-sirus');
    }

    /** Keterangan baris: isian posting ("OBAT RJ (12 trx)"), atau label cabang yang bisa dibaca, atau sumber jurnal. */
    public function keteranganBaris(array $baris): string
    {
        return (string) ($baris['keterangan'] ?: BukuBesar::labelCabangBaca($baris['label_cabang'] ?? null) ?: ($baris['keterangan_jurnal'] ?? '') ?: $baris['sumber']);
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
};
?>

<div>

    <x-page-title
        title="Buku Besar"
        subtitle="Mutasi & saldo berjalan satu akun — baris jurnal SIRUS bisa dirinci sampai transaksi aslinya" />

    <div class="w-full h-[calc(100vh-5rem)] flex flex-col bg-surface-soft dark:bg-gray-900">
        <div class="flex flex-col flex-1 min-h-0 px-6 pt-2 pb-6">

            {{-- TOOLBAR --}}
            <div class="sticky z-30 px-4 py-3 bg-surface-soft border-b border-hairline top-20 dark:bg-gray-900 dark:border-gray-700">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                    <div class="w-full lg:w-[28rem]">
                        <x-input-label for="akunId" value="Akun" class="sr-only" />
                        <x-select-input id="akunId" wire:model.live="akunId" aria-label="Akun">
                            <option value="">- pilih akun detail -</option>
                            @foreach (BaganAkun::detailPerGolongan() as $golonganId => $golongan)
                                <optgroup label="{{ $golonganId }} {{ $golongan['nama'] }}">
                                    @foreach ($golongan['akun'] as $akunDetailId => $namaAkun)
                                        <option value="{{ $akunDetailId }}">{{ $akunDetailId }} {{ $namaAkun }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-select-input>
                    </div>
                    <div class="flex items-end gap-2">
                        <div class="w-36">
                            <x-input-label for="periodeDari" value="Dari" class="sr-only" />
                            <x-select-input id="periodeDari" wire:model.live="periodeDari" aria-label="Dari periode">
                                @foreach ($this->periodeList as $pilihanPeriode)
                                    <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode($pilihanPeriode) }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <span class="pb-2 text-sm text-muted">s/d</span>
                        <div class="w-36">
                            <x-input-label for="periodeSampai" value="Sampai" class="sr-only" />
                            <x-select-input id="periodeSampai" wire:model.live="periodeSampai" aria-label="Sampai periode">
                                @foreach ($this->periodeList as $pilihanPeriode)
                                    <option value="{{ $pilihanPeriode }}">{{ SnapshotJurnal::labelPeriode($pilihanPeriode) }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    </div>
                    @if ($this->akun && $this->akun->tipe === 'D')
                        <div class="text-sm lg:ml-auto text-muted dark:text-gray-400">
                            Saldo normal <b>{{ $this->akun->saldo_normal === 'D' ? 'Debit' : 'Kredit' }}</b> ·
                            akhir <b class="text-ink dark:text-gray-100 tabular-nums">{{ $this->teksSaldo($this->mutasi['saldo_akhir']) }}</b>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-4 flex flex-col flex-1 min-h-0 bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex-1 min-h-0 overflow-auto rounded-2xl">
                    @if (!$this->akun)
                        <p class="px-6 py-10 text-center text-muted dark:text-gray-400">Pilih akun detail untuk melihat mutasinya.</p>
                    @else
                        <table class="ds-table ds-table-entri">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    <th>Tanggal</th>
                                    <th>No. Jurnal</th>
                                    <th>Keterangan</th>
                                    <th>Akun Lawan</th>
                                    <th class="text-right">Debit</th>
                                    <th class="text-right">Kredit</th>
                                    <th class="text-right">Saldo<span class="block text-xs font-normal tracking-normal normal-case">sisi {{ ($this->akun->saldo_normal ?? 'D') === 'D' ? 'Debit' : 'Kredit' }} (normal)</span></th>
                                    <th class="ds-c">Rincian</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="bg-surface-card dark:bg-gray-800">
                                    <td colspan="6" class="font-medium text-ink dark:text-gray-100">Saldo awal {{ SnapshotJurnal::labelPeriode($periodeDari) }}</td>
                                    <td class="text-right font-medium tabular-nums {{ $this->saldoBerlawanan($this->mutasi['saldo_awal']) ? 'bg-danger-soft text-fg-danger-strong' : '' }}">@if ($this->saldoBerlawanan($this->mutasi['saldo_awal']))<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $this->teksSaldo($this->mutasi['saldo_awal']) }}</td>
                                    <td></td>
                                </tr>
                                @forelse ($this->mutasi['baris'] as $indeks => $baris)
                                    <tr wire:key="mutasi-{{ $indeks }}-{{ $baris['jurnal_id'] }}-{{ $baris['cabang_kunci'] }}-{{ $baris['akun_lawan_id'] }}">
                                        <td class="whitespace-nowrap">{{ Format::tanggal($baris['tanggal']) }}</td>
                                        <td class="ds-td-meta">{{ $baris['no_jurnal'] }}</td>
                                        <td class="text-body dark:text-gray-300">{{ $this->keteranganBaris($baris) }}</td>
                                        <td><span class="font-mono">{{ $baris['akun_lawan_id'] }}</span> {{ $baris['nama_lawan'] }}</td>
                                        <td class="text-right tabular-nums">{{ (float) $baris['debit'] != 0 ? Format::uang($baris['debit']) : '' }}</td>
                                        <td class="text-right tabular-nums">{{ (float) $baris['kredit'] != 0 ? Format::uang($baris['kredit']) : '' }}</td>
                                        <td class="text-right tabular-nums whitespace-nowrap {{ $this->saldoBerlawanan($baris['saldo']) ? 'bg-danger-soft text-fg-danger-strong' : '' }}">@if ($this->saldoBerlawanan($baris['saldo']))<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $this->teksSaldo($baris['saldo']) }}</td>
                                        <td class="ds-c">
                                            @if ($baris['sumber'] === 'SIRUS')
                                                {{-- ikon saja (tanpa teks): ikon lihat = buka transaksi asli SIRUS --}}
                                                <x-icon-button color="gray" type="button" wire:click="bukaRincian({{ $indeks }})" title="Lihat transaksi asli SIRUS">
                                                    <span class="sr-only">Rincian transaksi SIRUS {{ $baris['no_jurnal'] }}</span><x-ikon nama="lihat" />
                                                </x-icon-button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-6 py-8 text-center text-muted dark:text-gray-400">
                                            @if (!$this->adaPosting)
                                                Belum ada periode yang diposting di rentang ini — buku besar hanya membaca jurnal yang sudah disahkan.
                                                <x-tautan-halaman route="buku-besar.periode" :params="['tahun' => substr($periodeDari, 0, 4)]" class="font-medium underline">Sahkan & posting di Tarik Data SIRUS</x-tautan-halaman>.
                                            @else
                                                Tidak ada mutasi akun ini di rentang yang sudah diposting.
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($this->mutasi['baris'] !== [])
                                <tfoot class="sticky bottom-0">
                                    <tr class="font-bold bg-surface-card text-ink dark:bg-gray-800 dark:text-gray-100">
                                        <td colspan="4" class="px-3 py-3">Jumlah mutasi · saldo akhir {{ SnapshotJurnal::labelPeriode($periodeSampai) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang(collect($this->mutasi['baris'])->sum('debit')) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang(collect($this->mutasi['baris'])->sum('kredit')) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap {{ $this->saldoBerlawanan($this->mutasi['saldo_akhir']) ? 'bg-danger-soft text-fg-danger-strong' : '' }}">@if ($this->saldoBerlawanan($this->mutasi['saldo_akhir']))<x-ikon-peringatan label="Saldo berlawanan dengan saldo normal" /> @endif{{ $this->teksSaldo($this->mutasi['saldo_akhir']) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                        @if ($this->mutasi['terpotong'])
                            <p class="px-4 py-2 text-xs text-fg-warning">Tampil 3.000 baris pertama — persempit rentang periode. Saldo akhir tetap dihitung dari semua baris.</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- MODAL RINCIAN SIRUS --}}
    <x-modal name="rincian-sirus" size="full" height="full">
        <div class="flex flex-col h-full">
            <x-modul-dokumen.header judul="Rincian SIRUS {{ $rincianKunci['no_jurnal'] ?? '' }}"
                ikon="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" tutupAlpine="$wire.tutupRincian()">
                {{ $rincianKunci['judul'] ?? '' }} — transaksi asli dari snapshot yang diposting (maks. 500 baris).
            </x-modul-dokumen.header>
            <div class="flex-1 overflow-auto bg-canvas dark:bg-gray-900">
                <table class="ds-table ds-table-entri">
                    <thead class="sticky top-0">
                        <tr>
                            <th>Waktu</th><th>Transaksi</th><th>Akun SIRUS</th><th>Lawan SIRUS</th><th class="ds-c">Shift</th>
                            <th class="text-right">Debit</th><th class="text-right">Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->rincian as $indeksRincian => $transaksi)
                            <tr wire:key="rincian-{{ $indeksRincian }}">
                                <td class="whitespace-nowrap">{{ Format::waktu($transaksi['txn_date']) }}</td>
                                <td>{{ $transaksi['txn_name'] }}</td>
                                <td class="ds-td-meta">{{ $transaksi['txn_acc'] }}</td>
                                <td class="ds-td-meta">{{ $transaksi['txn_acc_k'] }}</td>
                                <td class="ds-c">{{ $transaksi['shift'] }}</td>
                                <td class="text-right tabular-nums">{{ (float) $transaksi['txn_d'] != 0 ? Format::uang($transaksi['txn_d']) : '' }}</td>
                                <td class="text-right tabular-nums">{{ (float) $transaksi['txn_k'] != 0 ? Format::uang($transaksi['txn_k']) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold bg-surface-card dark:bg-gray-800">
                            <td colspan="5" class="px-3 py-3">{{ count($this->rincian) }} transaksi</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang(collect($this->rincian)->sum('txn_d')) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ Format::uang(collect($this->rincian)->sum('txn_k')) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="px-6 py-4 border-t bg-surface-soft border-hairline dark:bg-gray-900 dark:border-gray-700">
                <div class="flex justify-end"><x-secondary-button type="button" wire:click="tutupRincian"><x-ikon nama="batal" /> Tutup</x-secondary-button></div>
            </div>
        </div>
    </x-modal>
</div>
