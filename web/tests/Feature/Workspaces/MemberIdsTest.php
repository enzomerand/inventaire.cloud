<?php

declare(strict_types=1);

use App\Workspaces\MemberIds;
use App\Workspaces\WorkspaceRole;
use App\Workspaces\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The identifier the API gives a member of a workspace in place of the number of their account: a keyed hash, so that nothing is
 * stored, the number is not revealed and an identifier means something in one workspace only.
 */

describe('the identifier of a member', function (): void {
    it('is a lowercase string shaped like a ULID, the same every time for the same member and workspace', function (): void {
        $team = inventoryTeam();
        $ids = new MemberIds;

        $id = $ids->of($team['workspace'], $team['editor']);

        expect($id)->toMatch('/^[0-9a-z]{26}$/')
            ->and($ids->of($team['workspace'], $team['editor']))->toBe($id)
            ->and($ids->of($team['workspace'], $team['editor']->id))->toBe($id)
            ->and((new MemberIds)->of($team['workspace']->fresh() ?? $team['workspace'], $team['editor']->fresh() ?? $team['editor']))->toBe($id);
    });

    it('differs from one member to the next, and from one workspace to the next for the same member', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['owner'], 'Other');
        $other->members()->attach($team['editor']->id, ['role' => WorkspaceRole::Viewer->value]);
        $ids = new MemberIds;

        $all = [
            $ids->of($team['workspace'], $team['owner']), $ids->of($team['workspace'], $team['editor']), $ids->of($team['workspace'], $team['viewer']),
            $ids->of($other, $team['owner']), $ids->of($other, $team['editor']),
        ];

        expect(array_unique($all))->toHaveCount(5);
    });

    it('does not hold the number of the account, and depends on the key of the application', function (): void {
        $team = inventoryTeam();
        $ids = new MemberIds;
        $before = $ids->of($team['workspace'], $team['editor']);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        expect($ids->of($team['workspace'], $team['editor']))->not->toBe($before)
            ->and($before)->not->toContain((string) $team['editor']->id);
    });

    it('is found among the members of the workspace, whatever the case it is written in', function (): void {
        $team = inventoryTeam();
        $ids = new MemberIds;
        $id = $ids->of($team['workspace'], $team['viewer']);

        expect($ids->find($team['workspace'], $id)?->is($team['viewer']))->toBeTrue()
            ->and($ids->find($team['workspace'], strtoupper($id))?->is($team['viewer']))->toBeTrue();
    });

    it('is found nowhere else: not in another workspace, not for somebody who is no member, not once the member left', function (): void {
        $team = inventoryTeam();
        $other = app(Workspaces::class)->create($team['stranger'], 'Other');
        $ids = new MemberIds;

        expect($ids->find($other, $ids->of($team['workspace'], $team['owner'])))->toBeNull()
            ->and($ids->find($team['workspace'], $ids->of($other, $team['stranger'])))->toBeNull()
            ->and($ids->find($team['workspace'], $ids->of($team['workspace'], $team['stranger'])))->toBeNull();

        $team['workspace']->members()->detach($team['viewer']->id);

        expect($ids->find($team['workspace'], $ids->of($team['workspace'], $team['viewer'])))->toBeNull();
    });

    it('is not found for what is not an identifier', function (string $text): void {
        $team = inventoryTeam();

        expect((new MemberIds)->find($team['workspace'], $text))->toBeNull();
    })->with(['', 'abc', '01j0000000000000000000000x', str_repeat('a', 27), str_repeat('é', 26), '../../etc/passwd', str_repeat('a', 5000)]);
});
