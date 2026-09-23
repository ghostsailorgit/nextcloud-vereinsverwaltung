<?php
namespace OCA\Verein\Service;

use Exception;
use OCA\Verein\Db\Member;
use OCA\Verein\Db\MemberMapper;

class MemberService {
    private MemberMapper $mapper;
    private ?MemberCalendarService $calendarService;

    public function __construct(MemberMapper $mapper, ?MemberCalendarService $calendarService = null) {
        $this->mapper = $mapper;
        $this->calendarService = $calendarService;
    }

    public function findAll(): array {
        return $this->mapper->findAll();
    }

    /**
     * Search members by query (name, first name, member number or email),
     * remote-friendly for autocomplete.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function search(string $query, int $limit = 50): array {
        if (trim($query) === '') {
            return [];
        }
        return $this->mapper->search($query, $limit);
    }

    public function find(int $id): Member {
        try {
            return $this->mapper->find($id);
        } catch (Exception $e) {
            throw new Exception('Member not found');
        }
    }

    /**
     * @param array $data Accepted keys: name, firstName, salutation,
     *   memberNumber, address, street, postalCode, city, email, iban, bic,
     *   role, birthDate, joinDate, leaveDate, foundingMember, deceased
     */
    public function create(array $data): Member {
        $member = new Member();
        $this->applyData($member, $data);
        $member->setCreatedAt(date('Y-m-d H:i:s'));
        $member->setUpdatedAt(date('Y-m-d H:i:s'));
        $member = $this->mapper->insert($member);
        $this->calendarService?->syncMember($member);
        return $member;
    }

    public function update(int $id, array $data): Member {
        $member = $this->mapper->find($id);
        $this->applyData($member, $data);
        $member->setUpdatedAt(date('Y-m-d H:i:s'));
        $member = $this->mapper->update($member);
        $this->calendarService?->syncMember($member);
        return $member;
    }

    public function delete(int $id): Member {
        $member = $this->mapper->find($id);
        $this->calendarService?->removeMember($member);
        return $this->mapper->delete($member);
    }

    private function applyData(Member $member, array $data): void {
        $member->setName((string)($data['name'] ?? ''));
        $member->setFirstName($this->nullIfEmpty($data['firstName'] ?? null));
        $member->setSalutation($this->nullIfEmpty($data['salutation'] ?? null));
        $member->setMemberNumber($this->nullIfEmpty($data['memberNumber'] ?? null));
        $member->setAddress($this->nullIfEmpty($data['address'] ?? null));
        $member->setStreet($this->nullIfEmpty($data['street'] ?? null));
        $member->setPostalCode($this->nullIfEmpty($data['postalCode'] ?? null));
        $member->setCity($this->nullIfEmpty($data['city'] ?? null));
        $member->setEmail((string)($data['email'] ?? ''));
        $member->setIban($this->nullIfEmpty($data['iban'] ?? null));
        $member->setBic($this->nullIfEmpty($data['bic'] ?? null));
        $member->setRole((string)($data['role'] ?? 'member'));
        $member->setBirthDate($this->nullIfEmpty($data['birthDate'] ?? null));
        $member->setJoinDate($this->nullIfEmpty($data['joinDate'] ?? null));
        $member->setLeaveDate($this->nullIfEmpty($data['leaveDate'] ?? null));
        $member->setFoundingMember($this->toBool($data['foundingMember'] ?? false));
        $member->setDeceased($this->toBool($data['deceased'] ?? false));
    }

    private function nullIfEmpty(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, ['1', 1, 'true', true], true);
    }
}
