<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

#[Route('/users')]
#[IsGranted(User::ROLE_USER)]
class UserController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/', name: 'user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        if (!$this->isGranted(User::ROLE_ADMIN)) {
            return $this->redirectToRoute('user_show', [
                'id' => $this->getUser()?->getId(),
            ]);
        }

        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'user_show', methods: ['GET'])]
    #[IsGranted(UserVoter::VIEW, 'user')]
    public function show(Request $request, User $user): Response
    {
        $viewer = $this->getUser();
        $isOwnProfile = $viewer instanceof User && $viewer->getId() === $user->getId();
        $isAdminViewer = $this->isGranted(User::ROLE_ADMIN);
        $profileIsPrivate = $user->isProfilePrivate() && !$isOwnProfile && !$isAdminViewer;
        $offset = $request->query->get('offset');
        $offset = ctype_digit((string) $offset) ? (int) $offset : null;
        [$steamId, $discordId] = $this->resolveExternalIds($user);
        $canSeeEmail = !$profileIsPrivate && ($isOwnProfile || $isAdminViewer || $user->isProfileFieldVisible('email'));
        $canSeeSteam = !$profileIsPrivate && ($isOwnProfile || $isAdminViewer || $user->isProfileFieldVisible('steam'));
        $canSeeDiscord = !$profileIsPrivate && ($isOwnProfile || $isAdminViewer || $user->isProfileFieldVisible('discord'));
        $crashCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM crash WHERE owner_id = :owner',
            ['owner' => $user->getId()]
        );

        $recentCrashes = [];
        if (!$profileIsPrivate) {
            $sql = <<<'SQL'
                SELECT
                    crash.id,
                    UNIX_TIMESTAMP(crash.timestamp) AS timestamp,
                    crash.cmdline,
                    crash.processed,
                    crash.failed,
                    frame.module,
                    frame.rendered,
                    frame2.module AS module2,
                    frame2.rendered AS rendered2,
                    NULL AS notice
                FROM crash
                LEFT JOIN frame
                    ON frame.crash = crash.id
                   AND frame.thread = crash.thread
                   AND frame.frame = 0
                LEFT JOIN frame frame2
                    ON frame2.crash = crash.id
                   AND frame2.thread = crash.thread
                   AND frame2.frame = 1
                WHERE crash.owner_id = :owner
            SQL;
            $params = ['owner' => $user->getId()];

            if ($offset !== null) {
                $sql .= ' AND crash.timestamp < FROM_UNIXTIME(:offset)';
                $params['offset'] = $offset;
            }

            $sql .= ' ORDER BY crash.timestamp DESC LIMIT 20';

            $recentCrashes = $this->connection->fetchAllAssociative($sql, $params);
        }

        return $this->render('user/show.html.twig', [
            'user' => $user,
            'avatarSeed' => $this->avatarSeed($user),
            'crashCount' => $crashCount,
            'steamId' => $canSeeSteam ? $steamId : null,
            'discordId' => $canSeeDiscord ? $discordId : null,
            'contactEmail' => $canSeeEmail ? $user->getContactEmail() : null,
            'showEmail' => $canSeeEmail,
            'showSteam' => $canSeeSteam,
            'showDiscord' => $canSeeDiscord,
            'isAdminViewer' => $isAdminViewer,
            'isOwnProfile' => $isOwnProfile,
            'profileIsPrivate' => $profileIsPrivate,
            'offset' => $offset,
            'recentCrashes' => $recentCrashes,
            'profileFieldVisibility' => $user->getProfileFieldVisibility(),
        ]);
    }

    private function avatarSeed(User $user): string
    {
        [$steamId, $discordId] = $this->resolveExternalIds($user);

        return (string) (
            $steamId
            ?? $discordId
            ?? $user->getContactEmail()
            ?? $user->getLogin()
            ?? $user->getName()
            ?? $user->getId()
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveExternalIds(User $user): array
    {
        $steamId = null;
        $discordId = null;

        foreach ($user->getExternalAccounts() as $account) {
            $kind = $account->getKind();
            if ($kind === 'steam' && $steamId === null) {
                $steamId = $account->getIdentifier();
            }

            if ($kind === 'discord' && $discordId === null) {
                $discordId = $account->getIdentifier();
            }
        }

        return [$steamId, $discordId];
    }

    #[Route('/{id}/edit', name: 'user_edit', methods: ['GET', 'POST'])]
    #[IsGranted(UserVoter::EDIT, 'user')]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('user_index', [], Response::HTTP_SEE_OTHER);
        }

        [$steamId, $discordId] = $this->resolveExternalIds($user);
        $crashCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM crash WHERE owner_id = :owner',
            ['owner' => $user->getId()]
        );

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'avatarSeed' => $this->avatarSeed($user),
            'steamId' => $steamId,
            'discordId' => $discordId,
            'crashCount' => $crashCount,
        ]);
    }

    #[Route('/{id}', name: 'user_delete', methods: ['POST'])]
    #[IsGranted(UserVoter::DELETE, 'user')]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        /** @var ?string $token */
        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $token)) {
            $entityManager->remove($user);
            $entityManager->flush();
        }

        return $this->redirectToRoute('user_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/refresh/{externalAccount}', name: 'user_refresh_login', methods: ['GET'])]
    public function refresh(Request $request, User $user, ExternalAccount $externalAccount): Response
    {
        if ($this->getUser() !== $user || $this->isGranted(AuthenticatedVoter::IS_IMPERSONATOR)) {
            throw $this->createAccessDeniedException('Only the logged in user can refresh external accounts');
        }

        if ($externalAccount->getUser() !== $user) {
            throw $this->createAccessDeniedException('External account belongs to a different user');
        }

        $route = match ($externalAccount->getKind()) {
            'steam' => 'login_steam',
            default => throw $this->createNotFoundException('Refreshing this external account type is not supported'),
        };

        $response = $this->redirectToRoute($route);

        // This is where the user will be sent back to after re-authenticating.
        $targetPath = $this->generateUrl('user_show', ['id' => $user->getId()]);

        // TODO: Can't get the correct firewall name easily until 6.2
        //       https://symfony.com/doc/6.2/security.html#fetching-the-firewall-configuration-for-a-request
        $this->saveTargetPath($request->getSession(), 'main', $targetPath);

        return $response;
    }
}
