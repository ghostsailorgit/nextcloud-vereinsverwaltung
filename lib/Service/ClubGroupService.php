<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Verein\Service;

use OCA\Verein\Db\ClubGroup;
use OCA\Verein\Db\ClubGroupMapper;
use OCA\Verein\Db\ClubGroupMember;
use OCA\Verein\Db\ClubGroupMemberMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Exception\NotFoundException;
use OCA\Verein\Exception\ValidationException;
use OCA\Verein\L10n\SourceL10n;
use OCP\IL10N;

/**
 * Groups inside a club, e.g. training groups: a member can be in none, one or several, and each stay is one period with
 * a start and an end, so joining again leaves the earlier period on record.
 *
 * "Current" means an open period of somebody who is still a member of the club. A member who leaves the club keeps the open
 * period (nothing rewrites the history behind the user's back), it is just not counted or listed as current any more.
 */
class ClubGroupService {
    private const MAX_NAME = 100;
    private const MAX_DESCRIPTION = 255;

    private IL10N $l;

    public function __construct(
        private ClubGroupMapper $groups,
        private ClubGroupMemberMapper $periods,
        private MemberMapper $members,
        private ClubMapper $clubs,
        private ?AuditLogService $auditLog = null,
        ?IL10N $l10n = null
    ) {
        $this->l = $l10n ?? new SourceL10n();
    }

    /** @return array<int, array<string, mixed>> the groups with how many members they have now */
    public function list(int $clubId): array {
        $current = [];
        foreach ($this->currentPeriods($clubId) as $period) {
            $current[$period->getGroupId()] = ($current[$period->getGroupId()] ?? 0) + 1;
        }
        return array_map(
            fn (ClubGroup $g) => $g->jsonSerialize() + ['currentMembers' => $current[$g->getId()] ?? 0],
            $this->groups->findByClub($clubId)
        );
    }

    /** @return array<int, string[]> member id => names of the groups they are in now, sorted */
    public function currentGroupNames(int $clubId): array {
        $names = [];
        foreach ($this->groups->findByClub($clubId) as $group) {
            $names[$group->getId()] = $group->getName();
        }
        $byMember = [];
        foreach ($this->currentPeriods($clubId) as $period) {
            if (isset($names[$period->getGroupId()])) {
                $byMember[$period->getMemberId()][] = $names[$period->getGroupId()];
            }
        }
        foreach ($byMember as &$list) {
            sort($list);
        }
        return $byMember;
    }

    /** @throws ValidationException */
    public function create(int $clubId, array $data): ClubGroup {
        $this->clubs->find($clubId);
        $group = new ClubGroup();
        $group->setClubId($clubId);
        $this->apply($group, $data, null);
        $group->setCreatedAt(date('Y-m-d H:i:s'));
        $group = $this->groups->insert($group);
        $this->auditLog?->record($clubId, 'club_group', $group->getId(), 'create', $group->jsonSerialize());
        return $group;
    }

    /**
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function update(int $clubId, int $id, array $data): ClubGroup {
        $group = $this->ownGroup($clubId, $id);
        $before = $group->jsonSerialize();
        $this->apply($group, $data, $id);
        $group = $this->groups->update($group);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $group->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'club_group', $id, 'update', $changes);
            }
        }
        return $group;
    }

    /**
     * Removes the group and the periods in it.
     *
     * @throws NotFoundException
     */
    public function delete(int $clubId, int $id): void {
        $group = $this->ownGroup($clubId, $id);
        $this->periods->deleteByGroup($id);
        $this->groups->delete($group);
        $this->auditLog?->record($clubId, 'club_group', $id, 'delete');
    }

    /**
     * Every period in the group, with the member's name and whether they have left the club.
     *
     * @return array<int, array<string, mixed>> by name, the latest period first
     * @throws NotFoundException
     */
    public function periodsOfGroup(int $clubId, int $groupId): array {
        $this->ownGroup($clubId, $groupId);
        $members = $this->membersById($clubId);
        $rows = [];
        foreach ($this->periods->findByGroup($groupId) as $period) {
            $member = $members[$period->getMemberId()] ?? null;
            if ($member !== null) {
                $rows[] = $period->jsonSerialize() + ['memberName' => $member->getFullName(), 'memberFormer' => $member->isFormer()];
            }
        }
        usort($rows, fn (array $a, array $b) => [mb_strtolower($a['memberName']), $b['joinedOn']] <=> [mb_strtolower($b['memberName']), $a['joinedOn']]);
        return $rows;
    }

    /**
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function addMember(int $clubId, int $groupId, int $memberId, mixed $joinedOn, mixed $leftOn = null): ClubGroupMember {
        $this->ownGroup($clubId, $groupId);
        if (!isset($this->membersById($clubId)[$memberId])) {
            throw new NotFoundException($this->l->t('Member not found'));
        }
        [$from, $to] = $this->period($joinedOn, $leftOn);
        $this->refuseOverlap($groupId, $memberId, $from, $to, null);

        $period = new ClubGroupMember();
        $period->setClubId($clubId);
        $period->setGroupId($groupId);
        $period->setMemberId($memberId);
        $period->setJoinedOn($from);
        $period->setLeftOn($to);
        $period = $this->periods->insert($period);
        $this->auditLog?->record($clubId, 'club_group_member', $period->getId(), 'create', $period->jsonSerialize());
        return $period;
    }

    /**
     * Corrects a period or ends it (with a date left).
     *
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function updatePeriod(int $clubId, int $id, mixed $joinedOn, mixed $leftOn): ClubGroupMember {
        $period = $this->ownPeriod($clubId, $id);
        $before = $period->jsonSerialize();
        [$from, $to] = $this->period($joinedOn, $leftOn);
        $this->refuseOverlap($period->getGroupId(), $period->getMemberId(), $from, $to, $id);
        $period->setJoinedOn($from);
        $period->setLeftOn($to);
        $period = $this->periods->update($period);
        if ($this->auditLog !== null) {
            $changes = $this->auditLog->diff($before, $period->jsonSerialize());
            if ($changes !== []) {
                $this->auditLog->record($clubId, 'club_group_member', $id, 'update', $changes);
            }
        }
        return $period;
    }

    /** @throws NotFoundException */
    public function deletePeriod(int $clubId, int $id): void {
        $period = $this->ownPeriod($clubId, $id);
        $this->periods->delete($period);
        $this->auditLog?->record($clubId, 'club_group_member', $id, 'delete');
    }

    /** @return ClubGroupMember[] open periods of people who are still members */
    private function currentPeriods(int $clubId): array {
        $members = $this->membersById($clubId);
        return array_values(array_filter(
            $this->periods->findByClub($clubId),
            fn (ClubGroupMember $p) => $p->getLeftOn() === null && isset($members[$p->getMemberId()]) && !$members[$p->getMemberId()]->isFormer()
        ));
    }

    /** @return array<int, Member> */
    private function membersById(int $clubId): array {
        $byId = [];
        foreach ($this->members->findByClub($clubId) as $member) {
            $byId[$member->getId()] = $member;
        }
        return $byId;
    }

    /** @throws NotFoundException if the group is not one of this club's */
    private function ownGroup(int $clubId, int $id): ClubGroup {
        try {
            $group = $this->groups->find($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Group not found'));
        }
        if ($group->getClubId() !== $clubId) {
            throw new NotFoundException($this->l->t('Group not found'));
        }
        return $group;
    }

    /** @throws NotFoundException if the period is not one of this club's */
    private function ownPeriod(int $clubId, int $id): ClubGroupMember {
        try {
            $period = $this->periods->find($id);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            throw new NotFoundException($this->l->t('Group membership not found'));
        }
        if ($period->getClubId() !== $clubId) {
            throw new NotFoundException($this->l->t('Group membership not found'));
        }
        return $period;
    }

    /** @throws ValidationException */
    private function apply(ClubGroup $group, array $data, ?int $existingId): void {
        $name = trim(is_scalar($data['name'] ?? null) ? (string)$data['name'] : '');
        if ($name === '') {
            throw new ValidationException($this->l->t('The name of the group is required'));
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw new ValidationException($this->l->t('The name of the group is too long'));
        }
        $description = trim(is_scalar($data['description'] ?? null) ? (string)$data['description'] : '');
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new ValidationException($this->l->t('The description of the group is too long'));
        }
        foreach ($this->groups->findByClub($group->getClubId()) as $other) {
            if ($other->getId() !== $existingId && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new ValidationException($this->l->t('This group already exists'));
            }
        }
        $group->setName($name);
        $group->setDescription($description === '' ? null : $description);
    }

    /**
     * @return array{0: string, 1: ?string}
     * @throws ValidationException
     */
    private function period(mixed $joinedOn, mixed $leftOn): array {
        $from = $this->date($joinedOn);
        if ($from === null) {
            throw new ValidationException($this->l->t('The date joined is missing or invalid'));
        }
        $to = null;
        if ($leftOn !== null && $leftOn !== '') {
            $to = $this->date($leftOn);
            if ($to === null) {
                throw new ValidationException($this->l->t('The date left is invalid'));
            }
            if ($to < $from) {
                throw new ValidationException($this->l->t('The date left must not be before the date joined'));
            }
        }
        return [$from, $to];
    }

    private function date(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Leaving and joining again on the same day is fine, two periods that really run side by side are not.
     *
     * @throws ValidationException
     */
    private function refuseOverlap(int $groupId, int $memberId, string $from, ?string $to, ?int $exceptId): void {
        foreach ($this->periods->findByMember($memberId) as $other) {
            if ($other->getGroupId() !== $groupId || $other->getId() === $exceptId) {
                continue;
            }
            $startsBeforeOtherEnds = $other->getLeftOn() === null || $from < $other->getLeftOn();
            $otherStartsBeforeEnd = $to === null || $other->getJoinedOn() < $to;
            if ($startsBeforeOtherEnds && $otherStartsBeforeEnd) {
                throw new ValidationException($this->l->t('This period overlaps with another period of this member in the group'));
            }
        }
    }
}
