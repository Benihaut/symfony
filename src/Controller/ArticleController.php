<?php

namespace App\Controller;

use App\Entity\Article;
use App\Form\ArticleType;
use App\Repository\ArticleRepository;
use App\Entity\Commentaire;
use App\Form\CommentaireType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/article')]
final class ArticleController extends AbstractController
{
    #[Route(name: 'app_article_index', methods: ['GET'])]
    public function index(Request $request, ArticleRepository $articleRepository): Response
    {
        $query = $request->query->get('q');
        
        if ($query) {
            $articles = $articleRepository->findArticlesBySearch($query);
        } else {
            $articles = $articleRepository->findAll();
        }

        return $this->render('article/index.html.twig', [
            'articles' => $articles,
            'query' => $query
        ]);
    }

    public function findArticlesBySearch(string $query)
    {
        return $this->createQueryBuilder('a')
            ->where('a.titre LIKE :query')
            ->orWhere('a.contenue LIKE :query')
            ->setParameter('query', '%'.$query.'%')
            ->orderBy('a.datearticle', 'DESC')
            ->getQuery()
            ->getResult();
    }


    #[Route('/new', name: 'app_article_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN'); // Seul un admin peut créer un article

        $article = new Article();
        $form = $this->createForm(ArticleType::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($article);
            $entityManager->flush();

            return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('article/new.html.twig', [
            'article' => $article,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_article_show', methods: ['GET', 'POST'])]
    public function show(
        Request $request, 
        Article $article, 
        EntityManagerInterface $entityManager, 
        CsrfTokenManagerInterface $csrfTokenManager
    ): Response {
        $form = null;
        if ($this->getUser()) {
            $commentaire = new Commentaire($this->getUser(), $article);
            $form = $this->createForm(CommentaireType::class, $commentaire);

            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // Pas besoin de setIdarticle et setUser car c'est déjà fait dans le constructeur
                // Pas besoin de setDatecommentaire car c'est géré dans le constructeur
                $entityManager->persist($commentaire);
                $entityManager->flush();

                return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
            }
        }

        return $this->render('article/show.html.twig', [
            'article' => $article,
            'commentaires' => $article->getIdCommentaire(),
            'commentForm' => $form ? $form->createView() : null,
        ]);
    }


    #[Route('/{id}/edit', name: 'app_article_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Article $article, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN'); // Seul un admin peut modifier un article

        $form = $this->createForm(ArticleType::class, $article);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('article/edit.html.twig', [
            'article' => $article,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_article_delete', methods: ['POST'])]
    public function delete(Request $request, Article $article, EntityManagerInterface $entityManager): Response
    {
        dump('Suppression de l\'article !');
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Débogage CSRF
        dump($request->get('_token')); // Vérifie que le token est bien passé
        dump($article->getId()); // Vérifie que l'ID de l'article est correct

        if ($this->isCsrfTokenValid('delete'.$article->getId(), $request->get('_token'))) {
            try {
                $this->addFlash('info', 'Tentative de suppression de l\'article ' . $article->getId());
                $entityManager->remove($article);
                $entityManager->flush();
                $this->addFlash('success', 'L\'article a été supprimé avec succès.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de la suppression : ' . $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'Token CSRF invalide.');
        }

        return $this->redirectToRoute('app_article_index', [], Response::HTTP_SEE_OTHER);
    }



    #[Route('/{id}/comment/{commentId}/delete', name: 'app_article_comment_delete', methods: ['POST'])]
    public function deleteComment(Request $request, Article $article, int $commentId, EntityManagerInterface $entityManager): Response
    {
        $commentaire = $entityManager->getRepository(Commentaire::class)->find($commentId);

    if (!$commentaire || $commentaire->getIdarticle() !== $article) {
        throw $this->createNotFoundException('Commentaire non trouvé');
    }

    // Vérifiez si l'utilisateur actuel est l'auteur du commentaire ou un administrateur
    if ($this->getUser() === $commentaire->getUser() || $this->isGranted('ROLE_ADMIN')) {
        if ($this->isCsrfTokenValid('delete-comment'.$commentaire->getId(), $request->request->get('_token'))) {
            $entityManager->remove($commentaire);
            $entityManager->flush();
        }
    } else {
        throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à supprimer ce commentaire.');
    }

    return $this->redirectToRoute('app_article_show', ['id' => $article->getId()]);
}
        

}
