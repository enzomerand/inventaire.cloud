{{--
    The members of a workspace: who belongs to it and with which role. Its owner also changes roles, removes members, hands the
    ownership over, invites people and sends or withdraws invitations; the other members read the list and may leave.

    Alpine runs in its CSP build: the "workspaceMembers" component (resources/js/components/workspace-members.js) and, for the
    owner, the "workspaceInvitations" component (resources/js/components/workspace-invitations.js) are referenced by name and get
    their endpoints from the data-* attributes of their roots. Everything goes through the API, which judges the person again; what
    is hidden here (the controls of the owner are not even rendered for the others) is a convenience, never the rule. Removing
    a member, handing the ownership over, leaving and withdrawing an invitation ask for a confirmation in the row itself.

    The parts that appear and disappear are hidden with the "hidden" attribute (applied at once, unlike x-show), so that the
    cursor can be moved to a part the moment it is shown. Nothing that carries :hidden carries a d-* class. Every text that
    comes from the API goes through x-text, never x-html.

    Icons: never pass a class attribute to <x-tabler-*> (see components/locale-switcher.blade.php).
--}}
@extends('layouts.app')

@section('title', __('Members'))

@section('heading', __('Members'))

@section('content')
    <p>
        <a class="d-inline-flex align-items-center gap-1" href="{{ route('app.workspace', ['workspace' => $workspace->ulid], absolute: false) }}">
            <x-tabler-arrow-left aria-hidden="true" />
            {{ __('Back to :name', ['name' => $workspace->name]) }}
        </a>
    </p>

    <div class="row row-cards">
        <div class="col-12 col-lg-8">
            <div
                class="card"
                x-data="workspaceMembers"
                data-url="{{ route('api.v1.workspaces.members.index', ['workspace' => $workspace->ulid], absolute: false) }}"
                data-transfer-url="{{ route('api.v1.workspaces.transfer', ['workspace' => $workspace->ulid], absolute: false) }}"
                data-home-url="{{ route('app', absolute: false) }}"
                data-workspace-name="{{ $workspace->name }}"
                data-is-owner="{{ $isOwner ? '1' : '0' }}"
            >
                <div class="card-header">
                    <h2 class="card-title" tabindex="-1" data-focus="heading">{{ __('Members of :name', ['name' => $workspace->name]) }}</h2>
                </div>

                <div class="list-group list-group-flush">
                    <div class="list-group-item text-secondary" :hidden="!loading" role="status">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        {{ __('Loading…') }}
                    </div>

                    <div class="list-group-item" hidden :hidden="!loadFailed">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="text-danger" role="alert">{{ __('The members could not be loaded.') }}</span>
                            <button type="button" class="btn btn-sm" @click="load">{{ __('Try again') }}</button>
                        </div>
                    </div>

                    <template x-for="member in members" :key="member.id">
                        <div class="list-group-item">
                            <div class="row align-items-center g-2">
                                <div class="col-12 col-md">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <span class="fw-bold text-break" x-text="member.name"></span>
                                        <span class="badge" :class="roleClass(member)" x-text="roleLabel(member)"></span>
                                        <span class="badge bg-secondary-lt" :hidden="!member.is_self">{{ __('You') }}</span>
                                    </div>
                                    <div class="text-secondary text-break" :hidden="!member.email" x-text="member.email"></div>
                                    <div class="text-secondary small" x-text="joinedText(member)"></div>
                                </div>

                                @if ($isOwner)
                                    <div class="col-12 col-md-auto" :hidden="!canManage(member)">
                                        <div :hidden="!isConfirming(member)">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="fw-bold" :id="confirmTextId(member)" x-text="confirmText(member)"></span>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm"
                                                    :class="confirmClass"
                                                    data-member-action="confirm"
                                                    :data-member-id="member.id"
                                                    :aria-describedby="confirmTextId(member)"
                                                    @click="confirm(member)"
                                                    :disabled="busy"
                                                >
                                                    <span class="spinner-border spinner-border-sm me-2" :hidden="!busy" aria-hidden="true"></span>
                                                    <span x-text="confirmLabel"></span>
                                                </button>
                                                <button type="button" class="btn btn-sm" @click="cancelConfirm" :disabled="busy">{{ __('Cancel') }}</button>
                                            </div>
                                        </div>
                                        <div :hidden="isConfirming(member)">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <label class="visually-hidden" :for="roleSelectId(member)" x-text="roleSelectLabel(member)"></label>
                                                <select
                                                    class="form-select form-select-sm w-auto"
                                                    :id="roleSelectId(member)"
                                                    :value="member.role"
                                                    :disabled="busy"
                                                    @change="changeRole(member, $event)"
                                                >
                                                    <option value="editor">{{ __('Editor') }}</option>
                                                    <option value="viewer">{{ __('Viewer') }}</option>
                                                </select>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm"
                                                    data-member-action="transfer"
                                                    :data-member-id="member.id"
                                                    :aria-label="transferLabel(member)"
                                                    @click="ask(member, 'transfer')"
                                                    :disabled="busy"
                                                >
                                                    <x-tabler-crown aria-hidden="true" />
                                                    {{ __('Make owner') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-icon btn-outline-danger"
                                                    data-member-action="remove"
                                                    :data-member-id="member.id"
                                                    :aria-label="removeLabel(member)"
                                                    :title="removeLabel(member)"
                                                    @click="ask(member, 'remove')"
                                                    :disabled="busy"
                                                >
                                                    <x-tabler-user-minus aria-hidden="true" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </template>
                </div>

                @if ($isOwner)
                    <div class="card-footer" hidden :hidden="!hasOthers">
                        <p class="text-secondary mb-0">{{ __('To leave this workspace, hand its ownership over to another member first.') }}</p>
                    </div>
                @else
                    <div class="card-footer">
                        <div :hidden="confirmingLeave">
                            <button type="button" class="btn btn-outline-danger" data-focus="leave" @click="askLeave" :disabled="!canLeave">
                                <x-tabler-door-exit aria-hidden="true" />
                                {{ __('Leave this workspace') }}
                            </button>
                        </div>
                        <div hidden :hidden="!confirmingLeave">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="fw-bold" id="member-leave-text" x-text="leaveConfirmText"></span>
                                <button type="button" class="btn btn-danger" data-focus="confirmLeave" aria-describedby="member-leave-text" @click="leave" :disabled="busy">
                                    <span class="spinner-border spinner-border-sm me-2" hidden :hidden="!busy" aria-hidden="true"></span>
                                    {{ __('Leave') }}
                                </button>
                                <button type="button" class="btn" @click="cancelLeave" :disabled="busy">{{ __('Cancel') }}</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if ($isOwner)
            <div class="col-12 col-lg-4">
                <div
                    class="d-flex flex-column gap-3"
                    x-data="workspaceInvitations"
                    data-url="{{ route('api.v1.workspaces.invitations.index', ['workspace' => $workspace->ulid], absolute: false) }}"
                >
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">{{ __('Invite someone') }}</h2>
                        </div>
                        <form class="card-body" @submit.prevent="invite" novalidate>
                            <p class="text-secondary">{{ __('We email an invitation to this address. The person signs in with it, then accepts or declines.') }}</p>

                            <div class="alert alert-danger" role="alert" hidden :hidden="!formError" x-text="formError"></div>

                            <div class="mb-3">
                                <label class="form-label required" for="member-invite-email">{{ __('Email address') }}</label>
                                <input
                                    id="member-invite-email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': errors.email }"
                                    x-model="form.email"
                                    data-focus="email"
                                    maxlength="255"
                                    inputmode="email"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    aria-describedby="member-invite-email-error"
                                    :aria-invalid="isInvalid('email')"
                                    required
                                >
                                <div class="invalid-feedback" id="member-invite-email-error" x-text="errors.email"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label required" for="member-invite-role">{{ __('Role') }}</label>
                                <select
                                    id="member-invite-role"
                                    class="form-select"
                                    :class="{ 'is-invalid': errors.role }"
                                    x-model="form.role"
                                    aria-describedby="member-invite-role-hint member-invite-role-error"
                                    :aria-invalid="isInvalid('role')"
                                >
                                    <option value="editor">{{ __('Editor') }}</option>
                                    <option value="viewer">{{ __('Viewer') }}</option>
                                </select>
                                <div class="form-hint" id="member-invite-role-hint">{{ __('An editor can change the inventories and their items. A viewer can only read them.') }}</div>
                                <div class="invalid-feedback" id="member-invite-role-error" x-text="errors.role"></div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100" :disabled="!canInvite">
                                <span class="spinner-border spinner-border-sm me-2" hidden :hidden="!busy" aria-hidden="true"></span>
                                <x-tabler-send aria-hidden="true" />
                                {{ __('Send the invitation') }}
                            </button>
                        </form>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title" tabindex="-1" data-focus="heading">{{ __('Invitations waiting for an answer') }}</h2>
                        </div>

                        <div class="list-group list-group-flush">
                            <div class="list-group-item text-secondary" :hidden="!loading" role="status">
                                <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                {{ __('Loading…') }}
                            </div>

                            <div class="list-group-item text-secondary" hidden :hidden="!empty">{{ __('No invitation is waiting for an answer.') }}</div>

                            <div class="list-group-item" hidden :hidden="!loadFailed">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="text-danger" role="alert">{{ __('The invitations could not be loaded.') }}</span>
                                    <button type="button" class="btn btn-sm" @click="load">{{ __('Try again') }}</button>
                                </div>
                            </div>

                            <template x-for="invitation in invitations" :key="invitation.id">
                                <div class="list-group-item">
                                    <div class="fw-bold text-break" x-text="invitation.email"></div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="badge" :class="roleClass(invitation)" x-text="roleLabel(invitation)"></span>
                                        <span class="text-secondary small" x-text="metaText(invitation)"></span>
                                    </div>

                                    <div :hidden="!isConfirming(invitation)">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="fw-bold" :id="confirmTextId(invitation)" x-text="confirmText(invitation)"></span>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-danger"
                                                data-invitation-action="confirm"
                                                :data-invitation-id="invitation.id"
                                                :aria-describedby="confirmTextId(invitation)"
                                                @click="withdraw(invitation)"
                                                :disabled="anyWorking"
                                            >
                                                <span class="spinner-border spinner-border-sm me-2" :hidden="!isWorking(invitation)" aria-hidden="true"></span>
                                                {{ __('Withdraw') }}
                                            </button>
                                            <button type="button" class="btn btn-sm" @click="cancelWithdraw" :disabled="anyWorking">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>
                                    <div :hidden="isConfirming(invitation)">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm"
                                                :aria-label="resendLabel(invitation)"
                                                @click="resend(invitation)"
                                                :disabled="anyWorking"
                                            >
                                                <span class="spinner-border spinner-border-sm me-2" :hidden="!isWorking(invitation)" aria-hidden="true"></span>
                                                <x-tabler-refresh aria-hidden="true" />
                                                {{ __('Send again') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-invitation-action="withdraw"
                                                :data-invitation-id="invitation.id"
                                                :aria-label="withdrawLabel(invitation)"
                                                @click="askWithdraw(invitation)"
                                                :disabled="anyWorking"
                                            >{{ __('Withdraw') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
