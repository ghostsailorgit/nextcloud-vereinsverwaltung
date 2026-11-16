<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubGroupMemberMapper;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\PermissionDeniedException;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\RBAC\RoleService;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Which members the current user reaches with a member permission (issue #25): a role assigned for some groups only
 * (a department head) covers the people who are in one of those groups now - a stay without an end or ending today or
 * later. Every place that hands out or changes members under verein.member.view / verein.member.manage goes through
 * here; the middleware alone only knows that the user holds the permission somewhere in the club.
 *
 * Things that concern the whole club (defining groups, importing, linking accounts, taking over a person from another
 * club) need the permission for the whole club: requireWholeClub().
 */
class MemberScope {
    /** @var array<string, int[]|null> */
    private array $cache = [];
    private IL10N $l;

    public function __construct(
        private RoleService $roles,
        private ClubGroupMemberMapper $periods,
        private IUserSession $userSession,
        private ?Clock $clock = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /** @return int[]|null the groups the user is limited to, null = the whole club */
    public function groups(int $clubId, string $permission = 'verein.member.view'): ?array {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        return $this->roles->memberGroupScope($uid, $permission, $clubId);
    }

    /** @return int[]|null ids of the members the user reaches, null = all of the club */
    public function memberIds(int $clubId, string $permission = 'verein.member.view'): ?array {
        $key = $clubId . '|' . $permission;
        if (!array_key_exists($key, $this->cache)) {
            $groups = $this->groups($clubId, $permission);
            $this->cache[$key] = $groups === null ? null : $this->membersOfGroups($clubId, $groups);
        }
        return $this->cache[$key];
    }

    public function covers(int $clubId, int $memberId, string $permission = 'verein.member.view'): bool {
        $ids = $this->memberIds($clubId, $permission);
        return $ids === null || in_array($memberId, $ids, true);
    }

    /**
     * @template T of object
     * @param T[] $members entities with getId()
     * @return T[] those the user reaches, in the same order
     */
    public function filter(int $clubId, array $members, string $permission = 'verein.member.view'): array {
        $ids = $this->memberIds($clubId, $permission);
        if ($ids === null) {
            return $members;
        }
        $wanted = array_flip($ids);
        return array_values(array_filter($members, fn ($m) => isset($wanted[(int)$m->getId()])));
    }

    /**
     * A member outside the user's groups is "not found", the same answer as for a person of another club.
     *
     * @throws NotFoundException
     */
    public function check(int $clubId, int $memberId, string $permission = 'verein.member.view'): void {
        if (!$this->covers($clubId, $memberId, $permission)) {
            throw new NotFoundException($this->l->t('Member not found'));
        }
    }

    /** @throws PermissionDeniedException */
    public function requireWholeClub(int $clubId, string $permission = 'verein.member.manage'): void {
        if ($this->groups($clubId, $permission) !== null) {
            throw new PermissionDeniedException($this->l->t('Only possible with this permission for the whole club, not only for some groups'));
        }
    }

    /** @return int[] */
    private function membersOfGroups(int $clubId, array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }
        $today = $this->clock?->today() ?? date('Y-m-d');
        $wanted = array_flip($groupIds);
        $ids = [];
        foreach ($this->periods->findByClub($clubId) as $period) {
            if (!isset($wanted[$period->getGroupId()])) {
                continue;
            }
            $left = $period->getLeftOn();
            if ($left === null || $left === '' || $left >= $today) {
                $ids[$period->getMemberId()] = $period->getMemberId();
            }
        }
        return array_values($ids);
    }
}
