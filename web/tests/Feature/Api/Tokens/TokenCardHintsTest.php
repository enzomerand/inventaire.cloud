<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../../Support/inventories.php';

uses(RefreshDatabase::class);

/*
 * The card of the API tokens of the account page tells what the permissions are not: they stand alone (a token that cannot read can hardly
 * be used, the identifiers are found by reading), what a token writes is sent back to it, and "manage" can delete a workspace or an
 * inventory with all its data.
 */

describe('the card of the API tokens', function (): void {
    it('warns, in English, about the permissions', function (): void {
        $this->actingAs(inventoryPerson('Ada'))
            ->get('/app/account', frontend(['Accept-Language' => 'en']))
            ->assertOk()
            ->assertSee('Each permission stands alone: without Read a token can hardly be used, since the identifiers are found by reading. What a token writes is sent back to it.')
            ->assertSee('including deleting a workspace or an inventory with all its data.');
    });

    it('warns, in French, about the permissions', function (): void {
        $this->actingAs(inventoryPerson('Ada'))
            ->get('/app/account', frontend(['Accept-Language' => 'fr']))
            ->assertOk()
            ->assertSee("Chaque permission est indépendante : sans Lecture, un jeton ne sert presque à rien, car les identifiants se trouvent en lisant. Ce qu'un jeton écrit lui est renvoyé.")
            ->assertSee('y compris supprimer un espace de travail ou un inventaire avec toutes ses données.');
    });
});
