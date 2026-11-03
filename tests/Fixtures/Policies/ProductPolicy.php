<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures\Policies;

use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can_import;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can_import;
    }
}
