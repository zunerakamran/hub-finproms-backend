<?php

namespace App\Models\WebsiteCompliance;

use App\Support\WebsiteCompliance\WcDatabaseContext;

trait UsesWcDatabaseContext
{
    public function getConnectionName(): ?string
    {
        if (WcDatabaseContext::active()) {
            return WcDatabaseContext::connection();
        }

        return $this->connection;
    }
}
