<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Admin\Exceptions\UserAdministrationRefused;
use App\Admin\UserAdministration;
use App\Auth\Exceptions\AccountDeletionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Responses\ApiError;
use App\Mail\AfterResponse;
use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/**
 * The accounts of the instance, for its administrators: list and search them, create one, make one an administrator
 * (or not any more) and delete one. The rules are those of {@see UserAdministration}; the routes ask for the
 * "admin" gate and for the web front (a session), so none of this is reachable otherwise.
 *
 * Accounts are identified by their numeric id here: it is shown to administrators only.
 */
final class UserController extends Controller
{
    public function __construct(private readonly UserAdministration $users) {}

    /**
     * A page of 25 accounts, newest first, each with `workspaces_count`; `q` keeps those whose name or address
     * contains it (case ignored), `page` picks the page (the answer carries `meta` and `links`).
     */
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return AdminUserResource::collection($this->users->search($request->search()));
    }

    /**
     * Creates the account of an address (`201`), with its personal workspace, and emails the person (after the answer)
     * that it exists and how to sign in. An address that already has an account is a `409`, `account_exists`.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        try {
            $user = $this->users->create($this->user($request), $request->email(), $request->name());
        } catch (UserAdministrationRefused $refusal) {
            return $this->refusal($refusal);
        }

        $this->welcome($user);

        return $this->respond($user)->setStatusCode(201);
    }

    /**
     * Makes the account an administrator, or not any more (`is_admin`). The last administrator cannot be demoted
     * (`409`, `last_administrator`); an administrator who was demoted while the request was handled gets a `403`,
     * `not_administrator`.
     *
     * @param  string  $user  The id of the account, which the route has limited to digits.
     */
    public function update(UpdateUserRequest $request, string $user): JsonResponse
    {
        $account = $this->find($user);

        if ($account === null) {
            return $this->gone();
        }

        try {
            $account = $this->users->setAdministrator($this->user($request), $account, $request->administrator());
        } catch (UserAdministrationRefused $refusal) {
            return $this->refusal($refusal);
        } catch (ModelNotFoundException $exception) {
            return $this->goneIfAccount($exception);
        }

        return $this->respond($account);
    }

    /**
     * Deletes the account (`204`) the way its owner would: the workspaces nobody else belongs to go with it, and a
     * workspace it alone owned and that others belong to is handed over first, to its longest-standing editor (or to
     * its longest-standing member when it has no editor). Not the administrator's own account (`409`,
     * `self_deletion`: they do that from their account page), and not the last administrator (`409`,
     * `last_administrator`). An administrator who was demoted while the request was handled gets a `403`,
     * `not_administrator`, as for the other changes.
     *
     * @param  string  $user  The id of the account, which the route has limited to digits.
     */
    public function destroy(Request $request, string $user): JsonResponse|Response
    {
        $account = $this->find($user);

        if ($account === null) {
            return $this->gone();
        }

        try {
            $this->users->delete($this->user($request), $account);
        } catch (UserAdministrationRefused $refusal) {
            return $this->refusal($refusal);
        } catch (AccountDeletionRefused $refusal) {
            return $this->deletionRefusal($refusal);
        } catch (ModelNotFoundException $exception) {
            return $this->goneIfAccount($exception);
        }

        return response()->noContent();
    }

    /**
     * The account with that id, or null. Looked up here and not by the router (implicit binding): the router binds
     * before the "admin" gate runs, so somebody who is not an administrator would be told "403" for an id that exists
     * and "404" for one that does not, and could count the accounts of the instance.
     */
    private function find(string $id): ?User
    {
        return User::query()->whereKey((int) $id)->first();
    }

    /**
     * Tells the person, after the answer, that their account exists: in the default language of the instance, with
     * the address of the sign-in page (the one in APP_URL, never one built from the request).
     */
    private function welcome(User $user): void
    {
        $locale = $this->users->defaultLocale();
        $loginUrl = rtrim(config()->string('app.url'), '/').route('login', absolute: false);
        $mail = new WelcomeMail($user->name, $loginUrl);

        AfterResponse::run('a welcome email', function () use ($user, $locale, $mail): void {
            Mail::to($user->email)->locale($locale)->send($mail);
        });
    }

    /**
     * The answer that describes one account.
     */
    private function respond(User $user): JsonResponse
    {
        return (new AdminUserResource($user->loadCount('workspaces')))->response();
    }

    /**
     * The 409 for a request the rules refuse, or the 403 for an administrator who is not one any more.
     */
    private function refusal(UserAdministrationRefused $refusal): JsonResponse
    {
        // An administrator demoted while the request was on its way: the gate let them in, the locks say otherwise.
        if ($refusal->reason === UserAdministrationRefused::NOT_ADMINISTRATOR) {
            return ApiError::forbidden(__('This action is unauthorized.'), $refusal->reason);
        }

        $message = match ($refusal->reason) {
            UserAdministrationRefused::ACCOUNT_EXISTS => __('An account already exists for this address.'),
            UserAdministrationRefused::LAST_ADMINISTRATOR => __('This is the last administrator of the instance: make somebody else an administrator first.'),
            default => __('You cannot delete your own account here: use your account page.'),
        };

        return ApiError::conflict($message, $refusal->reason);
    }

    /**
     * The 409 for an account the deletion rules refuse to delete.
     */
    private function deletionRefusal(AccountDeletionRefused $refusal): JsonResponse
    {
        if ($refusal->reason === AccountDeletionRefused::LAST_ADMINISTRATOR) {
            return ApiError::conflict(__('This is the last administrator of the instance: make somebody else an administrator first.'), $refusal->reason);
        }

        return ApiError::conflict(__('This account cannot be deleted right now: try again.'), $refusal->reason);
    }

    /**
     * The 404 for an account that is not there (any more).
     */
    private function gone(): JsonResponse
    {
        return ApiError::notFound(__('This account no longer exists.'), 'account_not_found');
    }

    /**
     * The 404 for an account that somebody deleted in the meantime. Any other model not found is a failure.
     *
     * @param  ModelNotFoundException<Model>  $exception
     */
    private function goneIfAccount(ModelNotFoundException $exception): JsonResponse
    {
        if ($exception->getModel() !== User::class) {
            throw $exception;
        }

        return $this->gone();
    }
}
