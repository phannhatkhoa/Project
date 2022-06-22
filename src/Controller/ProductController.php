<?php

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Criteria;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/product")
 */
class ProductController extends AbstractController
{
    /**
     * @Route("/{pageId}", name="app_product_index", methods={"GET"})
     */
    public function index(LoggerInterface $logger, ProductRepository $productRepository, Request $request, $pageId = 1): Response
    {
        $selectedCategory = $request->query->get('category');
        $minPrice = $request->query->get('minPrice');
        $maxPrice = $request->query->get('maxPrice');
        $sortBy = $request->query->get('sort');
        $orderBy = $request->query->get('order');

        $expressionBuilder = Criteria::expr();
        $criteria = new Criteria();
        if (empty($minPrice)) {
            $minPrice = 0;
        }
        $criteria->where($expressionBuilder->gte('Price', $minPrice));
        if (!is_null($maxPrice) && !empty(($maxPrice))) {
            $criteria->andWhere($expressionBuilder->lte('Price', $maxPrice));
        }
        if (!is_null($selectedCategory)) {
            $criteria->andWhere($expressionBuilder->eq('Category', $selectedCategory));
        }
        if(!empty($sortBy)){
            $criteria->orderBy([$sortBy => ($orderBy == 'asc') ? Criteria::ASC : Criteria::DESC]);
        }
        $filteredList = $productRepository->matching($criteria);

        $numOfItems = $filteredList->count();   // total number of items satisfied above query
        $itemsPerPage = 8; // number of items shown each page
        $logger->info($numOfItems);
        $logger->info($pageId);
        $filteredList = $filteredList->slice($itemsPerPage * ($pageId - 1), $itemsPerPage);

        return $this->renderForm('product/index.html.twig', [
            'products' => $filteredList,
            'selectedCat' => $selectedCategory ?: 'Cat',
            'numOfPages' => ceil($numOfItems / $itemsPerPage)
        ]);
    }

//    public function index(ProductRepository $productRepository, Request $request, int $pageId = 1): Response
//    {
//        $selectedCategory = $request->query->get('category');
//        $minPrice = $request->query->get('minPrice');
//        $maxPrice = $request->query->get('maxPrice');
//
//
//        $expressionBuilder = Criteria::expr();
//        $criteria = new Criteria();
//        if (is_null($minPrice) || empty($minPrice)) {
//            $minPrice = 0;
//        }
//        $criteria->where($expressionBuilder->gte('Price', $minPrice));
//        if (!is_null($maxPrice) && !empty(($maxPrice))) {
//            $criteria->andWhere($expressionBuilder->lte('Price', $maxPrice));
//        }
//        if (!is_null($selectedCategory)) {
//            $criteria->andWhere($expressionBuilder->eq('Category', $selectedCategory));
//        }
//        $filteredList = $productRepository->matching($criteria);
//        $numOfItems = $filteredList->count();   // total number of items satisfied above query
//        $itemsPerPage = 8; // number of items shown each page
//        $filteredList = $filteredList->slice($itemsPerPage * ($pageId - 1), $itemsPerPage);
//        return $this->renderForm('product/index.html.twig', [
//            'products' => $filteredList,
//            'selectedCat' => $selectedCategory ?: 'Cat',
//            'numOfPages' => ceil($numOfItems / $itemsPerPage)
//        ]);
//    }
    /**
     * @Route("/new", name="app_product_new", methods={"GET", "POST"})
     */
    public function new(Request $request, ProductRepository $productRepository): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $productFile = $form->get('Image')->getData();
            if ($productFile) {
                try {
                    $productFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/images',
                        $form->get('Name')->getData() . '.JPG'
                    );
                } catch (FileException $e) {
                    print($e);
                }
                $product->setImage($form->get('Name')->getData() . '.JPG');
            }
            $productRepository->add($product, true);
            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('product/new.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_product_show", methods={"GET"})
     */
    public function show(Product $product): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_product_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Product $product, ProductRepository $productRepository): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $productRepository->add($product, true);

            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_product_delete", methods={"POST"})
     */
    public function delete(Request $request, Product $product, ProductRepository $productRepository): Response
    {
        if ($this->isCsrfTokenValid('delete' . $product->getId(), $request->request->get('_token'))) {
            $productRepository->remove($product, true);
        }

        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }
}
