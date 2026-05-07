<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
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

    #[Route('/', name: 'user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        if (!$this->isGranted(User::ROLE_ADMIN)) {
            return $this->redirectToRoute('user_show', [
                'id' => $this->getUser()?->getUserIdentifier(),
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
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
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

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
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
            'email' => 'login',
            'steam', 'github', 'discord', 'alliedmods' => sprintf('login_%s', $externalAccount->getKind()),
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
