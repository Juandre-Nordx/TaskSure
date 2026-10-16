@if(app(\App\Support\StoreContext::class)->store)<input type="hidden" name="_workspace_store_id" value="{{ app(\App\Support\StoreContext::class)->store->id }}">@endif
