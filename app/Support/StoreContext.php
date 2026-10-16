<?php

namespace App\Support;

use App\Models\Store;

class StoreContext
{
    public bool $resolved = false;

    public ?Store $store = null;

    public function run(Store $store, callable $operation): mixed
    {
        $before = [$this->resolved, $this->store];
        $this->resolved = true;
        $this->store = $store;
        try {
            return $operation();
        } finally {
            [$this->resolved, $this->store] = $before;
        }
    }
}
