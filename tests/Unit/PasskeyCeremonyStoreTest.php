<?php

namespace Tests\Unit;

use App\Services\PasskeyCeremonyStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PasskeyCeremonyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('passkeys.ceremony_ttl', 120);
        Cache::flush();
    }

    public function test_it_returns_a_ceremony_only_once(): void
    {
        $store = app(PasskeyCeremonyStore::class);
        $id = $store->store('login', '{"challenge":"example"}', 12, 34);

        $this->assertSame([
            'type' => 'login',
            'options' => '{"challenge":"example"}',
            'user_id' => 12,
            'device_id' => 34,
            'access_token_id' => null,
        ], $store->consume($id, 'login'));
        $this->assertNull($store->consume($id, 'login'));
    }

    public function test_it_does_not_return_a_ceremony_for_the_wrong_type(): void
    {
        $store = app(PasskeyCeremonyStore::class);
        $id = $store->store('registration', '{"challenge":"example"}', 12, null, 56);

        $this->assertNull($store->consume($id, 'login'));
        $this->assertNotNull($store->consume($id, 'registration'));
    }

    public function test_it_expires_ceremonies_after_the_configured_ttl(): void
    {
        config()->set('passkeys.ceremony_ttl', 1);

        $store = app(PasskeyCeremonyStore::class);
        $id = $store->store('login', '{"challenge":"example"}', 12);

        $this->travel(2)->seconds();

        $this->assertNull($store->consume($id, 'login'));
    }
}
