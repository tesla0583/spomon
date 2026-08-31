<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Client;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Реестр клиентов банка с живым поиском по ФИО/названию, документу и ИНН.
 */
final class ClientRegistry extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $clients = Client::query()
            ->with('card')
            ->withCount('spoRaws')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('full_name', 'like', "%{$this->search}%")
                        ->orWhere('doc_number', $this->search)
                        ->orWhere('tax_pay_number', $this->search);
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.client-registry', ['clients' => $clients]);
    }
}
