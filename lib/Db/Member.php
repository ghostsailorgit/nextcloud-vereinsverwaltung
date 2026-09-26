<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Verein\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method ?string getSalutation()
 * @method void setSalutation(?string $salutation)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getFirstName()
 * @method void setFirstName(?string $firstName)
 * @method ?string getAddress()
 * @method void setAddress(?string $address)
 * @method ?string getStreet()
 * @method void setStreet(?string $street)
 * @method ?string getPostalCode()
 * @method void setPostalCode(?string $postalCode)
 * @method ?string getCity()
 * @method void setCity(?string $city)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method ?string getIban()
 * @method void setIban(?string $iban)
 * @method ?string getBic()
 * @method void setBic(?string $bic)
 * @method ?string getBirthDate()
 * @method void setBirthDate(?string $birthDate)
 * @method bool getDeceased()
 * @method void setDeceased(bool $deceased)
 * @method ?string getUserId()
 * @method void setUserId(?string $userId)
 * @method ?string getAnonymizedAt()
 * @method void setAnonymizedAt(?string $anonymizedAt)
 * @method ?string getCreatedAt()
 * @method void setCreatedAt(?string $createdAt)
 * @method ?string getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Member extends Entity implements JsonSerializable {
    protected ?string $salutation = null;
    protected string $name = '';
    protected ?string $firstName = null;
    protected ?string $address = null;
    protected ?string $street = null;
    protected ?string $postalCode = null;
    protected ?string $city = null;
    protected string $email = '';
    protected ?string $iban = null;
    protected ?string $bic = null;
    protected ?string $birthDate = null;
    protected bool $deceased = false;
    protected ?string $userId = null;
    protected ?string $anonymizedAt = null;
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    /**
     * Without an explicit type, QBMapper binds a changed bool property as a
     * raw PHP value - (string)false is '', which MySQL then rejects for the
     * integer-backed boolean column ("Incorrect integer value: ''"). Only
     * bites on UPDATE (a real true->false change survives Entity's dirty
     * check), not on the initial INSERT, since a fresh entity's boolean
     * already equals its PHP default and never gets marked dirty at all.
     */
    public function __construct() {
        $this->addType('deceased', 'bool');
    }

    /**
     * Not a database column: the membership in the club this member is being
     * viewed in (set by MemberMapper). A Member is a plain person; join/leave
     * date, role, founding-member flag and SEPA mandate belong to a club and
     * are read through here.
     */
    private ?Membership $membership = null;

    public function setMembership(?Membership $membership): void {
        $this->membership = $membership;
    }

    public function getMembership(): ?Membership {
        return $this->membership;
    }

    public function getRole(): string {
        return $this->membership?->getRole() ?? 'member';
    }

    public function getJoinDate(): ?string {
        return $this->membership?->getJoinDate();
    }

    public function getLeaveDate(): ?string {
        return $this->membership?->getLeaveDate();
    }

    /** Deactivated in the club this member was loaded for (see Membership::$deactivated). */
    public function getDeactivated(): bool {
        return $this->membership?->getDeactivated() ?? false;
    }

    public function getFoundingMember(): bool {
        return $this->membership?->getFoundingMember() ?? false;
    }

    /**
     * "Vorname Name", falling back to just "Name" if no first name is set.
     */
    public function getFullName(): string {
        return trim(($this->firstName ?? '') . ' ' . $this->name);
    }

    /**
     * A member is "ehemalig" (former) once they've left or passed away.
     */
    public function isFormer(): bool {
        return $this->deceased || !empty($this->getLeaveDate());
    }

    /**
     * Current age in full years, or null if no birth date is set.
     */
    public function getAge(): ?int {
        return $this->yearsBetween($this->birthDate, date('Y-m-d'));
    }

    /**
     * Full years of membership: join date to leave date, or to today if
     * still active. Null if no join date is set.
     */
    public function getMembershipYears(): ?int {
        return $this->yearsBetween($this->getJoinDate(), $this->getLeaveDate() ?? date('Y-m-d'));
    }

    private function yearsBetween(?string $from, string $to): ?int {
        if (empty($from)) {
            return null;
        }
        try {
            $start = new \DateTime($from);
            $end = new \DateTime($to);
        } catch (\Exception $e) {
            return null;
        }
        if ($start > $end) {
            return null;
        }
        return $start->diff($end)->y;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'salutation' => $this->salutation,
            'name' => $this->name,
            'firstName' => $this->firstName,
            'fullName' => $this->getFullName(),
            'address' => $this->address,
            'street' => $this->street,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'email' => $this->email,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'birthDate' => $this->birthDate,
            // Linked Nextcloud account (uid) or null; the display name is added by MemberController
            'userId' => $this->userId,
            'deceased' => $this->deceased,
            'anonymizedAt' => $this->anonymizedAt,
            // Club-specific part (defaults when no club context is attached)
            'clubId' => $this->membership?->getClubId(),
            'role' => $this->getRole(),
            'joinDate' => $this->getJoinDate(),
            'leaveDate' => $this->getLeaveDate(),
            'foundingMember' => $this->getFoundingMember(),
            'deactivated' => $this->getDeactivated(),
            'mandateReference' => $this->membership?->getMandateReference(),
            'mandateDate' => $this->membership?->getMandateDate(),
            'mandateFile' => $this->membership?->getMandateFile(),
            'feeRateId' => $this->membership?->getFeeRateId(),
            'age' => $this->getAge(),
            'membershipYears' => $this->getMembershipYears(),
            'isFormer' => $this->isFormer(),
            'createdAt' => $this->createdAt,
        ];
    }
}
