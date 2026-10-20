{{--
    The invitations addressed to the address of the signed-in person: which workspace, who invited them, with which role and until
    when, to accept or decline. It is the page the invitation mail links to. An invitation is bound to the address, not to a
    link: whoever signs in with it sees the invitations, and nobody else does.

    Alpine runs in its CSP build: the "myInvitations" component (resources/js/components/my-invitations.js) is referenced by name
    and gets its endpoint and the page of a workspace (__id__ stands for its ULID) from the data-* attributes of its root. The list
    is read from the API. Accepting takes the person to the workspace; declining asks for a confirmation in the card.

    The parts that appear and disappear are hidden with the "hidden" attribute (applied at once, unlike x-show), so that the
    cursor can be moved to a part the moment it is shown. Nothing that carries :hidden carries a d-* class. Every text that
    comes from the API goes through x-text, never x-html.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Invitations'))

@section('heading', __('Invitations'))

@section('content')
    <div
        x-data="myInvitations"
        data-url="{{ route('api.v1.me.invitations.index', absolute: false) }}"
        data-workspace-url="{{ route('app.workspace', ['workspace' => '__id__'], absolute: false) }}"
    >
        <p class="text-secondary">{{ __('These invitations were sent to your email address. Accepting one gives you access to the workspace, with the role it names.') }}</p>

        <p class="text-secondary" :hidden="!loading" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            {{ __('Loading…') }}
        </p>

        <div class="alert alert-danger" role="alert" hidden :hidden="!loadFailed">
            <div class="d-flex align-items-center gap-3">
                <span>{{ __('Your invitations could not be loaded.') }}</span>
                <button type="button" class="btn btn-sm ms-auto" @click="load" :disabled="loading">{{ __('Try again') }}</button>
            </div>
        </div>

        <div class="row row-cards">
            <template x-for="invitation in invitations" :key="invitation.id">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="card-title text-break" x-text="invitation.workspace.name"></h2>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge" :class="roleClass(invitation)" x-text="roleLabel(invitation)"></span>
                                <span class="text-secondary" x-text="inviterText(invitation)"></span>
                            </div>
                            <p class="text-secondary small mb-3" x-text="expiresText(invitation)"></p>

                            <div :hidden="!isConfirming(invitation)">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-bold" :id="declineTextId(invitation)" x-text="declineText(invitation)"></span>
                                    <button
                                        type="button"
                                        class="btn btn-danger"
                                        data-invitation-action="confirm"
                                        :data-invitation-id="invitation.id"
                                        :aria-describedby="declineTextId(invitation)"
                                        @click="decline(invitation)"
                                        :disabled="busy"
                                    >
                                        <span class="spinner-border spinner-border-sm me-2" :hidden="!busy" aria-hidden="true"></span>
                                        {{ __('Decline') }}
                                    </button>
                                    <button type="button" class="btn" @click="cancelDecline" :disabled="busy">{{ __('Cancel') }}</button>
                                </div>
                            </div>
                            <div :hidden="isConfirming(invitation)">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        data-invitation-action="accept"
                                        :data-invitation-id="invitation.id"
                                        :aria-label="acceptLabel(invitation)"
                                        @click="accept(invitation)"
                                        :disabled="busy"
                                    >
                                        <span class="spinner-border spinner-border-sm me-2" :hidden="!isWorking(invitation)" aria-hidden="true"></span>
                                        <x-tabler-check aria-hidden="true" />
                                        {{ __('Accept') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        data-invitation-action="decline"
                                        :data-invitation-id="invitation.id"
                                        :aria-label="declineLabel(invitation)"
                                        @click="askDecline(invitation)"
                                        :disabled="busy"
                                    >{{ __('Decline') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="card" hidden :hidden="!empty">
            <div class="card-body">
                <div class="empty">
                    <div class="empty-icon hero-icon text-primary">
                        <x-tabler-mail-opened aria-hidden="true" />
                    </div>
                    <p class="empty-title" tabindex="-1" data-focus="heading">{{ __('No invitation is waiting for you') }}</p>
                    <p class="empty-subtitle text-secondary">
                        {{ __('When somebody invites the email address you signed in with to a workspace, the invitation shows up here.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
