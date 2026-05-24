<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
class User extends ServerOwner implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_ALLOWED_TO_SWITCH = 'ROLE_ALLOWED_TO_SWITCH';
    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';
    public const THEME_SYSTEM = 'system';

    public const MANAGED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_ALLOWED_TO_SWITCH,
    ];

    public const THEMES = [
        self::THEME_LIGHT,
        self::THEME_DARK,
        self::THEME_SYSTEM,
    ];

    /** @var array<int, string> */
    #[ORM\Column(type: 'json')]
    protected array $roles = [];

    #[ORM\Column(nullable: true)]
    protected ?\DateTimeImmutable $lastLogin = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $login = null;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $passwordHash = null;

    #[ORM\Column(nullable: true)]
    protected ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 128, unique: true)]
    protected string $uploadToken = '';

    #[ORM\Column(length: 16, options: ['default' => self::THEME_LIGHT])]
    protected string $theme = self::THEME_LIGHT;

    /** @var Collection<int, ExternalAccount> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ExternalAccount::class, cascade: ['remove'])]
    #[ORM\OrderBy(['kind' => 'ASC', 'displayName' => 'ASC'])]
    protected Collection $externalAccounts;

    /** @var Collection<int, Team> */
    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: Team::class, cascade: ['remove'])]
    #[ORM\OrderBy(['name' => 'ASC'])]
    protected Collection $teams;

    #[ORM\OneToOne()]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?ExternalAccount $contactEmail = null;

    public function __construct()
    {
        parent::__construct();

        $this->regenerateUploadToken();
        $this->externalAccounts = new ArrayCollection();
        $this->teams = new ArrayCollection();
    }

    public function getIcon(): string
    {
        return 'person-fill';
    }

    /**
     * @see UserInterface
     *
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        $roles = array_intersect(self::MANAGED_ROLES, $this->roles);

        // Guarantee every user at least has ROLE_USER
        array_unshift($roles, self::ROLE_USER);

        return array_unique($roles);
    }

    /**
     * @param array<int, string> $roles
     */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values(array_intersect($roles, self::MANAGED_ROLES));

        return $this;
    }

    public function getLastLogin(): ?\DateTimeImmutable
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeImmutable $lastLogin): self
    {
        $this->lastLogin = $lastLogin;

        return $this;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function setLogin(?string $login): self
    {
        $login = $login !== null ? mb_strtolower(trim($login)) : null;
        $this->login = ($login === '') ? null : $login;

        return $this;
    }

    public function hasLocalLogin(): bool
    {
        return $this->passwordHash !== null
            && $this->passwordHash !== ''
            && (
                ($this->login !== null && $this->login !== '')
                || $this->getContactEmail() !== null
            );
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): self
    {
        $this->passwordHash = $passwordHash !== '' ? $passwordHash : null;

        return $this;
    }

    public function hasPassword(): bool
    {
        return $this->passwordHash !== null && $this->passwordHash !== '';
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getUploadToken(): string
    {
        return $this->uploadToken;
    }

    public function regenerateUploadToken(): self
    {
        $this->uploadToken = bin2hex(random_bytes(32));

        return $this;
    }

    public function getTheme(): string
    {
        return in_array($this->theme, self::THEMES, true) ? $this->theme : self::THEME_LIGHT;
    }

    public function setTheme(string $theme): self
    {
        if (!in_array($theme, self::THEMES, true)) {
            throw new \InvalidArgumentException('Unsupported theme preference');
        }

        $this->theme = $theme;

        return $this;
    }

    /**
     * @return Collection<int, ExternalAccount>
     */
    public function getExternalAccounts(): Collection
    {
        return $this->externalAccounts;
    }

    public function addExternalAccount(ExternalAccount $externalAccount): self
    {
        if (!$this->externalAccounts->contains($externalAccount)) {
            $this->externalAccounts[] = $externalAccount;
            $externalAccount->setUser($this);
        }

        return $this;
    }

    public function removeExternalAccount(ExternalAccount $externalAccount): self
    {
        if ($this->externalAccounts->removeElement($externalAccount)) {
            // set the owning side to null (unless already changed)
            if ($externalAccount->getUser() === $this) {
                $externalAccount->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getEmailAddresses(): array
    {
        $externalAccountCriteria = Criteria::create()
            ->where(Criteria::expr()->eq('kind', 'email'));

        return $this->externalAccounts
            ->matching($externalAccountCriteria)
            ->map(fn ($externalAccount) => $externalAccount->getIdentifier())
            ->toArray();
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail?->getIdentifier();
    }

    public function setContactEmail(?string $contactEmail): self
    {
        if ($contactEmail === null) {
            $this->contactEmail = null;
            $this->emailVerifiedAt = null;

            return $this;
        }

        $externalAccountCriteria = Criteria::create()
            ->where(Criteria::expr()->eq('kind', 'email'))
            ->andWhere(Criteria::expr()->eq('identifier', $contactEmail));

        $externalAccount = $this->externalAccounts
            ->matching($externalAccountCriteria)
            ->first();

        if ($externalAccount === false) {
            throw new \InvalidArgumentException('Contact email does not belong to the user');
        }

        $this->contactEmail = $externalAccount;

        return $this;
    }

    /**
     * @return Collection<int, Team>
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): self
    {
        if (!$this->teams->contains($team)) {
            $this->teams[] = $team;
            $team->setOwner($this);
        }

        return $this;
    }

    public function removeTeam(Team $team): self
    {
        if ($this->teams->removeElement($team)) {
            // set the owning side to null (unless already changed)
            if ($team->getOwner() === $this) {
                $team->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ServerOwner>
     */
    public function getServerOwners(): Collection
    {
        /** @var array<int, ServerOwner> $serverOwners */
        $serverOwners = [$this, ...$this->getTeams()];

        return new ArrayCollection($serverOwners);
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->getId();
    }

    /**
     * This method can be removed in Symfony 6.0 - is not needed for apps that do not check user passwords.
     *
     * @see UserInterface
     */
    public function getSalt(): ?string
    {
        return null;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }
}
