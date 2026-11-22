<section class="quotes-dashboard" x-data="{ quotes: [], status: 'all', term: '', page: 1, meta: { pages: 1, total: 0 }, loading: false,
         async load() { this.loading=true; try { const p=new URLSearchParams({status:this.status,term:this.term,page:String(this.page),per_page:'20'}); const r=await fetch('/dashboard/quotes/data?'+p); const j=await r.json(); this.quotes=j.data||[]; this.meta=j.meta||this.meta; } finally { this.loading=false; } } }"
         x-init="load()"
         x-on:quotes-refresh.window="load()">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-1">Devis</h1>
            <p class="text-muted mb-0">Demandes commerciales et suivi du cycle de vie.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex gap-2 flex-wrap">
            <input class="form-control" style="max-width: 260px" type="search"
                   placeholder="Référence, nom ou e-mail" x-model="term"
                   x-on:input.debounce.300ms="page=1; load()">
            <select class="form-select" style="max-width: 220px" x-model="status" x-on:change="page=1; load()">
                <option value="all">Tous les statuts</option>
                <option value="pending">En attente</option>
                <option value="processing">En préparation</option>
                <option value="sent">Envoyé</option>
                <option value="accepted">Accepté</option>
                <option value="rejected">Refusé</option>
                <option value="expired">Expiré</option>
                <option value="converted">Converti</option>
                <option value="cancelled">Annulé</option>
            </select>
            <button class="btn btn-outline-secondary" type="button" x-on:click="load()">Actualiser</button>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Demandeur</th>
                        <th>Créé le</th>
                        <th>Statut</th>
                        <th>Total</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="loading">
                        <tr><td colspan="6" class="text-center py-4">Chargement…</td></tr>
                    </template>
                    <template x-for="quote in quotes" :key="quote.id">
                        <tr>
                            <td><strong x-text="quote.reference"></strong></td>
                            <td>
                                <span x-text="quote.customer_name"></span><br>
                                <small class="text-muted" x-text="quote.customer_email"></small>
                            </td>
                            <td x-text="quote.created_at"></td>
                            <td><span class="badge text-bg-secondary" x-text="quote.status"></span></td>
                            <td x-text="quote.total + ' ' + quote.currency"></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" x-bind:href="'/dashboard/quotes/pdf?id=' + quote.id">PDF</a>
                                <a class="btn btn-sm btn-outline-secondary" x-bind:href="'/dashboard/quotes/csv?id=' + quote.id">CSV</a>
                            </td>
                        </tr>
                    </template>
                    <template x-if="!loading && quotes.length === 0">
                        <tr><td colspan="6" class="text-center py-5 text-muted">Aucun devis trouvé.</td></tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.currentScript?.parentElement && Object.assign(
            document.currentScript.parentElement,
            {}
        );
    </script>
</section>
