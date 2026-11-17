<?php
/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Verein\Tests\Unit;

use OCA\Verein\Controller\ClubGroupController;
use OCA\Verein\Controller\MemberController;
use OCA\Verein\Controller\RoleController;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\L10n\SourceL10n;
use OCA\Verein\Service\ClubGroupService;
use OCA\Verein\Service\MemberScope;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\SelfServiceService;
use OCA\Verein\Service\ValidationService;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Smaller access fixes of the security audit 11/2026 (N4, N5, N7). */
class AccessFixesControllerTest extends TestCase {
    private array $params = [];

    private function request(): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        return $request;
    }

    private function session(): IUserSession {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('head');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function groupController(?array $scopedGroups, ?string $endedOn): array {
        $groups = $this->createMock(ClubGroupService::class);
        $groups->method('groupOfPeriod')->willReturn(5);
        $groups->method('leftOnOfPeriod')->willReturn($endedOn);
        $scope = $this->createMock(MemberScope::class);
        $scope->method('groups')->willReturn($scopedGroups);
        $controller = new ClubGroupController('verein', $this->request(), $groups, $this->createMock(RoleService::class), $this->session(), $scope, SourceL10n::fromAppLanguage('de'));
        return [$controller, $groups];
    }

    public function testN7ADepartmentHeadCannotReopenAnEndedStay(): void {
        $this->params = ['clubId' => '3', 'joinedOn' => '2024-01-01', 'leftOn' => ''];
        [$controller, $groups] = $this->groupController([5], '2025-06-30');
        $groups->expects($this->never())->method('updatePeriod');

        $this->assertSame(403, $controller->updateMember(8)->getStatus());

        $this->params['leftOn'] = '2025-12-31';
        $this->assertSame(403, $controller->updateMember(8)->getStatus(), 'extending is reopening too');
    }

    public function testN7ADepartmentHeadMayStillEndOrShortenAStay(): void {
        $this->params = ['clubId' => '3', 'joinedOn' => '2024-01-01', 'leftOn' => '2025-03-31'];
        [$controller, $groups] = $this->groupController([5], '2025-06-30');
        $groups->expects($this->once())->method('updatePeriod');

        $this->assertSame(200, $controller->updateMember(8)->getStatus());
    }

    public function testN7TheWholeClubMayReopen(): void {
        $this->params = ['clubId' => '3', 'joinedOn' => '2024-01-01', 'leftOn' => ''];
        [$controller, $groups] = $this->groupController(null, '2025-06-30');
        $groups->expects($this->once())->method('updatePeriod');

        $this->assertSame(200, $controller->updateMember(8)->getStatus());
    }

    public function testN4AnAccountLinkedInAnotherClubIsOnlyShownAsTaken(): void {
        $this->params = ['clubId' => '3', 'query' => 'an'];
        $members = $this->createMock(MemberService::class);
        $users = $this->createMock(IUserManager::class);
        $account = fn (string $uid) => (function () use ($uid) {
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            $u->method('getDisplayName')->willReturn(ucfirst($uid));
            return $u;
        })();
        $users->method('searchDisplayName')->willReturn([$account('anna'), $account('andreas')]);
        $here = new Member();
        $here->setId(1);
        $here->setName('Hier');
        $elsewhere = new Member();
        $elsewhere->setId(2);
        $elsewhere->setName('Woanders');
        $members->method('findByLinkedUser')->willReturnCallback(fn (string $uid) => $uid === 'anna' ? $here : $elsewhere);
        $members->method('clubIdsOf')->willReturnCallback(fn (int $id) => $id === 1 ? [3] : [4]);
        $roles = $this->createMock(RoleService::class);
        $roles->method('userHasPermission')->willReturn(true);
        $controller = new MemberController('verein', $this->request(), $members, new ValidationService(), $roles,
            $this->createMock(ClubMapper::class), $this->session(), $users, $this->createMock(SelfServiceService::class),
            l10n: SourceL10n::fromAppLanguage('de'));

        $found = $controller->searchUsers()->getData()['users'];

        $this->assertSame(['anna', 'Hier', 1, true], [$found[0]['id'], trim($found[0]['linkedTo']), $found[0]['linkedToId'], $found[0]['linked']]);
        $this->assertSame([null, null, true], [$found[1]['linkedTo'], $found[1]['linkedToId'], $found[1]['linked']]);
        $this->assertStringNotContainsString('Woanders', json_encode($found));
    }

    public function testN5TheRolePickerUsesTheSharingSearchOfNextcloud(): void {
        $search = $this->createMock(ISearch::class);
        $search->expects($this->once())->method('search')->with('ann', [\OCP\Share\IShare::TYPE_USER], false, 10, 0)->willReturn([[
            'exact' => ['users' => [['label' => 'Ann', 'value' => ['shareType' => 0, 'shareWith' => 'ann']]]],
            'users' => [['label' => 'Anna', 'value' => ['shareType' => 0, 'shareWith' => 'anna']], ['label' => 'Ann', 'value' => ['shareType' => 0, 'shareWith' => 'ann']]],
        ], false]);
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->never())->method('search');
        $controller = new RoleController('verein', $this->request(), $this->createMock(RoleService::class), $this->createMock(RoleMapper::class), $users, SourceL10n::fromAppLanguage('de'), $search);

        $this->assertSame(['ann', 'anna'], array_column($controller->searchUsers('ann')->getData(), 'id'));
    }

    public function testM4LookupSearchesWholeClubsAndOnlyOwnGroupsOfScopedClubs(): void {
        $this->params = ['clubId' => '3', 'query' => 'Mus'];
        $members = $this->createMock(MemberService::class);
        $members->expects($this->once())->method('lookupInOtherClubs')->with(3, 'Mus', [3, 4], [21, 22])->willReturn([]);
        $roles = $this->createMock(RoleService::class);
        $roles->method('getClubIdsWithPermission')->willReturn([3, 4, 5]);
        $scope = $this->createMock(MemberScope::class);
        $scope->method('groups')->willReturn(null);
        $scope->method('memberIds')->willReturnCallback(fn (int $club) => $club === 5 ? [21, 22] : null);
        $controller = new MemberController('verein', $this->request(), $members, new ValidationService(), $roles,
            $this->createMock(ClubMapper::class), $this->session(), $this->createMock(IUserManager::class), $this->createMock(SelfServiceService::class),
            l10n: SourceL10n::fromAppLanguage('de'), scope: $scope);

        $this->assertSame(200, $controller->lookup()->getStatus());
    }
}
