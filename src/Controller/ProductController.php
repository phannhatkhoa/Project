<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderDetail;
use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\OrderDetailRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Criteria;

use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Swift_Mailer;
use Swift_Message;
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
     * @Route("/addCart/{id}", name="app_add_cart", methods={"GET"})
     */
    public function addCart(Product $product, Request $request): Response
    {
        $session = $request->getSession();
        $quantity = (int)$request->query->get('quantity');

        //check if cart is empty
        if (!$session->has('cartElements')) {
            //if it is empty, create an array of pairs (prod Id & quantity) to store first cart element.
            $cartElements = array($product->getId() => $quantity * ($quantity + 1));
            //save the array to the session for the first time.
            $session->set('cartElements', $cartElements);
        } else {
            $cartElements = $session->get('cartElements');
            //Add new product after the first time. (would UPDATE new quantity for added product)
            $cartElements = array($product->getId() => $quantity * ($quantity + 1)) + $cartElements;
            //Re-save cart Elements back to session again (after update/append new product to shopping cart)
            $session->set('cartElements', $cartElements);
        }
        return $this->redirectToRoute('app_product_index'); //means 200, successful
    }
    /**
     * @Route("/sendmail", name="app_user_sendmail", methods={"GET"})
     */
    public function sendmail(Swift_Mailer $mailer): Response
    {
        $message = (new Swift_Message('Hello Email'))
            ->setFrom('ldd392002@gmail.com')
            ->setTo('duyle392002@gmail.com')
            ->setBody("3rd Test send email");

        $mailer->send($message);
        return new Response("Send mail successfully");
    }

    /**
     * @Route("/reviewCart", name="app_review_cart", methods={"GET"})
     */
    public function reviewCart(Request $request): Response
    {
        $session = $request->getSession();
        if ($session->has('cartElements')) {
            $cartElements = $session->get('cartElements');
        } else
            $cartElements = [];
        return $this->json($cartElements);
    }


    /**
     * @Route("/checkoutCart", name="app_checkout_cart", methods={"GET"})
     */
    public function checkoutCart(Request               $request,
                                 OrderDetailRepository $orderDetailRepository,
                                 OrderRepository       $orderRepository,
                                 ProductRepository     $productRepository,
                                 ManagerRegistry       $mr): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $entityManager = $mr->getManager();
        $session = $request->getSession(); //get a session
        // check if session has elements in cart
        if ($session->has('cartElements') && !empty($session->get('cartElements'))) {
            try {
                // start transaction!
                $entityManager->getConnection()->beginTransaction();
                $cartElements = $session->get('cartElements');

                //Create new Order and fill info for it. (Skip Total temporarily for now)
                $order = new Order();
                date_default_timezone_set('Asia/Ho_Chi_Minh');
                $order->setDate(new \DateTime());
                /** @var \App\Entity\User $user */
                $user = $this->getUser();
                $order->setUser($user);
                $orderRepository->add($order, true); //flush here first to have ID in Order in DB.

                //Create all Order Details for the above Order
                $total = 0;
                foreach ($cartElements as $product_id => $quantity) {
                    $product = $productRepository->find($product_id);
                    //create each Order Detail
                    $orderDetail = new OrderDetail();
                    $orderDetail->setOrd($order);
                    $orderDetail->setProduct($product);
                    $orderDetail->setQuantity($quantity);
                    $orderDetailRepository->add($orderDetail);

                    $total += $product->getPrice() * $quantity;
                }
                $order->setTotal($total);
                $orderRepository->add($order);
                // flush all new changes (all order details and update order's total) to DB
                $entityManager->flush();

                // Commit all changes if all changes are OK
                $entityManager->getConnection()->commit();

                // Clean up/Empty the cart data (in session) after all.
                $session->remove('cartElements');
            } catch (Exception $e) {
                // If any change above got trouble, we roll back (undo) all changes made above!
                $entityManager->getConnection()->rollBack();
            }
            return new Response("Check in DB to see if the checkout process is successful");
        } else
            return new Response("Nothing in cart to checkout!");
    }

    /**
     * @Route("/{pageId}", name="app_product_index", methods={"GET"})
     */
    public function index(LoggerInterface $logger, ProductRepository $productRepository, Request $request, $pageId = 1): Response
    {
        $selectedCategory = $request->query->get('category');
        $Name = $request->query->get('name');
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
        if (!is_null($Name) && !empty(($Name))) {
            $criteria->andWhere($expressionBuilder->contains('Name', $Name));
//            $criteria->orWhere($expressionBuilder->contains('description', $Name));

        }
        if (!empty($sortBy)) {
            $criteria->orderBy([$sortBy => ($orderBy == 'asc') ? Criteria::ASC : Criteria::DESC]);
        }
        $filteredList = $productRepository->matching($criteria);

        $numOfItems = $filteredList->count();   // total number of items satisfied above query
        $itemsPerPage = 9; // number of items shown each page
        $logger->info($numOfItems);
        $logger->info($pageId);
        $filteredList = $filteredList->slice((int)$itemsPerPage * ((int)$pageId - 1), (int)$itemsPerPage);

        return $this->renderForm('product/index.html.twig', [
            'products' => $filteredList,
            'selectedCat' => $selectedCategory ?: 'Cat',
            'numOfPages' => ceil($numOfItems / $itemsPerPage)
        ]);
    }

    /**
     * @Route("/create/new", name="app_product_new", methods={"GET", "POST"})
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
                        $this->getParameter('kernel.project_dir') . '/public/images/',
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
     * @Route("/show/{id}", name="app_product_show", methods={"GET"})
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
        $form = $this->createForm(ProductType::class, $product,array("no_edit" => true));
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
