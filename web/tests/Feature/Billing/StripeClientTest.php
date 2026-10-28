<?php

declare(strict_types=1);

use App\Billing\Billing;
use App\Billing\CheckoutParameters;
use App\Billing\StripeClient;
use App\Billing\StripeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStripe;

require_once __DIR__.'/../../Support/billing.php';

/*
 * The calls the application makes to Stripe: their shape on the wire (the key, the version of the API, form-encoded parameters, the
 * idempotency key), how they fail, and what they hand back. Nothing leaves the process: the network is faked and any request that is not
 * expected is refused.
 */

/**
 * Opens a Checkout session for a customer, the way the application does.
 */
function clientOpenSession(StripeClient $client, string $customer): string
{
    return $client->createCheckoutSession(['mode' => 'subscription', 'customer' => $customer])['id'];
}

beforeEach(function (): void {
    $this->stripe = billingOn();
    $this->client = new StripeClient;
});

describe('every call', function (): void {
    it('carries the key as a bearer token, the version of the API, and asks for JSON', function (): void {
        $this->stripe->addCustomer('cus_1');

        $this->client->subscriptionsOf('cus_1');

        $request = $this->stripe->requests[0];
        expect($request->header('Authorization'))->toBe(['Bearer '.FakeStripe::SECRET])
            ->and($request->header('Stripe-Version'))->toBe(['2026-09-30.endive'])
            ->and($request->header('Accept'))->toBe(['application/json'])
            ->and($request->url())->toStartWith('https://api.stripe.com/v1/subscriptions?');
    });

    it('waits 20 seconds for an answer and 5 to connect, and follows no redirect', function (): void {
        $this->stripe->addCustomer('cus_1');

        $this->client->subscriptionsOf('cus_1');
        $this->client->updateCustomer('cus_1', 'ada@example.test');

        foreach ($this->stripe->options as $options) {
            expect($options['timeout'])->toBe(20)
                ->and($options['connect_timeout'])->toBe(5)
                ->and($options['allow_redirects'])->toBeFalse();
        }
    });

    it('does not follow a redirect, and says so', function (): void {
        $this->stripe->answerWith(Http::response('', 302, ['Location' => 'https://elsewhere.example.test/v1/customers']));

        expect(fn () => $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('http_302')->and($e->retryable())->toBeFalse());
        expect($this->stripe->requests)->toHaveCount(1);
    });

    it('is the version of the API that is configured', function (): void {
        config(['services.stripe.api_version' => '2030-01-01.future']);
        $this->stripe->addCustomer('cus_1');

        $this->client->subscriptionsOf('cus_1');

        expect($this->stripe->requests[0]->header('Stripe-Version'))->toBe(['2030-01-01.future']);
    });

    it('never tries again by itself', function (int $status): void {
        $this->stripe->failNext('POST /v1/customers', $status, 3);

        expect(fn () => $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr'))->toThrow(StripeException::class);
        expect($this->stripe->count('POST /v1/customers'))->toBe(1);
    })->with([429, 500, 503, 400]);

    it('asks nothing of Stripe when the instance has no secret key', function (): void {
        billingOff();

        expect(fn () => $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('not_configured')->and($e->retryable())->toBeFalse()->and($e->status())->toBeNull());
        expect($this->stripe->requests)->toBe([]);
    });
});

describe('the idempotency key', function (): void {
    it('goes with every call that creates or changes something, a new one each time', function (): void {
        $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr');
        $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr');

        [$first, $second] = array_map(fn (Request $request): string => $request->header('Idempotency-Key')[0] ?? '', $this->stripe->requests);

        expect($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
            ->and($second)->toMatch('/^[0-9a-f-]{36}$/')
            ->and($second)->not->toBe($first);
    });

    it('is the one the caller gives, so that a call it repeats creates one customer, not two', function (): void {
        $first = $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr', 'customer-ulid');
        $again = $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr', 'customer-ulid');

        expect($again)->toBe($first)
            ->and($this->stripe->customers)->toHaveCount(1)
            ->and($this->stripe->requests[1]->header('Idempotency-Key'))->toBe(['customer-ulid']);
    });

    it('is refused by Stripe when it is used again with other parameters, and that is not worth retrying', function (): void {
        $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr', 'customer-ulid');

        expect(fn () => $this->client->createCustomer('grace@example.test', 'Shop', 'ulid', 'fr', 'customer-ulid'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('idempotency_error')->and($e->status())->toBe(400)->and($e->retryable())->toBeFalse());
    });

    it('is not sent with a read or a cancellation, which are idempotent by nature', function (): void {
        $id = $this->stripe->subscribe('cus_1');

        $this->client->subscriptionsOf('cus_1');
        $this->client->cancelSubscription($id);

        foreach ($this->stripe->requests as $request) {
            expect($request->hasHeader('Idempotency-Key'))->toBeFalse();
        }
    });
});

describe('creating a customer', function (): void {
    it('posts the address, the name, the workspace and the language, and gives back the id', function (): void {
        $id = $this->client->createCustomer('ada@example.test', 'Ada & Co', '01j0000000000000000000000x', 'fr');

        $request = $this->stripe->requests[0];
        expect($id)->toMatch('/^cus_/')
            ->and($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.stripe.com/v1/customers')
            ->and($request->header('Content-Type'))->toBe(['application/x-www-form-urlencoded'])
            ->and($request->data())->toEqual([
                'email' => 'ada@example.test',
                'name' => 'Ada & Co',
                'metadata' => ['workspace' => '01j0000000000000000000000x'],
                'preferred_locales' => ['fr'],
            ])
            ->and($this->stripe->customers[$id]['email'])->toBe('ada@example.test');
    });

    it('refuses an answer without a usable id', function (mixed $body): void {
        $this->stripe->answerWith(Http::response($body));

        expect(fn () => $this->client->createCustomer('ada@example.test', 'Shop', 'ulid', 'fr'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response')->and($e->retryable())->toBeFalse());
    })->with([
        'none' => [[]],
        'a number' => [['id' => 12]],
        'a path' => [['id' => '../../x']],
        'empty' => [['id' => '']],
        'a list' => [[['id' => 'cus_1']]],
    ]);
});

describe('updating a customer', function (): void {
    it('posts the new address to the customer', function (): void {
        $this->stripe->addCustomer('cus_1', 'old@example.test');

        $this->client->updateCustomer('cus_1', 'new@example.test');

        $request = $this->stripe->requests[0];
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.stripe.com/v1/customers/cus_1')
            ->and($request->data())->toBe(['email' => 'new@example.test'])
            ->and($this->stripe->customers['cus_1']['email'])->toBe('new@example.test');
    });

    it('writes the id of the customer so that it cannot change the path', function (): void {
        expect(fn () => $this->client->updateCustomer('cus_1/../../charges', 'new@example.test'))->toThrow(StripeException::class);

        expect($this->stripe->requests[0]->url())->toBe('https://api.stripe.com/v1/customers/cus_1%2F..%2F..%2Fcharges');
    });

    it('says when the customer is gone', function (): void {
        expect(fn () => $this->client->updateCustomer('cus_gone', 'new@example.test'))
            ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(404)->and($e->code())->toBe('resource_missing')->and($e->retryable())->toBeFalse());
    });
});

describe('the parameters of a Checkout session', function (): void {
    it('are exactly the ones of the specification', function (): void {
        $this->travelTo(now()->startOfSecond());
        $parameters = CheckoutParameters::make('cus_1', 'price_m', '01j0000000000000000000000x', 'https://inventaire.test/app/w/01j/billing?checkout=done', 'https://inventaire.test/app/w/01j/billing', 'fr');

        expect($parameters)->toBe([
            'mode' => 'subscription',
            'customer' => 'cus_1',
            'line_items' => [['price' => 'price_m', 'quantity' => 1]],
            'success_url' => 'https://inventaire.test/app/w/01j/billing?checkout=done',
            'cancel_url' => 'https://inventaire.test/app/w/01j/billing',
            'expires_at' => now()->getTimestamp() + 1860,
            'client_reference_id' => '01j0000000000000000000000x',
            'metadata' => ['instance' => Billing::instanceMarker()],
            'subscription_data' => ['metadata' => ['workspace' => '01j0000000000000000000000x', 'instance' => Billing::instanceMarker()]],
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
            'customer_update' => ['name' => 'auto', 'address' => 'auto'],
            'automatic_tax' => ['enabled' => false],
            'locale' => 'fr',
            'integration_identifier' => 'inventaire-cloud-pkqzrvmw',
        ]);
    });

    it('expire as soon as Stripe allows, a minute over its minimum of half an hour for the request to arrive', function (): void {
        $this->travelTo(now()->startOfSecond());
        $parameters = CheckoutParameters::make('cus_1', 'price_m', 'ulid', 'https://a.test/ok', 'https://a.test/ko', 'en');

        // Stripe takes from 30 minutes to 24 hours after the creation of the session, and refuses a session that would expire sooner.
        expect($parameters['expires_at'] - now()->getTimestamp())->toBe(CheckoutParameters::EXPIRES_AFTER)
            ->toBeGreaterThan(30 * 60)
            ->toBeLessThan(2 * 30 * 60);
    });

    it('never name the payment methods: Stripe offers the ones enabled in the dashboard', function (): void {
        expect(CheckoutParameters::make('cus_1', 'price_m', 'ulid', 'https://a.test/ok', 'https://a.test/ko', 'en'))->not->toHaveKey('payment_method_types');
    });

    it('carry the marker of the instance in the metadata of the session and of the subscription: 16 hexadecimal characters of the hash of its address', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $parameters = CheckoutParameters::make('cus_1', 'price_m', 'ulid', 'https://a.test/ok', 'https://a.test/ko', 'en');
        $marker = substr(hash('sha256', 'https://inventaire.example'), 0, 16);

        expect($parameters['metadata'])->toBe(['instance' => $marker])
            ->and($parameters['subscription_data']['metadata']['instance'])->toBe($marker)
            ->and($marker)->toMatch('/^[0-9a-f]{16}\z/')
            ->and(Billing::instanceMarker())->toBe($marker);

        // The trailing slash does not change it; another address does.
        config(['app.url' => 'https://inventaire.example']);
        expect(Billing::instanceMarker())->toBe($marker);

        config(['app.url' => 'https://other.example']);
        expect(Billing::instanceMarker())->not->toBe($marker);
    });

    it('ask Stripe to work the tax out only when the instance says so', function (): void {
        config(['billing.automatic_tax' => true]);

        expect(CheckoutParameters::make('cus_1', 'price_m', 'ulid', 'https://a.test/ok', 'https://a.test/ko', 'en')['automatic_tax'])->toBe(['enabled' => true]);
    });

    it('speak French or English, and leave any other language to the browser', function (string $locale, string $asked): void {
        expect(CheckoutParameters::make('cus_1', 'price_m', 'ulid', 'https://a.test/ok', 'https://a.test/ko', $locale)['locale'])->toBe($asked)
            ->and(CheckoutParameters::locale($locale))->toBe($asked);
    })->with([['fr', 'fr'], ['en', 'en'], ['de', 'auto'], ['', 'auto'], ['fr-FR', 'auto']]);
});

describe('creating a Checkout session', function (): void {
    it('posts the form with the booleans written as Stripe reads them, and gives back the id and the page to go to', function (): void {
        $this->travelTo(now()->startOfSecond());
        $this->stripe->addCustomer('cus_1');
        $parameters = CheckoutParameters::make('cus_1', FakeStripe::PRICE_MONTHLY, '01j0000000000000000000000x', 'https://inventaire.test/ok?checkout=done', 'https://inventaire.test/ko', 'fr');

        $session = $this->client->createCheckoutSession($parameters);

        $request = $this->stripe->requests[0];
        expect($session)->toBe(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])
            ->and($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.stripe.com/v1/checkout/sessions')
            ->and($request->body())->toContain('line_items%5B0%5D%5Bprice%5D=price_monthly_test')
            ->and($request->body())->not->toContain('payment_method_types')
            ->and($request->data())->toEqual([
                'mode' => 'subscription',
                'customer' => 'cus_1',
                'line_items' => [['price' => FakeStripe::PRICE_MONTHLY, 'quantity' => '1']],
                'success_url' => 'https://inventaire.test/ok?checkout=done',
                'cancel_url' => 'https://inventaire.test/ko',
                'expires_at' => (string) (now()->getTimestamp() + 1860),
                'client_reference_id' => '01j0000000000000000000000x',
                'metadata' => ['instance' => Billing::instanceMarker()],
                'subscription_data' => ['metadata' => ['workspace' => '01j0000000000000000000000x', 'instance' => Billing::instanceMarker()]],
                'allow_promotion_codes' => 'true',
                'billing_address_collection' => 'required',
                'tax_id_collection' => ['enabled' => 'true'],
                'customer_update' => ['name' => 'auto', 'address' => 'auto'],
                'automatic_tax' => ['enabled' => 'false'],
                'locale' => 'fr',
                'integration_identifier' => 'inventaire-cloud-pkqzrvmw',
            ]);
    });

    it('drops the parameters that are null', function (): void {
        $this->stripe->addCustomer('cus_1');

        $this->client->createCheckoutSession(['mode' => 'subscription', 'customer' => 'cus_1', 'locale' => null, 'metadata' => ['a' => null, 'b' => 'x']]);

        expect($this->stripe->requests[0]->data())->toBe(['mode' => 'subscription', 'customer' => 'cus_1', 'metadata' => ['b' => 'x']]);
    });

    it('refuses a page that is not an https address on a host of Stripe', function (mixed $url): void {
        $this->stripe->answerWith(Http::response(['id' => 'cs_1', 'url' => $url]));

        expect(fn () => $this->client->createCheckoutSession(['mode' => 'subscription']))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response'));
    })->with([
        'nothing' => [null],
        'empty' => [''],
        'a number' => [12],
        'not https' => ['http://checkout.stripe.com/x'],
        'a script' => ['javascript:alert(1)'],
        'no scheme' => ['//checkout.stripe.com/x'],
        'another host' => ['https://evil.example/c/pay/cs_1'],
        'a host that ends like Stripe' => ['https://checkout.notstripe.com/x'],
        'Stripe as a subdomain of another host' => ['https://checkout.stripe.com.evil.example/x'],
        'Stripe in the path' => ['https://evil.example/checkout.stripe.com/x'],
        'credentials of another host' => ['https://checkout.stripe.com@evil.example/x'],
        'a backslash that a browser reads as a slash' => ['https://evil.example\\@checkout.stripe.com/x'],
        'a port' => ['https://checkout.stripe.com:8443/x'],
        'a space' => ['https://checkout.stripe.com /x'],
        'a line break' => ["https://checkout.stripe.com/x\r\nSet-Cookie: a=b"],
        'the old test host' => ['https://checkout.stripe.test/x'],
    ]);

    it('accepts the pages Stripe hosts, on stripe.com or one of its subdomains', function (string $url): void {
        $this->stripe->answerWith(Http::response(['id' => 'cs_1', 'url' => $url]));

        expect($this->client->createCheckoutSession(['mode' => 'subscription'])['url'])->toBe($url);
    })->with([
        'Checkout' => ['https://checkout.stripe.com/c/pay/cs_test_a1b2#fid=abc'],
        'the portal' => ['https://billing.stripe.com/p/session/test_abc'],
        'another subdomain' => ['https://pay.stripe.com/x?y=1'],
        'the domain itself' => ['https://stripe.com/x'],
        'in capitals' => ['HTTPS://Checkout.Stripe.COM/x'],
    ]);

    it('refuses an id that ends with a line break, which a dollar would let through', function (): void {
        $this->stripe->answerWith(Http::response(['id' => "cs_1\n", 'url' => 'https://checkout.stripe.com/x']));

        expect(fn () => $this->client->createCheckoutSession(['mode' => 'subscription']))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response'));
    });

    it('says when the customer is not at Stripe', function (): void {
        expect(fn () => $this->client->createCheckoutSession(['mode' => 'subscription', 'customer' => 'cus_gone']))
            ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(404)->and($e->code())->toBe('resource_missing'));
    });
});

describe('creating a portal session', function (): void {
    it('posts the customer, where to come back to and the language, and gives back the page to go to', function (): void {
        $this->stripe->addCustomer('cus_1');

        $url = $this->client->createPortalSession('cus_1', 'https://inventaire.test/app/w/01j/billing', 'en');

        $request = $this->stripe->requests[0];
        expect($url)->toStartWith('https://billing.stripe.com/session/bps_test_')
            ->and($request->url())->toBe('https://api.stripe.com/v1/billing_portal/sessions')
            ->and($request->data())->toBe(['customer' => 'cus_1', 'return_url' => 'https://inventaire.test/app/w/01j/billing', 'locale' => 'en']);
    });

    it('leaves a language Stripe does not speak to the browser', function (): void {
        $this->stripe->addCustomer('cus_1');

        $this->client->createPortalSession('cus_1', 'https://inventaire.test/back', 'nl');

        expect($this->stripe->requests[0]->data()['locale'])->toBe('auto');
    });

    it('refuses an answer without a page to go to', function (): void {
        $this->stripe->answerWith(Http::response(['id' => 'bps_1']));

        expect(fn () => $this->client->createPortalSession('cus_1', 'https://inventaire.test/back', 'fr'))->toThrow(StripeException::class);
    });
});

describe('reading the subscriptions of a customer', function (): void {
    it('asks for all of them, the canceled included, a hundred a page', function (): void {
        $this->stripe->addCustomer('cus_1');

        $this->client->subscriptionsOf('cus_1');

        $request = $this->stripe->requests[0];
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($request->method())->toBe('GET')
            ->and((string) parse_url($request->url(), PHP_URL_PATH))->toBe('/v1/subscriptions')
            ->and($query)->toBe(['customer' => 'cus_1', 'status' => 'all', 'limit' => '100']);
    });

    it('follows the pages until Stripe says there is no more, so that none is missed', function (): void {
        $ids = [];

        foreach (range(1, 205) as $number) {
            $ids[] = $this->stripe->subscribe('cus_1', $number === 1 ? 'active' : 'canceled');
        }

        $subscriptions = $this->client->subscriptionsOf('cus_1');
        $queries = array_map(function ($request): array {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query;
        }, $this->stripe->requests);

        // The most recent first: the list of the fake is the list of Stripe, page after page.
        expect(array_column($subscriptions, 'id'))->toBe(array_reverse($ids))
            ->and($this->stripe->requests)->toHaveCount(3)
            ->and($queries[0])->not->toHaveKey('starting_after')
            ->and($queries[1]['starting_after'])->toBe($subscriptions[99]['id'])
            ->and($queries[2]['starting_after'])->toBe($subscriptions[199]['id'])
            ->and(array_unique(array_column($queries, 'limit')))->toBe(['100'])
            ->and(array_unique(array_column($queries, 'customer')))->toBe(['cus_1'])
            // The live one is the oldest, on the last page: reading the first page only would have called this customer Free.
            ->and($subscriptions[204]['status'])->toBe('active');
    });

    it('reads one page when there is no more, even a full one', function (): void {
        foreach (range(1, 100) as $ignored) {
            $this->stripe->subscribe('cus_1', 'canceled');
        }

        expect($this->client->subscriptionsOf('cus_1'))->toHaveCount(100)
            ->and($this->stripe->requests)->toHaveCount(1);
    });

    it('refuses, for good, a customer with more subscriptions than the pages it reads, rather than give a list that is cut short', function (): void {
        $this->stripe->answerWith(Http::response(['object' => 'list', 'has_more' => true, 'data' => [['id' => 'sub_endless', 'status' => 'canceled']]]));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response')->and($e->retryable())->toBeFalse()->and($e->getMessage())->toContain('1000 subscriptions'));
        expect($this->stripe->requests)->toHaveCount(StripeClient::MAX_PAGES);
    });

    it('refuses a page that says more follow but gives nothing to go on from', function (mixed $data): void {
        $this->stripe->answerWith(Http::response(['object' => 'list', 'has_more' => true, 'data' => $data]));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response')->and($e->retryable())->toBeFalse());
        expect($this->stripe->requests)->toHaveCount(1);
    })->with([
        'no subscription' => [[]],
        'no id' => [[['status' => 'active']]],
        'an id that is no id' => [[['id' => 'sub_1&limit=1']]],
    ]);

    it('fails as a whole when a later page fails, with the failure of that page', function (): void {
        foreach (range(1, 150) as $ignored) {
            $this->stripe->subscribe('cus_1', 'canceled');
        }

        $seen = 0;
        $this->stripe->onCall('GET /v1/subscriptions', function () use (&$seen): void {
            if (++$seen === 2) {
                $this->stripe->failNext('GET /v1/subscriptions', 503);
            }
        });

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(503)->and($e->retryable())->toBeTrue());
        expect($this->stripe->requests)->toHaveCount(2);
    });

    it('gives them as Stripe writes them, the most recent first', function (): void {
        $old = $this->stripe->subscribe('cus_1', 'canceled', 'month', 1_900_000_000);
        $new = $this->stripe->subscribe('cus_1', 'active', 'year', 1_950_000_000, true);
        $this->stripe->subscribe('cus_other');

        $subscriptions = $this->client->subscriptionsOf('cus_1');

        expect(array_column($subscriptions, 'id'))->toBe([$new, $old])
            ->and($subscriptions[0]['status'])->toBe('active')
            ->and($subscriptions[0]['cancel_at_period_end'])->toBeTrue()
            ->and($subscriptions[0]['items']['data'][0]['price']['id'])->toBe(FakeStripe::PRICE_YEARLY)
            ->and($subscriptions[0]['items']['data'][0]['price']['recurring']['interval'])->toBe('year')
            ->and($subscriptions[0]['items']['data'][0]['current_period_end'])->toBe(1_950_000_000);
    });

    it('gives an empty list for a customer that has none', function (): void {
        $this->stripe->addCustomer('cus_1');

        expect($this->client->subscriptionsOf('cus_1'))->toBe([]);
    });

    it('refuses an answer that is not a list of subscriptions', function (mixed $body): void {
        $this->stripe->answerWith(Http::response($body));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response'));
    })->with([
        'no data' => [['object' => 'list']],
        'data that is not a list' => [['data' => 'x']],
        'an object instead of a list' => [['data' => ['a' => ['id' => 'sub_1']]]],
        'entries that are not objects' => [['data' => ['sub_1']]],
    ]);
});

describe('the open Checkout sessions of a customer', function (): void {
    it('lists the ones that are open, a hundred a page, and no others', function (): void {
        $this->stripe->addCustomer('cus_1')->addCustomer('cus_2');
        $open = clientOpenSession($this->client, 'cus_1');
        $expired = clientOpenSession($this->client, 'cus_1');
        $this->client->expireCheckoutSession($expired);
        $done = clientOpenSession($this->client, 'cus_1');
        $this->stripe->completeCheckout($done);
        clientOpenSession($this->client, 'cus_2');
        $this->stripe->requests = [];

        $ids = $this->client->openCheckoutSessionsOf('cus_1');

        $request = $this->stripe->requests[0];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($ids)->toBe([$open])
            ->and($request->method())->toBe('GET')
            ->and((string) parse_url($request->url(), PHP_URL_PATH))->toBe('/v1/checkout/sessions')
            ->and($query)->toBe(['customer' => 'cus_1', 'status' => 'open', 'limit' => '100']);
    });

    it('gives none for a customer that has none', function (): void {
        $this->stripe->addCustomer('cus_1');

        expect($this->client->openCheckoutSessionsOf('cus_1'))->toBe([]);
    });

    it('follows the pages, so that none stays open', function (): void {
        $this->stripe->addCustomer('cus_1');
        $made = [];

        foreach (range(1, 130) as $ignored) {
            $made[] = clientOpenSession($this->client, 'cus_1');
        }

        $this->stripe->requests = [];

        expect($this->client->openCheckoutSessionsOf('cus_1'))->toBe(array_reverse($made))
            ->and($this->stripe->requests)->toHaveCount(2);
    });

    it('refuses a list with something in it that is no session id', function (): void {
        $this->stripe->answerWith(Http::response(['object' => 'list', 'has_more' => false, 'data' => [['id' => 'cs_1/../x']]]));

        expect(fn () => $this->client->openCheckoutSessionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe('invalid_response')->and($e->retryable())->toBeFalse());
    });

    it('expires one with a POST that carries an idempotency key, and the page of the session is over', function (): void {
        $this->stripe->addCustomer('cus_1');
        $id = clientOpenSession($this->client, 'cus_1');
        $this->stripe->requests = [];

        $this->client->expireCheckoutSession($id);

        $request = $this->stripe->requests[0];
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe("https://api.stripe.com/v1/checkout/sessions/{$id}/expire")
            ->and($request->hasHeader('Idempotency-Key'))->toBeTrue()
            ->and($this->stripe->sessions[$id]['status'])->toBe('expired');
    });

    it('does not let the id of a session change the address that is called', function (): void {
        expect(fn () => $this->client->expireCheckoutSession('cs_1/../../customers'))->toThrow(StripeException::class);

        expect($this->stripe->requests[0]->url())->toBe('https://api.stripe.com/v1/checkout/sessions/cs_1%2F..%2F..%2Fcustomers/expire');
    });

    it('says when the session is gone, or is not open any more (it was paid on)', function (): void {
        $this->stripe->addCustomer('cus_1');
        $paid = clientOpenSession($this->client, 'cus_1');
        $this->stripe->completeCheckout($paid);

        expect(fn () => $this->client->expireCheckoutSession('cs_gone'))
            ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(404)->and($e->code())->toBe('resource_missing'));
        expect(fn () => $this->client->expireCheckoutSession($paid))
            ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(400)->and($e->retryable())->toBeFalse()->and($e->code())->toBe('invalid_request_error'));
    });
});

describe('ending a subscription', function (): void {
    it('cancels it now with a DELETE', function (): void {
        $id = $this->stripe->subscribe('cus_1');

        $this->client->cancelSubscription($id);

        $request = $this->stripe->requests[0];
        expect($request->method())->toBe('DELETE')
            ->and($request->url())->toBe("https://api.stripe.com/v1/subscriptions/{$id}")
            ->and($this->stripe->subscriptions[$id]['status'])->toBe('canceled');
    });

    it('says when the subscription is gone, or was canceled already', function (): void {
        $done = $this->stripe->subscribe('cus_1', 'canceled');

        foreach (['sub_gone', $done] as $id) {
            expect(fn () => $this->client->cancelSubscription($id))
                ->toThrow(fn (StripeException $e) => expect($e->status())->toBe(404)->and($e->retryable())->toBeFalse());
        }
    });

    it('has it end with the period that is paid for: cancel_at_period_end is posted as true', function (): void {
        $id = $this->stripe->subscribe('cus_1');

        $this->client->cancelAtPeriodEnd($id);

        $request = $this->stripe->requests[0];
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe("https://api.stripe.com/v1/subscriptions/{$id}")
            ->and($request->data())->toBe(['cancel_at_period_end' => 'true'])
            ->and($request->hasHeader('Idempotency-Key'))->toBeTrue()
            ->and($this->stripe->subscriptions[$id]['cancel_at_period_end'])->toBeTrue()
            ->and($this->stripe->subscriptions[$id]['status'])->toBe('active');
    });
});

describe('a call that fails', function (): void {
    it('can be tried again when Stripe is slow or failing', function (int $status, string $code): void {
        $this->stripe->failNext('GET /v1/subscriptions', $status);

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->retryable())->toBeTrue()->and($e->status())->toBe($status)->and($e->code())->toBe($code));
    })->with([[429, 'rate_limit'], [500, 'api_error'], [502, 'api_error'], [503, 'api_error'], [504, 'api_error']]);

    it('can be tried again when the connection is lost', function (): void {
        $this->stripe->failNext('GET /v1/subscriptions', null);

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->retryable())->toBeTrue()->and($e->code())->toBe('unreachable')->and($e->status())->toBeNull());
    });

    it('keeps nothing of the failure of the connection but its class: the message of the client names the address, which holds the id of the customer', function (): void {
        $this->stripe->failNext('GET /v1/subscriptions', null);

        try {
            $this->client->subscriptionsOf('cus_secretcustomer');
            $thrown = null;
        } catch (StripeException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->getPrevious())->toBeNull()
            ->and($thrown->getMessage())->toBe('Stripe could not be reached (ConnectionException).');
    });

    it('cannot be tried again when Stripe refuses it', function (int $status): void {
        $this->stripe->failNext('GET /v1/subscriptions', $status);

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->retryable())->toBeFalse()->and($e->status())->toBe($status));
    })->with([400, 402, 403, 404, 409]);

    it('says it is the key that is refused, without repeating it', function (): void {
        config(['services.stripe.secret' => 'sk_test_the_wrong_key_xyz']);

        try {
            $this->client->subscriptionsOf('cus_1');
        } catch (StripeException $exception) {
            expect($exception->unauthorized())->toBeTrue()
                ->and($exception->retryable())->toBeFalse()
                ->and($exception->code())->toBe('invalid_request_error')
                ->and($exception->getMessage())->toBe('Stripe answered 401 to GET /v1/subscriptions (invalid_request_error).')
                ->and((string) $exception)->not->toContain('sk_test')
                ->and($exception->getMessage())->not->toContain('Invalid API Key');

            return;
        }

        $this->fail('The call should have failed.');
    });

    it('names the call, the status and the code of the error, never the text Stripe wrote', function (): void {
        $this->stripe->answerWith(Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_invalid_empty', 'message' => 'The email ada@example.test is wrong', 'param' => 'email']], 400));

        try {
            $this->client->updateCustomer('cus_1', 'ada@example.test');
        } catch (StripeException $exception) {
            expect($exception->getMessage())->toBe('Stripe answered 400 to POST /v1/customers/{id} (parameter_invalid_empty).')
                ->and($exception->getMessage())->not->toContain('ada@example.test')
                ->and($exception->getMessage())->not->toContain('cus_1');

            return;
        }

        $this->fail('The call should have failed.');
    });

    it('falls back to the type of the error, then to the status, when Stripe wrote no code', function (mixed $body, string $code): void {
        $this->stripe->answerWith(Http::response($body, 500));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->code())->toBe($code)->and($e->retryable())->toBeTrue());
    })->with([
        'a type' => [['error' => ['type' => 'api_error']], 'api_error'],
        'nothing' => [[], 'http_500'],
        'text that is no word' => [['error' => ['code' => 'Something went <wrong>!', 'type' => 'also bad']], 'http_500'],
        'an HTML page' => ['<html>Bad gateway</html>', 'http_500'],
    ]);

    it('gives the id Stripe put on the request, for its support, and only if it looks like one', function (): void {
        $this->stripe->answerWith(Http::response(['error' => ['type' => 'api_error']], 500, ['Request-Id' => 'req_AbC123xyz']));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->requestId())->toBe('req_AbC123xyz')->and($e->getMessage())->toBe('Stripe answered 500 to GET /v1/subscriptions (api_error), request req_AbC123xyz.'));

        $this->stripe->answerWith(Http::response(['error' => ['type' => 'api_error']], 500, ['Request-Id' => 'req_x; Set-Cookie: a=b']));

        expect(fn () => $this->client->subscriptionsOf('cus_1'))
            ->toThrow(fn (StripeException $e) => expect($e->requestId())->toBeNull()->and($e->getMessage())->not->toContain('Set-Cookie'));
    });
});
