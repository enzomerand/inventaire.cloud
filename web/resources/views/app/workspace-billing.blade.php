{{--
    The subscription of a workspace: the plan it is on, how much of its limits it uses and, for its owner, the way to buy Pro (Stripe
    Checkout) and to manage the subscription (the Stripe customer portal). The other members read the plan.

    Alpine runs in its CSP build: the "workspaceBilling" component (resources/js/components/workspace-billing.js) is referenced by name
    and gets its endpoints from the data-* attributes of its root. Everything goes through the API, which judges the person again; what is
    hidden here is a convenience, never the rule. The same page is where Stripe brings the person back after paying (?checkout=done): the
    script then asks for the subscription to be read and waits for the plan to change.

    The parts that appear and disappear are hidden with the "hidden" attribute (applied at once, unlike x-show). Nothing that carries
    :hidden carries a d-* class (the utility would win): the flex layouts are on inner elements. The live region (role="status") is always
    on the page and never hidden, only its text changes; what people see while something loads is a separate spinner, which screen
    readers skip (aria-hidden). Every text that comes from the API goes through x-text, never x-html.

    The two things the terms of sale promise a client are here: "Cancel your contract" (the customer portal opened on the cancellation) and,
    during the fourteen days after the subscription began, "Withdraw from the contract here", which opens a declaration (the name and the
    address of the person, prefilled from the account, and the contract it is about, read-only) confirmed with "Confirm the withdrawal".
    Their wording is the one the terms use. On an instance that has Stripe but whose seller is not complete (nothing can be sold yet) the
    button that buys Pro is replaced by a neutral "Coming soon".

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Subscription'))

@section('heading', __('Subscription'))

@section('content')
    <p>
        <a class="d-inline-flex align-items-center gap-1" href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">
            <x-tabler-arrow-left aria-hidden="true" />
            {{ __('Back to :name', ['name' => $workspace->name]) }}
        </a>
    </p>

    <div
        x-data="workspaceBilling"
        data-url="{{ route('api.v1.workspaces.billing.show', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-checkout-url="{{ route('api.v1.workspaces.billing.checkout', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-portal-url="{{ route('api.v1.workspaces.billing.portal', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-refresh-url="{{ route('api.v1.workspaces.billing.refresh', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-cancel-url="{{ route('api.v1.workspaces.billing.cancel', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-withdraw-url="{{ route('api.v1.workspaces.billing.withdraw', ['workspace' => $workspace->ulid], absolute: false) }}"
        data-person-name="{{ $person['name'] }}"
        data-person-email="{{ $person['email'] }}"
    >
        <p class="text-secondary" :hidden="!loading" aria-hidden="true">
            <span class="spinner-border spinner-border-sm me-2"></span>
            {{ __('Loading…') }}
        </p>
        <div class="visually-hidden" role="status" x-text="notice"></div>

        <div class="alert alert-danger" role="alert" hidden :hidden="!loadFailed">
            <div class="d-flex align-items-center gap-3">
                <span>{{ __('The subscription could not be loaded.') }}</span>
                <button type="button" class="btn btn-sm ms-auto" @click="load" :disabled="loading">{{ __('Try again') }}</button>
            </div>
        </div>

        <div class="alert alert-info" hidden :hidden="!hasNotice" aria-hidden="true">
            <div class="d-flex align-items-center gap-2">
                <span class="spinner-border spinner-border-sm" hidden :hidden="!confirming"></span>
                <span x-text="notice"></span>
            </div>
        </div>

        <div class="card" hidden :hidden="!notOffered">
            <div class="card-body">
                <p class="mb-0">{{ __('This service is not offered on this instance.') }}</p>
            </div>
        </div>

        <div class="row row-cards" hidden :hidden="!offered">
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Plan') }}</h2>
                        <div class="card-actions">
                            <span class="badge" :class="planClass" x-text="planLabel"></span>
                        </div>
                    </div>

                    <div class="card-body">
                        <div hidden :hidden="!isPro">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge" :class="statusClass" x-text="statusLabel" :hidden="!statusLabel"></span>
                                <span x-text="intervalLabel"></span>
                            </div>
                            <p class="text-secondary" x-text="periodText" :hidden="!periodText"></p>
                            <div class="alert alert-warning mb-0" role="alert" hidden :hidden="!pastDue">
                                {{ __('The last payment failed. Update your payment method to keep Pro.') }}
                            </div>
                        </div>

                        <div hidden :hidden="isPro">
                            <p class="text-secondary" x-text="endedText" :hidden="!endedText"></p>

                            <fieldset class="mb-0" hidden :hidden="!canUpgrade">
                                <legend class="form-label">{{ __('How often do you want to pay?') }}</legend>
                                <label class="form-check">
                                    <input class="form-check-input" type="radio" name="billing-interval" value="month" x-model="interval">
                                    <span class="form-check-label">
                                        {{ __('Monthly') }}
                                        <span class="text-secondary" x-text="monthPrice"></span>
                                    </span>
                                </label>
                                <label class="form-check mb-0" hidden :hidden="!hasYearly">
                                    <input class="form-check-input" type="radio" name="billing-interval" value="year" x-model="interval">
                                    <span class="form-check-label">
                                        {{ __('Yearly') }}
                                        <span class="text-secondary" x-text="yearPrice"></span>
                                    </span>
                                </label>
                            </fieldset>
                        </div>

                        <p class="text-secondary mb-0" hidden :hidden="canManage">{{ __('Only the owner manages the subscription.') }}</p>
                    </div>

                    <div class="card-footer" hidden :hidden="!actionsShown">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" hidden :hidden="!upgradeShown" @click="upgrade" :disabled="!canUpgrade || busy">
                                <span class="spinner-border spinner-border-sm me-2" hidden :hidden="!busy" aria-hidden="true"></span>
                                <x-tabler-rocket aria-hidden="true" />
                                {{ __('Upgrade to Pro') }}
                            </button>
                            <button type="button" class="btn" hidden :hidden="!comingSoon" disabled>
                                <x-tabler-clock aria-hidden="true" />
                                {{ __('Coming soon') }}
                            </button>
                            <button type="button" class="btn" hidden :hidden="!canOpenPortal" @click="manage" :disabled="busy">
                                <span class="spinner-border spinner-border-sm me-2" hidden :hidden="!busy" aria-hidden="true"></span>
                                <x-tabler-credit-card aria-hidden="true" />
                                {{ __('Manage the subscription') }}
                            </button>
                            <button type="button" class="btn" hidden :hidden="!canCancel" @click="cancel" :disabled="busy">
                                <x-tabler-circle-x aria-hidden="true" />
                                {{ __('Cancel your contract') }}
                            </button>
                            <button type="button" class="btn" hidden :hidden="!canWithdraw" @click="openWithdrawal" :disabled="busy || withdrawing" :aria-expanded="withdrawalOpen">
                                <x-tabler-arrow-back-up aria-hidden="true" />
                                {{ __('Withdraw from the contract here') }}
                            </button>
                        </div>
                    </div>

                    <form class="card-body border-top" hidden :hidden="!withdrawalOpen" @submit.prevent="submitWithdrawal" novalidate aria-labelledby="withdrawal-title">
                        <h3 class="card-title" id="withdrawal-title">{{ __('Withdraw from the contract') }}</h3>
                        <p class="text-secondary">{{ __('You can withdraw from the contract, without giving a reason, during the fourteen days after it began. Your subscription then ends at once, and you are refunded what you paid, less an amount proportionate to the service provided until now.') }}</p>
                        <p class="text-secondary" x-text="withdrawalUntilText" :hidden="!withdrawalUntilText"></p>

                        <div class="mb-3">
                            <label class="form-label required" for="withdrawal-name">{{ __('First and last name') }}</label>
                            <input id="withdrawal-name" type="text" class="form-control" x-model="withdrawalName" maxlength="255" autocomplete="name" required>
                        </div>

                        <div class="mb-3">
                            <span class="form-label" id="withdrawal-contract-label">{{ __('Contract concerned') }}</span>
                            <div class="form-control-plaintext" aria-labelledby="withdrawal-contract-label" x-text="withdrawalContractText"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label required" for="withdrawal-email">{{ __('Email address for the acknowledgement') }}</label>
                            <input id="withdrawal-email" type="email" class="form-control" x-model="withdrawalEmail" maxlength="255" autocomplete="email" required>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-danger" :disabled="!canSubmitWithdrawal">
                                <span class="spinner-border spinner-border-sm me-2" hidden :hidden="!withdrawing" aria-hidden="true"></span>
                                {{ __('Confirm the withdrawal') }}
                            </button>
                            <button type="button" class="btn" @click="closeWithdrawal" :disabled="withdrawing">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('Usage') }}</h2>
                    </div>

                    <div class="card-body">
                        <template x-for="row in usageRows" :key="row.key">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <span x-text="row.label"></span>
                                    <span class="text-secondary" x-text="row.text"></span>
                                </div>
                                <div class="progress progress-sm">
                                    <div
                                        class="progress-bar"
                                        :class="row.barClass"
                                        :style="row.style"
                                        role="progressbar"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        :aria-valuenow="row.percent"
                                        :aria-label="row.label"
                                    ></div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="list-group list-group-flush">
                        <template x-for="row in limitRows" :key="row.key">
                            <div class="list-group-item d-flex justify-content-between gap-2">
                                <span x-text="row.label"></span>
                                <span class="text-secondary" x-text="row.text"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
