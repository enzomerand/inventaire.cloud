<?php

declare(strict_types=1);

namespace App\Workspaces;

use Carbon\CarbonInterface;

/**
 * One line of the list of the members of a workspace, as the person who asks may see it.
 *
 * The address is private: it is shown to the owner and, on its own line, to the member it belongs to (`null` otherwise), so that
 * somebody who belongs to a shared workspace cannot collect the addresses of its other members.
 */
final readonly class MemberEntry
{
    /**
     * @param  int  $id  The account's id.
     * @param  string  $name  The name of the member.
     * @param  string|null  $email  The address, or null when the person who asks is not allowed to see it.
     * @param  WorkspaceRole  $role  What the member may do in the workspace.
     * @param  CarbonInterface|null  $joinedAt  When they joined.
     * @param  bool  $self  Whether this line is the person who asks.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public WorkspaceRole $role,
        public ?CarbonInterface $joinedAt,
        public bool $self,
    ) {}
}
