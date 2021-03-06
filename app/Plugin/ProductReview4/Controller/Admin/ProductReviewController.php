<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\ProductReview4\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Repository\Master\PageMaxRepository;
use Eccube\Service\CsvExportService;
use Eccube\Util\FormUtil;
use Knp\Component\Pager\PaginatorInterface;
use Plugin\ProductReview4\Entity\ProductReview;
use Plugin\ProductReview4\Entity\ProductReviewConfig;
use Plugin\ProductReview4\Entity\ProductReviewStatus;
use Plugin\ProductReview4\Form\Type\Admin\ProductReviewSearchType;
use Plugin\ProductReview4\Form\Type\Admin\ProductReviewType;
use Plugin\ProductReview4\Repository\ProductReviewConfigRepository;
use Plugin\ProductReview4\Repository\ProductReviewRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Class ProductReviewController admin.
 */
class ProductReviewController extends AbstractController
{
    /**
     * @var PageMaxRepository
     */
    protected $pageMaxRepository;

    /**
     * @var ProductReviewRepository
     */
    protected $productReviewRepository;

    /**
     * @var ProductReviewConfigRepository
     */
    protected $productReviewConfigRepository;

    /** @var CsvExportService */
    protected $csvExportService;

    /**
     * ProductReviewController constructor.
     *
     * @param PageMaxRepository $pageMaxRepository
     * @param ProductReviewRepository $productReviewRepository
     * @param ProductReviewConfigRepository $productReviewConfigRepository
     * @param CsvExportService $csvExportService
     */
    public function __construct(
        PageMaxRepository $pageMaxRepository,
        ProductReviewRepository $productReviewRepository,
        ProductReviewConfigRepository $productReviewConfigRepository,
        CsvExportService $csvExportService
    ) {
        $this->pageMaxRepository = $pageMaxRepository;
        $this->productReviewRepository = $productReviewRepository;
        $this->productReviewConfigRepository = $productReviewConfigRepository;
        $this->csvExportService = $csvExportService;
    }

    /**
     * Search function.
     *
     * @Route("/%eccube_admin_route%/product_review/", name="product_review_admin_product_review")
     * @Route("/%eccube_admin_route%/product_review/page/{page_no}", requirements={"page_no" = "\d+"}, name="product_review_admin_product_review_page")
     * @Template("@ProductReview4/admin/index.twig")
     *
     * @param Request $request
     * @param null $page_no
     *
     * @return array
     */
    public function index(Request $request, $page_no = null, PaginatorInterface $paginator)
    {
        $CsvType = $this->productReviewConfigRepository
            ->get()
            ->getCsvType();
        $builder = $this->formFactory->createBuilder(ProductReviewSearchType::class);
        $searchForm = $builder->getForm();

        $pageMaxis = $this->pageMaxRepository->findAll();
        $pageCount = $this->session->get(
            'product_review.admin.product_review.search.page_count',
            $this->eccubeConfig['eccube_default_page_count']
        );
        $pageCountParam = $request->get('page_count');
        if ($pageCountParam && is_numeric($pageCountParam)) {
            foreach ($pageMaxis as $pageMax) {
                if ($pageCountParam == $pageMax->getName()) {
                    $pageCount = $pageMax->getName();
                    $this->session->set('product_review.admin.product_review.search.page_count', $pageCount);
                    break;
                }
            }
        }

        if ('POST' === $request->getMethod()) {
            $searchForm->handleRequest($request);
            if ($searchForm->isValid()) {
                $searchData = $searchForm->getData();
                $page_no = 1;

                $this->session->set('product_review.admin.product_review.search', FormUtil::getViewData($searchForm));
                $this->session->set('product_review.admin.product_review.search.page_no', $page_no);
            } else {
                return [
                    'searchForm' => $searchForm->createView(),
                    'pagination' => [],
                    'pageMaxis' => $pageMaxis,
                    'page_no' => $page_no,
                    'page_count' => $pageCount,
                    'CsvType' => $CsvType,
                    'has_errors' => true,
                ];
            }
        } else {
            if (null !== $page_no || $request->get('resume')) {
                if ($page_no) {
                    $this->session->set('product_review.admin.product_review.search.page_no', (int) $page_no);
                } else {
                    $page_no = $this->session->get('product_review.admin.product_review.search.page_no', 1);
                }
                $viewData = $this->session->get('product_review.admin.product_review.search', []);
            } else {
                $page_no = 1;
                $viewData = FormUtil::getViewData($searchForm);
                $this->session->set('product_review.admin.product_review.search', $viewData);
                $this->session->set('product_review.admin.product_review.search.page_no', $page_no);
            }
            $searchData = FormUtil::submitAndGetData($searchForm, $viewData);
        }

        $qb = $this->productReviewRepository->getQueryBuilderBySearchData($searchData);

        $pagination = $paginator->paginate(
            $qb,
            $page_no,
            $pageCount
        );

        return [
            'searchForm' => $searchForm->createView(),
            'pagination' => $pagination,
            'pageMaxis' => $pageMaxis,
            'page_no' => $page_no,
            'page_count' => $pageCount,
            'CsvType' => $CsvType,
            'has_errors' => false,
        ];
    }

    /**
     * ??????.
     *
     * @Route("%eccube_admin_route%/product_review/{id}/edit", name="product_review_admin_product_review_edit")
     * @Template("@ProductReview4/admin/edit.twig")
     *
     * @param Request $request
     * @param $id
     *
     * @return array|RedirectResponse
     */
    public function edit(Request $request, ProductReview $ProductReview)
    {
        $Product = $ProductReview->getProduct();
        if (!$Product) {
            $this->addError('product_review.admin.product.not_found', 'admin');

            return $this->redirectToRoute('product_review_admin_product_review', ['resume' => 1]);
        }

        $form = $this->createForm(ProductReviewType::class, $ProductReview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ProductReview = $form->getData();
            $this->entityManager->persist($ProductReview);
            $this->entityManager->flush($ProductReview);

            log_info('Product review edit');

            $this->addSuccess('product_review.admin.save.complete', 'admin');

            return $this->redirectToRoute(
                'product_review_admin_product_review_edit',
                ['id' => $ProductReview->getId()]
            );
        }

        return [
            'form' => $form->createView(),
            'Product' => $Product,
            'ProductReview' => $ProductReview,
        ];
    }

    /**
     * Product review delete function.
     *
     * @Method("DELETE")
     * @Route("%eccube_admin_route%/product_review/{id}/delete", name="product_review_admin_product_review_delete")
     *
     * @param Request $request
     * @param int $id
     *
     * @return RedirectResponse
     */
    public function delete(ProductReview $ProductReview)
    {
        $this->isTokenValid();

        $this->entityManager->remove($ProductReview);
        $this->entityManager->flush($ProductReview);
        $this->addSuccess('product_review.admin.delete.complete', 'admin');

        log_info('Product review delete', ['id' => $ProductReview->getId()]);

        return $this->redirect($this->generateUrl('product_review_admin_product_review_page', ['resume' => 1]));
    }

    /**
     * ????????????????????????????????????????????????
     * 
     * @Route("%eccube_admin_route%/product_review/bulk/productreview-status/{id}", requirements={"id" = "\d+"}, name="product_review_admin_product_review_bulkstatus", methods={"POST"})
     * 
     * @param Request $request
     * @param ProductReviewStatus $ProductStatus
     * @return RedirectResponse
     */
    public function bulkstatus(Request $request, ProductReviewStatus $ProductStatus)
    {
        $this->isTokenValid();

        /** @var ProductReview[] $Products */
        $Products = $this->productReviewRepository->findBy(['id' => $request->get('ids')]);
        $count = 0;
        foreach ($Products as $Product) {
            try {
                $Product->setStatus($ProductStatus);
                $this->productReviewRepository->save($Product);
                $count++;
            } catch (\Exception $e) {
                $this->addError($e->getMessage(), 'admin');
            }
        }
        try {
            if ($count) {
                $this->entityManager->flush();
                $msg = $this->translator->trans('admin.product.bulk_change_status_complete', [
                    '%count%' => $count,
                    '%status%' => $ProductStatus->getName(),
                ]);
                $this->addSuccess($msg, 'admin');
            }
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), 'admin');
        }

        return $this->redirect($this->generateUrl('product_review_admin_product_review_page', ['resume' => 1]));
    } 

    

    /**
     * ??????????????????CSV?????????.
     *
     * @Route("%eccube_admin_route%/product_review/download", name="product_review_admin_product_review_download")
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function download(Request $request)
    {
        // ????????????????????????????????????.
        set_time_limit(0);

        // sql logger??????????????????.
        $em = $this->entityManager;
        $em->getConfiguration()->setSQLLogger(null);
        $response = new StreamedResponse();
        $response->setCallback(function () use ($request) {
            /** @var ProductReviewConfig $Config */
            $Config = $this->productReviewConfigRepository->get();
            $csvType = $Config->getCsvType();

            /* @var $csvService CsvExportService */
            $csvService = $this->csvExportService;

            /* @var $repo ProductReviewRepository */
            $repo = $this->productReviewRepository;

            // CSV????????????????????????.
            $csvService->initCsvType($csvType);

            // ?????????????????????.
            $csvService->exportHeader();

            $session = $request->getSession();
            $searchForm = $this->createForm(ProductReviewSearchType::class);

            $viewData = $session->get('eccube.admin.product.search', []);
            $searchData = FormUtil::submitAndGetData($searchForm, $viewData);

            $qb = $repo->getQueryBuilderBySearchData($searchData);

            // ?????????????????????.
            $csvService->setExportQueryBuilder($qb);
            $csvService->exportData(function ($entity, CsvExportService $csvService) {
                $arrCsv = $csvService->getCsvs();

                $row = [];
                // CSV?????????????????????????????????????????????.
                foreach ($arrCsv as $csv) {
                    // ????????????????????????.
                    $data = $csvService->getData($csv, $entity);
                    $row[] = $data;
                }
                // ??????.
                $csvService->fputcsv($row);
            });
        });

        $now = new \DateTime();
        $filename = 'product_review_'.$now->format('YmdHis').'.csv';
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$filename);
        $response->send();

        log_info('??????????????????CSV?????????????????????', [$filename]);

        return $response;
    }
}
