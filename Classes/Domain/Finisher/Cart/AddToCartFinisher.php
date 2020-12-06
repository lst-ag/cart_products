<?php

namespace Extcode\CartProducts\Domain\Finisher\Cart;

use Extcode\Cart\Domain\Finisher\Cart\AddToCartFinisherInterface;
use Extcode\Cart\Domain\Model\Cart\Cart;
use Extcode\Cart\Domain\Model\Cart\Product;
use Extcode\Cart\Domain\Model\Dto\AvailabilityResponse;
use Extcode\CartProducts\Utility\ProductUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * This file is part of the "cart_products" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
class AddToCartFinisher implements AddToCartFinisherInterface
{
    /**
     * Object manager
     *
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
     */
    public function injectObjectManager(\TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Product Repository
     *
     * @var \Extcode\CartProducts\Domain\Repository\Product\ProductRepository
     */
    protected $productRepository;

    /**
     * @param \Extcode\CartProducts\Domain\Repository\Product\ProductRepository $productRepository
     */
    public function injectProductRepository(\Extcode\CartProducts\Domain\Repository\Product\ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * @param Request $request
     * @param Product $cartProduct
     * @param Cart $cart
     * @param string $mode
     *
     * @return AvailabilityResponse
     */
    public function checkAvailability(
        Request $request,
        Product $cartProduct,
        Cart $cart,
        string $mode = 'update'
    ) : AvailabilityResponse {
        /** @var AvailabilityResponse $availabilityResponse */
        $availabilityResponse = GeneralUtility::makeInstance(
            AvailabilityResponse::class
        );

        if ($cartProduct->getProductType() != 'CartProducts') {
            return $availabilityResponse;
        }

        $querySettings = $this->productRepository->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->productRepository->setDefaultQuerySettings($querySettings);

        $product = $this->productRepository->findByIdentifier($cartProduct->getProductId());

        if (!$product->isHandleStock()) {
            return $availabilityResponse;
        }

        if ($request->hasArgument('quantities')) {
            $quantities = $request->getArgument('quantities');
            $quantities = $quantities[$cartProduct->getId()];
        } else {
            if ($request->hasArgument('quantity')) {
                if ($request->hasArgument('beVariants')) {
                    $quantities[$request->getArgument('beVariants')[1]] = $request->getArgument('quantity');
                } else {
                    $quantities = $request->getArgument('quantity');
                }
            }
        }

        if (!$product->isHandleStockInVariants()) {
            $quantity = (int)$quantities;

            if (($mode === 'add') && $cart->getProduct($cartProduct->getId())) {
                $quantity += $cart->getProduct($cartProduct->getId())->getQuantity();
            }

            if ($quantity > $product->getStock()) {
                $availabilityResponse->setAvailable(false);
                $flashMessage = GeneralUtility::makeInstance(
                    FlashMessage::class,
                    LocalizationUtility::translate(
                        'tx_cart.error.stock_handling.update',
                        'cart'
                    ),
                    '',
                    AbstractMessage::ERROR
                );

                $availabilityResponse->addMessage($flashMessage);
            }

            return $availabilityResponse;
        } else {
            foreach ($product->getBeVariants() as $beVariant) {
                $quantity = (int)$quantities[$beVariant->getUid()];
                if (($mode === 'add') && $cart->getProduct($cartProduct->getId())) {
                    if ($cart->getProduct($cartProduct->getId())->getBeVariant($beVariant->getUid())) {
                        $quantity += (int)$cart->getProduct($cartProduct->getId())->getBeVariant($beVariant->getUid())->getQuantity();
                    }
                }
                if ($quantity > $beVariant->getStock()) {
                    $availabilityResponse->setAvailable(false);
                    $flashMessage = GeneralUtility::makeInstance(
                        FlashMessage::class,
                        LocalizationUtility::translate(
                            'tx_cart.error.stock_handling.update',
                            'cart'
                        ),
                        '',
                        AbstractMessage::ERROR
                    );

                    $availabilityResponse->addMessage($flashMessage);
                }
            }

            return $availabilityResponse;
        }
    }

    /**
     * @param Request $request
     * @param Cart $cart
     *
     * @return array
     */
    public function getProductFromRequest(
        Request $request,
        Cart $cart
    ): array {
        $requestArguments = $request->getArguments();
        $taxClasses = $cart->getTaxClasses();

        $errors = $this->checkRequestArguments($requestArguments);

        if (!empty($errors)) {
            return [$errors, []];
        }

        $productUtility = $this->objectManager->get(
            ProductUtility::class
        );
        $productUtility->setTaxClasses($taxClasses);

        $cartProduct = $productUtility->getProductFromRequest($request, $cart->getTaxClasses());

        return [[], [$cartProduct]];
    }

    /**
     * @param array $requestArguments
     *
     * @return array
     */
    protected function checkRequestArguments(array $requestArguments)
    {
        if (!(int)$requestArguments['product']) {
            return [
                'messageBody' => LocalizationUtility::translate(
                    'tx_cart.error.parameter.no_product',
                    'cart_products'
                ),
                AbstractMessage::ERROR
            ];
        }

        if ((int)$requestArguments['quantity'] < 0) {
            return [
                'messageBody' => LocalizationUtility::translate(
                    'tx_cart.error.invalid_quantity',
                    'cart_products'
                ),
                'severity' => AbstractMessage::WARNING
            ];
        }
    }
}
