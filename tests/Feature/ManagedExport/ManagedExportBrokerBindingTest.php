<?php

namespace Tests\Feature\ManagedExport;

use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\UnixManagedExportAccessBroker;
use Tests\TestCase;

class ManagedExportBrokerBindingTest extends TestCase
{
    public function test_application_resolves_the_public_managed_export_port(): void
    {
        $this->assertInstanceOf(
            UnixManagedExportAccessBroker::class,
            $this->app->make(ManagedExportAccessBroker::class),
        );
    }
}
