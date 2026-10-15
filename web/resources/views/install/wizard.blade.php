{{--
    The install wizard. The server describes every step, field, value and label
    as JSON (see App\Installer\WizardState) and the Alpine "installer" component
    (resources/js/components/installer.js) renders it: one generic form for the
    configuration steps, plus the requirements list, the mail code box and the
    final summary.

    Alpine runs in its CSP build: expressions stay simple (property access, method
    calls with arguments, comparisons) and never contain Blade values.
--}}
<script type="application/json" id="installer-state">@json($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)</script>

<div class="card" x-data="installer" data-base-url="{{ route('install.show', absolute: false) }}">
    <div class="card-body">
        <ol class="steps steps-green steps-counter my-4" aria-label="{{ __('Installation steps') }}">
            <template x-for="step in steps" :key="step.id">
                <li class="step-item" :class="stepClass(step)" :aria-current="ariaCurrent(step)" x-text="step.title"></li>
            </template>
        </ol>

        <template x-for="step in steps" :key="step.id">
            <section x-show="isCurrent(step)">
                <h2 class="card-title" x-text="step.title"></h2>
                <p class="text-secondary" x-text="step.intro"></p>

                <div class="alert alert-info" role="status" x-show="step.notice" x-text="step.notice"></div>
                <div class="alert alert-danger" role="alert" x-show="step.error" x-text="step.error"></div>

                {{-- Requirements --}}
                <template x-if="step.id === 'requirements'">
                    <div>
                        <ul class="list-group mb-3">
                            <template x-for="item in requirements.items" :key="item.id">
                                <li class="list-group-item d-flex align-items-start gap-3">
                                    <span class="badge mt-1" :class="requirementClass(item)" x-text="requirementLabel(item)"></span>
                                    <div>
                                        <div x-text="item.label"></div>
                                        <div class="text-secondary small" x-show="item.detail" x-text="item.detail"></div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn" @click="recheck" :disabled="busy">{{ __('Check again') }}</button>
                            <button type="button" class="btn btn-primary ms-auto" @click="continueFromRequirements(step)" :disabled="busy || !requirements.passed">
                                {{ __('Continue') }}
                            </button>
                        </div>
                    </div>
                </template>

                {{-- Configuration steps: one generic form built from the field descriptions --}}
                <form x-show="showFields(step)" @submit.prevent="submit(step)" novalidate>
                    <template x-for="field in step.fields" :key="field.name">
                        <div class="mb-3" x-show="isVisible(step, field)">
                            <template x-if="field.type !== 'checkbox'">
                                <label class="form-label" :for="fieldId(step, field)">
                                    <span x-text="field.label"></span>
                                    <span class="badge bg-blue-lt ms-2" x-show="field.locked">{{ __('Set by the environment') }}</span>
                                </label>
                            </template>

                            <template x-if="field.type === 'select'">
                                <select class="form-select" :class="fieldClass(field)" :id="fieldId(step, field)" x-model="field.value" :disabled="field.locked">
                                    <template x-for="option in field.options" :key="option.value">
                                        <option :value="option.value" :selected="option.value === field.value" x-text="option.label"></option>
                                    </template>
                                </select>
                            </template>

                            <template x-if="field.type === 'checkbox'">
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input" :class="fieldClass(field)" :id="fieldId(step, field)" x-model="field.value" :disabled="field.locked">
                                    <span class="form-check-label">
                                        <span x-text="field.label"></span>
                                        <span class="badge bg-blue-lt ms-2" x-show="field.locked">{{ __('Set by the environment') }}</span>
                                    </span>
                                </label>
                            </template>

                            <template x-if="field.type !== 'select' && field.type !== 'checkbox'">
                                <input
                                    class="form-control"
                                    :class="fieldClass(field)"
                                    :id="fieldId(step, field)"
                                    :type="field.type"
                                    :placeholder="placeholder(field)"
                                    x-model="field.value"
                                    :disabled="field.locked"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                >
                            </template>

                            <div class="invalid-feedback" x-text="field.error"></div>
                            <div class="form-hint" x-show="field.help" x-text="field.help"></div>
                        </div>
                    </template>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="button" class="btn" x-show="hasPrevious(step)" @click="back" :disabled="busy">{{ __('Back') }}</button>
                        <button type="button" class="btn btn-link" x-show="step.optional" @click="skip(step)" :disabled="busy">{{ __('Skip this step') }}</button>
                        <button type="submit" class="btn btn-primary ms-auto" :disabled="busy">
                            <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                            <span x-text="step.submit"></span>
                        </button>
                    </div>
                </form>

                {{-- Mail step, second phase: the code that was just emailed --}}
                <template x-if="step.id === 'mail'">
                    <form x-show="showCode(step)" @submit.prevent="verifyCode" novalidate>
                        <p>{{ __('Enter the 6-digit code sent to the test address. If it does not arrive, check your spam folder or go back to correct the settings.') }}</p>
                        <div class="mb-3">
                            <label class="form-label" for="install-mail-code">{{ __('Verification code') }}</label>
                            <input
                                id="install-mail-code"
                                type="text"
                                class="form-control"
                                :class="codeInvalidClass"
                                x-model="code"
                                inputmode="numeric"
                                maxlength="6"
                                autocomplete="one-time-code"
                            >
                            <div class="invalid-feedback" x-text="codeError"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn" @click="editMail" :disabled="busy">{{ __('Change the settings or send a new code') }}</button>
                            <button type="submit" class="btn btn-primary ms-auto" :disabled="busy">
                                <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                                {{ __('Verify the code') }}
                            </button>
                        </div>
                    </form>
                </template>

                {{-- Last screen: summary and the install button --}}
                <template x-if="step.id === 'finalize'">
                    <div>
                        <ul class="list-group mb-3">
                            <template x-for="item in steps" :key="item.id">
                                <li class="list-group-item" x-show="isSummaryItem(item)">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span x-text="item.title"></span>
                                        <span class="badge" :class="statusClass(item)" x-text="statusLabel(item)"></span>
                                    </div>
                                </li>
                            </template>
                        </ul>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn" @click="back" :disabled="busy">{{ __('Back') }}</button>
                            <button type="button" class="btn btn-primary ms-auto" @click="finalize(step)" :disabled="busy || !canFinalize">
                                <span class="spinner-border spinner-border-sm me-2" x-show="busy" aria-hidden="true"></span>
                                <span x-text="step.submit"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </template>
    </div>
</div>
